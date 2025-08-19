// ==== starmus-audio-form-submission.js ====
// Build Hash: 66b1d653d14e7c7f31fe6b00158f7cdf6eb3cd6e9b6e8ca97e6271430253fc00
// SHA-1: e4fdb834a69511fc7c3a0ef6acd7ccfba32aa04f
// SHA-256: 66b1d653d14e7c7f31fe6b00158f7cdf6eb3cd6e9b6e8ca97e6271430253fc00

document.addEventListener('DOMContentLoaded', () => {
    const logPrefix = 'STARMUS_FORM:';
    console.log(logPrefix, 'DOM fully loaded. Initializing audio form submissions.');

    // Offline queue configuration
    const DB_NAME = 'starmus_audio_queue';
    const STORE_NAME = 'queue';
    const CHUNK_SIZE = 512 * 1024; // 512KB chunks
    const canUseIDB = 'indexedDB' in window;
    const dbPromise = canUseIDB ? new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, 1);
        request.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) {
                db.createObjectStore(STORE_NAME, { keyPath: 'id' });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    }) : Promise.resolve(null);

    function blobToBase64(blob) {
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
        for (let i = 0; i < binary.length; i++) {
            array[i] = binary.charCodeAt(i);
        }
        return new Blob([array], { type: mime });
    }

    async function enqueue(item) {
        if (canUseIDB) {
            const db = await dbPromise;
            return new Promise((resolve, reject) => {
                const tx = db.transaction(STORE_NAME, 'readwrite');
                tx.objectStore(STORE_NAME).put(item);
                tx.oncomplete = () => {
                    updateQueueUI();
                    resolve();
                };
                tx.onerror = () => reject(tx.error);
            });
        }
        const list = JSON.parse(localStorage.getItem(DB_NAME) || '[]');
        const base64 = await blobToBase64(item.audio);
        list.push({ ...item, audioData: base64 });
        localStorage.setItem(DB_NAME, JSON.stringify(list));
        updateQueueUI();
    }

    async function getAllItems() {
        if (canUseIDB) {
            const db = await dbPromise;
            return new Promise((resolve, reject) => {
                const tx = db.transaction(STORE_NAME, 'readonly');
                const req = tx.objectStore(STORE_NAME).getAll();
                req.onsuccess = () => resolve(req.result || []);
                req.onerror = () => reject(req.error);
            });
        }
        const list = JSON.parse(localStorage.getItem(DB_NAME) || '[]');
        return list.map(item => {
            if (item.audioData) {
                item.audio = base64ToBlob(item.audioData);
            }
            return item;
        });
    }

    async function updateItem(id, fields) {
        if (canUseIDB) {
            const db = await dbPromise;
            return new Promise((resolve, reject) => {
                const tx = db.transaction(STORE_NAME, 'readwrite');
                const store = tx.objectStore(STORE_NAME);
                const req = store.get(id);
                req.onsuccess = () => {
                    const data = Object.assign(req.result, fields);
                    store.put(data);
                };
                tx.oncomplete = resolve;
                tx.onerror = () => reject(tx.error);
            });
        }
        const list = JSON.parse(localStorage.getItem(DB_NAME) || '[]');
        const idx = list.findIndex(i => i.id === id);
        if (idx > -1) {
            list[idx] = Object.assign(list[idx], fields);
            localStorage.setItem(DB_NAME, JSON.stringify(list));
        }
    }

    async function deleteItem(id) {
        if (canUseIDB) {
            const db = await dbPromise;
            return new Promise((resolve, reject) => {
                const tx = db.transaction(STORE_NAME, 'readwrite');
                tx.objectStore(STORE_NAME).delete(id);
                tx.oncomplete = () => {
                    updateQueueUI();
                    resolve();
                };
                tx.onerror = () => reject(tx.error);
            });
        }
        const list = JSON.parse(localStorage.getItem(DB_NAME) || '[]');
        const filtered = list.filter(i => i.id !== id);
        localStorage.setItem(DB_NAME, JSON.stringify(filtered));
        updateQueueUI();
    }

    const queueBanner = document.createElement('div');
    queueBanner.id = 'starmus_queue_banner';
    queueBanner.className = 'sparxstar_status sparxstar_visually_hidden';
    queueBanner.innerHTML = '<span class="sparxstar_status__text" id="starmus_queue_count"></span> <button type="button" id="starmus_queue_retry">Retry</button>';
    document.body.appendChild(queueBanner);

    document.getElementById('starmus_queue_retry').addEventListener('click', () => {
        processQueue();
    });

    async function updateQueueUI() {
        const items = await getAllItems();
        const countSpan = document.getElementById('starmus_queue_count');
        if (items.length > 0) {
            countSpan.textContent = `${items.length} recording${items.length > 1 ? 's' : ''} pending upload`;
            queueBanner.classList.remove('sparxstar_visually_hidden');
        } else {
            queueBanner.classList.add('sparxstar_visually_hidden');
        }
    }

    let processingQueue = false;
    async function processQueue() {
        if (processingQueue) return;
        processingQueue = true;
        const items = await getAllItems();
        for (const item of items) {
            const success = await uploadQueuedItem(item);
            if (!success) {
                break;
            }
        }
        processingQueue = false;
        updateQueueUI();
    }

    async function uploadQueuedItem(item) {
        let offset = item.uploadedBytes || 0;
        while (offset < item.audio.size) {
            const chunk = item.audio.slice(offset, offset + CHUNK_SIZE);
            const fd = new FormData();
            for (const key in item.meta) {
                fd.append(key, item.meta[key]);
            }
            fd.append(item.audioField, chunk, item.meta.fileName || 'audio.webm');
            fd.append('chunk_offset', offset);
            fd.append('total_size', item.audio.size);
            try {
                const response = await fetch(item.ajaxUrl, { method: 'POST', body: fd });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Upload error');
                }
                offset += chunk.size;
                await updateItem(item.id, { uploadedBytes: offset });
            } catch (err) {
                console.error(logPrefix, 'Chunk upload failed:', err);
                return false;
            }
        }
        await deleteItem(item.id);
        return true;
    }

    window.addEventListener('online', () => {
        processQueue();
    });

    updateQueueUI();
    if (navigator.onLine) {
        processQueue();
    }

    const recorderWrappers = document.querySelectorAll('[data-enabled-recorder]');

    if (recorderWrappers.length === 0) {
        console.log(logPrefix, 'No audio recorder forms found on this page.');
        return;
    }

    recorderWrappers.forEach(wrapper => {
        const wrapperId = wrapper.id;
        if (!wrapperId || !wrapperId.startsWith('starmus_audioWrapper_')) {
            console.warn(logPrefix, 'Recorder wrapper found without a valid prefixed ID. Skipping:', wrapper);
            return;
        }

        const formInstanceId = wrapperId.substring('starmus_audioWrapper_'.length);
        console.log(logPrefix, `Initializing for form instance: ${formInstanceId}`);

        const formElement = document.getElementById(formInstanceId);
        const statusDiv = document.getElementById(`sparxstar_status_${formInstanceId}`);
        const statusTextSpan = statusDiv ? statusDiv.querySelector('.sparxstar_status__text') : null;
        const loaderDiv = document.getElementById(`sparxstar_loader_overlay_${formInstanceId}`);
        const loaderTextSpan = loaderDiv ? loaderDiv.querySelector('.sparxstar_status__text') : null;
        const audioIdField = document.getElementById(`audio_uuid_${formInstanceId}`);
        const submitButton = document.getElementById(`submit_button_${formInstanceId}`);

        if (!formElement) {
            console.error(logPrefix, `Form element not found for instance ID: ${formInstanceId}. Skipping.`);
            return;
        }
        if (!statusDiv || !statusTextSpan) {
            console.warn(logPrefix, `Status display not fully found for instance ID: ${formInstanceId}.`);
        }
        if (!loaderDiv || !loaderTextSpan) {
            console.warn(logPrefix, `Loader display not fully found for instance ID: ${formInstanceId}.`);
        }
        if (!audioIdField) {
            console.error(logPrefix, `Audio ID field not found for instance ID: ${formInstanceId}. Submission will likely fail.`);
        }

        if (typeof StarmusAudioRecorder !== 'undefined' && StarmusAudioRecorder.init) {
            console.log(logPrefix, `Initializing StarmusAudioRecorder module for instance: ${formInstanceId}`);
            StarmusAudioRecorder.init({
                formInstanceId: formInstanceId,
                buildHash: '66b1d653d14e7c7f31fe6b00158f7cdf6eb3cd6e9b6e8ca97e6271430253fc00',
                recorderContainerSelector: `#starmus_audioWrapper_${formInstanceId}`
            }).then(success => {
                if (success) {
                    console.log(logPrefix, `Recorder module initialized successfully for ${formInstanceId}.`);
                } else {
                    console.error(logPrefix, `Recorder module FAILED to initialize for ${formInstanceId}.`);
                    if (statusTextSpan) statusTextSpan.textContent = 'Recorder failed to load.';
                    if (statusDiv) statusDiv.classList.remove('sparxstar_visually_hidden');
                }
            }).catch(error => {
                console.error(logPrefix, `Error during recorder module initialization for ${formInstanceId}:`, error);
                if (statusTextSpan) statusTextSpan.textContent = 'Error loading recorder.';
                if (statusDiv) statusDiv.classList.remove('sparxstar_visually_hidden');
            });
        } else {
            console.error(logPrefix, 'StarmusAudioRecorder module is not available.');
            if (statusTextSpan) statusTextSpan.textContent = 'Critical error: Recorder unavailable.';
            if (statusDiv) statusDiv.classList.remove('sparxstar_visually_hidden');
        }

        wrapper.addEventListener('starmusAudioReady', (event) => {
            console.log(logPrefix, `starmusAudioReady event received for ${formInstanceId}!`, event.detail);
            if (event.detail && event.detail.audioId) {
                document.cookie = `audio_uuid=${audioIdField.value}; path=/; SameSite=Lax; Secure`;
                console.log(logPrefix, `Cookie set for ${formInstanceId} with audioId:`, event.detail.audioId);
                let readyMessage = 'Recording ready. ';
                if (event.detail.durationMs && event.detail.durationMs > (60 * 1000 * 1)) {
                    const minutes = Math.floor(event.detail.durationMs / 60000);
                    readyMessage += `Your recording is about ${minutes} min long and may take some time to upload. `;
                }
                readyMessage += 'Please submit when ready.';
                _updateStatusForInstance(formInstanceId, readyMessage, 'info');
                if (statusDiv) statusDiv.classList.remove('sparxstar_visually_hidden');
            } else {
                console.warn(logPrefix, `starmusAudioReady event for ${formInstanceId} missing audioId in detail.`);
            }
        });

        formElement.addEventListener('submit', async (e) => {
            e.preventDefault();
            console.log(logPrefix, `Submit event for form: ${formInstanceId}`);

            // Client-side validation (as before)
            if (!audioIdField || !audioIdField.value) {
                _updateStatusForInstance(formInstanceId, 'Error: Audio not recorded or Audio ID missing.', 'error');
                return;
            }
            const audioFileInput = document.getElementById(`audio_file_${formInstanceId}`);
            if (!audioFileInput || audioFileInput.files.length === 0) {
                _updateStatusForInstance(formInstanceId, 'Error: No audio file data to submit.', 'error');
                return;
            }
            const consentCheckbox = document.getElementById(`audio_consent_${formInstanceId}`);
            if (consentCheckbox && !consentCheckbox.checked) {
                _updateStatusForInstance(formInstanceId, 'Error: Consent is required.', 'error');
                return;
            }

            if (submitButton) submitButton.disabled = true; // Disable submit button

            // Show overlay loader and update its text
            if (loaderDiv) {
                const loaderTextElement = loaderDiv.querySelector('.sparxstar_status__text'); // Get the text span inside loader
                if (loaderTextElement) loaderTextElement.textContent = 'Submitting your recording…';
                loaderDiv.classList.remove('sparxstar_visually_hidden'); // Make overlay visible
            }
            if (statusDiv) statusDiv.classList.add('sparxstar_visually_hidden'); // Hide regular status

            const formData = new FormData(formElement);
            // --- DEBUG: Log FormData before submission ---
            let debugOutput = '--- FormData before submission ---\n';
            for (let [key, value] of formData.entries()) {
                debugOutput += key + ': ' + (value instanceof File ? value.name : value) + '\n';
            }
            // Option 1: Log to console (desktop/dev)
            console.log(debugOutput);
            // Option 2: Show in debug div (for mobile)
            let debugDiv = document.getElementById('debug_output');
            if (!debugDiv) {
                debugDiv = document.createElement('pre');
                debugDiv.id = 'debug_output';
                debugDiv.style = 'background:#eee;color:#222;font-size:12px;padding:8px;overflow:auto;max-height:200px;';
                document.body.appendChild(debugDiv);
            }
            debugDiv.textContent = debugOutput;
            // --- END DEBUG ---

            const ajaxUrl = (typeof starmusFormData !== 'undefined' && starmusFormData.ajax_url) ? starmusFormData.ajax_url : '/wp-admin/admin-ajax.php';

            async function queueCurrentSubmission() {
                const meta = {};
                formData.forEach((value, key) => {
                    if (!(value instanceof File)) {
                        meta[key] = value;
                    }
                });
                const queueEntry = {
                    id: audioIdField.value || (window.crypto && crypto.randomUUID ? crypto.randomUUID() : String(Date.now())),
                    meta,
                    audio: audioFileInput.files[0],
                    ajaxUrl,
                    audioField: audioFileInput.name,
                    uploadedBytes: 0
                };
                await enqueue(queueEntry);
            }

            if (!navigator.onLine) {
                await queueCurrentSubmission();
                if (loaderDiv) loaderDiv.classList.add('sparxstar_visually_hidden');
                _updateStatusForInstance(formInstanceId, 'Offline: saved for later upload.', 'info', true, submitButton);
                formElement.reset();
                if (typeof StarmusAudioRecorder !== 'undefined' && StarmusAudioRecorder.cleanup) {
                    StarmusAudioRecorder.cleanup();
                }
                return;
            }

            try {
                const response = await fetch(ajaxUrl, {
                    method: 'POST',
                    body: formData
                });

                let responseData;
                try {
                    responseData = await response.json();
                } catch (parseError) {
                    const responseText = await response.text();
                    console.error(logPrefix, `JSON parsing error for ${formInstanceId}. Status: ${response.status}. Response:`, responseText, parseError);
                    _updateStatusForInstance(formInstanceId, `Error: Invalid server response. (${response.status})`, 'error', true, submitButton);
                    if (loaderDiv) loaderDiv.classList.add('sparxstar_visually_hidden'); // Hide loader on error too
                    return;
                }

                if (loaderDiv) loaderDiv.classList.add('sparxstar_visually_hidden'); // Hide loader after getting response

                if (response.ok && responseData.success) {
                    _updateStatusForInstance(formInstanceId, responseData.message || 'Successfully submitted!', 'success', true);
                    formElement.reset();
                    if (typeof StarmusAudioRecorder !== 'undefined' && StarmusAudioRecorder.cleanup) {
                        StarmusAudioRecorder.cleanup();
                    }
                    if (typeof window.onStarmusSubmitSuccess === 'function') {
                        window.onStarmusSubmitSuccess(formInstanceId, responseData);
                    }
                } else {
                    const errorMessage = responseData.data?.message || responseData.message || 'Unknown server error.';
                    _updateStatusForInstance(formInstanceId, `Error: ${errorMessage}`, 'error', true, submitButton);
                }
            } catch (networkError) {
                console.error(logPrefix, `Network error during submission for ${formInstanceId}:`, networkError);
                if (loaderDiv) loaderDiv.classList.add('sparxstar_visually_hidden');
                await queueCurrentSubmission();
                _updateStatusForInstance(formInstanceId, 'Offline: saved for later upload.', 'info', true, submitButton);
                formElement.reset();
                if (typeof StarmusAudioRecorder !== 'undefined' && StarmusAudioRecorder.cleanup) {
                    StarmusAudioRecorder.cleanup();
                }
            }
        });

        // Helper function for status updates
        function _updateStatusForInstance(instanceId, message, type = 'info', makeStatusDivVisible = true, submitBtnToReEnable = null) {
            // This targets the main status div, not the loader's text span
            const localStatusDiv = document.getElementById(`sparxstar_status_${instanceId}`);
            const localStatusTextSpan = localStatusDiv ? localStatusDiv.querySelector('.sparxstar_status__text') : null;

            if (localStatusTextSpan) {
                localStatusTextSpan.textContent = message;
                localStatusTextSpan.className = 'sparxstar_status__text'; // Reset classes
                if (type) localStatusTextSpan.classList.add(type);
            }
            if (localStatusDiv && makeStatusDivVisible) {
                localStatusDiv.classList.remove('sparxstar_visually_hidden');
            }
            if (submitBtnToReEnable) {
                submitBtnToReEnable.disabled = false;
            }
        }

        console.log(logPrefix, `Event listeners and setup complete for form instance: ${formInstanceId}`);
    });
});
