/**
 * @file starmus-state-store.js
 * @version 5.0.0
 * @description Unified ES5-safe, redux-like store for managing recording state.
 */

(function (global) {
  'use strict';

  var DEFAULT_INITIAL_STATE = {
    instanceId: null,
    env: {},
    tier: null,
    status: 'uninitialized',
    error: null,
    source: {
      kind: null,
      blob: null,
      file: null,
      fileName: '',
      transcript: '',
      interimTranscript: '',
    },
    calibration: {
      phase: null,
      message: '',
      volumePercent: 0,
      complete: false,
      gain: 1.0,
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
      case 'starmus/tier-ready':
        if (state.tier) return state;
        return merge(state, {
          tier: action.payload && action.payload.tier ? action.payload.tier : state.tier,
        });

      case 'starmus/init':
        return merge(state, merge(action.payload || {}, { status: 'idle', error: null }));

      case 'starmus/ui/step-continue':
        return merge(state, { status: 'ready_to_record', error: null });

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
          calibration: merge(state.calibration, merge(action.calibration || {}, { complete: true })),
        });

      case 'starmus/mic-start':
        return merge(state, {
          status: 'recording',
          error: null,
          recorder: merge(state.recorder, { duration: 0, amplitude: 0, isPaused: false }),
        });

      case 'starmus/mic-pause':
        return merge(state, {
          status: 'paused',
          recorder: merge(state.recorder, { isPaused: true }),
        });

      case 'starmus/mic-resume':
        return merge(state, {
          status: 'recording',
          recorder: merge(state.recorder, { isPaused: false }),
        });

      case 'starmus/mic-stop':
        return merge(state, { status: 'processing' });

      case 'starmus/recorder-tick':
        return merge(state, {
          recorder: merge(state.recorder, {
            duration: action.duration,
            amplitude: action.amplitude,
          }),
        });

      case 'starmus/recording-available':
        return merge(state, {
          status: 'ready_to_submit',
          source: merge(state.source, {
            kind: 'blob',
            blob: action.payload.blob,
            fileName: action.payload.fileName,
          }),
        });

      case 'starmus/recorder-playback-state':
        return merge(state, {
          recorder: merge(state.recorder, { isPlaying: action.isPlaying }),
        });

      case 'starmus/transcript-update':
        return merge(state, {
          source: merge(state.source, { transcript: action.transcript }),
        });

      case 'starmus/transcript-interim':
        return merge(state, {
          source: merge(state.source, { interimTranscript: action.interim }),
        });

      case 'starmus/env-update':
        return merge(state, {
          env: merge(state.env, action.payload || {}),
          tier: action.tier || state.tier,
        });

      case 'starmus/file-attached':
        return merge(state, {
          status: 'ready_to_submit',
          source: {
            kind: 'file',
            file: action.file,
            fileName: action.file.name,
          },
        });

      case 'starmus/submit-start':
        return merge(state, { status: 'submitting', error: null });

      case 'starmus/submit-progress':
        return merge(state, {
          submission: merge(state.submission, { progress: action.progress }),
        });

      case 'starmus/submit-complete':
        return merge(state, {
          status: 'complete',
          submission: { progress: 1, isQueued: false },
        });

      case 'starmus/submit-queued':
        return merge(state, {
          status: 'complete',
          submission: { progress: 0, isQueued: true },
        });

      case 'starmus/error':
        return merge(state, { error: action.error || action.payload });

      case 'starmus/reset':
        return merge(shallowClone(DEFAULT_INITIAL_STATE), {
          instanceId: state.instanceId,
          env: state.env,
          tier: state.tier,
          status: 'idle',
          source: {
            kind: null,
            blob: null,
            file: null,
            fileName: '',
            transcript: '',
            interimTranscript: '',
          },
        });

      default:
        return state;
    }
  }

  function createStore(initial) {
    var state = merge(DEFAULT_INITIAL_STATE, initial || {});
    var listeners = [];

    return {
      getState: function () {
        return state;
      },
      dispatch: function (action) {
        state = reducer(state, action);
        for (var i = 0; i < listeners.length; i++) {
          try {
            listeners[i](state);
          } catch (e) {
            console.error(e);
          }
        }
      },
      subscribe: function (fn) {
        if (listeners.indexOf(fn) === -1) listeners.push(fn);
        return function () {
          var idx = listeners.indexOf(fn);
          if (idx !== -1) listeners.splice(idx, 1);
        };
      },
    };
  }

  // Attach globally
  global.StarmusStore = global.StarmusStore || {};
  global.StarmusStore.createStore = createStore;

  // CommonJS support
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { createStore: createStore };
  }

  // UMD fallback
  if (typeof exports !== 'undefined') {
    exports.createStore = createStore;
  }

})(typeof window !== 'undefined' ? window : globalThis);

// ES6 named export
export function createStore(initial) {
  return window.StarmusStore.createStore(initial);
}
