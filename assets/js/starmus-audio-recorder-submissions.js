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
 * @description This script handles form submissions for the Starmus Audio Recorder,
 * including multi-step form logic and REST API uploads.
 */

// eslint-disable-next-line no-redeclare
/* global StarmusAudioRecorder */
document.addEventListener('DOMContentLoaded', () => {
	'use strict';

	const CONFIG = {
		LOG_PREFIX: '[Starmus Submissions]',
		DEBUG_MODE:
			typeof window.STARMUS_DEBUG !== 'undefined'
				? window.STARMUS_DEBUG
				: false,
	};

	// --- Form Initialization and Handling ---
	const recorderWrappers = document.querySelectorAll(
		'[data-enabled-recorder]'
	);
	if (recorderWrappers.length === 0) {
		return;
	}

	recorderWrappers.forEach((wrapper) => {
		const formInstanceId = wrapper.id.substring(
			'starmus_audioWrapper_'.length
		);
		const formElement = document.getElementById(formInstanceId);

		if (!formElement) {
			console.error(
				CONFIG.LOG_PREFIX,
				`Form element not found for instance ID: ${formInstanceId}.`
			);
			return;
		}

		const step1 = formElement.querySelector(
			`#starmus_step_1_${formInstanceId}`
		);
		const step2 = formElement.querySelector(
			`#starmus_step_2_${formInstanceId}`
		);
		const continueBtn = formElement.querySelector(
			`#starmus_continue_btn_${formInstanceId}`
		);

		// Fallback for themes that might not have the multi-step structure.
		if (!step1 || !step2 || !continueBtn) {
			console.error(
				CONFIG.LOG_PREFIX,
				'Multi-step form elements are missing. Defaulting to show recorder.'
			);
			if (step1) step1.style.display = 'none';
			if (step2) step2.style.display = 'block';
			initializeRecorder(formInstanceId);
			return;
		}

		continueBtn.addEventListener('click', function (event) {
			event.preventDefault();
			const errorMessageDiv = formElement.querySelector(
				`#starmus_step_1_error_${formInstanceId}`
			);
			const statusMessageDiv = formElement.querySelector(
				`#starmus_step_1_status_${formInstanceId}`
			);

			const fieldsToValidate = [
				{
					id: `audio_title_${formInstanceId}`,
					name: 'Title',
					type: 'text',
				},
				{
					id: `language_${formInstanceId}`,
					name: 'Language',
					type: 'select',
				},
				{
					id: `recording_type_${formInstanceId}`,
					name: 'Recording Type',
					type: 'select',
				},
				{
					id: `audio_consent_${formInstanceId}`,
					name: 'Consent',
					type: 'checkbox',
				},
			];

			errorMessageDiv.style.display = 'none';
			errorMessageDiv.textContent = '';
			fieldsToValidate.forEach((field) =>
				document
					.getElementById(field.id)
					?.removeAttribute('aria-describedby')
			);

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

		/**
		 * Attempts to capture geolocation and then proceeds to the next step.
		 */
		function captureGeolocationAndProceed() {
			if ('geolocation' in navigator) {
				navigator.geolocation.getCurrentPosition(
					(position) => {
						const latField = formElement.querySelector(
							`#gps_latitude_${formInstanceId}`
						);
						const lonField = formElement.querySelector(
							`#gps_longitude_${formInstanceId}`
						);
						if (latField)
							latField.value = position.coords.latitude;
						if (lonField)
							lonField.value = position.coords.longitude;
						if (CONFIG.DEBUG_MODE)
							console.log(
								CONFIG.LOG_PREFIX,
								'GPS Location captured.'
							);
						transitionToStep2();
					},
					(error) => {
						console.warn(
							CONFIG.LOG_PREFIX,
							`Geolocation error (${error.code}): ${error.message}`
						);
						// Proceed even if geolocation fails.
						transitionToStep2();
					}
				);
			} else {
				console.log(CONFIG.LOG_PREFIX, 'Geolocation is not available.');
				transitionToStep2();
			}
		}

		/**
		 * Hides step 1 and shows step 2, then initializes the audio recorder.
		 */
		function transitionToStep2() {
			const statusMessageDiv = formElement.querySelector(
				`#starmus_step_1_status_${formInstanceId}`
			);
			if (statusMessageDiv) statusMessageDiv.style.display = 'none';
			if (continueBtn) continueBtn.disabled = false;

			step1.style.display = 'none';
			step2.style.display = 'block';

			const step2Heading = formElement.querySelector(
				`#sparxstar_audioRecorderHeading_${formInstanceId}`
			);
			if (step2Heading) {
				step2Heading.setAttribute('tabindex', '-1');
				step2Heading.focus();
			}
			initializeRecorder(formInstanceId);
		}

		/**
		 * Initializes the StarmusAudioRecorder module for this specific form instance.
		 * @param {string} instanceId - The unique ID of the form instance.
		 */
		function initializeRecorder(instanceId) {
			// This check prevents errors if the StarmusAudioRecorder script fails to load.
			if (typeof StarmusAudioRecorder?.init === 'function') {
				StarmusAudioRecorder.init({ formInstanceId: instanceId }).then(
					(success) => {
						if (success && CONFIG.DEBUG_MODE) {
							console.log(
								CONFIG.LOG_PREFIX,
								`Recorder module initialized for ${instanceId}.`
							);
						}
					}
				);
			} else {
				console.error(
					CONFIG.LOG_PREFIX,
					'StarmusAudioRecorder module is not available.'
				);
			}
		}

		formElement.addEventListener('submit', async (e) => {
			e.preventDefault();

			const submitButton = document.getElementById(
				`submit_button_${formInstanceId}`
			);
			const loaderDiv = document.getElementById(
				`sparxstar_loader_overlay_${formInstanceId}`
			);

			// This function will be defined in the recorder module and handles the upload.
			if (typeof StarmusAudioRecorder?.submit === 'function') {
				if (submitButton) submitButton.disabled = true;
				if (loaderDiv)
					loaderDiv.classList.remove('sparxstar_visually_hidden');

				const result = await StarmusAudioRecorder.submit(
					formInstanceId
				);

				if (loaderDiv)
					loaderDiv.classList.add('sparxstar_visually_hidden');

				if (result.success) {
					if (result.redirectUrl) {
						window.location.href = result.redirectUrl;
					} else {
						alert('Successfully submitted!');
						formElement.reset();
						if (
							typeof StarmusAudioRecorder?.cleanup === 'function'
						) {
							StarmusAudioRecorder.cleanup(formInstanceId);
						}
					}
				} else {
					// The recorder module's submit function should handle its own error messaging.
					if (submitButton) submitButton.disabled = false;
				}
			} else {
				alert(
					'Error: Submission handler is not available. Please contact support.'
				);
			}
		});
	}); // End of recorderWrappers.forEach
});