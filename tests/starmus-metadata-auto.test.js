import test from 'node:test';
import assert from 'node:assert/strict';

import { initAutoMetadata } from '../src/js/starmus-metadata-auto.js';

function createMockForm(initialValues = {}) {
  const inputs = new Map();

  const form = {
    querySelector(selector) {
      const match = selector.match(/input\[name="([^"]+)"\]/);
      if (!match) {
        return null;
      }
      return inputs.get(match[1]) || null;
    },
    appendChild(input) {
      inputs.set(input.name, input);
      return input;
    },
    getInput(name) {
      return inputs.get(name) || null;
    }
  };

  Object.entries(initialValues).forEach(([name, value]) => {
    form.appendChild({ type: 'hidden', name, value });
  });

  return form;
}

function createMockStore(initialState) {
  let state = initialState;
  const listeners = [];

  return {
    getState() {
      return state;
    },
    subscribe(fn) {
      listeners.push(fn);
      return () => {
        const index = listeners.indexOf(fn);
        if (index >= 0) {
          listeners.splice(index, 1);
        }
      };
    },
    emit(nextState) {
      state = nextState;
      listeners.forEach(listener => listener());
    }
  };
}

function withMockDocument(fn) {
  const originalDocument = globalThis.document;
  globalThis.document = {
    createElement(tag) {
      return { type: tag, name: '', value: '' };
    }
  };

  try {
    fn();
  } finally {
    globalThis.document = originalDocument;
  }
}

test('initAutoMetadata syncs calibration/env/metadata/transcription/waveform fields', () => {
  withMockDocument(() => {
    const form = createMockForm();
    const store = createMockStore({
      calibration: { complete: true, gain: 1.2, speechLevel: 0.7, message: 'ready' },
      env: { network: { type: 'slow-2g' } },
      source: {
        metadata: { duration: 10, mimeType: 'audio/webm', fileSize: 1000 },
        transcript: 'hello',
        transcriptJson: { confidence: 0.9 },
        waveform: [0, 1, 0]
      }
    });

    initAutoMetadata(store, form);

    assert.equal(form.getInput('_starmus_calibration').value, JSON.stringify({ gain: 1.2, speechLevel: 0.7, message: 'ready' }));
    assert.equal(form.getInput('_starmus_env').value, JSON.stringify({ network: { type: 'slow-2g' } }));
    assert.equal(form.getInput('recording_metadata').value, JSON.stringify({ duration: 10, mimeType: 'audio/webm', fileSize: 1000 }));
    assert.equal(form.getInput('transcription').value, 'hello');
    assert.equal(form.getInput('transcription_json').value, JSON.stringify({ confidence: 0.9 }));
    assert.equal(form.getInput('waveform_json').value, JSON.stringify([0, 1, 0]));
  });
});

test('initAutoMetadata does not overwrite non-empty server-injected values with empty defaults', () => {
  withMockDocument(() => {
    const form = createMockForm({
      _starmus_env: '{"server":"keep"}',
      _starmus_calibration: '{"gain":2}'
    });

    const store = createMockStore({
      calibration: { complete: false, gain: 0, speechLevel: 0, message: '' },
      env: {},
      source: {}
    });

    initAutoMetadata(store, form);

    assert.equal(form.getInput('_starmus_env').value, '{"server":"keep"}');
    assert.equal(form.getInput('_starmus_calibration').value, '{"gain":2}');
  });
});

test('cleanup unsubscribe stops further sync updates', () => {
  withMockDocument(() => {
    const form = createMockForm();
    const store = createMockStore({
      calibration: { complete: true, gain: 1, speechLevel: 1, message: 'one' },
      env: { browser: 'A' },
      source: { metadata: { duration: 1, mimeType: 'audio/webm', fileSize: 1 } }
    });

    const cleanup = initAutoMetadata(store, form);
    assert.equal(typeof cleanup, 'function');

    const firstValue = form.getInput('_starmus_env').value;
    cleanup();

    store.emit({
      calibration: { complete: true, gain: 2, speechLevel: 2, message: 'two' },
      env: { browser: 'B' },
      source: { metadata: { duration: 2, mimeType: 'audio/wav', fileSize: 2 } }
    });

    assert.equal(form.getInput('_starmus_env').value, firstValue);
  });
});
