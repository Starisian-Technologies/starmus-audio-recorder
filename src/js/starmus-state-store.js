/**
 * @file starmus-state-store.js
 * @version 4.6.0
 */

'use strict';

const DEFAULT_INITIAL_STATE = {
    instanceId: null,
    env: {},
    tier: null, 
    status: 'uninitialized', 
    error: null,
    source: { kind: null, blob: null, file: null, fileName: '', transcript: '' },
    calibration: { phase: null, message: '', volumePercent: 0, complete: false, gain: 1.0 },
    recorder: { duration: 0, amplitude: 0, isPlaying: false, isPaused: false },
    submission: { progress: 0, isQueued: false },
};

function reducer(state, action) {
    if (!action || !action.type) return state;

    switch (action.type) {
        case 'starmus/init':
            return { ...state, ...action.payload, status: 'idle', error: null };

        case 'starmus/ui/step-continue':
            return { ...state, status: 'ready_to_record', error: null };

        case 'starmus/calibration-start':
            return { ...state, status: 'calibrating' };

        case 'starmus/calibration-update':
            return { ...state, calibration: { ...state.calibration, message: action.message, volumePercent: action.volumePercent } };

        case 'starmus/calibration-complete':
            return { 
                ...state, 
                status: 'ready', 
                calibration: { ...state.calibration, ...action.calibration, complete: true } 
            };

        case 'starmus/mic-start':
            return { ...state, status: 'recording', error: null, recorder: { ...state.recorder, duration: 0, amplitude: 0, isPaused: false } };

        case 'starmus/mic-pause':
            return { ...state, status: 'paused', recorder: { ...state.recorder, isPaused: true } };

        case 'starmus/mic-resume':
            return { ...state, status: 'recording', recorder: { ...state.recorder, isPaused: false } };

        case 'starmus/mic-stop':
            return { ...state, status: 'processing' };

        case 'starmus/recorder-tick':
            return { 
                ...state, 
                recorder: { ...state.recorder, duration: action.duration, amplitude: action.amplitude } 
            };

        case 'starmus/recording-available':
            return {
                ...state,
                status: 'ready_to_submit',
                source: { ...state.source, kind: 'blob', blob: action.payload.blob, fileName: action.payload.fileName }
            };

        case 'starmus/recorder-playback-state':
            return { ...state, recorder: { ...state.recorder, isPlaying: action.isPlaying } };

        case 'starmus/transcript-update':
            return { ...state, source: { ...state.source, transcript: action.transcript } };

        case 'starmus/file-attached':
            return {
                ...state,
                status: 'ready_to_submit',
                source: { kind: 'file', file: action.file, fileName: action.file.name }
            };

        case 'starmus/submit-start':
            return { ...state, status: 'submitting', error: null };

        case 'starmus/submit-progress':
            return { ...state, submission: { ...state.submission, progress: action.progress } };

        case 'starmus/submit-complete':
            return { ...state, status: 'complete', submission: { progress: 1, isQueued: false } };

        case 'starmus/submit-queued':
            return { ...state, status: 'complete', submission: { progress: 0, isQueued: true } };

        case 'starmus/error':
            return { ...state, error: action.error || action.payload };

        case 'starmus/reset':
            return { ...DEFAULT_INITIAL_STATE, instanceId: state.instanceId, env: state.env, tier: state.tier, status: 'idle' };

        default:
            return state;
    }
}

export function createStore(initial = {}) {
    let state = { ...DEFAULT_INITIAL_STATE, ...initial };
    const listeners = new Set();
    return {
        getState: () => state,
        dispatch: (action) => {
            state = reducer(state, action);
            listeners.forEach(l => { try { l(state); } catch(e) { console.error(e); } });
        },
        subscribe: (l) => { listeners.add(l); return () => listeners.delete(l); }
    };
}
