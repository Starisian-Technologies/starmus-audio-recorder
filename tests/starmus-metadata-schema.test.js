/**
 * @file starmus-metadata-schema.test.js
 * @description Hard-fail test ensuring reducer + metadata map never drift.
 * If this test fails, metadata serialization must be updated.
 */

import { buildMetadataMap } from '../src/js/starmus-metadata-auto.js';
import { DEFAULT_INITIAL_STATE } from '../src/js/starmus-state-store.js';

// --- CANONICAL SCHEMA
const EXPECTED_KEYS = [
  'starmus_title',
  'starmus_language',
  'starmus_recording_type',
  'audio_file_type',
  'agreement_to_terms',
  '_starmus_calibration',
  '_starmus_env',
  'recording_metadata',
  'waveform_json',
  'session_date',
  'session_start_time',
  'session_end_time',
  'location',
  'gps_coordinates',
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

test('metadata schema matches expected key list', () => {
  const map = buildMetadataMap(DEFAULT_INITIAL_STATE);
  const keys = Object.keys(map);

  const missing = EXPECTED_KEYS.filter(k => keys.indexOf(k) === -1);
  const extras = keys.filter(k => EXPECTED_KEYS.indexOf(k) === -1);

  if (missing.length || extras.length) {
    throw new Error(
      [
        'METADATA SCHEMA DRIFT DETECTED',
        missing.length ? 'Missing keys: ' + missing.join(', ') : '',
        extras.length ? 'Unexpected keys: ' + extras.join(', ') : ''
      ].filter(Boolean).join('\n')
    );
  }
});

test('all metadata keys map to valid state paths', () => {
  const map = buildMetadataMap(DEFAULT_INITIAL_STATE);
  
  for (const [key, value] of Object.entries(map)) {
    if (value === null || value === undefined) {
      throw new Error(`Metadata key '${key}' maps to null/undefined - check state path validity`);
    }
  }
});

test('state store provides all expected paths', () => {
  // Verify critical state paths exist in DEFAULT_INITIAL_STATE
  const requiredStatePaths = [
    'source.title',
    'source.language', 
    'source.recording_type',
    'calibration',
    'env',
    'transcript'
  ];

  for (const path of requiredStatePaths) {
    const pathParts = path.split('.');
    let current = DEFAULT_INITIAL_STATE;
    
    for (const part of pathParts) {
      if (!current || typeof current !== 'object' || !(part in current)) {
        throw new Error(`Required state path '${path}' not found in DEFAULT_INITIAL_STATE`);
      }
      current = current[part];
    }
  }
});