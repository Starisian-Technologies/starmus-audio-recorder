/**
 * @file starmus-state-store.js
 * @version 6.1.0-METADATA-READY
 * @description Unified Redux-style state store for Starmus audio recorder.
 * Captures technical metadata (duration/size) on stop and manages complete
 * application state including recording, calibration, submission, and environment data.
 */

(function (global) {
  'use strict';

  /**
   * Default initial state object for new store instances.
   * Defines complete application state schema with all required properties.
   * 
   * @constant
   * @type {Object}
   * @property {string|null} instanceId - Unique identifier for recorder instance
   * @property {string|null} tier - Browser capability tier (A/B/C)
   * @property {string} status - Current application state (uninitialized/idle/recording/etc)
   * @property {number} step - UI step number (1 or 2)
   * @property {Object|null} error - Last error that occurred
   * @property {Object} env - Environment data from UEC/SparxstarUEC
   * @property {Object} env.device - Device information and capabilities
   * @property {Object} env.browser - Browser type and feature detection
   * @property {Object} env.network - Network connection information
   * @property {Object} env.identifiers - Session and visitor identifiers
   * @property {Array} env.errors - Array of initialization and runtime errors
   * @property {Object} source - Audio source data (recording or uploaded file)
   * @property {string|null} source.kind - Source type ('blob' or 'file')
   * @property {Blob|null} source.blob - Recorded audio blob
   * @property {File|null} source.file - Uploaded audio file
   * @property {string} source.fileName - Audio file name
   * @property {string} source.transcript - User-provided transcript
   * @property {string} source.interimTranscript - Real-time speech recognition results
   * @property {Object} source.metadata - Technical audio metadata
   * @property {number} source.metadata.duration - Audio duration in seconds
   * @property {string} source.metadata.mimeType - Audio MIME type
   * @property {number} source.metadata.fileSize - Audio file size in bytes
   * @property {Object} calibration - Microphone calibration state
   * @property {string|null} calibration.phase - Current calibration phase
   * @property {string} calibration.message - User-facing calibration message
   * @property {number} calibration.volumePercent - Volume level percentage (0-100)
   * @property {boolean} calibration.complete - Whether calibration is finished
   * @property {number} calibration.gain - Audio gain multiplier
   * @property {number} calibration.speechLevel - Detected speech level
   * @property {Object} recorder - Recording state and metrics
   * @property {number} recorder.duration - Current recording duration in seconds
   * @property {number} recorder.amplitude - Current audio amplitude level
   * @property {boolean} recorder.isPlaying - Whether audio is currently playing
   * @property {boolean} recorder.isPaused - Whether recording is paused
   * @property {Object} submission - Upload and submission state
   * @property {number} submission.progress - Upload progress (0.0 to 1.0)
   * @property {boolean} submission.isQueued - Whether submission is queued for offline
   */
  const DEFAULT_INITIAL_STATE = {
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

  /**
   * Creates a shallow clone of an object.
   * Only copies enumerable own properties, not nested objects.
   * 
   * @function
   * @param {Object} obj - Object to clone
   * @returns {Object} Shallow copy of the input object
   */
  function shallowClone(obj) {
    const out = {};
    for (const k in obj) {if (objObject.prototype.hasOwnProperty.call(k)) {out[k] = obj[k];}}
    return out;
  }

  /**
   * Merges two objects, with properties from b overriding properties from a.
   * Creates a new object without mutating the inputs.
   * 
   * @function
   * @param {Object} a - Base object
   * @param {Object} b - Object whose properties will override base
   * @returns {Object} New merged object
   */
  function merge(a, b) {
    const out = shallowClone(a);
    for (const k in b) {if (bObject.prototype.hasOwnProperty.call(k)) {out[k] = b[k];}}
    return out;
  }

  /**
   * Redux-style reducer function that handles state transitions.
   * Processes actions and returns new state without mutating the original.
   * 
   * @function
   * @param {Object} state - Current application state
   * @param {Object} action - Action object with type and optional payload
   * @param {string} action.type - Action type identifier
   * @param {Object} [action.payload] - Action data payload
   * @param {Object} [action.error] - Error information for error actions
   * @returns {Object} New state object
   * 
   * @description Supported action types:
   * - 'starmus/init' - Initialize store with payload data
   * - 'starmus/env-update' - Update environment data from UEC
   * - 'starmus/error' - Handle error and add to error log
   * - 'starmus/tier-ready' - Set browser capability tier
   * - 'starmus/ui/step-continue' - Advance to step 2
   * - 'starmus/calibration-start' - Begin microphone calibration
   * - 'starmus/calibration-update' - Update calibration progress
   * - 'starmus/calibration-complete' - Finish calibration
   * - 'starmus/mic-start' - Start recording
   * - 'starmus/mic-pause' - Pause recording
   * - 'starmus/mic-resume' - Resume recording
   * - 'starmus/mic-stop' - Stop recording
   * - 'starmus/recorder-tick' - Update recording metrics
   * - 'starmus/recording-available' - Set recorded audio blob with metadata
   * - 'starmus/transcript-update' - Update transcript text
   * - 'starmus/transcript-interim' - Update interim speech recognition
   * - 'starmus/file-attached' - Set uploaded file with metadata
   * - 'starmus/submit-start' - Begin submission process
   * - 'starmus/submit-progress' - Update upload progress
   * - 'starmus/submit-complete' - Complete submission
   * - 'starmus/submit-queued' - Queue submission for offline
   * - 'starmus/reset' - Reset state while preserving instance data
   */
  function reducer(state, action) {
    if (!action || !action.type) {return state;}

    if (!state.instanceId && action.payload && action.payload.instanceId) {
      state = merge(state, { instanceId: action.payload.instanceId });
    }

    switch (action.type) {
      case 'starmus/init':
        return merge(state, merge(action.payload || {}, { status: 'idle', error: null }));

      case 'starmus/env-update':
        const newEnv = merge(state.env, action.payload || {});
        if (!newEnv.errors) {newEnv.errors = state.env.errors || [];}
        return merge(state, { env: newEnv });

      case 'starmus/error':
        const errObj = action.error || action.payload;
        const currentErrors = (state.env && state.env.errors) ? state.env.errors.slice() : [];
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

  /**
   * Creates a new Redux-style store instance.
   * Provides getState, dispatch, and subscribe methods for state management.
   * 
   * @function
   * @param {Object} [initial={}] - Initial state to merge with defaults
   * @returns {Object} Store instance with state management methods
   * @returns {function} returns.getState - Function that returns current state
   * @returns {function} returns.dispatch - Function to dispatch actions
   * @returns {function} returns.subscribe - Function to subscribe to state changes
   * 
   * @example
   * const store = createStore({ instanceId: 'rec-123' });
   * store.subscribe(state => console.log('State changed:', state));
   * store.dispatch({ type: 'starmus/init', payload: { tier: 'A' } });
   * const currentState = store.getState();
   */
  function createStore(initial) {
    let state = merge(DEFAULT_INITIAL_STATE, initial || {});
    const listeners = [];
    return {
      /**
       * Returns the current state object.
       * @returns {Object} Current application state
       */
      getState: function () { return state; },
      
      /**
       * Dispatches an action to update state.
       * @param {Object} action - Action object with type and optional payload
       */
      dispatch: function (action) {
        state = reducer(state, action);
        for (let i = 0; i < listeners.length; i++) {listeners[i](state);}
      },
      
      /**
       * Subscribes to state changes.
       * @param {function} fn - Callback function to call on state changes
       * @returns {function} Unsubscribe function
       */
      subscribe: function (fn) {
        listeners.push(fn);
        return function () { listeners.splice(listeners.indexOf(fn), 1); };
      },
    };
  }

  /**
   * Global StarmusStore namespace for browser environments.
   * @global
   * @namespace StarmusStore
   */
  global.StarmusStore = global.StarmusStore || {};
  
  /**
   * Global createStore function reference.
   * @memberof StarmusStore
   * @type {function}
   */
  global.StarmusStore.createStore = createStore;

  /**
   * CommonJS module export for Node.js environments.
   */
  if (typeof module !== 'undefined' && module.exports) {module.exports = { createStore: createStore };}

})(typeof window !== 'undefined' ? window : globalThis);

/**
 * ES6 module export wrapper that delegates to global StarmusStore.
 * Ensures compatibility between module systems.
 * 
 * @function
 * @exports createStore
 * @param {Object} [initial={}] - Initial state to merge with defaults
 * @returns {Object} Store instance with state management methods
 */
export function createStore(initial) { return window.StarmusStore.createStore(initial); }