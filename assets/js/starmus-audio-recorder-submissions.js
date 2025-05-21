// ==== starmus-audio-form-submission.js ====
// Build Hash: 66b1d653d14e7c7f31fe6b00158f7cdf6eb3cd6e9b6e8ca97e6271430253fc00
// SHA-1: e4fdb834a69511fc7c3a0ef6acd7ccfba32aa04f
// SHA-256: 66b1d653d14e7c7f31fe6b00158f7cdf6eb3cd6e9b6e8ca97e6271430253fc00

document.addEventListener('DOMContentLoaded', () => {
    const logPrefix = 'STARMUS_FORM:';
    console.log(logPrefix, 'DOM fully loaded. Initializing audio form submissions.');

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
                _updateStatusForInstance(formInstanceId, 'Network error. Please check connection and try again.', 'error', true, submitButton);
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
