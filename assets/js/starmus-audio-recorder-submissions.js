// ==== starmus-audio-form-submission.js ====
// Build Hash: 77c4a... (Updated) - Rewritten for unified chunked uploads and enhanced robustness.
// This version applies resumable, chunked uploads to ALL submissions for maximum resilience.

document.addEventListener('DOMContentLoaded', () => {
    const logPrefix = 'STARMUS_FORM:';
    console.log(logPrefix, 'DOM fully loaded. Initializing audio form submissions.');

    // --- Configuration ---
    const isDebugMode = new URLSearchParams(window.location.search).has('starmus_debug');
    const DB_NAME = 'starmus_audio_queue';
    const STORE_NAME = 'queue';
    const CHUNK_SIZE = 512 * 1024; // 512KB chunks

    // --- Offline Queue Storage (IndexedDB with localStorage Fallback) ---
    let idbAvailable = 'indexedDB' in window;
    const dbPromise = (() => {
        if (!idbAvailable) return Promise.resolve(null);
        return new Promise((resolve) => {
            const request = indexedDB.open(DB_NAME, 1);
            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                if (!db.objectStoreNames.contains(STORE_NAME)) {
                    db.createObjectStore(STORE_NAME, { keyPath: 'id' });
                }
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = (event) => {
                console.error(logPrefix, 'IndexedDB failed to open. Falling back to localStorage.', event.target.error);
                idbAvailable = false; // Disable IDB for subsequent operations
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
        const storableItem = { ...item, audioData: base64, audio: undefined }; // Remove blob before storing
        if (existingIndex > -1) {
            list[existingIndex] = storableItem;
        } else {
            list.push(storableItem);
        }
        localStorage.setItem(DB_NAME, JSON.stringify(list));
    }

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
                return false; // Paused, not fully uploaded
            }

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
                let data;
                try {
                    data = await response.json();
                } catch (jsonErr) {
                    throw new Error('Server returned invalid JSON.');
                }

                if (!response.ok || !data.success) {
                    throw new Error(data && data.message ? data.message : 'Server returned an error');
                }

                offset += chunk.size;
                console.log(logPrefix, `Chunk uploaded successfully. Progress: ${offset}/${item.audio.size}`);

            } catch (err) {
                console.error(logPrefix, 'Chunk upload failed. Queuing submission for later.', err);
                // If IndexedDB fails during transaction, fallback to localStorage
                try {
                    await enqueue({ ...item, uploadedBytes: offset });
                } catch (enqueueErr) {
                    idbAvailable = false;
                    await enqueue({ ...item, uploadedBytes: offset });
                }
                return false; // Failed, not fully uploaded
            }
        }

        console.log(logPrefix, `Upload complete for item ${item.id}. Removing from queue.`);
        await deleteItem(item.id);
        return true; // Success!
    }

    let isProcessingQueue = false;
    async function processQueue() {
        if (isProcessingQueue || !navigator.onLine) return;
        isProcessingQueue = true;
        document.getElementById('starmus_queue_retry').disabled = true;
        console.log(logPrefix, "Processing offline queue...");

        const items = await getAllItems();
        for (const item of items) {
            const success = await uploadSubmission(item);
            if (!success) {
                console.warn(logPrefix, "Queue processing paused due to an upload failure.");
                break; // Stop processing if one fails, to wait for better connection.
            }
        }

        isProcessingQueue = false;
        document.getElementById('starmus_queue_retry').disabled = false;
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

    async function updateQueueUI() {
        const items = await getAllItems();
        const countSpan = document.getElementById('starmus_queue_count');
        if (items.length > 0) {
            countSpan.textContent = `${items.length} recording${items.length > 1 ? 's are' : ' is'} pending upload.`;
            queueBanner.classList.remove('sparxstar_visually_hidden');
        } else {
            queueBanner.classList.add('sparxstar_visually_hidden');
        }
        // Accessibility: update aria-live region
        queueBanner.setAttribute('aria-live', items.length > 0 ? 'assertive' : 'polite');
    }

    window.addEventListener('online', processQueue);
    updateQueueUI();
    if (navigator.onLine) processQueue();


    // --- Form Initialization and Handling ---
    const recorderWrappers = document.querySelectorAll('[data-enabled-recorder]');
    if (recorderWrappers.length === 0) {
        console.log(logPrefix, 'No audio recorder forms found on this page.');
        return;
    }

    recorderWrappers.forEach(wrapper => {
        const formInstanceId = wrapper.id.substring('starmus_audioWrapper_'.length);
        const formElement = document.getElementById(formInstanceId);

        // A helper scoped to this form instance
        const _updateStatus = (message, type = 'info', makeVisible = true, reEnableSubmit = false) => {
            const statusDiv = document.getElementById(`sparxstar_status_${formInstanceId}`);
            const textSpan = statusDiv?.querySelector('.sparxstar_status__text');
            if (textSpan) {
                textSpan.textContent = message;
                textSpan.className = 'sparxstar_status__text ' + type;
            }
            if (statusDiv && makeVisible) statusDiv.classList.remove('sparxstar_visually_hidden');
            if (reEnableSubmit) {
                const submitButton = document.getElementById(`submit_button_${formInstanceId}`);
                if (submitButton) submitButton.disabled = false;
            }
        };

        if (!formElement) {
            console.error(logPrefix, `Form element not found for instance ID: ${formInstanceId}.`);
            return;
        }

        // Initialize the recorder module for this form instance
        if (typeof StarmusAudioRecorder?.init === 'function') {
            StarmusAudioRecorder.init({
                formInstanceId: formInstanceId,
                recorderContainerSelector: `#starmus_audioWrapper_${formInstanceId}`
            }).then(success => {
                if (success) {
                    console.log(logPrefix, `Recorder module initialized successfully for ${formInstanceId}.`);
                } else {
                    _updateStatus('Recorder failed to load.', 'error');
                }
            });
        } else {
            console.error(logPrefix, 'StarmusAudioRecorder module is not available.');
            _updateStatus('Critical error: Recorder unavailable.', 'error');
        }

        formElement.addEventListener('submit', async (e) => {
            e.preventDefault();
            console.log(logPrefix, `Submit event for form: ${formInstanceId}`);

            const audioIdField = document.getElementById(`audio_uuid_${formInstanceId}`);
            const audioFileInput = document.getElementById(`audio_file_${formInstanceId}`);
            const consentCheckbox = document.getElementById(`audio_consent_${formInstanceId}`);
            const submitButton = document.getElementById(`submit_button_${formInstanceId}`);
            const loaderDiv = document.getElementById(`sparxstar_loader_overlay_${formInstanceId}`);

            // Validation
            if (!audioIdField?.value || !audioFileInput?.files?.length) {
                _updateStatus('Error: No audio file has been recorded to submit.', 'error');
                return;
            }
            if (consentCheckbox && !consentCheckbox.checked) {
                _updateStatus('Error: You must provide consent to submit.', 'error');
                return;
            }

            if (submitButton) submitButton.disabled = true;
            if (loaderDiv) loaderDiv.classList.remove('sparxstar_visually_hidden');

            // Prepare the submission item
            const formData = new FormData(formElement);
            const meta = {};
            formData.forEach((value, key) => {
                if (!(value instanceof File)) meta[key] = value;
            });

            if (starmusFormData?.action) {
                meta.action = starmusFormData.action;
            }
            if (starmusFormData?.nonce) {
                meta.nonce = starmusFormData.nonce;
            }

            const submissionItem = {
                id: audioIdField.value,
                meta: meta,
                audio: audioFileInput.files[0],
                ajaxUrl: starmusFormData?.ajax_url || '/wp-admin/admin-ajax.php',
                audioField: audioFileInput.name,
                uploadedBytes: 0
            };

            // Production-safe debugging
            if (isDebugMode) {
                console.log(logPrefix, '--- Submission Data ---', submissionItem);
                const debugDiv = document.getElementById('debug_output') || document.createElement('pre');
                debugDiv.id = 'debug_output';
                debugDiv.textContent = JSON.stringify(submissionItem.meta, null, 2);
                document.body.appendChild(debugDiv);
            }

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
                updateQueueUI(); // Immediately show it in the queue banner
            }
        });
    });
});