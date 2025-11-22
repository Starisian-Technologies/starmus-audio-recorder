/**
 * @file starmus-offline.js
 * @version 1.2.0
 * @description Offline-first submission queue using IndexedDB.
 * Includes transaction durability fixes and OOM crash protection for low-end Android.
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
    // Exponential backoff: 0s, 5s, 10s, 30s, 1m, 2m, 5m, 10m, 20m, 30m
    retryDelays: [0, 5000, 10000, 30000, 60000, 120000, 300000, 600000, 1200000, 1800000],
    // Safety limit for low-end Android devices to prevent OOM crashes during DB commit
    maxBlobSize: 40 * 1024 * 1024, // 40MB
};

/**
 * Offline queue manager using IndexedDB.
 */
class OfflineQueue {
    constructor() {
        this.db = null;
        this.isOnline = navigator.onLine;
        this.isProcessing = false;
    }

    /**
     * Initialize IndexedDB connection.
     * @returns {Promise<void>}
     */
    async init() {
        if (!window.indexedDB) {
            console.error('[Offline] Critical: IndexedDB not supported. Offline uploads unavailable.');
            return Promise.resolve();
        }

        return new Promise((resolve, _reject) => {
            const request = indexedDB.open(CONFIG.dbName, CONFIG.dbVersion);

            request.onerror = (event) => {
                debugLog('[Offline] DB Open Failed:', event.target.error);
                resolve(); 
            };

            request.onblocked = () => {
                 debugLog('[Offline] DB Open Blocked: Close other tabs');
            };

            request.onsuccess = (event) => {
                this.db = event.target.result;
                
                // Handle version changes (e.g. if user opens a newer version of the app in a new tab)
                this.db.onversionchange = () => {
                    this.db.close();
                    debugLog('[Offline] Database outdated, closing connection.');
                };

                debugLog('[Offline] IndexedDB connection ready');
                resolve();
            };

            request.onupgradeneeded = (event) => {
                const db = event.target.result;
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
     */
    async add(instanceId, audioBlob, fileName, formFields, metadata) {
        if (!this.db) {
            throw new Error('Database not initialized');
        }

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

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([CONFIG.storeName], 'readwrite');
            const store = transaction.objectStore(CONFIG.storeName);

            // CRASH PROTECTION: Abort early if blob is too large for device profile
            if (item.audioBlob.size > CONFIG.maxBlobSize) { 
                transaction.abort();
                const msg = `Audio file too large for offline storage (${(item.audioBlob.size / 1024 / 1024).toFixed(2)}MB > 40MB limit)`;
                debugLog(`[Offline] ${msg}`);
                reject(new Error(msg));
                return;
            }

            // Force-clone blob to detach underlying buffer
            // On low-end Android, MediaRecorder and IDB share the same memory pointer
            // When recorder releases buffer, Blob becomes detached → IDB commit fails silently
            const safeBlob = new Blob([item.audioBlob], { type: item.audioBlob.type });
            item.audioBlob = safeBlob;

            const request = store.add(item);

            // Resolve only on durability commit
            transaction.oncomplete = () => {
                debugLog('[Offline] Transaction Committed:', item.id);
                this._notifyQueueUpdate();
                resolve(item.id);
            };

            transaction.onerror = (event) => {
                const err = event.target.error;
                // Detect quota exceeded explicitly to prevent retry loops
                if (err?.name === 'QuotaExceededError') {
                    debugLog('[Offline] IndexedDB quota exceeded');
                    reject(new Error('IndexedDB quota exceeded — cannot save offline.'));
                    return;
                }
                debugLog('[Offline] Transaction Failed:', err);
                reject(err);
            };

            request.onerror = (e) => {
                e.stopPropagation(); 
                reject(e.target.error);
            };
        });
    }

    /**
     * Get all pending submissions from queue.
     * @returns {Promise<Array>}
     */
    async getAll() {
        if (!this.db) {
            return [];
        }

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
     * Remove a submission from queue.
     */
    async remove(submissionId) {
        if (!this.db) {
            return;
        }

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([CONFIG.storeName], 'readwrite');
            const store = transaction.objectStore(CONFIG.storeName);
            store.delete(submissionId);

            transaction.oncomplete = () => {
                debugLog('[Offline] Removed from queue:', submissionId);
                this._notifyQueueUpdate();
                resolve();
            };

            transaction.onerror = (event) => {
                reject(event.target.error);
            };
        });
    }

    /**
     * Update retry count for a submission.
     */
    async _updateRetryCount(submissionId, retryCount, error) {
        if (!this.db) {
            return;
        }

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([CONFIG.storeName], 'readwrite');
            const store = transaction.objectStore(CONFIG.storeName);
            
            const getRequest = store.get(submissionId);

            getRequest.onsuccess = () => {
                const item = getRequest.result;
                if (item) {
                    item.retryCount = retryCount;
                    item.lastAttempt = Date.now();
                    item.error = error || null;
                    store.put(item); 
                }
            };

            transaction.oncomplete = () => {
                resolve();
            };

            transaction.onerror = () => {
                reject(transaction.error);
            };
        });
    }

    /**
     * Process offline queue - attempt to upload all pending submissions.
     */
    async processQueue() {
        if (this.isProcessing || !navigator.onLine) {
            return;
        }

        this.isProcessing = true;
        debugLog('[Offline] Checking queue...');

        try {
            const pending = await this.getAll();
            
            if (pending.length === 0) {
                this.isProcessing = false;
                return;
            }

            debugLog(`[Offline] Found ${pending.length} pending items`);

            for (const item of pending) {
                if (item.retryCount >= CONFIG.maxRetries) {
                    debugLog('[Offline] Max retries exceeded, leaving in queue for manual review:', item.id);
                    continue;
                }

                if (item.lastAttempt) {
                    const delay = CONFIG.retryDelays[Math.min(item.retryCount, CONFIG.retryDelays.length - 1)];
                    if (Date.now() - item.lastAttempt < delay) {
                        continue;
                    }
                }

                try {
                    debugLog(`[Offline] Retrying upload (Attempt ${item.retryCount + 1}):`, item.id);
                    
                    const useTus = isTusAvailable() && item.audioBlob.size > 1024 * 1024;
                    
                    if (useTus) {
                        await uploadWithTus(
                            item.audioBlob,
                            item.fileName,
                            item.formFields,
                            item.metadata,
                            item.instanceId,
                            null 
                        );
                    } else {
                        await uploadDirect(
                            item.audioBlob,
                            item.fileName,
                            item.formFields,
                            item.metadata,
                            item.instanceId
                        );
                    }

                    await this.remove(item.id);
                    
                } catch (error) {
                    console.error('[Offline] Upload failed:', error);
                    
                    // Don't retry on non-retryable errors (quota, client errors, malformed responses)
                    const errorMsg = error?.message || '';
                    if (errorMsg.includes('QuotaExceededError') ||
                        errorMsg.includes('quota exceeded') ||
                        errorMsg.includes('400') ||
                        errorMsg.includes('Invalid JSON')) {
                        debugLog('[Offline] Non-retryable error, leaving in queue for manual review:', item.id);
                        // Don't increment retry count; leave for user intervention
                        return;
                    }
                    
                    await this._updateRetryCount(
                        item.id,
                        item.retryCount + 1,
                        errorMsg || 'Upload failed (network instability)'
                    );
                }
            }
        } catch (err) {
            console.error('[Offline] Queue processing error', err);
        } finally {
            this.isProcessing = false;
        }
    }

    setupNetworkListeners() {
        window.addEventListener('online', () => {
            debugLog('[Offline] Network online - Triggering Queue');
            this.isOnline = true;
            this.processQueue();
        });

        window.addEventListener('offline', () => {
            this.isOnline = false;
        });

        setInterval(() => {
            if (navigator.onLine && !this.isProcessing) {
                this.processQueue();
            }
        }, 60000);
    }

    _notifyQueueUpdate() {
        if (window.StarmusHooks?.doAction) {
            this.getAll().then((queue) => {
                const safeQueue = queue.map(q => ({
                    id: q.id,
                    fileName: q.fileName,
                    retryCount: q.retryCount,
                    error: q.error,
                    timestamp: q.timestamp
                }));
                window.StarmusHooks.doAction('starmus_offline_queue_updated', safeQueue);
            });
        }
    }
}

// Global singleton instance
let queueInstance = null;

export async function getOfflineQueue() {
    if (!queueInstance) {
        queueInstance = new OfflineQueue();
        await queueInstance.init();
        queueInstance.setupNetworkListeners();
    }
    return queueInstance;
}

export async function queueSubmission(instanceId, audioBlob, fileName, formFields, metadata) {
    const queue = await getOfflineQueue();
    return queue.add(instanceId, audioBlob, fileName, formFields, metadata);
}

export async function getPendingCount() {
    const queue = await getOfflineQueue();
    const pending = await queue.getAll();
    return pending.length;
}
