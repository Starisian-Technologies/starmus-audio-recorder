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

    if (CONFIG.DEBUG_MODE) {
        console.log(CONFIG.LOG_PREFIX, 'DOM fully loaded. Initializing audio form submissions in debug mode.');
    }

    // --- Offline Queue Storage (IndexedDB with localStorage Fallback) ---
    let idbAvailable = 'indexedDB' in window;

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
        const list = JSON.parse(localStorage.getItem(CONFIG.DB_NAME) || '[]');
        const base64 = await blobToBase64(item.audio);
        const storableItem = { ...item, audioData: base64, audio: undefined };
        const existingIndex = list.findIndex(i => i.id === item.id);
        if (existingIndex > -1) {
            list[existingIndex] = storableItem;
        } else {
            list.push(storableItem);
        }
        localStorage.setItem(CONFIG.DB_NAME, JSON.stringify(list));
    }

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

    // --- REFACTORED: UPLOAD LOGIC TO USE REST API ---
    async function uploadSubmission(item, formInstanceId) {
        let offset = item.uploadedBytes || 0;

        while (offset < item.audio.size) {
            if (!navigator.onLine) {
                console.warn(CONFIG.LOG_PREFIX, 'Upload paused: connection lost.');
                await enqueue({ ...item, uploadedBytes: offset });
                return { success: false, queued: true };
            }

            const chunk = item.audio.slice(offset, offset + CONFIG.CHUNK_SIZE);
            const fd = new FormData();
            
            // Append metadata and other form fields
            Object.keys(item.meta).forEach(key => fd.append(key, item.meta[key]));
            
            // Append the audio chunk itself
            fd.append(item.audioField, chunk, item.meta.fileName || 'audio.webm');
            fd.append('chunk_offset', offset);
            fd.append('total_size', item.audio.size);

            try {
                // CHANGE: Use rest_url and add the X-WP-Nonce header for authentication.
                const response = await fetch(item.restUrl, {
                    method: 'POST',
                    headers: {
                        'X-WP-Nonce': starmusFormData.rest_nonce
                    },
                    body: fd
                });
                
                // The new REST API returns structured errors, which we can parse.
                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({ message: 'Could not parse error response.' }));
                    throw new Error(errorData.message || `Server responded with status ${response.status}`);
                }

                const data = await response.json();

                offset += chunk.size;
                if (CONFIG.DEBUG_MODE) console.log(CONFIG.LOG_PREFIX, `Chunk uploaded. Progress: ${offset}/${item.audio.size}`);

                // If this is the last chunk, the response contains the final payload.
                if (offset >= item.audio.size) {
                    await deleteItem(item.id);
                    // CHANGE: The redirect_url is now at the top level of the JSON response.
                    return { success: true, queued: false, redirectUrl: data?.redirect_url };
                }

            } catch (err) {
                console.error(CONFIG.LOG_PREFIX, 'Chunk upload failed. Queuing submission for later.', err);
                await enqueue({ ...item, uploadedBytes: offset });
                return { success: false, queued: true };
            }
        }
        
        // This part should ideally be covered by the last chunk logic above
        await deleteItem(item.id);
        return { success: true, queued: false };
    }

    let isProcessingQueue = false;
    async function processQueue() {
        if (isProcessingQueue || !navigator.onLine) return;
        isProcessingQueue = true;
        
        const retryBtn = document.getElementById('starmus_queue_retry');
        if (retryBtn) retryBtn.disabled = true;
        
        console.log(CONFIG.LOG_PREFIX, "Processing offline queue...");
        const items = await getAllItems();
        if (items.length > 0) {
            for (const item of items) {
                const result = await uploadSubmission(item);
                if (!result.success) {
                    console.warn(CONFIG.LOG_PREFIX, "Queue processing paused due to an upload failure.");
                    break; 
                }
            }
        }
        isProcessingQueue = false;
        if (retryBtn) retryBtn.disabled = false;
        await updateQueueUI();
    }

    const queueBanner = document.createElement('div');
    queueBanner.id = 'starmus_queue_banner';
    queueBanner.className = 'starmus_status starmus_visually_hidden';
    queueBanner.setAttribute('aria-live', 'polite');
    queueBanner.innerHTML = `<span class="starmus_status__text" id="starmus_queue_count"></span> <button type="button" id="starmus_queue_retry" class="sparxstar_button">Retry Uploads</button>`;
    document.body.appendChild(queueBanner);

    document.getElementById('starmus_queue_retry')?.addEventListener('click', processQueue);

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
    if (navigator.onLine) setTimeout(processQueue, 2000);

    // --- Form Initialization and Handling ---
    const recorderWrappers = document.querySelectorAll('[data-enabled-recorder]');
    if (recorderWrappers.length === 0) return;

    recorderWrappers.forEach(wrapper => {
        const formInstanceId = wrapper.id.substring('starmus_audioWrapper_'.length);
        const formElement = document.getElementById(formInstanceId);

        if (!formElement) {
            return console.error(CONFIG.LOG_PREFIX, `Form element not found for instance ID: ${formInstanceId}.`);
        }

        const step1 = formElement.querySelector(`#starmus_step_1_${formInstanceId}`);
        const step2 = formElement.querySelector(`#starmus_step_2_${formInstanceId}`);
        const continueBtn = formElement.querySelector(`#starmus_continue_btn_${formInstanceId}`);
        
        if (!step1 || !step2 || !continueBtn) {
            console.error(CONFIG.LOG_PREFIX, 'Multi-step form elements are missing. Defaulting to show recorder.');
            if (step1) step1.style.display = 'none';
            if (step2) step2.style.display = 'block';
            initializeRecorder(formInstanceId); 
            return;
        }

        continueBtn.addEventListener('click', function(event) {
            event.preventDefault();
            const errorMessageDiv = formElement.querySelector(`#starmus_step_1_error_${formInstanceId}`);
            
            const fieldsToValidate = [
                { id: `audio_title_${formInstanceId}`, name: 'Title', type: 'text' },
                { id: `language_${formInstanceId}`, name: 'Language', type: 'select' },
                { id: `recording_type_${formInstanceId}`, name: 'Recording Type', type: 'select' },
                { id: `audio_consent_${formInstanceId}`, name: 'Consent', type: 'checkbox' }
            ];

            errorMessageDiv.style.display = 'none';
            errorMessageDiv.textContent = '';
            fieldsToValidate.forEach(field => document.getElementById(field.id)?.removeAttribute('aria-describedby'));

            for (const field of fieldsToValidate) {
                const input = document.getElementById(field.id);
                if (!input) continue;
                let isValid = true;
                if (field.type === 'text') isValid = input.value.trim() !== '';
                if (field.type === 'select') isValid = input.value !== '';
                if (field.type === 'checkbox') isValid = input.checked;

                if (!isValid) {
                    errorMessageDiv.textContent = `Please complete the "${field.name}" field.`;
                    errorMessageDiv.style.display = 'block';
                    errorMessageDiv.id = `starmus_step_1_error_${formInstanceId}`; // Ensure it has an ID
                    input.focus();
                    input.setAttribute('aria-describedby', errorMessageDiv.id);
                    return;
                }
            }
            captureGeolocationAndProceed();
        });
        
        function captureGeolocationAndProceed() {
            if ("geolocation" in navigator) {
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        formElement.querySelector(`#gps_latitude_${formInstanceId}`).value = position.coords.latitude;
                        formElement.querySelector(`#gps_longitude_${formInstanceId}`).value = position.coords.longitude;
                        if (CONFIG.DEBUG_MODE) console.log(CONFIG.LOG_PREFIX, 'GPS Location captured.');
                        transitionToStep2();
                    },
                    (error) => {
                        console.warn(CONFIG.LOG_PREFIX, `Geolocation error (${error.code}): ${error.message}`);
                        transitionToStep2();
                    }
                );
            } else {
                console.log(CONFIG.LOG_PREFIX, "Geolocation is not available.");
                transitionToStep2();
            }
        }

        function transitionToStep2() {
            step1.style.display = 'none';
            step2.style.display = 'block';
            const step2Heading = formElement.querySelector(`#sparxstar_audioRecorderHeading_${formInstanceId}`);
            if (step2Heading) {
                step2Heading.setAttribute('tabindex', '-1');
                step2Heading.focus();
            }
            initializeRecorder(formInstanceId);
        }

        function initializeRecorder(instanceId) {
            if (typeof StarmusAudioRecorder?.init === 'function') {
                StarmusAudioRecorder.init({ formInstanceId: instanceId })
                    .then(success => {
                        if (success && CONFIG.DEBUG_MODE) {
                            console.log(CONFIG.LOG_PREFIX, `Recorder module initialized for ${instanceId}.`);
                        }
                    });
            } else {
                console.error(CONFIG.LOG_PREFIX, 'StarmusAudioRecorder module is not available.');
            }
        }

        // --- REFACTORED: SUBMIT HANDLER ---
        formElement.addEventListener('submit', async (e) => {
            e.preventDefault();

            const audioIdField = document.getElementById(`audio_uuid_${formInstanceId}`);
            const audioFileInput = document.getElementById(`audio_file_${formInstanceId}`);
            const submitButton = document.getElementById(`submit_button_${formInstanceId}`);
            const loaderDiv = document.getElementById(`sparxstar_loader_overlay_${formInstanceId}`);

            if (!audioIdField?.value || !audioFileInput?.files?.length) {
                alert('Error: No audio file has been recorded to submit.');
                return;
            }

            if (submitButton) submitButton.disabled = true;
            if (loaderDiv) loaderDiv.classList.remove('sparxstar_visually_hidden');

            const formData = new FormData(formElement);
            const meta = {};
            formData.forEach((value, key) => {
                if (!(value instanceof File)) meta[key] = value;
            });
            
            // CHANGE: Simplified submission item. No need for wordpressData (action/nonce) in the body.
            const submissionItem = {
                id: audioIdField.value,
                meta, // Contains all form fields, which will be sent in the body.
                audio: audioFileInput.files[0],
                restUrl: starmusFormData?.rest_url, // Use the new REST URL from wp_localize_script
                audioField: audioFileInput.name,
                uploadedBytes: 0
            };

            if (CONFIG.DEBUG_MODE) console.log(CONFIG.LOG_PREFIX, '--- Submission Data ---', submissionItem);

            if (!submissionItem.restUrl) {
                alert('Error: The submission endpoint is not configured. Please contact support.');
                if (submitButton) submitButton.disabled = false;
                if (loaderDiv) loaderDiv.classList.add('sparxstar_visually_hidden');
                return;
            }

            const result = await uploadSubmission(submissionItem, formInstanceId);

            if (loaderDiv) loaderDiv.classList.add('sparxstar_visually_hidden');

            if (result.success) {
                if (result.redirectUrl) {
                    window.location.href = result.redirectUrl;
                } else {
                    alert('Successfully submitted!');
                    formElement.reset();
                    if (typeof StarmusAudioRecorder?.cleanup === 'function') {
                        StarmusAudioRecorder.cleanup(formInstanceId);
                    }
                }
            } else if (result.queued) {
                alert('Connection issue. Your recording has been saved and will be uploaded automatically when you are back online.');
                formElement.reset();
                if (typeof StarmusAudioRecorder?.cleanup === 'function') {
                    StarmusAudioRecorder.cleanup(formInstanceId);
                }
                await updateQueueUI();
            } else {
                alert('An unknown error occurred during upload. Please try again.');
                if (submitButton) submitButton.disabled = false;
            }
        });
    });
});
