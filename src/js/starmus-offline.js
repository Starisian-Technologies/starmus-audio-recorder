/**
 * @file starmus‑offline.js
 * @version 1.4.0‑full‑compat
 * @description Offline‑first submission queue using IndexedDB.
 *   Full logic ported from legacy version: durable queue, blob‑clone for Android crash safety,
 *   quota checks, retry/backoff, fallback, queue notifications, global + module exports.
 */

'use strict';

import { debugLog } from './starmus-hooks.js';
import { uploadWithPriority, isTusAvailable } from './starmus-tus.js';

const CONFIG = {
  dbName: 'StarmusSubmissions',
  storeName: 'pendingSubmissions',
  dbVersion: 1,
  maxRetries: 10,
  retryDelays: [0, 5000, 10000, 30000, 60000, 120000, 300000, 600000, 1200000, 1800000],
  maxBlobSize: 40 * 1024 * 1024, // 40MB
};

// Internal queue class
class OfflineQueue {
  constructor() {
    this.db = null;
    this.isProcessing = false;
  }

  async init() {
    if (!window.indexedDB) {
      console.error('[Offline] IndexedDB not supported. Offline uploads disabled.');
      return;
    }

    return new Promise((resolve) => {
      const req = indexedDB.open(CONFIG.dbName, CONFIG.dbVersion);

      req.onerror = (e) => {
        debugLog('[Offline] DB open failed:', e);
        resolve();
      };

      req.onblocked = () => {
        debugLog('[Offline] DB open blocked — other tab open');
      };

      req.onsuccess = (e) => {
        this.db = e.target.result;
        this.db.onversionchange = () => {
          this.db.close();
          debugLog('[Offline] DB version changed — closed connection');
        };
        debugLog('[Offline] DB ready');
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

    if (audioBlob.size > CONFIG.maxBlobSize) {
      const msg = `Audio too large (${(audioBlob.size / 1024 / 1024).toFixed(2)} MB) — exceeds limit of ${(CONFIG.maxBlobSize / 1024 / 1024)} MB`;
      debugLog('[Offline] ' + msg);
      throw new Error(msg);
    }

    // Clone blob to detach underlying buffer — prevents Android WebView / memory-share crash issues
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
        debugLog('[Offline] Queued submission:', item.id);
        this._notifyQueueUpdate();
        resolve(item.id);
      };

      tx.onerror = (ev) => {
        const err = ev.target.error;
        if (err && err.name === 'QuotaExceededError') {
          debugLog('[Offline] IndexedDB quota exceeded — cannot save offline');
          reject(new Error('IndexedDB quota exceeded — cannot store offline'));
        } else {
          debugLog('[Offline] Transaction error:', err);
          reject(err);
        }
      };
    });
  }

  async getAll() {
    if (!this.db) return [];
    return new Promise((resolve, reject) => {
      const tx = this.db.transaction([CONFIG.storeName], 'readonly');
      const store = tx.objectStore(CONFIG.storeName);
      const req = store.getAll();

      req.onsuccess = () => resolve(req.result || []);
      req.onerror = () => reject(req.error);
    });
  }

  async remove(id) {
    if (!this.db) return;
    return new Promise((resolve, reject) => {
      const tx = this.db.transaction([CONFIG.storeName], 'readwrite');
      tx.objectStore(CONFIG.storeName).delete(id);

      tx.oncomplete = () => {
        debugLog('[Offline] Removed from queue:', id);
        this._notifyQueueUpdate();
        resolve();
      };

      tx.onerror = (ev) => reject(ev.target.error);
    });
  }

  async _updateRetry(id, retryCount, error) {
    if (!this.db) return;
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

  async processQueue() {
    if (this.isProcessing || !navigator.onLine) return;
    this.isProcessing = true;
    debugLog('[Offline] Processing queue...');

    try {
      const pending = await this.getAll();
      debugLog(`[Offline] ${pending.length} submissions pending`);

      for (const item of pending) {
        const { id, audioBlob, fileName, formFields, metadata, retryCount, instanceId } = item;

        if (retryCount >= CONFIG.maxRetries) {
          debugLog('[Offline] Max retries exceeded for', id);
          continue;
        }

        // Back‑off handling
        if (item.lastAttempt !== null) {
          const delay = CONFIG.retryDelays[Math.min(retryCount, CONFIG.retryDelays.length - 1)];
          if (Date.now() - item.lastAttempt < delay) {
            continue;
          }
        }

        try {
          debugLog('[Offline] Uploading queued item', id);

          const result = await uploadWithPriority(audioBlob, fileName, formFields, metadata, instanceId, null);
          debugLog('[Offline] Upload succeeded for', id, result);

          await this.remove(id);

        } catch (err) {
          debugLog('[Offline] Upload failed for', id, err);

          const msg = err && err.message ? err.message : String(err);
          // Decide if retryable: skip retry on manifest fatal errors (e.g. 400 / invalid JSON / quota), else retry
          const nonRetryable = /400|Invalid JSON|QuotaExceeded/i.test(msg);
          if (!nonRetryable) {
            await this._updateRetry(id, retryCount + 1, msg);
          } else {
            debugLog('[Offline] Non‑retryable error, leaving in queue for manual review:', id);
          }
        }
      }
    } catch (fatal) {
      console.error('[Offline] Queue processing error:', fatal);
    } finally {
      this.isProcessing = false;
    }
  }

  setupNetworkListeners() {
    window.addEventListener('online', () => {
      debugLog('[Offline] Network online — processing queue');
      this.processQueue();
    });
    window.addEventListener('offline', () => {
      debugLog('[Offline] Network offline — queue paused');
    });
    // Also attempt retry periodically (every minute)
    setInterval(() => {
      if (navigator.onLine) {
        this.processQueue().catch(e => debugLog('[Offline] Retry error', e));
      }
    }, 60 * 1000);
  }

  _notifyQueueUpdate() {
    const BUS = window.CommandBus || window.StarmusHooks;
    if (!BUS || typeof BUS.dispatch !== 'function') { return; }

    this.getAll()
      .then(queue => {
        BUS.dispatch('starmus/offline/queue_updated', {
          queue: queue.map(item => ({
            id: item.id,
            fileName: item.fileName,
            retryCount: item.retryCount,
            error: item.error,
            timestamp: item.timestamp
          }))
        });
      })
      .catch(e => debugLog('[Offline] Queue notification failed', e));
  }
}

// Create singleton
const offlineQueue = new OfflineQueue();

// Public API

export async function getOfflineQueue() {
  if (!offlineQueue.db) {
    await offlineQueue.init();
    offlineQueue.setupNetworkListeners();
  }
  return offlineQueue;
}

export async function queueSubmission(instanceId, audioBlob, fileName, formFields, metadata) {
  const q = await getOfflineQueue();
  return q.add(instanceId, audioBlob, fileName, formFields, metadata);
}

export async function getPendingCount() {
  const q = await getOfflineQueue();
  const list = await q.getAll();
  return list.length;
}

export function initOffline() {
  return getOfflineQueue();
}

export default offlineQueue;

// Optionally expose global for legacy / WP
if (typeof window !== 'undefined') {
  window.initOffline = initOffline;
}
