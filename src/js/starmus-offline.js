/**
 * @file starmus‑offline.js
 * @version 1.3.4-STABLE
 * @description Offline-first queue with TUS-aware upload routing.
 * Fully merged legacy durability logic + new priority pipeline.
 */

'use strict';

import { debugLog } from './starmus-hooks.js';
import { uploadWithPriority, isTusAvailable } from './starmus-tus.js';

/* ------------------------------------------------------------------
   CONFIG
------------------------------------------------------------------- */
const CONFIG = {
  dbName: 'StarmusSubmissions',
  storeName: 'pendingSubmissions',
  dbVersion: 1,
  maxRetries: 10,
  retryDelays: [0, 5000, 10000, 30000, 60000, 120000, 300000, 600000, 1200000, 1800000],
  maxBlobSize: 40 * 1024 * 1024,
};

/* ------------------------------------------------------------------
   QUEUE CLASS
------------------------------------------------------------------- */
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
        debugLog('[Offline] DB blocked — close other tabs');
      };

      req.onsuccess = (e) => {
        this.db = e.target.result;
        this.db.onversionchange = () => {
          this.db.close();
          debugLog('[Offline] DB version changed — closed');
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
          debugLog('[Offline] Created queue store');
        }
      };
    });
  }

  async add(instanceId, audioBlob, fileName, formFields, metadata) {
    if (!this.db) throw new Error('IndexedDB not initialized');

    if (audioBlob.size > CONFIG.maxBlobSize) {
      throw new Error(
        `Audio too large for offline storage (${(audioBlob.size / 1024 / 1024).toFixed(2)}MB)`
      );
    }

    const safeBlob = new Blob([audioBlob], { type: audioBlob.type });
    const id = `starmus-offline-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;

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
      error: null,
    };

    return new Promise((resolve, reject) => {
      const tx = this.db.transaction([CONFIG.storeName], 'readwrite');
      tx.objectStore(CONFIG.storeName).add(item);

      tx.oncomplete = () => {
        debugLog('[Offline] Submission queued:', id);
        this._notifyQueueUpdate();
        resolve(id);
      };

      tx.onerror = (ev) => {
        const err = ev.target.error;
        reject(err?.name === 'QuotaExceededError'
          ? new Error('Offline queue storage full')
          : err);
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
      tx.oncomplete = () => resolve();
      tx.onerror = (e) => reject(e.target.error);
    });
  }

  async _updateRetry(id, retryCount, msg) {
    if (!this.db) return;
    return new Promise((resolve) => {
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
    });
  }

  async processQueue() {
    if (this.isProcessing || !navigator.onLine) return;
    this.isProcessing = true;

    try {
      const pending = await this.getAll();
      for (const item of pending) {
        const { id, audioBlob, retryCount } = item;

        if (retryCount >= CONFIG.maxRetries) continue;
        try {
          await uploadWithPriority({
            blob: audioBlob,
            fileName: item.fileName,
            formFields: item.formFields,
            metadata: item.metadata,
            instanceId: item.instanceId,
            background: true
          });

          await this.remove(id);

        } catch (err) {
          const msg = err?.message || 'Upload failed';
          const nonRetryable = msg.includes('400') || msg.includes('Invalid JSON') || msg.includes('QuotaExceeded');
          if (!nonRetryable) await this._updateRetry(id, retryCount + 1, msg);
        }
      }
    } finally {
      this.isProcessing = false;
    }
  }

  setupNetworkListeners() {
    window.addEventListener('online', () => this.processQueue());
    setInterval(() => navigator.onLine && this.processQueue(), 60000);
  }

  _notifyQueueUpdate() {
    if (window.StarmusHooks?.doAction) {
      this.getAll().then((queue) => {
        window.StarmusHooks.doAction(
          'starmus_offline_queue_updated',
          queue.map(({ id, fileName, retryCount, timestamp, error }) => ({
            id, fileName, retryCount, timestamp, error
          }))
        );
      });
    }
  }
}

/* ------------------------------------------------------------------
   SINGLETON + EXPORTS
------------------------------------------------------------------- */

const offlineQueue = new OfflineQueue();

export async function getOfflineQueue() {
  if (!offlineQueue.db) {
    await offlineQueue.init();
    offlineQueue.setupNetworkListeners();
  }
  return offlineQueue;
}

export async function queueSubmission(i, b, f, ff, m) {
  return (await getOfflineQueue()).add(i, b, f, ff, m);
}

export async function getPendingCount() {
  return (await getOfflineQueue()).getAll().then(list => list.length);
}

/** 🔥 REQUIRED EXPORT YOU WERE MISSING */
export function initOffline() {
  return getOfflineQueue();
}

/** DEFAULT EXPORT REQUIRED BY OLD CODE */
export default offlineQueue;

/** WP browser global */
if (typeof window !== 'undefined') {
  window.initOffline = initOffline;
}
