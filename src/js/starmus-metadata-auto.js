/**
 * @file starmus-metadata-auto.js
 * @version 1.1.0
 * @description Automatically syncs app state metadata into hidden form fields on submission-ready,
 *   supports clearing on reset, validates required fields, and auto‑timestamps if recorder provides start/end times.
 */

'use strict';

/**
 * Populate hidden inputs for a data map inside a form.
 * @param {Object} dataMap — key: field name, value: string/number/object/array
 * @param {HTMLFormElement} formEl
 */
function populateHiddenFields(dataMap, formEl) {
  Object.keys(dataMap).forEach((key) => {
    const raw = dataMap[key];
    let input = formEl.querySelector(`input[name="${key}"]`);
    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = key;
      formEl.appendChild(input);
    }
    if (raw === undefined || raw === null) {
      input.value = '';
    } else if (typeof raw === 'string' || typeof raw === 'number' || typeof raw === 'boolean') {
      input.value = String(raw);
    } else {
      try {
        input.value = JSON.stringify(raw);
      } catch (e) {
        console.warn('[StarmusMetadataAuto] Could not serialize field', key, raw);
        input.value = '';
      }
    }
  });
}

/**
 * Remove all known fields from the form — useful on reset or re‑start.
 * @param {HTMLFormElement} formEl
 * @param {string[]} fieldNames
 */
function clearHiddenFields(formEl, fieldNames) {
  if (!formEl || !Array.isArray(fieldNames) || formEl.nodeType !== 1) {
    console.warn('[StarmusMetadataAuto] Invalid form element or field names for clearHiddenFields');
    return;
  }

  fieldNames.forEach((key) => {
    try {
      const input = formEl.querySelector(`input[name="${key}"]`);
      if (input && input.parentNode) {
        // Double-check the element is actually a child of the form
        if (formEl.contains(input)) {
          input.parentNode.removeChild(input);
          console.debug('[StarmusMetadataAuto] Removed field:', key);
        } else {
          console.debug('[StarmusMetadataAuto] Field not a child of form:', key);
        }
      }
    } catch (e) {
      console.warn('[StarmusMetadataAuto] Could not remove field', key, e.message);
    }
  });
}

/**
 * Build metadata map from full state.
 * Adjust mapping to reflect your actual store shape.
 * @param {Object} state
 */
function buildMetadataMap(state) {
  const now = new Date();
  const date = now.toISOString().split('T')[0];
  const time = now.toISOString();

  return {
    starmus_title: state.source?.title || '',
    starmus_language: state.source?.language || '',
    starmus_recording_type: state.source?.type || '',
    audio_file_type: state.submission?.blob?.type || 'audio/webm',
    agreement_to_terms: state.user?.agreedToTerms ? '1' : '0',
    _starmus_calibration: state.calibration || {},
    _starmus_env: state.env || {},
    first_pass_transcription: state.source?.transcript || '',
    recording_metadata: state.source?.metadata || {},
    waveform_json: state.source?.waveform || {},
    project_collection_id: state.submission?.collectionId || '',
    accession_number: state.submission?.accession || '',
    session_date: date,
    session_start_time: state.recorder?.startTime || time,
    session_end_time: state.recorder?.endTime || time,
    location: state.source?.location || '',
    gps_coordinates: state.source?.gps || '',
    contributor_id: state.user?.id || '',
    interviewers_recorders: state.source?.interviewers || '',
    recording_equipment: state.source?.equipment || '',
    audio_files_originals: state.source?.originals || [],
    media_condition_notes: state.source?.conditionNotes || '',
    related_consent_agreement: state.source?.consent || '',
    usage_restrictions_rights: state.source?.usageRights || '',
    access_level: state.submission?.accessLevel || '',
    audio_quality_score: state.submission?.qualityScore || '',
    mic_rest_adjustments: state.calibration?.adjustments || '',
    device: navigator.userAgent,
    user_agent: navigator.userAgent
  };
}

/**
 * Validate that required metadata keys are non-empty / non-null.
 * @param {Object} metadataMap
 * @param {string[]} requiredFields
 * @returns { { valid: boolean, missing: string[] } }
 */
function validateMetadata(metadataMap, requiredFields) {
  const missing = [];
  requiredFields.forEach((key) => {
    const val = metadataMap[key];
    if (
      val === undefined ||
      val === null ||
      (typeof val === 'string' && val.trim() === '') ||
      (Array.isArray(val) && val.length === 0)
    ) {
      missing.push(key);
    }
  });
  return { valid: missing.length === 0, missing };
}

/**
 * Auto‑hook initializer.
 * @param {Object} store — your state store (must support getState() and subscribe())
 * @param {HTMLFormElement} formEl — the form element to populate
 * @param {Object} [options]
 *   - trigger: string or array of strings: store.status values that trigger write (default: ['ready_to_submit'])
 *   - requiredFields: array of field names that must be present (default: [])
 *   - clearOn: string or array of strings: status values that trigger clearing metadata (default: ['reset'])
 */
function initAutoMetadata(store, formEl, options) {
  if (!store || typeof store.getState !== 'function' || typeof store.subscribe !== 'function') {
    console.warn('[StarmusMetadataAuto] Invalid store — cannot init auto metadata');
    return;
  }
  if (!(formEl instanceof HTMLFormElement)) {
    console.warn('[StarmusMetadataAuto] Invalid form element — must be HTMLFormElement');
    return;
  }
  const cfg = options || {};
  const triggers = Array.isArray(cfg.trigger) ? cfg.trigger : [cfg.trigger || 'ready_to_submit'];
  const clearOn = Array.isArray(cfg.clearOn) ? cfg.clearOn : [cfg.clearOn || 'reset'];
  const required = Array.isArray(cfg.requiredFields) ? cfg.requiredFields : [];

  let lastStatus = null;

  function handleChange() {
    const state = store.getState();
    const status = state.status;

    if (clearOn.includes(status)) {
      clearHiddenFields(formEl, Object.keys(buildMetadataMap(state)));
    }

    if (status !== lastStatus && triggers.includes(status)) {
      lastStatus = status;
      const metadata = buildMetadataMap(state);
      const { valid, missing } = required.length ? validateMetadata(metadata, required) : { valid: true, missing: [] };

      if (!valid) {
        console.warn('[StarmusMetadataAuto] Missing required metadata fields:', missing);
        // Optionally: you can block submission or alert user
      }

      populateHiddenFields(metadata, formEl);
    } else {
      lastStatus = status;
    }
  }

  const unsubscribe = store.subscribe(handleChange);
  // Run once now in case already in ready state
  handleChange();

  return unsubscribe;
}

// --- EXPORTS ---
export { initAutoMetadata, populateHiddenFields, buildMetadataMap, validateMetadata, clearHiddenFields };
export default { initAutoMetadata, populateHiddenFields, buildMetadataMap, validateMetadata, clearHiddenFields };

// Global bridge (optional)
if (typeof window !== 'undefined') {
  window.StarmusMetadataAuto = {
    initAutoMetadata,
    populateHiddenFields,
    buildMetadataMap,
    validateMetadata,
    clearHiddenFields
  };
}