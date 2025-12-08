/**
 * @file starmus-offline.js
 * @version 1.3.2
 * @description Offline-first submission queue using IndexedDB.
 * Works with updated starmus-tus.js upload pipeline.
 */

'use strict';

import { debugLog } from './starmus-hooks.js';
import { uploadWithPriority } from './starmus-tus.js'; 

function isTusAvailableSafe(blobSize) {
  try {
    const cfg = window.starmusConfig || {};
    return !!(window.tus?.Upload && cfg.endpoints?.tusUpload && blobSize > 1024 * 1024);
  } catch {
    return false;
  }
}

const CONFIG = {
  dbName: 'StarmusSubmissions',
  storeName: 'pendingSubmissions',
  dbVersion: 1,
  maxRetries: 10,
  retryDelays: [0, 5000, 10000, 30000, 60000, 120000, 300000, 600000, 1200000, 1800000],
  maxBlobSize: 40 * 1024 * 1024, // 40MB
};

class OfflineQueue {
  constructor() {
    this.db = null;
    this.isProcessing = false;
  }

  async init() {
    if (!window.indexedDB) {
      console.error('[Offline] IndexedDB not supported — offline queue disabled.');
      return;
    }

    return new Promise((resolve) => {
      const req = indexedDB.open(CONFIG.dbName, CONFIG.dbVersion);

      req.onerror = (e) => {
        debugLog('[Offline] DB open failed', e);
        resolve();
      };

      req.onblocked = () => {
        debugLog('[Offline] DB open blocked — close other tabs');
      };

      req.onsuccess = (e) => {
        this.db = e.target.result;
        this.db.onversionchange = () => {
          this.db.close();
          debugLog('[Offline] DB version change — closed connection');
        };
        debugLog('[Offline] IndexedDB ready');
        resolve();
      };

      req.onupgradeneeded = (e) => {
        const db = e.target.result;
        if (!db.objectStoreNames.contains(CONFIG.storeName)) {
          const store = db.createObjectStore(CONFIG.storeName, { keyPath: 'id' });
          store.createIndex('timestamp', 'timestamp', { unique: false });
          store.createIndex('retryCount', 'retryCount', { unique: false });
          debugLog('[Offline] Created object store', CONFIG.storeName);
        }
      };
    });
  }

  async add(instanceId, audioBlob, fileName, formFields, metadata) {
    if (!this.db) throw new Error('IndexedDB not initialized');

    const id = `starmus-offline-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;

    const safeBlob =
      audioBlob.size > CONFIG.maxBlobSize
        ? null
        : (typeof structuredClone === 'function'
            ? structuredClone(audioBlob)
            : new Blob([audioBlob], { type: audioBlob.type }));

    if (!safeBlob) {
      throw new Error(
        `Blob too large for offline storage (${(audioBlob.size / 1024 / 1024).toFixed(2)}MB)`
      );
    }

    const item = {
      id,
      instanceId,
      fileName,
      timestamp: Date.now(),
      audioBlob: safeBlob,
      formFields: formFields || {},
      metadata: metadata || {},
      retryCount: 0,
      lastAttempt: null,
      error: null
    };

    return new Promise((resolve, reject) => {
      const tx = this.db.transaction([CONFIG.storeName], 'readwrite');
      tx.objectStore(CONFIG.storeName).add(item);
      tx.oncomplete = () => {
        debugLog('[Offline] Queued submission', id);
        resolve(id);
      };
      tx.onerror = (ev) => {
        const err = ev.target.error;
        if (err?.name === 'QuotaExceededError') {
          reject(new Error('IndexedDB quota exceeded — cannot queue submission offline.'));
        } else {
          reject(err);
        }
      };
    });
  }

  async getAll() {
    if (!this.db) return [];
    return new Promise((resolve, reject) => {
      const tx = this.db.transaction([CONFIG.storeName], 'readonly');
      const req = tx.objectStore(CONFIG.storeName).getAll();
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
        debugLog('[Offline] Removed from queue', id);
        resolve();
      };
      tx.onerror = (ev) => reject(ev.target.error);
    });
  }

  async _updateRetry(id, retryCount, msg) {
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
          item.error = msg || null;
          store.put(item);
        }
      };

      tx.oncomplete = resolve;
      tx.onerror = () => reject(tx.error);
    });
  }

  async processQueue() {
    if (this.isProcessing || !navigator.onLine) return;

    this.isProcessing = true;
    debugLog('[Offline] Processing queue...');

    try {
      const pending = await this.getAll();
      debugLog('[Offline] Pending submissions:', pending.length);

      for (const item of pending) {
        const { id, audioBlob, fileName, formFields, metadata, retryCount, instanceId } = item;

        if (retryCount >= CONFIG.maxRetries) {
          debugLog('[Offline] Max retries reached for', id);
          continue;
        }

        try {
          debugLog(`[Offline] Attempt ${retryCount + 1} for`, id);

          // ✅ CORRECT CALL SIGNATURE
          await uploadWithPriority({
            blob: audioBlob,
            fileName,
            formFields,
            metadata,
            instanceId,
            background: true
          });

          await this.remove(id);
          debugLog('[Offline] Upload succeeded:', id);

        } catch (err) {
          const msg = err?.message || 'Unknown upload error';
          debugLog('[Offline] Upload failed:', id, msg);

          if (msg.includes('400') || msg.includes('Invalid JSON') || msg.includes('QuotaExceeded')) {
            debugLog('[Offline] Non-retryable error — leaving item in queue');
            continue;
          }

          await this._updateRetry(id, retryCount + 1, msg);

          const waitMs = CONFIG.retryDelays[Math.min(retryCount, CONFIG.retryDelays.length - 1)];
          debugLog(`[Offline] Retrying ${id} after ${waitMs}ms...`);
          await new Promise(r => setTimeout(r, waitMs));
        }
      }
    } catch (fatal) {
      console.error('[Offline] Fatal queue processing error', fatal);
    } finally {
      this.isProcessing = false;
    }
  }

  setupNetworkListeners() {
    window.addEventListener('online', () => {
      debugLog('[Offline] Online — resuming queue');
      this.processQueue();
    });

    window.addEventListener('offline', () => {
      debugLog('[Offline] Offline — pausing queue');
    });

    setInterval(() => {
      if (navigator.onLine) {
        this.processQueue().catch(e => debugLog('[Offline] Retry loop error', e));
      }
    }, 60000);
  }
}

const offlineQueue = new OfflineQueue();

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
  return (await q.getAll()).length;
}

export default offlineQueue;

function initOffline() {
  // idempotent: calling twice won’t re-init because offlineQueue.db persists
  return getOfflineQueue();
}

if (typeof window !== 'undefined') {
  window.initOffline = initOffline;
}