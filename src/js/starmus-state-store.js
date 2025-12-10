/**
 * @file starmus-state-store.js
 * @version 6.1.0-METADATA-READY
 * @description Unified store. Captures technical metadata (duration/size) on stop.
 */

(function (global) {
  'use strict';

  var DEFAULT_INITIAL_STATE = {
    instanceId: null,
    tier: null,
    status: 'uninitialized',
    step: 1,
    error: null,
    // Schema-compliant Environment Object
    env: {
        device: {},
        browser: {},
        network: {},
        identifiers: {},
        errors: [] 
    },
    source: {
      kind: null,
      blob: null,
      file: null,
      fileName: '',
      transcript: '',
      interimTranscript: '',
      // ADDED: Container for technical specs
      metadata: {
          duration: 0,
          mimeType: '',
          fileSize: 0
      }
    },
    calibration: {
      phase: null,
      message: '',
      volumePercent: 0,
      complete: false,
      gain: 1.0,
      speechLevel: 0
    },
    recorder: {
      duration: 0,
      amplitude: 0,
      isPlaying: false,
      isPaused: false,
    },
    submission: {
      progress: 0,
      isQueued: false,
    },
  };

  function shallowClone(obj) {
    var out = {};
    for (var k in obj) if (obj.hasOwnProperty(k)) out[k] = obj[k];
    return out;
  }

  function merge(a, b) {
    var out = shallowClone(a);
    for (var k in b) if (b.hasOwnProperty(k)) out[k] = b[k];
    return out;
  }

  function reducer(state, action) {
    if (!action || !action.type) return state;

    if (!state.instanceId && action.payload && action.payload.instanceId) {
      state = merge(state, { instanceId: action.payload.instanceId });
    }

    switch (action.type) {
      case 'starmus/init':
        return merge(state, merge(action.payload || {}, { status: 'idle', error: null }));

      case 'starmus/env-update':
        var newEnv = merge(state.env, action.payload || {});
        if (!newEnv.errors) newEnv.errors = state.env.errors || [];
        return merge(state, { env: newEnv });

      case 'starmus/error':
        var errObj = action.error || action.payload;
        var currentErrors = (state.env && state.env.errors) ? state.env.errors.slice() : [];
        currentErrors.push({
            code: errObj.code || 'RUNTIME_ERROR',
            message: errObj.message || 'Unknown',
            timestamp: Date.now(),
            severity: errObj.retryable === false ? 'hard' : 'soft'
        });
        return merge(state, { error: errObj, env: merge(state.env, { errors: currentErrors }) });

      case 'starmus/tier-ready':
        return merge(state, { tier: action.payload.tier || state.tier });

      case 'starmus/ui/step-continue':
        return merge(state, { step: 2, status: 'idle', error: null });

      case 'starmus/calibration-start':
        return merge(state, { status: 'calibrating' });

      case 'starmus/calibration-update':
        return merge(state, {
          calibration: merge(state.calibration, {
            message: action.message,
            volumePercent: action.volumePercent,
          }),
        });

      case 'starmus/calibration-complete':
        return merge(state, {
          status: 'ready',
          calibration: merge(state.calibration, merge(action.payload.calibration || {}, { complete: true })),
        });

      case 'starmus/mic-start':
        return merge(state, { status: 'recording', error: null, recorder: merge(state.recorder, { duration: 0, isPaused: false }) });

      case 'starmus/mic-pause':
        return merge(state, { status: 'paused', recorder: merge(state.recorder, { isPaused: true }) });

      case 'starmus/mic-resume':
        return merge(state, { status: 'recording', recorder: merge(state.recorder, { isPaused: false }) });

      case 'starmus/mic-stop':
        return merge(state, { status: 'ready_to_submit' }); 

      case 'starmus/recorder-tick':
        return merge(state, { recorder: merge(state.recorder, { duration: action.duration, amplitude: action.amplitude }) });

      // CRITICAL UPDATE: Capture metadata when blob is ready
      case 'starmus/recording-available':
        return merge(state, {
          status: 'ready_to_submit',
          source: merge(state.source, { 
            kind: 'blob', 
            blob: action.payload.blob, 
            fileName: action.payload.fileName,
            metadata: {
                duration: state.recorder.duration || 0,
                mimeType: action.payload.blob.type || 'audio/webm',
                fileSize: action.payload.blob.size || 0
            }
          }),
        });

      case 'starmus/transcript-update':
        return merge(state, { source: merge(state.source, { transcript: action.transcript }) });

      case 'starmus/transcript-interim':
        return merge(state, { source: merge(state.source, { interimTranscript: action.interim }) });

      case 'starmus/file-attached':
        return merge(state, {
          status: 'ready_to_submit',
          source: { 
              kind: 'file', 
              file: action.file, 
              fileName: action.file.name,
              metadata: {
                  duration: 0, // Cannot know duration of uploaded file easily
                  mimeType: action.file.type,
                  fileSize: action.file.size
              }
          }
        });

      case 'starmus/submit-start':
        return merge(state, { status: 'submitting', error: null });

      case 'starmus/submit-progress':
        return merge(state, { submission: merge(state.submission, { progress: action.progress }) });

      case 'starmus/submit-complete':
        return merge(state, { status: 'complete', submission: { progress: 1, isQueued: false } });

      case 'starmus/submit-queued':
        return merge(state, {
          status: 'complete',
          submission: { progress: 0, isQueued: true }
        });

      case 'starmus/reset':
        return merge(shallowClone(DEFAULT_INITIAL_STATE), {
          instanceId: state.instanceId,
          env: state.env, 
          tier: state.tier,
          status: 'idle'
        });

      default:
        return state;
    }
  }

  function createStore(initial) {
    var state = merge(DEFAULT_INITIAL_STATE, initial || {});
    var listeners = [];
    return {
      getState: function () { return state; },
      dispatch: function (action) {
        state = reducer(state, action);
        for (var i = 0; i < listeners.length; i++) listeners[i](state);
      },
      subscribe: function (fn) {
        listeners.push(fn);
        return function () { listeners.splice(listeners.indexOf(fn), 1); };
      },
    };
  }

  global.StarmusStore = global.StarmusStore || {};
  global.StarmusStore.createStore = createStore;

  if (typeof module !== 'undefined' && module.exports) module.exports = { createStore: createStore };

})(typeof window !== 'undefined' ? window : globalThis);

export function createStore(initial) { return window.StarmusStore.createStore(initial); }