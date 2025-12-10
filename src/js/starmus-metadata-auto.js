/**
 * @file starmus-metadata-auto.js
 * @version 6.1.0-SAFE-SYNC
 * @description Syncs Store State to Hidden Form Fields automatically.
 *              Protects PHP-injected values from being wiped by empty JS state.
 */

'use strict';

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
    const stringValue = typeof value === 'object' ? JSON.stringify(value) : (value || '');
    
    // 3. SAFETY GUARD: 
    // If the input already has a value (injected by PHP), do NOT overwrite it 
    // with an empty/default JS value.
    if (input.value && input.value.trim() !== '' && (stringValue === '' || stringValue === '{}' || stringValue === '[]')) {
        return; 
    }

    // 4. Update only if changed
    if (input.value !== stringValue) {
        input.value = stringValue;
    }
}

export function initAutoMetadata(store, formEl, options) {
  if (!store || !formEl) {
    console.warn('[StarmusMetadata] Store or Form missing.');
    return;
  }

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
    updateField(formEl, '_starmus_env', env);

    // 3. Transcription
    const transcript = ((source.transcript || '') + ' ' + (source.interimTranscript || '')).trim();
    updateField(formEl, 'first_pass_transcription', transcript);

    // 4. Technical Metadata (New)
    if (source.metadata) {
        updateField(formEl, 'recording_metadata', source.metadata);
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