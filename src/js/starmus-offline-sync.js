/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * @module  StarmusOfflineSync
 * @version 1.0.0
 * @file    Global handler for syncing offline Starmus submissions.
 */
(function(window, document) {
    'use strict';

    // Check for necessary dependencies
    if (!window.indexedDB) {
        return; // This browser can't support offline sync.
    }

    const CONFIG = {
        LOG_PREFIX: '[Starmus Sync]',
        DB_NAME: 'StarmusSubmissions',
        STORE_NAME: 'pendingSubmissions'
    };
    function log(level, msg, data) { if (console && console[level]) { console[level](CONFIG.LOG_PREFIX, msg, data || ''); } }

    let db = null;
    let isSyncing = false;

    /**
     * Opens a connection to the IndexedDB.
     */
    function initDB() {
        return new Promise((resolve, reject) => {
            if (db) { return resolve(db); }
            const req = indexedDB.open(CONFIG.DB_NAME, 1);
            req.onsuccess = (e) => {
                db = e.target.result;
                resolve(db);
            };
            req.onerror = (e) => {
                log('error', 'IndexedDB error', e?.target?.errorCode);
                reject(e?.target?.errorCode);
            };
            // onupgradeneeded is handled by the main submissions script
        });
    }

    /**
     * The core sync function. Checks for pending items and sends them.
     */
    async function checkAndSync() {
        if (isSyncing) {
            log('debug', 'Sync already in progress. Skipping.');
            return;
        }
        if (!navigator.onLine) {
            log('debug', 'Browser is offline. Skipping sync.');
            return;
        }

        isSyncing = true;
        log('info', 'Starting check for pending submissions...');

        try {
            const db = await initDB();
            const tx = db.transaction([CONFIG.STORE_NAME], 'readwrite');
            const store = tx.objectStore(CONFIG.STORE_NAME);
            const allItemsReq = store.getAll();

            allItemsReq.onsuccess = async () => {
                const pendingItems = allItemsReq.result;

                // **THIS IS THE CRITICAL CHECK**
                if (!pendingItems || pendingItems.length === 0) {
                    log('info', 'No pending submissions to sync.');
                    isSyncing = false;
                    return;
                }

                log('info', `Found ${pendingItems.length} pending submission(s). Starting upload process.`);

                for (const item of pendingItems) {
                    try {
                        // Ensure the global uploader function is available
                        if (window.StarmusSubmissionsHandler && typeof window.StarmusSubmissionsHandler.resumableTusUpload === 'function') {
                            await window.StarmusSubmissionsHandler.resumableTusUpload(
                                item.audioBlob,
                                item.fileName,
                                item.formFields,
                                item.meta,
                                item.formInstanceId
                            );

                            // On success, delete from DB
                            const deleteTx = db.transaction([CONFIG.STORE_NAME], 'readwrite');
                            await deleteTx.objectStore(CONFIG.STORE_NAME).delete(item.id);
                            log('info', `Successfully synced and removed item ${item.id} from queue.`);
                        } else {
                            log('warn', 'Upload handler not found. Cannot sync item.', item.id);
                        }
                    } catch (uploadError) {
                        log('error', `Failed to sync item ${item.id}. It will be retried later.`, uploadError);
                        // We stop on the first error to avoid hammering a broken server.
                        // The sync will re-attempt on the next page load or 'online' event.
                        break; 
                    }
                }
                isSyncing = false;
            };
            allItemsReq.onerror = () => {
                isSyncing = false;
            };

        } catch (dbError) {
            log('error', 'Could not perform sync due to a database error.', dbError);
            isSyncing = false;
        }
    }

    // --- TRIGGERS ---

    // 1. When the page loads, wait a moment then check.
    document.addEventListener('DOMContentLoaded', () => {
        setTimeout(checkAndSync, 2000); // Wait 2s to let other scripts load.
    });

    // 2. When the browser comes back online.
    window.addEventListener('online', checkAndSync);

})(window, document);
