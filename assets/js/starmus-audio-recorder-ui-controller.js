/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * @package Starmus\submissions
 * @since 0.1.0
 * @version 0.8.1
 * @file UI Controller - Manages form interaction and delegates tasks.
 * @description This script is the "glue". It handles the two-step UI, validates
 * user input, and then tells the recorder and submission modules what to do.
 */
(function(window, document) {
    'use strict';

    const LOG_PREFIX = '[Starmus UI Controller]';
    function log(level, msg, data) { if(!console||!console[level]) return; console[level](LOG_PREFIX, msg, data || ''); }
    function el(id) { return document.getElementById(id); }
    function safeId(id) { return typeof id === 'string' && /^[A-Za-z0-9_-]{1,100}$/.test(id); }

    /**
     * Sanitizes text for safe display
     * @param {string} text - Text to sanitize
     * @returns {string} - Sanitized text
     */
    function sanitizeText(text) {
        if (typeof text !== 'string') return '';
        return text.replace(/[\u0000-\u001F\u007F<>"'&]/g, ' ').substring(0, 200);
    }

    function showUserMessage(formId, message, type) {
        const messageArea = el('starmus_step1_usermsg_' + formId);
        if (messageArea) {
            messageArea.textContent = sanitizeText(message);
            messageArea.setAttribute('data-status', type || 'info');
        }
    }

    /**
     * Attempts to capture the user's geolocation and stores it in hidden form fields.
     * @param {string} formId - The ID of the form instance.
     * @returns {Promise<boolean>} - A promise that resolves when geolocation capture is complete or has failed/timed out.
     */
    function captureGeolocation(formId) {
        return new Promise(function(resolve) {
            const form = el(formId);
            if (!form || typeof navigator === 'undefined' || !navigator.geolocation) {
                log('warn', 'Geolocation not available in this browser.');
                return resolve(true); // Resolve immediately if not available
            }

            const latField = form.querySelector('input[name="gps_latitude"]');
            const lonField = form.querySelector('input[name="gps_longitude"]');
            if (!latField || !lonField) {
                log('warn', 'GPS hidden fields not found in form.', formId);
                return resolve(true); // Resolve immediately if fields are missing
            }
            
            const options = {
                timeout: 8000,       // 8 seconds to get a lock
                maximumAge: 300000,  // 5 minutes old is acceptable
                enableHighAccuracy: false
            };

            let resolved = false;

            // Fallback timer in case the browser's geolocation hangs
            const fallbackTimeout = setTimeout(function() {
                if (!resolved) {
                    resolved = true;
                    log('warn', 'Geolocation request timed out.');
                    resolve(true);
                }
            }, options.timeout + 1000);

            navigator.geolocation.getCurrentPosition(
                function(position) {
                    if (!resolved) {
                        resolved = true;
                        clearTimeout(fallbackTimeout);
                        if (position && position.coords) {
                            latField.value = position.coords.latitude;
                            lonField.value = position.coords.longitude;
                            log('log', 'Geolocation captured successfully.');
                        }
                        resolve(true);
                    }
                },
                function(error) {
                    if (!resolved) {
                        resolved = true;
                        clearTimeout(fallbackTimeout);
                        log('warn', 'Geolocation failed.', error.message);
                        resolve(true); // Always resolve so we don't block the user
                    }
                },
                options
            );
        });
    }

    /**
     * Validates a single form field based on its type.
     * @param {HTMLElement} input - The input element to validate.
     * @returns {boolean} - True if the field is valid.
     */
    function validateField(input) {
        if (!input) return false;

        try {
            switch (input.type) {
                case 'text':
                case 'textarea':
                    return input.value && input.value.trim() !== '';
                case 'select-one':
                    return input.value !== '';
                case 'checkbox':
                    return input.checked;
                default:
                    return true;
            }
        } catch (e) {
            log('error', 'Validation error for field', input.id || 'unknown');
            return false;
        }
    }

    /**
     * Handles the click of the "Continue" button, validating and capturing GPS.
     * @param {string} formId - The ID of the form instance.
     */
    function handleContinueClick(formId) {
        const step1 = el('starmus_step1_' + formId);
        const step2 = el('starmus_step2_' + formId);
        const continueBtn = el('starmus_continue_btn_' + formId);
        const messageArea = el('starmus_step1_usermsg_' + formId);

        if (!step1 || !step2 || !continueBtn) return;

        // --- 1. Validate Form ---
        let allValid = true;
        const requiredInputs = step1.querySelectorAll('[required]');
        for (const input of requiredInputs) {
            if (!validateField(input)) {
                allValid = false;
                const label = step1.querySelector('label[for="' + input.id + '"]');
                const fieldName = label ? label.textContent.replace('*', '').trim() : 'A required field';

                if (messageArea) {
                    messageArea.textContent = 'Please complete the "' + sanitizeText(fieldName) + '" field.';
                    messageArea.setAttribute('data-status', 'error');
                } else {
                    log('error', 'Validation failed for:', sanitizeText(fieldName));
                }

                if (typeof input.focus === 'function') {
                    input.focus();
                }
                break; // Stop on the first error
            }
        }

        if (!allValid) return; // Stop if validation fails

        // --- 2. Show Progress and Capture Geolocation ---
        continueBtn.disabled = true;
        showUserMessage(formId, 'Capturing location...', 'info');

        captureGeolocation(formId).then(function() {
            // --- 3. Transition to Step 2 ---
            continueBtn.disabled = false;
            showUserMessage(formId, '', 'info'); // Clear the message
            step1.style.display = 'none';
            step2.style.display = 'block';

            // --- 4. Initialize the Recorder ---
            if (window.StarmusAudioRecorder && typeof window.StarmusAudioRecorder.init === 'function') {
                window.StarmusAudioRecorder.init({ formInstanceId: formId })
                    .then(function(result){
                        showUserMessage(formId, 'Recorder ready.', 'info');
                    })
                    .catch(function(err){
                        log('error','Engine init failed', err && err.message);
                    });
            }
        });
    }

    /**
     * Binds all necessary event listeners for a single form instance.
     * @param {HTMLElement} form - The form element to bind.
     */
    function initializeForm(form) {
        const formId = form.id;
        if (!safeId(formId)) return;
        
        // Bind once guard
        if (form.getAttribute('data-starmus-bound') === '1') return;
        form.setAttribute('data-starmus-bound', '1');

        const continueBtn = el('starmus_continue_btn_' + formId);

        if (continueBtn) {
            continueBtn.addEventListener('click', function() {
                handleContinueClick(formId);
            });
        }

        try {
            form.addEventListener('submit', function(event) {
                event.preventDefault();

                // Delegate to submissions handler module
                if (window.StarmusSubmissionsHandler && typeof window.StarmusSubmissionsHandler.handleSubmit === 'function') {
                    window.StarmusSubmissionsHandler.handleSubmit(formId, form);
                } else {
                    log('error', 'Submission handler module not available.');
                }
            });
        } catch (err) {
            log('error', 'Failed to bind form submission', err.message);
        }
    }

    /**
     * Finds and initializes all Starmus forms on the page.
     */
    function initializeAllForms() {
        try {
            const forms = document.querySelectorAll('form.starmus-audio-form');
            if (forms && forms.length > 0) {
                for (let i = 0; i < forms.length; i++) {
                    const form = forms[i];
                    if (form && form.id) {
                        initializeForm(form);
                    }
                }
            }
        } catch (err) {
            log('error', 'Failed to initialize forms.', err.message);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeAllForms);
    } else {
        initializeAllForms();
    }

})(window, document);
