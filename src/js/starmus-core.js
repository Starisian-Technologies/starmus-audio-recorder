/**
 * @file starmus-core.js
 * @version 3.1.0
 * @description Business-logic engine for Starmus. Manages upload strategies,
 * a persistent IndexedDB offline queue, and consent-aware payload shaping.
 */
/* global window, indexedDB, fetch, FormData */
(function (window) {
    'use strict';

    if (!window.STARMUS) {
        window.STARMUS = {};
    }

    var STARMUS = window.STARMUS;
    var CommandBus = STARMUS.CommandBus;

    var OFFLINE_DB_NAME = 'starmus_offline_queue';
    var OFFLINE_DB_STORE = 'submissions';
    var OFFLINE_DB_VERSION = 1;
    var TUS_CHUNK_SIZE = 5 * 1024 * 1024; // 5 MB

    // ------------------------------
    // IndexedDB wrapper
    // ------------------------------
    var OfflineDB = {
        _dbPromise: null,

        _getDB: function () {
            if (this._dbPromise) {
                return this._dbPromise;
            }

            this._dbPromise = new Promise(function (resolve, reject) {
                var request = indexedDB.open(OFFLINE_DB_NAME, OFFLINE_DB_VERSION);

                request.onupgradeneeded = function (event) {
                    var db = event.target.result;
                    if (!db.objectStoreNames.contains(OFFLINE_DB_STORE)) {
                        db.createObjectStore(OFFLINE_DB_STORE, { keyPath: 'id' });
                    }
                };

                request.onsuccess = function (event) {
                    resolve(event.target.result);
                };

                request.onerror = function (event) {
                    reject(event.target.error || new Error('IndexedDB open failed'));
                };
            });

            return this._dbPromise;
        },

        add: function (item) {
            return this._getDB().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var tx = db.transaction(OFFLINE_DB_STORE, 'readwrite');
                    tx.objectStore(OFFLINE_DB_STORE).put(item);
                    tx.oncomplete = function () {
                        resolve(true);
                    };
                    tx.onerror = function (event) {
                        reject(event.target.error || new Error('IndexedDB add failed'));
                    };
                });
            });
        },

        getAll: function () {
            return this._getDB().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var tx = db.transaction(OFFLINE_DB_STORE, 'readonly');
                    var store = tx.objectStore(OFFLINE_DB_STORE);
                    var request = store.getAll();

                    request.onsuccess = function (event) {
                        resolve(event.target.result || []);
                    };
                    request.onerror = function (event) {
                        reject(event.target.error || new Error('IndexedDB getAll failed'));
                    };
                });
            });
        },

        remove: function (id) {
            return this._getDB().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var tx = db.transaction(OFFLINE_DB_STORE, 'readwrite');
                    tx.objectStore(OFFLINE_DB_STORE).delete(id);
                    tx.oncomplete = function () {
                        resolve(true);
                    };
                    tx.onerror = function (event) {
                        reject(event.target.error || new Error('IndexedDB delete failed'));
                    };
                });
            });
        },

        processQueue: function (handlerFn) {
            var self = this;
            if (typeof handlerFn !== 'function') {
                return Promise.resolve();
            }
            return self.getAll().then(function (items) {
                if (!items.length) {
                    return;
                }
                var chain = Promise.resolve();
                items.forEach(function (item) {
                    chain = chain.then(function () {
                        return handlerFn(item).then(function (success) {
                            if (success) {
                                return self.remove(item.id);
                            }
                            return null;
                        });
                    });
                });
                return chain;
            });
        }
    };

    // ------------------------------
    // Upload config & helpers
    // ------------------------------

    function resolveUploadConfig(env) {
        var tech = env && env.technical ? env.technical : {};
        var endpoints = tech.endpoints || {};
        return {
            directEndpoint: endpoints.directUpload || '',
            tusEndpoint: endpoints.tusUpload || '',
            extraHeaders: endpoints.extraHeaders || {}
        };
    }

    function mergeHeaders(extraHeaders, base) {
        var headers = {};
        var k;
        if (base) {
            for (k in base) {
                if (Object.prototype.hasOwnProperty.call(base, k)) {
                    headers[k] = base[k];
                }
            }
        }
        if (extraHeaders) {
            for (k in extraHeaders) {
                if (Object.prototype.hasOwnProperty.call(extraHeaders, k)) {
                    headers[k] = extraHeaders[k];
                }
            }
        }
        return headers;
    }

    // ------------------------------
    // Core init
    // ------------------------------

    function init(store) {
        if (!CommandBus || !store) {
            return;
        }

        CommandBus.subscribe('submit', function (cmd) {
            var state = store.getState();
            if (cmd.meta.instanceId === state.instanceId) {
                handleSubmission(store, cmd.payload.formFields || {});
            }
        });

        // When the browser comes back online, try to flush the offline queue.
        if (typeof window.addEventListener === 'function') {
            window.addEventListener('online', function () {
                OfflineDB.processQueue(function (item) {
                    return attemptUploadFromQueue(item);
                });
            });
        }
    }

    // ------------------------------
    // Submission pipeline
    // ------------------------------

    function handleSubmission(store, formFields) {
        var state = store.getState();
        var source = state.source || {};
        var env = state.env || {};
        var tech = env.technical || {};
        var profile = tech.profile || {};

        var fileOrBlob = source.file || source.blob;
        if (!fileOrBlob) {
            store.dispatch({
                type: 'starmus/error',
                payload: {
                    code: 'submission:no-source',
                    message: 'No audio recording or file is available to submit.',
                    retryable: false
                }
            });
            return;
        }

        var uploadConfig = resolveUploadConfig(env);

        // Deep-ish clone env snapshot for logging/transmission.
        var envSnapshot;
        try {
            envSnapshot = JSON.parse(JSON.stringify(env));
        } catch (e) {
            envSnapshot = null;
        }

        // Consent enforcement: strip statistical identifiers if no statistics consent.
        var hasStatsConsent = !!(
            env &&
            env.technical &&
            env.technical.consent &&
            env.technical.consent.statistics === true
        );

        if (!hasStatsConsent && envSnapshot && envSnapshot.technical && envSnapshot.technical.identifiers) {
            envSnapshot.technical.identifiers.visitorId = null;
            envSnapshot.technical.identifiers.deviceDetails = null;
            envSnapshot.technical.identifiers.ipAddress = null;
        }

        var pkg = {
            id: 'starmus_' + Date.now() + '_' + Math.random().toString(16).slice(2),
            instanceId: state.instanceId,
            source: {
                type: source.type,
                mimeType: source.mimeType,
                fileName: source.fileName
            },
            binary: fileOrBlob,
            formFields: formFields || {},
            envSnapshot: envSnapshot,
            uploadConfig: uploadConfig
        };

        // Aggressive offline queueing for poor networks.
        if (profile.networkProfile === 'offline' || profile.networkProfile === 'degraded') {
            OfflineDB.add(pkg).then(function () {
                store.dispatch({ type: 'starmus/submission/queued' });
            }).catch(function (e) {
                store.dispatch({
                    type: 'starmus/error',
                    payload: {
                        code: 'submission:queue-failed',
                        message: 'Failed to save recording for offline upload.',
                        retryable: true
                    }
                });
                if (window.console && console.error) {
                    console.error('Starmus offline queue add failed:', e);
                }
            });
            return;
        }

        // Decide strategy: TUS for large files (if configured), direct for smaller.
        var useTus = !!uploadConfig.tusEndpoint && fileOrBlob.size > TUS_CHUNK_SIZE;

        if (useTus) {
            uploadTusChunked(store, fileOrBlob, pkg, uploadConfig, true);
        } else {
            uploadDirect(store, fileOrBlob, pkg, uploadConfig, true);
        }
    }

    // ------------------------------
    // Direct upload
    // ------------------------------

    function uploadDirect(store, fileOrBlob, pkg, cfg, queueOnFail) {
        if (!cfg.directEndpoint) {
            if (store) {
                store.dispatch({
                    type: 'starmus/error',
                    payload: {
                        code: 'submission:no-endpoint',
                        message: 'Direct upload endpoint is not configured.',
                        retryable: false
                    }
                });
            }
            return Promise.resolve(false);
        }

        if (store) {
            store.dispatch({
                type: 'starmus/submission/start',
                payload: { strategy: 'direct' }
            });
        }

        var formData = new FormData();
        formData.append('file', fileOrBlob, pkg.source.fileName || 'recording.webm');
        formData.append('metadata', JSON.stringify({
            source: pkg.source,
            formFields: pkg.formFields,
            envSnapshot: pkg.envSnapshot
        }));

        var headers = mergeHeaders(cfg.extraHeaders, {});

        return fetch(cfg.directEndpoint, {
            method: 'POST',
            body: formData,
            headers: headers
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Direct upload failed with status ' + response.status);
            }
            if (store) {
                store.dispatch({ type: 'starmus/submission/progress', payload: { progress: 1 } });
                store.dispatch({ type: 'starmus/submission/complete' });
            }
            return true;
        }).catch(function (error) {
            if (queueOnFail) {
                return OfflineDB.add(pkg).then(function () {
                    if (store) {
                        store.dispatch({ type: 'starmus/submission/queued' });
                    }
                    return false;
                }).catch(function (e) {
                    if (store) {
                        store.dispatch({
                            type: 'starmus/error',
                            payload: {
                                code: 'submission:upload-failed',
                                message: error && error.message ? error.message : 'Direct upload failed.',
                                retryable: true
                            }
                        });
                    }
                    if (window.console && console.error) {
                        console.error('Starmus direct upload + queue failed:', error, e);
                    }
                    return false;
                });
            }
            if (store) {
                store.dispatch({
                    type: 'starmus/error',
                    payload: {
                        code: 'submission:upload-failed',
                        message: error && error.message ? error.message : 'Direct upload failed.',
                        retryable: true
                    }
                });
            }
            if (window.console && console.error) {
                console.error('Starmus direct upload failed:', error);
            }
            return false;
        });
    }

    // ------------------------------
    // TUS chunked upload
    // ------------------------------

    function uploadTusChunked(store, fileOrBlob, pkg, cfg, queueOnFail) {
        if (!cfg.tusEndpoint) {
            if (store) {
                store.dispatch({
                    type: 'starmus/error',
                    payload: {
                        code: 'submission:no-tus-endpoint',
                        message: 'TUS upload endpoint is not configured.',
                        retryable: false
                    }
                });
            }
            return Promise.resolve(false);
        }

        if (store) {
            store.dispatch({
                type: 'starmus/submission/start',
                payload: { strategy: 'tus_chunked' }
            });
        }

        var headersBase = { 'Tus-Resumable': '1.0.0', 'Upload-Length': String(fileOrBlob.size) };
        var headers = mergeHeaders(cfg.extraHeaders, headersBase);

        // 1. Create upload
        return fetch(cfg.tusEndpoint, {
            method: 'POST',
            headers: headers
        }).then(function (res) {
            if (!res.ok) {
                throw new Error('TUS create failed with status ' + res.status);
            }
            var uploadUrl = res.headers.get('Location');
            if (!uploadUrl) {
                throw new Error('TUS server did not provide upload Location header.');
            }
            // 2. Upload chunks
            var offset = 0;

            function sendNextChunk() {
                if (offset >= fileOrBlob.size) {
                    if (store) {
                        store.dispatch({ type: 'starmus/submission/progress', payload: { progress: 1 } });
                        store.dispatch({ type: 'starmus/submission/complete' });
                    }
                    return Promise.resolve(true);
                }

                var chunk = fileOrBlob.slice(offset, offset + TUS_CHUNK_SIZE);
                var chunkHeadersBase = {
                    'Tus-Resumable': '1.0.0',
                    'Upload-Offset': String(offset),
                    'Content-Type': 'application/offset+octet-stream'
                };
                var chunkHeaders = mergeHeaders(cfg.extraHeaders, chunkHeadersBase);

                return fetch(uploadUrl, {
                    method: 'PATCH',
                    headers: chunkHeaders,
                    body: chunk
                }).then(function (patchRes) {
                    if (!patchRes.ok) {
                        throw new Error('TUS PATCH failed at offset ' + offset + ' with status ' + patchRes.status);
                    }
                    offset += chunk.size;
                    if (store) {
                        store.dispatch({
                            type: 'starmus/submission/progress',
                            payload: { progress: offset / fileOrBlob.size }
                        });
                    }
                    return sendNextChunk();
                });
            }

            return sendNextChunk();
        }).catch(function (error) {
            if (queueOnFail) {
                return OfflineDB.add(pkg).then(function () {
                    if (store) {
                        store.dispatch({ type: 'starmus/submission/queued' });
                    }
                    return false;
                }).catch(function (e) {
                    if (store) {
                        store.dispatch({
                            type: 'starmus/error',
                            payload: {
                                code: 'submission:upload-failed',
                                message: error && error.message ? error.message : 'TUS upload failed.',
                                retryable: true
                            }
                        });
                    }
                    if (window.console && console.error) {
                        console.error('Starmus TUS upload + queue failed:', error, e);
                    }
                    return false;
                });
            }
            if (store) {
                store.dispatch({
                    type: 'starmus/error',
                    payload: {
                        code: 'submission:upload-failed',
                        message: error && error.message ? error.message : 'TUS upload failed.',
                        retryable: true
                    }
                });
            }
            if (window.console && console.error) {
                console.error('Starmus TUS upload failed:', error);
            }
            return false;
        });
    }

    // ------------------------------
    // Offline queue re-upload
    // ------------------------------

    function attemptUploadFromQueue(item) {
        var cfg = item.uploadConfig || resolveUploadConfig(item.envSnapshot || {});
        var fileOrBlob = item.binary;

        if (!fileOrBlob) {
            // Nothing to upload; treat as successfully consumed.
            return Promise.resolve(true);
        }

        var useTus = !!cfg.tusEndpoint && fileOrBlob.size > TUS_CHUNK_SIZE;
        // For background processing, we pass store = null and queueOnFail = false
        if (useTus) {
            return uploadTusChunked(null, fileOrBlob, item, cfg, false);
        }
        return uploadDirect(null, fileOrBlob, item, cfg, false);
    }

    STARMUS.Core = {
        init: init
    };

}(window));
