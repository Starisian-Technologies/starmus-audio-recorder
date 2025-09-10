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
 *
 * @module starmus-audio-recorder
 * @since 0.1.0
 * @version 0.4.0
 * @file Manages the submission lifecycle of audio recordings - Optimized for legacy browsers
 * @description This script handles form submissions for the Starmus Audio Recorder,
 * including multi-step form logic and REST API uploads. Optimized for older smartphones.
 */

(function() {
	'use strict';

	// Only run in legacy environments
	if (window.MediaRecorder && window.Promise && window.fetch) {
		return; // Modern environment handled by other modules
	}

	// Feature detection and polyfills with safety checks
	if (!Array.prototype.forEach) {
		Array.prototype.forEach = function(callback, thisArg) {
			for (var i = 0; i < this.length; i++) {
				// Handle sparse arrays correctly
				if (this.hasOwnProperty(i)) {
					callback.call(thisArg, this[i], i, this);
				}
			}
		};
	}

	if (!String.prototype.trim) {
		String.prototype.trim = function() {
			return this.replace(/^[\s\uFEFF\xA0]+|[\s\uFEFF\xA0]+$/g, '');
		};
	}

	// Secure logging with enhanced sanitization
	function sanitizeForLog(input) {
		if (typeof input !== 'string') {
			try {
				return JSON.stringify(input).substring(0, 100);
			} catch (e) {
				return String(input).substring(0, 100);
			}
		}
		return input.replace(/[\u0000-\u001F\u007F<>"'&]/g, ' ').substring(0, 100);
	}

	// Validate redirect URLs to prevent open redirect attacks
	function isValidRedirectUrl(url) {
		if (typeof url !== 'string' || url.length === 0) return false;
		
		// Only allow relative URLs or same-origin URLs
		if (url.startsWith('/')) return true;
		
		try {
			var urlObj = new URL(url, window.location.origin);
			return urlObj.origin === window.location.origin;
		} catch (e) {
			return false;
		}
	}

	function secureLog(level, prefix, message, data) {
		if (typeof console === 'undefined' || !console[level]) return;
		var sanitizedMessage = sanitizeForLog(message);
		var sanitizedData = data ? sanitizeForLog(data) : '';
		console[level](prefix, sanitizedMessage, sanitizedData);
	}

	// Configuration
	var CONFIG = {
		LOG_PREFIX: '[Starmus Submissions]',
		DEBUG_MODE: typeof window.STARMUS_DEBUG !== 'undefined' ? window.STARMUS_DEBUG : false,
		RETRY_DELAY: 2000
	};

	// DOM element cache for performance
	var elementCache = {};

	function getCachedElement(id) {
		if (!elementCache.hasOwnProperty(id)) {
			var element = document.getElementById(id);
			elementCache[id] = element || null;
		}
		return elementCache[id];
	}

	// User notification system (replaces alert)
	function showUserMessage(formInstanceId, message, type) {
		var messageDiv = getCachedElement('starmus_user_message_' + formInstanceId);
		if (!messageDiv) {
			messageDiv = document.createElement('div');
			messageDiv.id = 'starmus_user_message_' + formInstanceId;
			messageDiv.className = 'starmus-user-message';
			messageDiv.setAttribute('role', 'alert');
			messageDiv.setAttribute('aria-live', 'polite');
			var form = getCachedElement(formInstanceId);
			if (form) form.insertBefore(messageDiv, form.firstChild);
		}
		
		messageDiv.textContent = sanitizeForLog(message);
		messageDiv.className = 'starmus-user-message starmus-message-' + (type || 'info');
		messageDiv.style.display = 'block';
		
		// Clear existing timeout to prevent memory leaks (legacy browser compatible)
		var existingTimeoutId = messageDiv.getAttribute('data-timeout-id');
		if (existingTimeoutId) {
			clearTimeout(parseInt(existingTimeoutId, 10));
		}
		
		// Auto-hide after 5 seconds for non-error messages
		if (type !== 'error') {
			var timeoutId = setTimeout(function() {
				if (messageDiv) {
					messageDiv.style.display = 'none';
					messageDiv.removeAttribute('data-timeout-id');
				}
			}, 5000);
			messageDiv.setAttribute('data-timeout-id', String(timeoutId));
		}
	}

	// Initialize when DOM is ready
	function initializeWhenReady() {
		if (document.readyState === 'loading') {
			if (document.addEventListener) {
				document.addEventListener('DOMContentLoaded', initialize);
			} else if (document.attachEvent) {
				// Legacy IE support - deprecated, consider removing when IE support is dropped
				document.attachEvent('onreadystatechange', function() {
					if (document.readyState === 'complete') initialize();
				});
			}
		} else {
			initialize();
		}
	}

	function initialize() {
		var recorderWrappers = document.querySelectorAll('[data-enabled-recorder]');
		if (recorderWrappers.length === 0) return;

		// Convert NodeList to Array for older browsers
		var wrappers = [];
		for (var i = 0; i < recorderWrappers.length; i++) {
			wrappers.push(recorderWrappers[i]);
		}

		wrappers.forEach(function(wrapper) {
			var formInstanceId = wrapper.id.substring('starmus_audioWrapper_'.length);
			var formElement = getCachedElement(formInstanceId);

			if (!formElement) {
				secureLog('error', CONFIG.LOG_PREFIX, 'Form element not found for instance ID', formInstanceId);
				return;
			}

			setupFormInstance(formElement, formInstanceId);
		});
	}

	function setupFormInstance(formElement, formInstanceId) {
		// Bind once guard
		if (formElement.getAttribute('data-starmus-bound') === '1') return;
		formElement.setAttribute('data-starmus-bound', '1');
		
		var step1 = formElement.querySelector('#starmus_step1_' + formInstanceId);
		var step2 = formElement.querySelector('#starmus_step2_' + formInstanceId);
		var continueBtn = formElement.querySelector('#starmus_continue_btn_' + formInstanceId);

		// Cache form elements for performance
		var formElements = {
			step1: step1,
			step2: step2,
			continueBtn: continueBtn,
			errorDiv: formElement.querySelector('#starmus_step1_error_' + formInstanceId),
			statusDiv: formElement.querySelector('#starmus_step1_status_' + formInstanceId),
			submitBtn: formElement.querySelector('#submit_button_' + formInstanceId),
			loaderDiv: formElement.querySelector('#starmus_loader_overlay_' + formInstanceId)
		};

		// Fallback for missing multi-step structure
		if (!step1 || !step2 || !continueBtn) {
			secureLog('error', CONFIG.LOG_PREFIX, 'Multi-step form elements missing, defaulting to recorder');
			if (step1) step1.style.display = 'none';
			if (step2) step2.style.display = 'block';
			initializeRecorder(formInstanceId);
			return;
		}

		// Pre-cache field elements for validation
		var validationFields = [
			{ id: 'audio_title_' + formInstanceId, name: 'Title', type: 'text' },
			{ id: 'language_' + formInstanceId, name: 'Language', type: 'select' },
			{ id: 'recording_type_' + formInstanceId, name: 'Recording Type', type: 'select' },
			{ id: 'audio_consent_' + formInstanceId, name: 'Consent', type: 'checkbox' }
		];

		var fieldElements = {};
		validationFields.forEach(function(field) {
			fieldElements[field.id] = getCachedElement(field.id);
		});

		// Continue button handler
		continueBtn.addEventListener('click', function(event) {
			event.preventDefault();
			handleContinueClick(formElements, fieldElements, validationFields, formInstanceId);
		});

		// Form submission handler
		formElement.addEventListener('submit', function(e) {
			e.preventDefault();
			handleFormSubmit(formElements, formInstanceId);
		});
	}

	function handleContinueClick(formElements, fieldElements, validationFields, formInstanceId) {
		if (formElements.errorDiv) {
			formElements.errorDiv.style.display = 'none';
			formElements.errorDiv.textContent = '';
		}

		// Clear previous aria-describedby attributes
		for (var j = 0; j < validationFields.length; j++) {
			var fieldForClearing = validationFields[j];
			var elementForClearing = fieldElements[fieldForClearing.id];
			if (elementForClearing && elementForClearing.removeAttribute) {
				elementForClearing.removeAttribute('aria-describedby');
			}
		}

		// Validate fields with early exit
		for (var i = 0; i < validationFields.length; i++) {
			var field = validationFields[i];
			var input = fieldElements[field.id];
			if (!input) continue;

			var isValid = validateField(input, field.type);
			if (!isValid) {
				showValidationError(formElements.errorDiv, field.name, input, formInstanceId);
				return;
			}
		}

		// Transition directly to step 2 (geolocation handled by modern UI controller)
		transitionToStep2(formElements, formInstanceId);
	}

	function validateField(input, type) {
		if (!input) return false;
		try {
			// Use checkValidity when available
			if (typeof input.checkValidity === 'function' && !input.checkValidity()) {
				return false;
			}
			switch (type) {
				case 'text':
					return input.value && input.value.trim() !== '';
				case 'select':
					return input.value !== '';
				case 'checkbox':
					return input.checked;
				default:
					return true;
			}
		} catch (e) {
			secureLog('error', CONFIG.LOG_PREFIX, 'Validation error', input.id || 'unknown');
			return false;
		}
	}

	function showValidationError(errorDiv, fieldName, input, formInstanceId) {
		if (errorDiv) {
			// Sanitize field name to prevent XSS
			var safeFieldName = sanitizeForLog(fieldName);
			errorDiv.textContent = 'Please complete the "' + safeFieldName + '" field.';
			errorDiv.style.display = 'block';
			// Don't set ID here - should be set during initialization
		}
		
		if (input && input.focus) {
			input.focus();
			if (input.setAttribute && errorDiv && errorDiv.id) {
				input.setAttribute('aria-describedby', errorDiv.id);
			}
		}
	}



	function transitionToStep2(formElements, formInstanceId) {
		formElements.step1.style.display = 'none';
		formElements.step2.style.display = 'block';

		// Safe heading focus
		var step2Heading = document.getElementById('starmus_audioRecorderHeading_' + formInstanceId);
		if (step2Heading) {
			step2Heading.setAttribute('tabindex', '-1');
			if (step2Heading.focus) step2Heading.focus();
		}

		initializeRecorder(formInstanceId);
	}

	function initializeRecorder(instanceId) {
		if (typeof window.StarmusAudioRecorder !== 'undefined' && 
			typeof window.StarmusAudioRecorder.init === 'function') {
			
			var initPromise = window.StarmusAudioRecorder.init({ formInstanceId: instanceId });
			
			// Handle both Promise and non-Promise returns for compatibility
			if (initPromise && typeof initPromise.then === 'function') {
				initPromise.then(function(success) {
					if (success && CONFIG.DEBUG_MODE) {
						secureLog('log', CONFIG.LOG_PREFIX, 'Recorder module initialized for', instanceId);
					}
				}).catch(function(error) {
					secureLog('error', CONFIG.LOG_PREFIX, 'Recorder initialization failed', error.message || error);
					showUserMessage(instanceId, 'Audio recorder failed to initialize. Please refresh the page.', 'error');
				});
			} else {
				// Handle non-Promise return with error checking
				if (!initPromise) {
					secureLog('error', CONFIG.LOG_PREFIX, 'Recorder initialization returned falsy value');
					showUserMessage(instanceId, 'Audio recorder failed to initialize. Please refresh the page.', 'error');
				}
			}
		} else {
			secureLog('error', CONFIG.LOG_PREFIX, 'StarmusAudioRecorder module not available');
			showUserMessage(instanceId, 'Audio recorder is not available. Please refresh the page.', 'error');
		}
	}

	function handleFormSubmit(formElements, formInstanceId) {
		if (typeof window.StarmusAudioRecorder !== 'undefined' && 
			typeof window.StarmusAudioRecorder.submit === 'function') {
			
			if (formElements.submitBtn) formElements.submitBtn.disabled = true;
			if (formElements.loaderDiv) {
				formElements.loaderDiv.classList.remove('starmus_visually_hidden');
			}

			var submitPromise = window.StarmusAudioRecorder.submit(formInstanceId);
			
			// Handle both Promise and non-Promise returns
			if (submitPromise && typeof submitPromise.then === 'function') {
				submitPromise.then(function(result) {
					handleSubmitResult(result, formElements, formInstanceId);
				}).catch(function(error) {
					secureLog('error', CONFIG.LOG_PREFIX, 'Submit failed', error.message || error);
					handleSubmitError(formElements, formInstanceId, error);
				});
			} else {
				// Fallback for non-Promise return with longer timeout
				setTimeout(function() {
					handleSubmitResult(submitPromise, formElements, formInstanceId);
				}, 500);
			}
		} else {
			showUserMessage(formInstanceId, 'Submission handler is not available. Please contact support.', 'error');
		}
	}

	function handleSubmitResult(result, formElements, formInstanceId) {
		if (formElements.loaderDiv) {
			formElements.loaderDiv.classList.add('starmus_visually_hidden');
		}

		if (result && result.success) {
			if (result.redirectUrl) {
				// Validate redirect URL to prevent open redirect attacks
				if (isValidRedirectUrl(result.redirectUrl)) {
					window.location.href = result.redirectUrl;
				} else {
					secureLog('warn', CONFIG.LOG_PREFIX, 'Invalid redirect URL blocked', result.redirectUrl);
					showUserMessage(formInstanceId, 'Successfully submitted!', 'success');
				}
			} else {
				showUserMessage(formInstanceId, 'Successfully submitted!', 'success');
				var form = getCachedElement(formInstanceId);
				if (form && form.reset) form.reset();
				
				if (typeof window.StarmusAudioRecorder !== 'undefined' && 
					typeof window.StarmusAudioRecorder.cleanup === 'function') {
					window.StarmusAudioRecorder.cleanup(formInstanceId);
				}
			}
		} else {
			handleSubmitError(formElements, formInstanceId, new Error('Invalid result'));
		}
	}

	function handleSubmitError(formElements, formInstanceId, error) {
		if (formElements.submitBtn) formElements.submitBtn.disabled = false;
		if (formElements.loaderDiv) {
			formElements.loaderDiv.classList.add('starmus_visually_hidden');
		}
		
		// Provide more specific error messages when possible
		var errorMessage = 'Submission failed. Please try again.';
		if (error && error.message) {
			secureLog('error', CONFIG.LOG_PREFIX, 'Submit error details', error.message);
			// Don't expose technical details to users for security
		}
		
		showUserMessage(formInstanceId, errorMessage, 'error');
	}

	// Initialize the module
	initializeWhenReady();

})();