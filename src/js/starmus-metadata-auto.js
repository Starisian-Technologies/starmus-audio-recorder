/**
 * @file starmus-metadata-auto.js
 * @version 6.0.0-SYNC-FIX
 * @description Syncs Store State to Hidden Form Fields automatically.
 */

'use strict';

// Fields we must keep in sync with the PHP handler
const METADATA_FIELDS = [
  '_starmus_calibration',     // JSON: Gain, Noise Floor
  '_starmus_env',            // JSON: UEC Data (Device, Network)
  'first_pass_transcription', // String: Speech-to-text
  'recording_metadata',       // JSON: Extra
  'waveform_json'             // JSON: Peaks data
];

function updateField(form, name, value) {
    let input = form.querySelector(`input[name="${name}"]`);
    if (!input) {
        // Create if missing
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        form.appendChild(input);
    }
    
    const stringValue = typeof value === 'object' ? JSON.stringify(value) : (value || '');
    if (input.value !== stringValue) {
        input.value = stringValue;
    }
}

export function initAutoMetadata(store, formEl, options) {
  if (!store || !formEl) return;

  function sync() {
    const state = store.getState();
    const env = state.env || {};
    const cal = state.calibration || {};
    const source = state.source || {};

    // 1. Calibration Data
    const calData = cal.complete ? {
        gain: cal.gain,
        speechLevel: cal.speechLevel,
        message: cal.message
    } : {};
    updateField(formEl, '_starmus_calibration', calData);

    // 2. UEC / Environment Data
    // We send the whole env object which includes UEC technical data
    updateField(formEl, '_starmus_env', env);

    // 3. Transcription
    // Combine final + interim for the "first pass" field
    const transcript = ((source.transcript || '') + ' ' + (source.interimTranscript || '')).trim();
    updateField(formEl, 'first_pass_transcription', transcript);

    // 4. Waveform (Optional)
    if (source.waveform) {
        updateField(formEl, 'waveform_json', source.waveform);
    }
  }

  // Sync immediately and on every store update
  sync();
  return store.subscribe(sync);
}