import test from 'node:test';
import assert from 'node:assert/strict';

import { createStore } from '../src/js/starmus-state-store.js';

test('starmus/init sets idle status and merges payload', () => {
  const store = createStore();
  store.dispatch({
    type: 'starmus/init',
    payload: { tier: 'A', instanceId: 'rec-1' }
  });

  const state = store.getState();
  assert.equal(state.status, 'idle');
  assert.equal(state.tier, 'A');
  assert.equal(state.instanceId, 'rec-1');
});

test('starmus/error appends environment errors with severity', () => {
  const store = createStore({ env: { errors: [] } });
  store.dispatch({
    type: 'starmus/error',
    error: { code: 'FAIL', message: 'failed', retryable: false }
  });

  const state = store.getState();
  assert.equal(state.error.code, 'FAIL');
  assert.equal(state.env.errors.length, 1);
  assert.equal(state.env.errors[0].severity, 'hard');
  assert.equal(state.env.errors[0].code, 'FAIL');
});

test('starmus/recording-available captures blob metadata and recorder duration', () => {
  const store = createStore({ recorder: { duration: 12.5, amplitude: 0, isPlaying: false, isPaused: false } });
  const blob = new Blob(['audio'], { type: 'audio/webm' });

  store.dispatch({
    type: 'starmus/recording-available',
    payload: { blob, fileName: 'recording.webm' }
  });

  const state = store.getState();
  assert.equal(state.status, 'ready_to_submit');
  assert.equal(state.source.kind, 'blob');
  assert.equal(state.source.fileName, 'recording.webm');
  assert.equal(state.source.metadata.duration, 12.5);
  assert.equal(state.source.metadata.mimeType, 'audio/webm');
  assert.equal(state.source.metadata.fileSize, blob.size);
});

test('starmus/file-attached sets source file metadata', () => {
  const store = createStore();
  const file = { name: 'upload.wav', type: 'audio/wav', size: 4096 };

  store.dispatch({
    type: 'starmus/file-attached',
    file
  });

  const state = store.getState();
  assert.equal(state.status, 'ready_to_submit');
  assert.equal(state.source.kind, 'file');
  assert.equal(state.source.file, file);
  assert.equal(state.source.fileName, 'upload.wav');
  assert.equal(state.source.metadata.mimeType, 'audio/wav');
  assert.equal(state.source.metadata.fileSize, 4096);
});

test('starmus/reset preserves instance/env/tier while resetting flow state', () => {
  const store = createStore({
    instanceId: 'x-1',
    tier: 'B',
    env: { device: { model: 'low-end' }, browser: {}, network: {}, identifiers: {}, errors: [] }
  });

  store.dispatch({ type: 'starmus/mic-start' });
  store.dispatch({ type: 'starmus/reset' });

  const state = store.getState();
  assert.equal(state.instanceId, 'x-1');
  assert.equal(state.tier, 'B');
  assert.equal(state.status, 'idle');
  assert.equal(state.step, 1);
  assert.equal(state.source.kind, null);
  assert.deepEqual(state.env.device, { model: 'low-end' });
});

test('subscribe/unsubscribe fires listeners exactly while subscribed', () => {
  const store = createStore();
  let calls = 0;

  const unsubscribe = store.subscribe(() => {
    calls += 1;
  });

  store.dispatch({ type: 'starmus/mic-start' });
  unsubscribe();
  store.dispatch({ type: 'starmus/mic-stop' });

  assert.equal(calls, 1);
});
