import test from 'node:test';
import assert from 'node:assert/strict';

import { createStore } from '../src/js/starmus-state-store.js';

const EXPECTED_TOP_LEVEL_KEYS = [
  'instanceId',
  'tier',
  'status',
  'step',
  'error',
  'env',
  'source',
  'calibration',
  'recorder',
  'submission'
];

const EXPECTED_SOURCE_METADATA_KEYS = [
  'duration',
  'mimeType',
  'fileSize'
];

test('store initial state schema matches expected top-level shape', () => {
  const state = createStore().getState();
  const keys = Object.keys(state).sort();
  assert.deepEqual(keys, EXPECTED_TOP_LEVEL_KEYS.sort());
});

test('store source metadata schema matches expected keys', () => {
  const state = createStore().getState();
  const metadataKeys = Object.keys(state.source.metadata).sort();
  assert.deepEqual(metadataKeys, EXPECTED_SOURCE_METADATA_KEYS.sort());
});

test('critical nested state paths exist', () => {
  const state = createStore().getState();
  assert.ok(state.env && typeof state.env === 'object');
  assert.ok(state.source && typeof state.source === 'object');
  assert.ok(state.calibration && typeof state.calibration === 'object');
  assert.ok(state.recorder && typeof state.recorder === 'object');
  assert.ok(state.submission && typeof state.submission === 'object');
});
