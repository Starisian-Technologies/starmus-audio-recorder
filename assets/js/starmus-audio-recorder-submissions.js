/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * NOTICE: All information contained herein is, and remains, the property of Starisian Technologies and its suppliers, if any.
 * The intellectual and technical concepts contained herein are proprietary to Starisian Technologies and its suppliers and may
 * be covered by U.S. and foreign patents, patents in process, and are protected by trade secret or copyright law.
 *
 * Dissemination of this information or reproduction of this material is strictly forbidden unless
 * prior written permission is obtained from Starisian Technologies.
 *
 * SPDX-License-Identifier:  LicenseRef-Starisian-Technologies-Proprietary
 * License URI:              https://github.com/Starisian-Technologies/starmus-audio-recorder/LICENSE.md
 */

/**
 * @file Manages the submission lifecycle of audio recordings.
 * @description This script implements a resilient, offline-first upload strategy. It handles
 * form submissions, chunks audio files for robust uploads, and uses IndexedDB (with a
 * localStorage fallback) to queue submissions that fail due to network issues. The queue
 * is processed automatically when the user comes back online.
 */

/* global starmusFormData, StarmusAudioRecorder */
document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    // --- Configuration ---
    const CONFIG = {
        LOG_PREFIX: 'STARMUS_FORM:',
        DB_NAME: 'starmus_audio_queue',
        STORE_NAME: 'queue',
        CHUNK_SIZE: 512 * 1024, // 512KB chunks
        DEBUG_MODE: new URLSearchParams(window.location.search).has('starmus_debug')
    };

    console.log(CONFIG.LOG_PREFIX, 'DOM fully loaded. Initializing audio form submissions.');

    // --- Offline Queue Storage (IndexedDB with localStorage Fallback) ---
    /**
     * @type {boolean} - Flag indicating if IndexedDB is available and operational.
     */
    let idbAvailable = 'indexedDB' in window;

    /**
     * A promise that resolves with the IndexedDB database instance.
     * @type {Promise<IDBDatabase|null>}
     */
    const dbPromise = (() => {
        if (!idbAvailable) return Promise.resolve(null);
        return new Promise((resolve) => {
            const request = indexedDB.open(CONFIG.DB_NAME, 1);
            request.onupgradeneeded = event => {
                const db = event.target.result;
                if (!db.objectStoreNames.contains(CONFIG.STORE_NAME)) {
                    db.createObjectStore(CONFIG.STORE_NAME, { keyPath: 'id' });
                }
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = event => {
                console.error(CONFIG.LOG_PREFIX, 'IndexedDB failed to open. Falling back to localStorage.', event.target.error);
                idbAvailable = false;
                resolve(null);
            };
        });
    })();

    async function blobToBase64(blob) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onloadend = () => resolve(reader.result);
            reader.onerror = reject;
            reader.readAsDataURL(blob);
        });
    }

    function base64ToBlob(base64) {
        const parts = base64.split(',');
        const mime = parts[0].match(/:(.*?);/)[1];
        const binary = atob(parts[1]);
        const array = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) { array[i] = binary.charCodeAt(i); }
        return new Blob([array], { type: mime });
    }

    /**
     * Adds or updates an item in the offline queue.
     * @param {object} item - The submission item to enqueue.
     * @returns {Promise<void>}
     */
    async function enqueue(item) {
        if (idbAvailable) {
            try {
                const db = await dbPromise;
                const tx = db.transaction(CONFIG.STORE_NAME, 'readwrite');
                tx.objectStore(CONFIG.STORE_NAME).put(item);
                return new Promise((resolve, reject) => {
                    tx.oncomplete = resolve;
                    tx.onerror = () => reject(tx.error);
                });
            } catch (err) {
                console.error(CONFIG.LOG_PREFIX, 'IndexedDB put failed. Retrying with localStorage.', err);
                idbAvailable = false; // Fallback for this session
            }
        }
        // Fallback to localStorage
        const list = JSON.parse(localStorage.getItem(CONFIG.DB_NAME) || '[]');
        const base64 = await blobToBase64(item.audio);
        const storableItem = { ...item, audioData: base64, audio: undefined }; // Don't store blob in LS
        const existingIndex = list.findIndex(i => i.id === item.id);
        if (existingIndex > -1) {
            list[existingIndex] = storableItem;
        } else {
            list.push(storableItem);
        }
        localStorage.setItem(CONFIG.DB_NAME, JSON.stringify(list));
    }

    /**
     * Retrieves all items from the offline queue.
     * @returns {Promise<object[]>}
     */
    async function getAllItems() {
        if (idbAvailable) {
            try {
                const db = await dbPromise;
                return new Promise((resolve, reject) => {
                    const req = db.transaction(CONFIG.STORE_NAME, 'readonly').objectStore(CONFIG.STORE_NAME).getAll();
                    req.onsuccess = () => resolve(req.result || []);
                    req.onerror = () => reject(req.error);
                });
            } catch (err) {
                console.error(CONFIG.LOG_PREFIX, 'IndexedDB getAll failed. Retrying with localStorage.', err);
                idbAvailable = false;
            }
        }
        const list = JSON.parse(localStorage.getItem(CONFIG.DB_NAME) || '[]');
        return list.map(item => ({ ...item, audio: base64ToBlob(item.audioData) }));
    }

    /**
     * Deletes an item from the offline queue by its ID.
     * @param {string} id - The unique ID of the item to delete.
     * @returns {Promise<void>}
     */
    async function deleteItem(id) {
        if (idbAvailable) {
            try {
                const db = await dbPromise;
                const tx = db.transaction(CONFIG.STORE_NAME, 'readwrite');
                tx.objectStore(CONFIG.STORE_NAME).delete(id);
                return new Promise((resolve, reject) => {
                    tx.oncomplete = resolve;
                    tx.onerror = () => reject(tx.error);
                });
            } catch (err) {
                 console.error(CONFIG.LOG_PREFIX, 'IndexedDB delete failed. Retrying with localStorage.', err);
                 idbAvailable = false;
            }
        }
        const list = JSON.parse(localStorage.getItem(CONFIG.DB_NAME) || '[]');
        localStorage.setItem(CONFIG.DB_NAME, JSON.stringify(list.filter(i => i.id !== id)));
    }

    // --- Unified Uploader & Queue Processing ---
    /**
     * The core upload function. Handles chunking, retries, and queueing.
     * @param {object} item - The submission item to upload.
     * @returns {Promise<boolean>} - True if fully uploaded, false if failed and queued.
     */
    async function uploadSubmission(item) {
        let offset = item.uploadedBytes || 0;

        while (offset < item.audio.size) {
            if (!navigator.onLine) {
                console.warn(CONFIG.LOG_PREFIX, 'Upload paused: connection lost.');
                await enqueue({ ...item, uploadedBytes: offset });
                return false;
            }

            const chunk = item.audio.slice(offset, offset + CONFIG.CHUNK_SIZE);
            const fd = new FormData();

            // Append WordPress-specific data (action, nonce) from the saved item
            for (const key in item.wordpressData) {
                fd.append(key, item.wordpressData[key]);
            }
            // Append other metadata from the form
            for (const key in item.meta) {
                fd.append(key, item.meta[key]);
            }
            
            fd.append(item.audioField, chunk, item.meta.fileName || 'audio.webm');
            fd.append('chunk_offset', offset);
            fd.append('total_size', item.audio.size);

            try {
                const response = await fetch(item.ajaxUrl, { method: 'POST', body: fd });
                if (!response.ok) {
                    // Try to get a specific error message from the JSON body
                    const errorData = await response.json().catch(() => null);
                    const errorMessage = errorData?.data?.message || `Server responded with status ${response.status}`;
                    throw new Error(errorMessage);
                }

                const data = await response.json();
                if (!data.success) {
                    throw new Error(data?.data?.message || 'Server returned a failure response.');
                }
                
                offset += chunk.size;
                console.log(CONFIG.LOG_PREFIX, `Chunk uploaded. Progress: ${offset}/${item.audio.size}`);

            } catch (err) {
                console.error(CONFIG.LOG_PREFIX, 'Chunk upload failed. Queuing submission for later.', err);
                await enqueue({ ...item, uploadedBytes: offset });
                return false;
            }
        }

        console.log(CONFIG.LOG_PREFIX, `Upload complete for item ${item.id}. Removing from queue.`);
        await deleteItem(item.id);
        return true;
    }

    let isProcessingQueue = false;
    /**
     * Processes all items currently in the offline queue.
     * @returns {Promise<void>}
     */
    async function processQueue() {
        if (isProcessingQueue || !navigator.onLine) return;
        isProcessingQueue = true;
        const retryBtn = document.getElementById('starmus_queue_retry');
        if (retryBtn) retryBtn.disabled = true;
        console.log(CONFIG.LOG_PREFIX, "Processing offline queue...");

        const items = await getAllItems();
        if (items.length > 0) {
            for (const item of items) {
                const success = await uploadSubmission(item);
                if (!success) {
                    console.warn(CONFIG.LOG_PREFIX, "Queue processing paused due to an upload failure.");
                    break; // Stop processing further items if one fails
                }
            }
        }

        isProcessingQueue = false;
        if (retryBtn) retryBtn.disabled = false;
        await updateQueueUI();
    }

    // --- UI and Event Listeners ---
    const queueBanner = document.createElement('div');
    queueBanner.id = 'starmus_queue_banner';
    queueBanner.className = 'starmus_status starmus_visually_hidden';
    queueBanner.setAttribute('aria-live', 'polite');
    queueBanner.innerHTML = `<span class="starmus_status__text" id="starmus_queue_count"></span> <button type="button" id="starmus_queue_retry" class="button">Retry Uploads</button>`;
    document.body.appendChild(queueBanner);

    document.getElementById('starmus_queue_retry').addEventListener('click', processQueue);

    /**
     * Updates the visibility and text of the offline queue UI banner.
     * @returns {Promise<void>}
     */
    async function updateQueueUI() {
        const items = await getAllItems();
        const countSpan = document.getElementById('starmus_queue_count');
        if (items.length > 0) {
            countSpan.textContent = `${items.length} recording${items.length > 1 ? 's are' : ' is'} pending upload.`;
            queueBanner.classList.remove('starmus_visually_hidden');
        } else {
            queueBanner.classList.add('starmus_visually_hidden');
        }
    }

    window.addEventListener('online', processQueue);
    updateQueueUI();
    if (navigator.onLine) {
        // Delay initial queue processing slightly to let the page settle.
        setTimeout(processQueue, 2000);
    }

    // --- Form Initialization and Handling ---
    const recorderWrappers = document.querySelectorAll('[data-enabled-recorder]');
    if (recorderWrappers.length === 0) return;

    recorderWrappers.forEach(wrapper => {
        const formInstanceId = wrapper.id.substring('starmus_audioWrapper_'.length);
        const formElement = document.getElementById(formInstanceId);

        const _updateStatus = (message, type = 'info', makeVisible = true, reEnableSubmit = false) => {
            const statusDiv = document.getElementById(`sparxstar_status_${formInstanceId}`); // Keep old name for compatibility if needed
            if (statusDiv) {
                const textSpan = statusDiv.querySelector('.sparxstar_status__text');
                if (textSpan) textSpan.textContent = message;
                statusDiv.className = `sparxstar_status ${type}`;
                if (makeVisible) statusDiv.classList.remove('sparxstar_visually_hidden');
            }
            if (reEnableSubmit) {
                const submitButton = document.getElementById(`submit_button_${formInstanceId}`);
                if (submitButton) submitButton.disabled = false;
            }
        };

        if (!formElement) {
            return console.error(CONFIG.LOG_PREFIX, `Form element not found for instance ID: ${formInstanceId}.`);
        }

        if (typeof StarmusAudioRecorder?.init === 'function') {
            StarmusAudioRecorder.init({
                formInstanceId: formInstanceId
            }).then(success => {
                if (success) {
                    console.log(CONFIG.LOG_PREFIX, `Recorder module initialized successfully for ${formInstanceId}.`);
                } else {
                    _updateStatus('Recorder failed to load.', 'error');
                }
            });
        } else {
            console.error(CONFIG.LOG_PREFIX, 'StarmusAudioRecorder module is not available.');
            _updateStatus('Critical error: Recorder unavailable.', 'error');
        }

        formElement.addEventListener('submit', async (e) => {
            e.preventDefault();

            const audioIdField = document.getElementById(`audio_uuid_${formInstanceId}`);
            const audioFileInput = document.getElementById(`audio_file_${formInstanceId}`);
            const consentCheckbox = document.getElementById(`audio_consent_${formInstanceId}`);
            const submitButton = document.getElementById(`submit_button_${formInstanceId}`);
            const loaderDiv = document.getElementById(`sparxstar_loader_overlay_${formInstanceId}`);

            if (!audioIdField?.value || !audioFileInput?.files?.length) {
                return _updateStatus('Error: No audio file has been recorded or selected to submit.', 'error');
            }
            if (consentCheckbox && !consentCheckbox.checked) {
                return _updateStatus('Error: You must provide consent to submit.', 'error');
            }

            if (submitButton) submitButton.disabled = true;
            if (loaderDiv) loaderDiv.classList.remove('sparxstar_visually_hidden');

            const formData = new FormData(formElement);
            const meta = {};
            formData.forEach((value, key) => {
                if (!(value instanceof File)) {
                    meta[key] = value;
                }
            });

            // CRITICAL FIX: Save the WordPress-specific data to the submission item
            // so it persists in the offline queue and can be used by processQueue().
            const wordpressData = {
                action: starmusFormData?.action,
                nonce: starmusFormData?.nonce,
            };

            const submissionItem = {
                id: audioIdField.value,
                meta: meta,
                wordpressData: wordpressData, // Save it here!
                audio: audioFileInput.files[0],
                ajaxUrl: starmusFormData?.ajax_url || '/wp-admin/admin-ajax.php',
                audioField: audioFileInput.name,
                uploadedBytes: 0
            };

            if (CONFIG.DEBUG_MODE) {
                console.log(CONFIG.LOG_PREFIX, '--- Submission Data ---', submissionItem);
            }

            const success = await uploadSubmission(submissionItem);

            if (loaderDiv) loaderDiv.classList.add('sparxstar_visually_hidden');

            if (success) {
                _updateStatus('Successfully submitted!', 'success');
                formElement.reset();
                if (typeof StarmusAudioRecorder?.cleanup === 'function') {
                    StarmusAudioRecorder.cleanup(formInstanceId);
                }
                if (typeof window.onStarmusSubmitSuccess === 'function') {
                    window.onStarmusSubmitSuccess(formInstanceId, { message: 'Success' });
                }
            } else {
                _updateStatus('Connection issue. Your recording has been saved and will be uploaded automatically when you are back online.', 'info', true, true);
                formElement.reset();
                if (typeof StarmusAudioRecorder?.cleanup === 'function') {
                    StarmusAudioRecorder.cleanup(formInstanceId);
                }
                await updateQueueUI();
            }
        });
    });
});
