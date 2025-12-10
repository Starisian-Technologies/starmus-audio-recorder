/**
 * @file starmus-metadata-auto.js
 * @version 1.2.0
 * @description Corrected metadata synchronizer:
 *   - canonical field list (no dynamic clears)
 *   - no optional chaining (legacy compat)
 *   - removes redundant user agent
 *   - prunes nested objects safely
 *   - aligns to reducer shape without phantom fields
 */

'use strict';

// ---------------------------------------------------------------------------
// CONFIG: CANONICAL FIELD LIST
// ---------------------------------------------------------------------------

const METADATA_FIELDS = [
  'starmus_title',
  'starmus_language',
  'starmus_recording_type',
  'audio_file_type',
  'agreement_to_terms',
  '_starmus_calibration',
  '_starmus_env',
  'first_pass_transcription',
  'recording_metadata',
  'waveform_json',
  'session_date',
  'session_start_time',
  'session_end_time',
  'location',
  'gps_coordinates',
  'contributor_id',
  'interviewers_recorders',
  'recording_equipment',
  'audio_files_originals',
  'media_condition_notes',
  'related_consent_agreement',
  'usage_restrictions_rights',
  'audio_quality_score',
  'access_level',
  'device'
];

// ---------------------------------------------------------------------------
// UTILS
// ---------------------------------------------------------------------------

function get(obj, key, fallback) {
  return obj && obj[key] != null ? obj[key] : fallback;
}

/**
 * Deep pruning helper — removes runtime-only keys from objects that would
 * otherwise bloat form submissions or IndexedDB clones on Android.
 */
function prune(obj, removeKeys) {
  if (!obj || typeof obj !== 'object') return obj;
  const copy = Array.isArray(obj) ? obj.slice() : Object.assign({}, obj);
  removeKeys.forEach(k => { if (k in copy) delete copy[k]; });
  return copy;
}

// ---------------------------------------------------------------------------
// CORE SERIALIZER
// ---------------------------------------------------------------------------

function buildMetadataMap(state) {
  const now = new Date();
  const iso = now.toISOString();
  const date = iso.split('T')[0];

  // SAFE ACCESSORS, NO OPTIONAL CHAINING
  const source = get(state, 'source', {});
  const submission = get(state, 'submission', {});
  const user = get(state, 'user', {});
  const env = get(state, 'env', {});
  const calibration = get(state, 'calibration', {});
  const recorder = get(state, 'recorder', {});

  return {
    starmus_title: get(source, 'title', ''),
    starmus_language: get(source, 'language', ''),
    starmus_recording_type: get(source, 'type', ''),

    // BLOB TYPE ALIGNED WITH REDUCER — NO phantom field
    audio_file_type: submission.blob && submission.blob.type ? submission.blob.type : 'audio/webm',

    agreement_to_terms: user.agreedToTerms ? '1' : '0',

    // PRUNE noisy, runtime calibration/env keys
    _starmus_calibration: prune(calibration, ['volumePercent', 'phase']),
    _starmus_env: prune(env, ['build', 'debug', 'flags']),

    first_pass_transcription: get(source, 'transcript', ''),
    recording_metadata: prune(get(source, 'metadata', {}), ['debug', 'transient']),

    // Waveform JSON may be large — prune raw PCM/peaks arrays
    waveform_json: prune(get(source, 'waveform', {}), ['peaks', 'pcm']),

    session_date: date,
    session_start_time: get(recorder, 'startTime', iso),
    session_end_time: get(recorder, 'endTime', iso),

    location: get(source, 'location', ''),
    gps_coordinates: get(source, 'gps', ''),

    contributor_id: get(user, 'id', ''),
    interviewers_recorders: get(source, 'interviewers', ''),
    recording_equipment: get(source, 'equipment', ''),

    audio_files_originals: get(source, 'originals', []),
    media_condition_notes: get(source, 'conditionNotes', ''),
    related_consent_agreement: get(source, 'consent', ''),
    usage_restrictions_rights: get(source, 'usageRights', ''),

    audio_quality_score: get(submission, 'qualityScore', ''),
    access_level: get(submission, 'accessLevel', ''),

    // SINGLE user agent field (no duplication)
    device: navigator.userAgent
  };
}

// ---------------------------------------------------------------------------
// FORM POPULATION
// ---------------------------------------------------------------------------

function populateHiddenFields(map, formEl) {
  METADATA_FIELDS.forEach((key) => {
    const raw = map[key];
    let input = formEl.querySelector('input[name="' + key + '"]');

    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = key;
      formEl.appendChild(input);
    }

    if (raw == null) {
      input.value = '';
    } else if (typeof raw === 'string' || typeof raw === 'number' || typeof raw === 'boolean') {
      input.value = String(raw);
    } else {
      try {
        input.value = JSON.stringify(raw);
      } catch (e) {
        console.warn('[StarmusMetadataAuto] Serialize fail for', key, e.message);
        input.value = '';
      }
    }
  });
}

// ---------------------------------------------------------------------------
// CLEARING
// ---------------------------------------------------------------------------

function clearHiddenFields(formEl) {
  METADATA_FIELDS.forEach((key) => {
    const input = formEl.querySelector('input[name="' + key + '"]');
    if (input && formEl.contains(input)) {
      input.parentNode.removeChild(input);
    }
  });
}

// ---------------------------------------------------------------------------
// VALIDATION
// ---------------------------------------------------------------------------

function validateMetadata(map, required) {
  const missing = [];
  required.forEach((key) => {
    const val = map[key];
    if (val == null || (typeof val === 'string' && val.trim() === '') || (Array.isArray(val) && val.length === 0)) {
      missing.push(key);
    }
  });
  return { valid: missing.length === 0, missing };
}

// ---------------------------------------------------------------------------
// HOOK INIT
// ---------------------------------------------------------------------------

function initAutoMetadata(store, formEl, options) {
  if (!store || typeof store.getState !== 'function' || typeof store.subscribe !== 'function') {
    console.warn('[StarmusMetadataAuto] Invalid store');
    return;
  }
  if (!(formEl instanceof HTMLFormElement)) {
    console.warn('[StarmusMetadataAuto] Invalid form');
    return;
  }

  const cfg = options || {};
  const triggers = Array.isArray(cfg.trigger) ? cfg.trigger : [cfg.trigger || 'ready_to_submit'];
  const clearOn = Array.isArray(cfg.clearOn) ? cfg.clearOn : [cfg.clearOn || 'reset'];
  const required = Array.isArray(cfg.requiredFields) ? cfg.requiredFields : [];

  let lastStatus = null;

  function handle() {
    const state = store.getState();
    const status = state.status;

    if (clearOn.indexOf(status) !== -1) clearHiddenFields(formEl);

    if (status !== lastStatus && triggers.indexOf(status) !== -1) {
      const map = buildMetadataMap(state);
      if (required.length) {
        const { valid, missing } = validateMetadata(map, required);
        if (!valid) console.warn('[StarmusMetadataAuto] Missing:', missing);
      }
      populateHiddenFields(map, formEl);
    }
    lastStatus = status;
  }

  const unsubscribe = store.subscribe(handle);
  handle();
  return unsubscribe;
}

// ---------------------------------------------------------------------------
// EXPORTS
// ---------------------------------------------------------------------------

export {
  initAutoMetadata,
  buildMetadataMap,
  populateHiddenFields,
  clearHiddenFields,
  validateMetadata
};

export default { initAutoMetadata };

// Global bridge for WP
if (typeof window !== 'undefined') {
  window.StarmusMetadataAuto = { initAutoMetadata };
}
