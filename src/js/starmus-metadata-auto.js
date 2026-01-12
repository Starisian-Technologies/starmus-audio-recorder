/**
 * @file starmus-metadata-auto.js
 * @version 6.1.0-SAFE-SYNC
 * @description Syncs Store State to Hidden Form Fields automatically.
 *              Protects PHP-injected values from being wiped by empty JS state.
 */

'use strict';

/**
 * Updates or creates a hidden form field with safety guards to protect PHP-injected values.
 * Prevents empty JavaScript state from overwriting server-side data.
 *
 * @function updateField
 * @param {HTMLFormElement} form - Form element containing the input fields
 * @param {string} name - Name attribute for the input field
 * @param {*} value - Value to set (objects are JSON.stringify'd)
 * @returns {void}
 *
 * @important If input.value exists (server-side injection), it is protected
 * against 'Zero-ing out'. Updates only occur if stringValue is
 * non-empty AND different from current value.
 *
 * @description Safety features:
 * - Creates hidden input if it doesn't exist
 * - Converts objects to JSON strings automatically
 * - Protects existing PHP-injected values from being overwritten by empty JS values
 * - Only updates if the value has actually changed
 * - Skips updates when trying to overwrite non-empty values with empty ones
 *
 * @example
 * // Update calibration data
 * updateField(form, '_starmus_calibration', { gain: 0.8, level: 'good' });
 *
 * @example
 * // Create new transcript field
 * updateField(form, 'session_date', '2024-01-01');
 *
 * @example
 * // Protected update - won't overwrite existing PHP value with empty string
 * updateField(form, 'existing_field', ''); // Skipped if field has value
 */
function updateField(form, name, value) {
	let input = form.querySelector(`input[name="${name}"]`);

	// 1. Create input if it doesn't exist
	if (!input) {
		input = document.createElement('input');
		input.type = 'hidden';
		input.name = name;
		form.appendChild(input);
	}

	// 2. Prepare the string value for the form
	const stringValue = typeof value === 'object' ? JSON.stringify(value) : value || '';

	// 3. SAFETY GUARD:
	// If the input already has a value (injected by PHP), do NOT overwrite it
	// with an empty/default JS value.
	if (
		input.value &&
    input.value.trim() !== '' &&
    (stringValue === '' || stringValue === '{}' || stringValue === '[]')
	) {
		return;
	}

	// 4. Update only if changed
	if (input.value !== stringValue) {
		input.value = stringValue;
	}
}

/**
 * Initializes automatic metadata synchronization from store state to form fields.
 * Creates a bidirectional sync that updates hidden form fields whenever the store state changes.
 * Protects server-side injected values from being overwritten by empty client-side state.
 *
 * @function initAutoMetadata
 * @exports initAutoMetadata
 * @param {Object} store - Redux-style store with getState, subscribe methods
 * @param {function} store.getState - Function to get current application state
 * @param {function} store.subscribe - Function to subscribe to state changes
 * @param {HTMLFormElement} formEl - Form element to sync metadata fields to
 * @param {Object} [options] - Configuration options (currently unused)
 * @returns {Function} A cleanup function. Call this during component
 * unmounting or page transitions to prevent the sync listener from
 * firing on a non-existent form.
 *
 * @description Synchronized fields:
 * 1. **_starmus_calibration** - Microphone calibration data (gain, speechLevel, message)
 * 2. **_starmus_env** - UEC environment data (browser, device, network info)
 * 3. **Runtime Metadata** - Processing configuration and environment
 * 4. **recording_metadata** - Technical recording metadata (sample rate, format, etc.)
 * 5. **waveform_json** - Audio waveform data for visualization
 *
 * @description State mapping:
 * - `state.calibration` → `_starmus_calibration` (when calibration.complete is true)
 * - `state.env` → `_starmus_env` (UEC browser/device data)
 * - `state.calibration` data → `_starmus_calibration`
 * - `state.source.metadata` → `recording_metadata` (technical audio data)
 * - `state.source.waveform` → `waveform_json` (visualization data)
 *
 * @example
 * // Basic initialization
 * const unsubscribe = initAutoMetadata(store, document.querySelector('form'));
 *
 * @example
 * // With cleanup
 * const cleanup = initAutoMetadata(store, formElement);
 * // Later: cleanup() to stop synchronization
 *
 * @example
 * // State structure expected:
 * const state = {
 *   calibration: { complete: true, gain: 0.8, speechLevel: 'good' },
 *   env: { browser: 'Chrome', device: 'mobile' },
 *   source: {
 *     transcript: 'Hello world',
 *     interimTranscript: 'how are',
 *     metadata: { sampleRate: 44100, format: 'webm' },
 *     waveform: [0.1, 0.2, 0.3]
 *   }
 * };
 */
export function initAutoMetadata(store, formEl, _options) {
	if (!store || !formEl) {
		console.warn('[StarmusMetadata] Store or Form missing.');
		return;
	}

	/**
   * Synchronizes current store state to form fields.
   * Called initially and whenever store state changes.
   *
   * @function sync
   * @inner
   * @returns {void}
   */
	function sync() {
		const state = store.getState();
		const env = state.env || {};
		const cal = state.calibration || {};
		const source = state.source || {};

		// 1. Calibration Data
		const calData = cal.complete
			? {
				gain: cal.gain,
				speechLevel: cal.speechLevel,
				message: cal.message,
			}
			: {};
		updateField(formEl, '_starmus_calibration', calData);

		// 2. UEC / Environment Data
		updateField(formEl, '_starmus_env', env);

		// 3. Technical Metadata (New)
		if (source.metadata) {
			updateField(formEl, 'recording_metadata', source.metadata);
		}

		// 4. Transcription (New)
		// Assuming transcript is stored in source.transcript based on file search
		if (source.transcript) {
			updateField(formEl, 'transcription', source.transcript);
		}

		// 4b. Transcription JSON (Timestamps/Confidence)
		if (source.transcriptJson) {
			updateField(formEl, 'transcription_json', source.transcriptJson);
		}

		// 5. Waveform (Optional)
		if (source.waveform) {
			updateField(formEl, 'waveform_json', source.waveform);
		}
	}

	// Initial sync + Subscribe
	sync();
	return store.subscribe(sync);
}
