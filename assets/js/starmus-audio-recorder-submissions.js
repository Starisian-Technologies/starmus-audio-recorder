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

document.addEventListener('DOMContentLoaded', () => {
    'use strict';
    
    const logPrefix = 'STARMUS_FORM:';
    console.log(logPrefix, 'DOM fully loaded. Initializing audio form submissions.');

    // --- Configuration ---
    const isDebugMode = new URLSearchParams(window.location.search).has('starmus_debug');
    const DB_NAME = 'starmus_audio_queue';
    const STORE_NAME = 'queue';
    const CHUNK_SIZE = 512 * 1024; // 512KB chunks

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
            const request = indexedDB.open(DB_NAME, 1);
            request.onupgradeneeded = event => {
                const db = event.target.result;
                if (!db.objectStoreNames.contains(STORE_NAME)) {
                    db.createObjectStore(STORE_NAME, { keyPath: 'id' });
                }
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = event => {
                console.error(logPrefix, 'IndexedDB failed to open. Falling back to localStorage.', event.target.error);
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
            const db = await dbPromise;
            return new Promise((resolve, reject) => {
                const tx = db.transaction(STORE_NAME, 'readwrite');
                tx.objectStore(STORE_NAME).put(item);
                tx.oncomplete = resolve;
                tx.onerror = () => reject(tx.error);
            });
        }
        const list = JSON.parse(localStorage.getItem(DB_NAME) || '[]');
        const base64 = await blobToBase64(item.audio);
        const existingIndex = list.findIndex(i => i.id === item.id);
        const storableItem = { ...item, audioData: base64, audio: undefined };
        if (existingIndex > -1) {
            list[existingIndex] = storableItem;
        } else {
            list.push(storableItem);
        }
        localStorage.setItem(DB_NAME, JSON.stringify(list));
    }

    /**
     * Retrieves all items from the offline queue.
     * @returns {Promise<object[]>}
     */
    async function getAllItems() {
        if (idbAvailable) {
            const db = await dbPromise;
            return new Promise((resolve, reject) => {
                const req = db.transaction(STORE_NAME, 'readonly').objectStore(STORE_NAME).getAll();
                req.onsuccess = () => resolve(req.result || []);
                req.onerror = () => reject(req.error);
            });
        }
        const list = JSON.parse(localStorage.getItem(DB_NAME) || '[]');
        return list.map(item => ({ ...item, audio: base64ToBlob(item.audioData) }));
    }

    /**
     * Deletes an item from the offline queue by its ID.
     * @param {string} id - The unique ID of the item to delete.
     * @returns {Promise<void>}
     */
    async function deleteItem(id) {
        if (idbAvailable) {
            const db = await dbPromise;
            return new Promise((resolve, reject) => {
                const tx = db.transaction(STORE_NAME, 'readwrite');
                tx.objectStore(STORE_NAME).delete(id);
                tx.oncomplete = resolve;
                tx.onerror = () => reject(tx.error);
            });
        }
        const list = JSON.parse(localStorage.getItem(DB_NAME) || '[]');
        localStorage.setItem(DB_NAME, JSON.stringify(list.filter(i => i.id !== id)));
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
                console.warn(logPrefix, 'Upload paused: connection lost.');
                await enqueue({ ...item, uploadedBytes: offset });
                return false;
            }

            const chunk = item.audio.slice(offset, offset + CHUNK_SIZE);
            const fd = new FormData();
            for (const key in item.meta) fd.append(key, item.meta[key]);
            fd.append(item.audioField, chunk, item.meta.fileName || 'audio.webm');
            fd.append('chunk_offset', offset);
            fd.append('total_size', item.audio.size);

            try {
                const response = await fetch(item.ajaxUrl, { method: 'POST', body: fd });
                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data?.message || 'Server returned an error');
                }
                
                offset += chunk.size;
                console.log(logPrefix, `Chunk uploaded. Progress: ${offset}/${item.audio.size}`);

            } catch (err) {
                console.error(logPrefix, 'Chunk upload failed. Queuing submission for later.', err);
                try {
                    await enqueue({ ...item, uploadedBytes: offset });
                } catch (enqueueErr) {
                    idbAvailable = false;
                    await enqueue({ ...item, uploadedBytes: offset });
                }
                return false;
            }
        }

        console.log(logPrefix, `Upload complete for item ${item.id}. Removing from queue.`);
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
        console.log(logPrefix, "Processing offline queue...");

        const items = await getAllItems();
        for (const item of items) {
            const success = await uploadSubmission(item);
            if (!success) {
                console.warn(logPrefix, "Queue processing paused due to an upload failure.");
                break;
            }
        }

        isProcessingQueue = false;
        if (retryBtn) retryBtn.disabled = false;
        updateQueueUI();
    }

    // --- UI and Event Listeners ---
    const queueBanner = document.createElement('div');
    queueBanner.id = 'starmus_queue_banner';
    queueBanner.className = 'sparxstar_status sparxstar_visually_hidden';
    queueBanner.setAttribute('aria-live', 'polite');
    queueBanner.innerHTML = `<span class="sparxstar_status__text" id="starmus_queue_count"></span> <button type="button" id="starmus_queue_retry" class="button">Retry Uploads</button>`;
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
            queueBanner.classList.remove('sparxstar_visually_hidden');
        } else {
            queueBanner.classList.add('sparxstar_visually_hidden');
        }
    }

    window.addEventListener('online', processQueue);
    updateQueueUI();
    if (navigator.onLine) processQueue();

    // --- Form Initialization and Handling ---
    const recorderWrappers = document.querySelectorAll('[data-enabled-recorder]');
    if (recorderWrappers.length === 0) return;

    recorderWrappers.forEach(wrapper => {
        const formInstanceId = wrapper.id.substring('starmus_audioWrapper_'.length);
        const formElement = document.getElementById(formInstanceId);

        const _updateStatus = (message, type = 'info', makeVisible = true, reEnableSubmit = false) => {
            const statusDiv = document.getElementById(`sparxstar_status_${formInstanceId}`);
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

        if (!formElement) return console.error(logPrefix, `Form element not found for instance ID: ${formInstanceId}.`);

        if (typeof StarmusAudioRecorder?.init === 'function') {
            StarmusAudioRecorder.init({
                formInstanceId: formInstanceId
            }).then(success => {
                if (success) console.log(logPrefix, `Recorder module initialized successfully for ${formInstanceId}.`);
                else _updateStatus('Recorder failed to load.', 'error');
            });
        } else {
            console.error(logPrefix, 'StarmusAudioRecorder module is not available.');
            _updateStatus('Critical error: Recorder unavailable.', 'error');
        }

        formElement.addEventListener('submit', async (e) => {
            e.preventDefault();

            const audioIdField = document.getElementById(`audio_uuid_${formInstanceId}`);
            const audioFileInput = document.getElementById(`audio_file_${formInstanceId}`);
            const consentCheckbox = document.getElementById(`audio_consent_${formInstanceId}`);
            const submitButton = document.getElementById(`submit_button_${formInstanceId}`);
            const loaderDiv = document.getElementById(`sparxstar_loader_overlay_${formInstanceId}`);

            if (!audioIdField?.value || !audioFileInput?.files?.length) return _updateStatus('Error: No audio file has been recorded to submit.', 'error');
            if (consentCheckbox && !consentCheckbox.checked) return _updateStatus('Error: You must provide consent to submit.', 'error');

            if (submitButton) submitButton.disabled = true;
            if (loaderDiv) loaderDiv.classList.remove('sparxstar_visually_hidden');

            const formData = new FormData(formElement);
            const meta = {};
            formData.forEach((value, key) => { if (!(value instanceof File)) meta[key] = value; });
            if (starmusFormData?.action) meta.action = starmusFormData.action;
            if (starmusFormData?.nonce) meta.nonce = starmusFormData.nonce;

            const submissionItem = {
                id: audioIdField.value,
                meta: meta,
                audio: audioFileInput.files[0],
                ajaxUrl: starmusFormData?.ajax_url || '/wp-admin/admin-ajax.php',
                audioField: audioFileInput.name,
                uploadedBytes: 0
            };

            if (isDebugMode) console.log(logPrefix, '--- Submission Data ---', submissionItem);

            const success = await uploadSubmission(submissionItem);

            if (loaderDiv) loaderDiv.classList.add('sparxstar_visually_hidden');

            if (success) {
                _updateStatus('Successfully submitted!', 'success');
                formElement.reset();
                StarmusAudioRecorder?.cleanup();
                if (typeof window.onStarmusSubmitSuccess === 'function') {
                    window.onStarmusSubmitSuccess(formInstanceId, { message: 'Success' });
                }
            } else {
                _updateStatus('Connection issue. Your recording has been saved and will be uploaded automatically when you are back online.', 'info', true, true);
                formElement.reset();
                StarmusAudioRecorder?.cleanup();
                updateQueueUI();
            }
        });
    });
});