/**
 * @file starmus-offline.js
 * @version 1.0.0
 * @description Offline-first submission queue using IndexedDB.
 * Critical for Gambia, West Africa networks: 2G/3G dropouts, Android low-memory kills,
 * browser crashes, tab reloads. Ensures no recordings are lost.
 * 
 * Features:
 * - IndexedDB queue for failed uploads
 * - Automatic retry when network returns
 * - Background sync attempts
 * - localStorage fallback for browsers without IndexedDB
 * - Queue status UI updates
 */

'use strict';

import { debugLog } from './starmus-hooks.js';
import { uploadWithTus, uploadDirect, isTusAvailable } from './starmus-tus.js';

/**
 * Configuration for offline queue.
 */
const CONFIG = {
    dbName: 'StarmusSubmissions',
    storeName: 'pendingSubmissions',
    dbVersion: 1,
    maxRetries: 10,
    retryDelays: [0, 5000, 10000, 30000, 60000, 120000, 300000, 600000, 1200000, 1800000], // 0s → 30min
    localStorageKey: 'starmus_offline_queue_fallback',
};

/**
 * Offline queue manager using IndexedDB.
 */
class OfflineQueue {
    constructor() {
        this.db = null;
        this.isOnline = navigator.onLine;
        this.isProcessing = false;
        this.useLocalStorageFallback = false;
    }

    /**
     * Initialize IndexedDB connection.
     * Falls back to localStorage if IndexedDB unavailable.
     * 
     * @returns {Promise<void>}
     */
    async init() {
        if (!window.indexedDB) {
            debugLog('[Offline] IndexedDB not supported, using localStorage fallback');
            this.useLocalStorageFallback = true;
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            const request = indexedDB.open(CONFIG.dbName, CONFIG.dbVersion);

            request.onerror = () => {
                debugLog('[Offline] IndexedDB open failed, using localStorage fallback');
                this.useLocalStorageFallback = true;
                resolve();
            };

            request.onsuccess = (event) => {
                this.db = event.target.result;
                debugLog('[Offline] IndexedDB connection ready');
                resolve();
            };

            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                
                // Create object store if it doesn't exist
                if (!db.objectStoreNames.contains(CONFIG.storeName)) {
                    const objectStore = db.createObjectStore(CONFIG.storeName, { keyPath: 'id' });
                    objectStore.createIndex('timestamp', 'timestamp', { unique: false });
                    objectStore.createIndex('retryCount', 'retryCount', { unique: false });
                    debugLog('[Offline] Created object store:', CONFIG.storeName);
                }
            };
        });
    }

    /**
     * Add a submission to the offline queue.
     * 
     * @param {string} instanceId - Starmus instance ID
     * @param {Blob} audioBlob - Audio recording
     * @param {string} fileName - Original filename
     * @param {object} formFields - Form metadata
     * @param {object} metadata - Extended metadata (calibration, transcript, env)
     * @returns {Promise<string>} Submission ID
     */
    async add(instanceId, audioBlob, fileName, formFields, metadata) {
        const submissionId = `starmus-offline-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
        
        const item = {
            id: submissionId,
            instanceId,
            fileName,
            timestamp: Date.now(),
            audioBlob,
            formFields: formFields || {},
            metadata: metadata || {},
            retryCount: 0,
            lastAttempt: null,
            error: null,
        };

        if (this.useLocalStorageFallback) {
            return this._addToLocalStorage(item);
        } else {
            return this._addToIndexedDB(item);
        }
    }

    /**
     * Add item to IndexedDB.
     * @private
     */
    async _addToIndexedDB(item) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([CONFIG.storeName], 'readwrite');
            const store = transaction.objectStore(CONFIG.storeName);
            const request = store.add(item);

            request.onsuccess = () => {
                debugLog('[Offline] Added to queue:', item.id);
                this._notifyQueueUpdate();
                resolve(item.id);
            };

            request.onerror = () => {
                debugLog('[Offline] Failed to add to queue:', request.error);
                reject(request.error);
            };
        });
    }

    /**
     * Add item to localStorage (fallback).
     * Note: Cannot store Blobs in localStorage, so we convert to base64.
     * @private
     */
    async _addToLocalStorage(item) {
        try {
            // Convert Blob to base64 for localStorage
            const base64Audio = await this._blobToBase64(item.audioBlob);
            
            const storageItem = {
                ...item,
                audioBlob: null, // Remove blob
                audioBase64: base64Audio, // Add base64
            };

            const queue = this._getLocalStorageQueue();
            queue.push(storageItem);
            
            try {
                localStorage.setItem(CONFIG.localStorageKey, JSON.stringify(queue));
                debugLog('[Offline] Added to localStorage fallback queue:', item.id);
                this._notifyQueueUpdate();
                return item.id;
            } catch (e) {
                // localStorage quota exceeded
                debugLog('[Offline] localStorage quota exceeded, cannot save offline');
                throw new Error('STORAGE_QUOTA_EXCEEDED');
            }
        } catch (error) {
            debugLog('[Offline] Failed to save to localStorage:', error);
            throw error;
        }
    }

    /**
     * Get all pending submissions from queue.
     * 
     * @returns {Promise<Array>} Array of pending submissions
     */
    async getAll() {
        if (this.useLocalStorageFallback) {
            return this._getLocalStorageQueue();
        } else {
            return this._getAllFromIndexedDB();
        }
    }

    /**
     * Get all from IndexedDB.
     * @private
     */
    async _getAllFromIndexedDB() {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([CONFIG.storeName], 'readonly');
            const store = transaction.objectStore(CONFIG.storeName);
            const request = store.getAll();

            request.onsuccess = () => {
                resolve(request.result || []);
            };

            request.onerror = () => {
                reject(request.error);
            };
        });
    }

    /**
     * Get queue from localStorage.
     * @private
     */
    _getLocalStorageQueue() {
        try {
            const json = localStorage.getItem(CONFIG.localStorageKey);
            return json ? JSON.parse(json) : [];
        } catch (e) {
            debugLog('[Offline] Failed to read localStorage queue:', e);
            return [];
        }
    }

    /**
     * Remove a submission from queue.
     * 
     * @param {string} submissionId - ID to remove
     * @returns {Promise<void>}
     */
    async remove(submissionId) {
        if (this.useLocalStorageFallback) {
            return this._removeFromLocalStorage(submissionId);
        } else {
            return this._removeFromIndexedDB(submissionId);
        }
    }

    /**
     * Remove from IndexedDB.
     * @private
     */
    async _removeFromIndexedDB(submissionId) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([CONFIG.storeName], 'readwrite');
            const store = transaction.objectStore(CONFIG.storeName);
            const request = store.delete(submissionId);

            request.onsuccess = () => {
                debugLog('[Offline] Removed from queue:', submissionId);
                this._notifyQueueUpdate();
                resolve();
            };

            request.onerror = () => {
                reject(request.error);
            };
        });
    }

    /**
     * Remove from localStorage.
     * @private
     */
    async _removeFromLocalStorage(submissionId) {
        const queue = this._getLocalStorageQueue();
        const filtered = queue.filter(item => item.id !== submissionId);
        localStorage.setItem(CONFIG.localStorageKey, JSON.stringify(filtered));
        debugLog('[Offline] Removed from localStorage queue:', submissionId);
        this._notifyQueueUpdate();
    }

    /**
     * Update retry count for a submission.
     * @private
     */
    async _updateRetryCount(submissionId, retryCount, error) {
        if (this.useLocalStorageFallback) {
            const queue = this._getLocalStorageQueue();
            const item = queue.find(i => i.id === submissionId);
            if (item) {
                item.retryCount = retryCount;
                item.lastAttempt = Date.now();
                item.error = error || null;
                localStorage.setItem(CONFIG.localStorageKey, JSON.stringify(queue));
            }
        } else {
            return new Promise((resolve, reject) => {
                const transaction = this.db.transaction([CONFIG.storeName], 'readwrite');
                const store = transaction.objectStore(CONFIG.storeName);
                const request = store.get(submissionId);

                request.onsuccess = () => {
                    const item = request.result;
                    if (item) {
                        item.retryCount = retryCount;
                        item.lastAttempt = Date.now();
                        item.error = error || null;
                        store.put(item);
                    }
                    resolve();
                };

                request.onerror = () => {
                    reject(request.error);
                };
            });
        }
    }

    /**
     * Process offline queue - attempt to upload all pending submissions.
     * Uses exponential backoff for retries.
     * 
     * @returns {Promise<void>}
     */
    async processQueue() {
        if (this.isProcessing) {
            debugLog('[Offline] Queue processing already in progress');
            return;
        }

        if (!this.isOnline) {
            debugLog('[Offline] Cannot process queue while offline');
            return;
        }

        this.isProcessing = true;
        debugLog('[Offline] Starting queue processing');

        try {
            const pending = await this.getAll();
            
            if (pending.length === 0) {
                debugLog('[Offline] No pending submissions');
                this.isProcessing = false;
                return;
            }

            debugLog(`[Offline] Processing ${pending.length} pending submission(s)`);

            for (const item of pending) {
                // Check if max retries exceeded
                if (item.retryCount >= CONFIG.maxRetries) {
                    debugLog('[Offline] Max retries exceeded for:', item.id);
                    // Keep in queue but don't retry (user can manually retry later)
                    continue;
                }

                // Check if we should retry based on delay
                if (item.lastAttempt) {
                    const delay = CONFIG.retryDelays[Math.min(item.retryCount, CONFIG.retryDelays.length - 1)];
                    const timeSinceLastAttempt = Date.now() - item.lastAttempt;
                    
                    if (timeSinceLastAttempt < delay) {
                        debugLog(`[Offline] Skipping ${item.id}, retry delay not elapsed`);
                        continue;
                    }
                }

                // Attempt upload
                try {
                    debugLog(`[Offline] Retrying upload (attempt ${item.retryCount + 1}):`, item.id);
                    
                    // Convert base64 back to Blob if using localStorage fallback
                    let audioBlob = item.audioBlob;
                    if (this.useLocalStorageFallback && item.audioBase64) {
                        audioBlob = await this._base64ToBlob(item.audioBase64, 'audio/webm');
                    }

                    // Use same upload logic as live submissions
                    const useTus = isTusAvailable() && audioBlob.size > 1024 * 1024;
                    
                    if (useTus) {
                        await uploadWithTus(
                            audioBlob,
                            item.fileName,
                            item.formFields,
                            item.metadata,
                            item.instanceId,
                            null // No progress callback for background uploads
                        );
                    } else {
                        await uploadDirect(
                            audioBlob,
                            item.fileName,
                            item.formFields,
                            item.metadata,
                            item.instanceId
                        );
                    }

                    // Success - remove from queue
                    debugLog('[Offline] Upload successful, removing from queue:', item.id);
                    await this.remove(item.id);
                    
                } catch (error) {
                    debugLog('[Offline] Upload failed:', error.message);
                    await this._updateRetryCount(item.id, item.retryCount + 1, error.message);
                }
            }
        } finally {
            this.isProcessing = false;
            debugLog('[Offline] Queue processing complete');
        }
    }

    /**
     * Set up network event listeners.
     */
    setupNetworkListeners() {
        window.addEventListener('online', () => {
            debugLog('[Offline] Network online, processing queue');
            this.isOnline = true;
            this.processQueue();
        });

        window.addEventListener('offline', () => {
            debugLog('[Offline] Network offline');
            this.isOnline = false;
        });

        // Also try to process queue periodically if online
        setInterval(() => {
            if (this.isOnline && !this.isProcessing) {
                this.processQueue();
            }
        }, 60000); // Every minute
    }

    /**
     * Notify UI about queue updates.
     * @private
     */
    _notifyQueueUpdate() {
        if (window.StarmusHooks?.doAction) {
            this.getAll().then((queue) => {
                window.StarmusHooks.doAction('starmus_offline_queue_updated', queue);
            });
        }
    }

    /**
     * Convert Blob to base64 string.
     * @private
     */
    _blobToBase64(blob) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onloadend = () => resolve(reader.result);
            reader.onerror = reject;
            reader.readAsDataURL(blob);
        });
    }

    /**
     * Convert base64 string back to Blob.
     * @private
     */
    _base64ToBlob(base64, mimeType) {
        const byteString = atob(base64.split(',')[1]);
        const ab = new ArrayBuffer(byteString.length);
        const ia = new Uint8Array(ab);
        
        for (let i = 0; i < byteString.length; i++) {
            ia[i] = byteString.charCodeAt(i);
        }
        
        return new Blob([ab], { type: mimeType });
    }
}

// Global singleton instance
let queueInstance = null;

/**
 * Get or create the offline queue instance.
 * 
 * @returns {Promise<OfflineQueue>}
 */
export async function getOfflineQueue() {
    if (!queueInstance) {
        queueInstance = new OfflineQueue();
        await queueInstance.init();
        queueInstance.setupNetworkListeners();
    }
    return queueInstance;
}

/**
 * Add a submission to offline queue.
 * 
 * @param {string} instanceId - Starmus instance ID
 * @param {Blob} audioBlob - Audio recording
 * @param {string} fileName - Original filename
 * @param {object} formFields - Form metadata
 * @param {object} metadata - Extended metadata
 * @returns {Promise<string>} Submission ID
 */
export async function queueSubmission(instanceId, audioBlob, fileName, formFields, metadata) {
    const queue = await getOfflineQueue();
    return queue.add(instanceId, audioBlob, fileName, formFields, metadata);
}

/**
 * Get count of pending submissions.
 * 
 * @returns {Promise<number>}
 */
export async function getPendingCount() {
    const queue = await getOfflineQueue();
    const pending = await queue.getAll();
    return pending.length;
}
