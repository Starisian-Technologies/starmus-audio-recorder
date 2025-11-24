/**
 * @file starmus-state-store.js
 * @version 4.0.0
 * @description Tiny Redux-style store and Starmus reducer.
 */

'use strict';

const DEFAULT_INITIAL_STATE = {
    instanceId: null,
    env: {},
    tier: null, // 'A' (full), 'B' (degraded), 'C' (fallback)
    status: 'uninitialized', // 'idle', 'ready_to_record', 'recording', 'processing', 'ready_to_submit', 'submitting', 'complete'
    error: null,
    source: {
        kind: null,    // 'mic' | 'file'
        blob: null,    // Blob from MediaRecorder
        file: null,    // File from <input>
        fileName: '',
        transcript: '', // Speech recognition transcript for AI processing
    },
    calibration: {
        phase: null,      // 'quiet' | 'speech' | 'validating'
        message: '',
        volumePercent: 0,
        complete: false,
        gain: 1.0,
        snr: null,
        noiseFloor: null,
        speechLevel: null,
    },
    recorder: {
        duration: 0,      // Current recording duration in seconds
        amplitude: 0,     // Current audio amplitude (0-100)
        isPlaying: false, // Playback state for preview
    },
    submission: {
        progress: 0,
        isQueued: false,
    },
};

/**
 * Pure reducer for Starmus state.
 *
 * @param {object} state
 * @param {object} action
 * @returns {object}
 */
function reducer(state, action) {
    if (!action || !action.type) {
        return state;
    }

    switch (action.type) {
        case 'starmus/init':
            return {
                ...state,
                instanceId: action.payload.instanceId || state.instanceId,
                env: action.payload.env || state.env,
                tier: action.payload.tier || state.tier,
                status: 'idle',
                error: null,
                submission: {
                    progress: 0,
                    isQueued: false,
                },
            };

        case 'starmus/calibration-start':
            return {
                ...state,
                status: 'calibrating',
                calibration: {
                    phase: 'quiet',
                    message: 'Preparing calibration...',
                    volumePercent: 0,
                    complete: false,
                    gain: 1.0,
                    snr: null,
                    noiseFloor: null,
                    speechLevel: null,
                }
            };

        case 'starmus/calibration-update':
            return {
                ...state,
                calibration: {
                    ...state.calibration,
                    message: action.message || state.calibration.message,
                    volumePercent: action.volumePercent !== undefined ? action.volumePercent : state.calibration.volumePercent
                }
            };

        case 'starmus/calibration-complete':
            return {
                ...state,
                status: 'ready',
                calibration: {
                    ...state.calibration,
                    ...action.calibration,
                    complete: true,
                    message: 'Calibration complete'
                }
            };

        case 'starmus/set-tier':
            return {
                ...state,
                tier: action.tier,
            };

        case 'starmus/ui/step-continue':
            return {
                ...state,
                status: 'ready_to_record',
                error: null,
            };

        case 'starmus/mic-start':
            return {
                ...state,
                status: 'recording',
                error: null,
                source: {
                    ...state.source,
                    kind: 'mic',
                    // blob will be filled when recording finishes
                },
                recorder: {
                    duration: 0,
                    amplitude: 0,
                    isPlaying: false,
                },
            };

        case 'starmus/recorder-update':
            return {
                ...state,
                recorder: {
                    ...state.recorder,
                    duration: action.duration !== undefined ? action.duration : state.recorder.duration,
                    amplitude: action.amplitude !== undefined ? action.amplitude : state.recorder.amplitude,
                },
            };

        case 'starmus/playback-toggle':
            return {
                ...state,
                recorder: {
                    ...state.recorder,
                    isPlaying: !state.recorder.isPlaying,
                },
            };

        case 'starmus/mic-stop':
            return {
                ...state,
                status: 'processing',
            };

        case 'starmus/mic-complete':
            return {
                ...state,
                status: 'ready_to_submit',
                error: null,
                source: {
                    kind: 'mic',
                    blob: action.blob || null,
                    file: null,
                    fileName: action.fileName || 'recording.webm',
                    transcript: action.transcript || state.source.transcript || '',
                },
                submission: {
                    progress: 0,
                    isQueued: false,
                },
            };

        case 'starmus/transcript-update':
            return {
                ...state,
                source: {
                    ...state.source,
                    transcript: action.transcript || '',
                },
            };

        case 'starmus/file-attached':
            return {
                ...state,
                status: 'ready_to_submit',
                error: null,
                source: {
                    kind: 'file',
                    blob: null,
                    file: action.file || null,
                    fileName: action.file ? action.file.name : '',
                },
                submission: {
                    progress: 0,
                    isQueued: false,
                },
            };

        case 'starmus/submit-start':
            return {
                ...state,
                status: 'submitting',
                error: null,
                submission: {
                    ...state.submission,
                    progress: 0,
                    isQueued: !!action.isQueued,
                },
            };

        case 'starmus/submit-progress':
            return {
                ...state,
                status: 'submitting',
                submission: {
                    ...state.submission,
                    progress: typeof action.progress === 'number' ? action.progress : state.submission.progress,
                },
            };

        case 'starmus/submit-queued':
            return {
                ...state,
                status: 'complete',
                submission: {
                    ...state.submission,
                    progress: 0,
                    isQueued: true,
                    submissionId: action.submissionId,
                },
            };

        case 'starmus/submit-complete':
            return {
                ...state,
                status: 'complete',
                submission: {
                    ...state.submission,
                    progress: 1,
                },
            };

        case 'starmus/error':
            return {
                ...state,
                status: action.status || state.status || 'idle',
                error: action.error || { message: 'Unknown error', retryable: true },
            };

        case 'starmus/reset':
            return {
                ...DEFAULT_INITIAL_STATE,
                instanceId: state.instanceId,
                env: state.env,
                tier: state.tier,
                status: 'idle',
            };

        default:
            return state;
    }
}

/**
 * Creates a minimal store with getState, dispatch, subscribe.
 *
 * @param {object} [initial]
 * @returns {{getState: function, dispatch: function, subscribe: function}}
 */
export function createStore(initial = {}) {
    let state = {
        ...DEFAULT_INITIAL_STATE,
        ...initial,
    };

    const listeners = new Set();

    function getState() {
        return state;
    }

    function dispatch(action) {
        state = reducer(state, action);
        listeners.forEach((listener) => {
            try {
                listener(state);
            } catch (e) {
                 
                console.error('[Starmus] Store listener error:', e);
            }
        });
    }

    function subscribe(listener) {
        listeners.add(listener);
        return () => {
            listeners.delete(listener);
        };
    }

    return {
        getState,
        dispatch,
        subscribe,
    };
}
