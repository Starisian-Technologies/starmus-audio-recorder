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

    // --- Configuration and Offline Queue Storage (No Changes Here) ---
    const CONFIG = { /* ... */ };
    let idbAvailable = 'indexedDB' in window;
    const dbPromise = (() => { /* ... */ })();
    async function blobToBase64(blob) { /* ... */ }
    function base64ToBlob(base64) { /* ... */ }
    async function enqueue(item) { /* ... */ }
    async function getAllItems() { /* ... */ }
    async function deleteItem(id) { /* ... */ }
    async function uploadSubmission(item, formInstanceId) { /* ... */ } // This remains the same as the REST API version
    let isProcessingQueue = false;
    async function processQueue() { /* ... */ }
    const queueBanner = document.createElement('div');
    /* ... */
    document.body.appendChild(queueBanner);
    document.getElementById('starmus_queue_retry')?.addEventListener('click', processQueue);
    async function updateQueueUI() { /* ... */ }
    window.addEventListener('online', processQueue);
    updateQueueUI();
    if (navigator.onLine) setTimeout(processQueue, 2000);
    // --- End of Unchanged Section ---

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
            const statusMessageDiv = formElement.querySelector(`#starmus_step_1_status_${formInstanceId}`);
            
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
                    errorMessageDiv.id = `starmus_step_1_error_${formInstanceId}`;
                    input.focus();
                    input.setAttribute('aria-describedby', errorMessageDiv.id);
                    return;
                }
            }

            if (statusMessageDiv) statusMessageDiv.style.display = 'block';
            continueBtn.disabled = true;

            captureGeolocationAndProceed();
        });
        
        // --- CORRECTED SCOPE: These functions are now defined INSIDE the forEach loop ---
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
                        transitionToStep2(); // Proceed even if geolocation fails
                    }
                );
            } else {
                console.log(CONFIG.LOG_PREFIX, "Geolocation is not available.");
                transitionToStep2();
            }
        }

        function transitionToStep2() {
            const statusMessageDiv = formElement.querySelector(`#starmus_step_1_status_${formInstanceId}`);
            if (statusMessageDiv) statusMessageDiv.style.display = 'none';
            if (continueBtn) continueBtn.disabled = false;

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
            
            const submissionItem = {
                id: audioIdField.value,
                meta,
                audio: audioFileInput.files[0],
                restUrl: starmusFormData?.rest_url,
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
    }); // End of recorderWrappers.forEach
});
