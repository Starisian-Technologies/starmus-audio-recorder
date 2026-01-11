/**
 * @file starmus-offline.js
 * @version 1.5.0-GLOBAL-EXPOSE
 * @description Offline-first submission queue using IndexedDB for reliable audio uploads.
 * Provides automatic retry mechanisms, network status monitoring, and persistent storage
 * for audio submissions when network connectivity is unavailable or unreliable.
 *
 * Features:
 * - IndexedDB-based persistent storage for audio blobs and metadata
 * - Automatic retry with exponential backoff delays
 * - Network connectivity monitoring and auto-resume
 * - Blob size validation and memory management
 * - Queue status notifications through command bus
 * - Cross-tab synchronization and version management
 */

'use strict';

import { debugLog } from './starmus-hooks.js';
import { uploadWithPriority } from './starmus-tus.js';

/**
 * Configuration object for offline queue behavior.
 * Defines database settings, retry policies, and size limits.
 *
 * @constant
 * @type {Object}
 * @property {string} dbName - IndexedDB database name
 * @property {string} storeName - Object store name for submissions
 * @property {number} dbVersion - Database schema version
 * @property {number} maxRetries - Maximum retry attempts per submission
 * @property {Array<number>} retryDelays - Retry delay intervals in milliseconds
 * @property {Object<string, number>} maxBlobSizes - Tier-based maximum blob sizes in bytes
 * @property {number} defaultMaxBlobSize - Fallback maximum blob size in bytes when tier is unknown
 */
const CONFIG = {
	dbName: 'StarmusSubmissions',
	storeName: 'pendingSubmissions',
	dbVersion: 1,
	maxRetries: 10,
	retryDelays: [0, 5000, 10000, 30000, 60000, 120000, 300000, 600000, 1200000, 1800000],
	// Tier-based size limits for African markets
	maxBlobSizes: {
		A: 20 * 1024 * 1024, // 20MB for high-end devices
		B: 10 * 1024 * 1024, // 10MB for mid-range devices
		C: 5 * 1024 * 1024, // 5MB for low-end devices
	},
	defaultMaxBlobSize: 5 * 1024 * 1024, // Default to Tier C for safety
};

/**
 * Resolves the maximum allowed blob size based on environment tier metadata.
 * Uses conservative defaults for safety in low-bandwidth markets.
 *
 * @param {Object} metadata - Submission metadata containing environment details
 * @param {Object} [metadata.env] - Environment object with tier classification
 * @param {string} [metadata.tier] - Explicit tier override
 * @returns {number} Maximum blob size in bytes allowed for the submission
 */
function getMaxBlobSize(metadata = {}) {
	const rawTier =
    metadata && typeof metadata === 'object' ? (metadata.tier ?? metadata.env?.tier) : undefined;

	if (
		typeof rawTier === 'string' &&
    Object.prototype.hasOwnProperty.call(CONFIG.maxBlobSizes, rawTier)
	) {
		return CONFIG.maxBlobSizes[rawTier];
	}
	return CONFIG.defaultMaxBlobSize;
}

/**
 * Internal queue class for managing offline audio submissions.
 * Handles IndexedDB operations, retry logic, and network monitoring.
 *
 * @class
 * @private
 */
// Internal queue class
class OfflineQueue {
	/**
   * Creates a new OfflineQueue instance.
   * Initializes database connection state and processing flags.
   *
   * @constructor
   */
	constructor() {
		/**
     * IndexedDB database connection.
     * @type {IDBDatabase|null}
     */
		this.db = null;

		/**
     * Flag indicating if queue processing is active.
     * @type {boolean}
     */
		this.isProcessing = false;
	}

	/**
   * Initializes IndexedDB database connection and schema.
   * Creates object store and indexes if needed during upgrade.
   * Handles version changes and connection management.
   *
   * @async
   * @method
   * @returns {Promise<void>} Resolves when database is ready or fails gracefully
   *
   * @description Database Schema:
   * - Object Store: 'pendingSubmissions' with keyPath 'id'
   * - Index: 'timestamp' for chronological ordering
   * - Index: 'retryCount' for retry management
   */
	async init() {
		if (!window.indexedDB) {
			const error = new Error('IndexedDB not supported');
			console.error('[Offline] CRITICAL:', error.message);
			this._reportStorageFailure('no_indexeddb', error);
			throw error; // Don't silently continue
		}

		return new Promise((resolve, reject) => {
			const req = indexedDB.open(CONFIG.dbName, CONFIG.dbVersion);

			req.onerror = (e) => {
				const error = e.target.error;
				console.error('[Offline] CRITICAL: DB open failed:', error);

				// Detailed error reporting for African market debugging
				this._reportStorageFailure('db_open_failed', error, {
					name: error.name,
					message: error.message,
					userAgent: navigator.userAgent,
					isPrivateBrowsing: this._detectPrivateBrowsing(),
				});

				reject(error); // Don't silently fail
			};

			req.onblocked = () => {
				const error = new Error('DB open blocked - close other tabs');
				console.error('[Offline] CRITICAL:', error.message);
				this._reportStorageFailure('db_blocked', error);
				reject(error);
			};

			req.onsuccess = (e) => {
				this.db = e.target.result;

				this.db.onversionchange = () => {
					this.db.close();
					console.warn('[Offline] DB version changed — closed connection');
				};

				this.db.onerror = (event) => {
					console.error('[Offline] DB runtime error:', event.target.error);
					this._reportStorageFailure('db_runtime_error', event.target.error);
				};

				console.log('[Offline] DB ready');
				resolve();
			};

			req.onupgradeneeded = (e) => {
				const db = e.target.result;
				if (!db.objectStoreNames.contains(CONFIG.storeName)) {
					const store = db.createObjectStore(CONFIG.storeName, { keyPath: 'id' });
					store.createIndex('timestamp', 'timestamp', { unique: false });
					store.createIndex('retryCount', 'retryCount', { unique: false });
					debugLog('[Offline] Created object store:', CONFIG.storeName);
				}
			};
		});
	}

	async add(instanceId, audioBlob, fileName, formFields = {}, metadata = {}) {
		if (!this.db) {
			throw new Error('OfflineQueue: DB not initialized');
		}

		const maxAllowedSize = getMaxBlobSize(metadata);
		if (audioBlob.size > maxAllowedSize) {
			throw new Error(
				`Audio too large (${(audioBlob.size / 1024 / 1024).toFixed(2)} MB); limit ${(maxAllowedSize / 1024 / 1024).toFixed(2)} MB`
			);
		}

		// Clone blob to detach underlying buffer
		const safeBlob = new Blob([audioBlob], { type: audioBlob.type });

		const item = {
			id: `starmus-offline-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`,
			instanceId,
			fileName,
			timestamp: Date.now(),
			audioBlob: safeBlob,
			formFields,
			metadata,
			retryCount: 0,
			lastAttempt: null,
			error: null,
		};

		return new Promise((resolve, reject) => {
			const tx = this.db.transaction([CONFIG.storeName], 'readwrite');
			const store = tx.objectStore(CONFIG.storeName);
			store.add(item);

			tx.oncomplete = () => {
				debugLog('[Offline] Queued:', item.id);
				this._notifyQueueUpdate();
				resolve(item.id);
			};

			tx.onerror = (ev) => reject(ev.target.error);
		});
	}

	/**
   * Retrieves all pending submissions from the database.
   *
   * @async
   * @method
   * @returns {Promise<Array<Object>>} Array of submission objects
   */
	async getAll() {
		if (!this.db) {
			return [];
		}
		return new Promise((resolve, reject) => {
			const tx = this.db.transaction([CONFIG.storeName], 'readonly');
			const store = tx.objectStore(CONFIG.storeName);
			const req = store.getAll();
			req.onsuccess = () => resolve(req.result || []);
			req.onerror = () => reject(req.error);
		});
	}

	/**
   * Removes a submission from the queue by ID.
   * Triggers queue update notification after removal.
   *
   * @async
   * @method
   * @param {string} id - Submission ID to remove
   * @returns {Promise<void>}
   */
	async remove(id) {
		if (!this.db) {
			return;
		}
		return new Promise((resolve, reject) => {
			const tx = this.db.transaction([CONFIG.storeName], 'readwrite');
			tx.objectStore(CONFIG.storeName).delete(id);
			tx.oncomplete = () => {
				this._notifyQueueUpdate();
				resolve();
			};
			tx.onerror = (ev) => reject(ev.target.error);
		});
	}

	/**
   * Updates retry information for a failed submission.
   * Records retry count, timestamp, and error details.
   *
   * @async
   * @method
   * @private
   * @param {string} id - Submission ID to update
   * @param {number} retryCount - New retry count
   * @param {string} [error] - Error message from failed attempt
   * @returns {Promise<void>}
   */
	async _updateRetry(id, retryCount, error) {
		if (!this.db) {
			return;
		}
		return new Promise((resolve, reject) => {
			const tx = this.db.transaction([CONFIG.storeName], 'readwrite');
			const store = tx.objectStore(CONFIG.storeName);
			const req = store.get(id);
			req.onsuccess = () => {
				const item = req.result;
				if (item) {
					item.retryCount = retryCount;
					item.lastAttempt = Date.now();
					item.error = error || null;
					store.put(item);
				}
			};
			req.onerror = (ev) => reject(ev.target.error);
			tx.oncomplete = () => resolve();
		});
	}

	/**
   * Processes all pending submissions in the queue.
   * Attempts upload with retry logic and exponential backoff.
   * Only runs when online and not already processing.
   *
   * @async
   * @method
   * @returns {Promise<void>}
   *
   * @description Processing logic:
   * 1. Skips if already processing or offline
   * 2. Retrieves all pending submissions
   * 3. For each item, checks retry limits and delays
   * 4. Attempts upload using uploadWithPriority
   * 5. Removes successful uploads from queue
   * 6. Updates retry count for failed uploads
   * 7. Skips non-retryable errors (400, Invalid JSON, etc.)
   */
	async processQueue() {
		if (this.isProcessing || !navigator.onLine) {
			return;
		}
		this.isProcessing = true;

		try {
			const pending = await this.getAll();
			if (pending.length === 0) {
				this.isProcessing = false;
				return;
			}

			debugLog(`[Offline] Processing ${pending.length} items`);

			for (const item of pending) {
				const { id, audioBlob, fileName, formFields, metadata, retryCount, instanceId } = item;

				if (retryCount >= CONFIG.maxRetries) {
					continue;
				}

				if (item.lastAttempt !== null) {
					const delay = CONFIG.retryDelays[Math.min(retryCount, CONFIG.retryDelays.length - 1)];
					if (Date.now() - item.lastAttempt < delay) {
						continue;
					}
				}

				try {
					const _result = await uploadWithPriority({
						blob: audioBlob,
						fileName,
						formFields,
						metadata,
						instanceId,
					}); // Wrapped in object per recent fix

					await this.remove(id);
				} catch (err) {
					const msg = err && err.message ? err.message : String(err);
					const nonRetryable = /400|Invalid JSON|QuotaExceeded/i.test(msg);
					if (!nonRetryable) {
						await this._updateRetry(id, retryCount + 1, msg);
					}
				}
			}
		} catch (fatal) {
			console.error('[Offline] Queue fatal:', fatal);
		} finally {
			this.isProcessing = false;
		}
	}

	/**
   * Sets up network event listeners for automatic queue processing.
   * Processes queue when connection comes online and periodically while online.
   *
   * @method
   * @returns {void}
   */
	setupNetworkListeners() {
		window.addEventListener('online', () => this.processQueue());
		setInterval(() => {
			if (navigator.onLine) {
				this.processQueue().catch(() => {});
			}
		}, 60 * 1000);
	}

	/**
   * Notifies external listeners about queue status changes.
   * Dispatches event through CommandBus with current queue state.
   *
   * @method
   * @private
   * @returns {void}
   */
	_notifyQueueUpdate() {
		const BUS = window.CommandBus || window.StarmusHooks;
		if (!BUS || typeof BUS.dispatch !== 'function') {
			return;
		}

		this.getAll().then((queue) => {
			BUS.dispatch('starmus/offline/queue_updated', {
				count: queue.length,
				queue: queue.map((item) => ({
					id: item.id,
					retryCount: item.retryCount,
					error: item.error,
				})),
			});
		});
	}

	/**
   * Reports storage failures to SPARXSTAR for debugging
   */
	_reportStorageFailure(type, error, details = {}) {
		const errorData = {
			type: `offline_storage_${type}`,
			error: error.message,
			details: {
				...details,
				timestamp: Date.now(),
				storageEstimate: null,
			},
		};

		// Get storage quota info if available
		if ('storage' in navigator && 'estimate' in navigator.storage) {
			navigator.storage.estimate().then((estimate) => {
				errorData.details.storageEstimate = {
					usage: estimate.usage,
					quota: estimate.quota,
					usagePercent: ((estimate.usage / estimate.quota) * 100).toFixed(2),
				};

				// Report to SPARXSTAR if available
				if (window.SparxstarIntegration?.reportError) {
					window.SparxstarIntegration.reportError(errorData.type, errorData);
				}
			});
		} else {
			// Report immediately if storage API not available
			if (window.SparxstarIntegration?.reportError) {
				window.SparxstarIntegration.reportError(errorData.type, errorData);
			}
		}

		// Also show user-friendly error
		this._showUserError(type, error);
	}

	/**
   * Detects private browsing mode (common cause of IndexedDB failures)
   */
	_detectPrivateBrowsing() {
		try {
			const test = window.indexedDB.open('test');
			test.onerror = () => true;
			return false;
		} catch (e) {
			return true;
		}
	}

	/**
   * Shows user-friendly error message
   */
	_showUserError(type, error) {
		const messages = {
			no_indexeddb:
        "Your browser doesn't support offline storage. Recordings will upload immediately.",
			db_open_failed: 'Storage initialization failed. Please check your browser settings.',
			db_blocked: 'Please close other tabs and try again.',
			quota_exceeded: 'Storage full. Please free up space or upload pending recordings.',
		};

		const message = messages[type] || 'Storage error occurred.';
		console.error(`[Offline] User message: ${message}`);

		// Dispatch event for UI to show error
		if (window.CommandBus) {
			window.CommandBus.dispatch('starmus/storage-error', {
				type,
				message,
				error: error.message,
			});
		}
	}
}

/**
 * Global offline queue instance.
 * @type {OfflineQueue}
 */
const offlineQueue = new OfflineQueue();

/**
 * Gets the initialized offline queue instance.
 * Initializes database connection and network listeners on first access.
 *
 * @async
 * @function
 * @exports getOfflineQueue
 * @returns {Promise<OfflineQueue>} Configured offline queue instance
 */
export async function getOfflineQueue() {
	if (!offlineQueue.db) {
		await offlineQueue.init();
		offlineQueue.setupNetworkListeners();
	}
	return offlineQueue;
}

/**
 * Queues an audio submission for offline processing.
 * Convenience function that gets queue instance and adds submission.
 *
 * @async
 * @function
 * @exports queueSubmission
 * @param {string} instanceId - Recorder instance identifier
 * @param {Blob} audioBlob - Audio file blob to queue
 * @param {string} fileName - Name for the audio file
 * @param {Object} formFields - Form data (consent, language, etc.)
 * @param {Object} metadata - Additional metadata (transcript, calibration, env)
 * @returns {Promise<string>} Unique submission ID for tracking
 *
 * @example
 * const submissionId = await queueSubmission(
 *   'rec-123',
 *   audioBlob,
 *   'recording.webm',
 *   { consent: 'yes', language: 'en' },
 *   { transcript: 'Hello world', tier: 'A' }
 * );
 */
export async function queueSubmission(instanceId, audioBlob, fileName, formFields, metadata) {
	const q = await getOfflineQueue();
	return q.add(instanceId, audioBlob, fileName, formFields, metadata);
}

/**
 * Gets the count of pending submissions in the offline queue.
 *
 * @async
 * @function
 * @exports getPendingCount
 * @returns {Promise<number>} Number of pending submissions
 */
export async function getPendingCount() {
	const q = await getOfflineQueue();
	const list = await q.getAll();
	return list.length;
}

/**
 * Initializes the offline queue system.
 * Alias for getOfflineQueue for backward compatibility.
 *
 * @function
 * @exports initOffline
 * @returns {Promise<OfflineQueue>} Configured offline queue instance
 */
export function initOffline() {
	return getOfflineQueue();
}

/**
 * Default export of the offline queue instance.
 * @default
 */
export default offlineQueue;

/**
 * Global browser environment exports.
 * Makes offline functions available on window object for direct access.
 * @global
 */
// EXPOSE GLOBALLY FOR SAFETY
if (typeof window !== 'undefined') {
	/**
   * Global initOffline function reference.
   * @global
   * @type {function}
   */
	window.initOffline = initOffline;

	/**
   * Global offline queue getter function.
   * @global
   * @type {function}
   */
	window.StarmusOfflineQueue = getOfflineQueue;
}
