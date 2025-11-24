/**
 * @file starmus-state-store.js
 * @version 4.1.0
 * @description Tiny Redux-style store. Aligned for Visualizer/Timer updates.
 */

'use strict';

const DEFAULT_INITIAL_STATE = {
    instanceId: null,
    env: {},
    tier: null, 
    status: 'uninitialized', 
    error: null,
    source: {
        kind: null,    // 'mic' | 'file' | 'blob'
        blob: null,    
        file: null,    
        fileName: '',
        transcript: '', 
    },
    calibration: {
        phase: null,
        message: '',
        volumePercent: 0,
        complete: false,
        gain: 1.0,
        snr: null,
        noiseFloor: null,
        speechLevel: null,
    },
    recorder: {
        duration: 0,      
        amplitude: 0,     
        isPlaying: false, 
    },
    submission: {
        progress: 0,
        isQueued: false,
    },
};

function reducer(state, action) {
    if (!action || !action.type) {return state;}

    switch (action.type) {
        case 'starmus/init':
            return {
                ...state,
                instanceId: action.payload.instanceId || state.instanceId,
                env: action.payload.env || state.env,
                tier: action.payload.tier || state.tier,
                status: 'idle',
                error: null,
                submission: { progress: 0, isQueued: false },
            };

        case 'starmus/ui/step-continue':
            return { ...state, status: 'ready_to_record', error: null };

        case 'starmus/mic-start':
            return {
                ...state,
                status: 'recording',
                error: null,
                source: { ...state.source, kind: 'mic' },
                recorder: { duration: 0, amplitude: 0, isPlaying: false },
            };

        // --- FIXED: Matches starmus-recorder.js ---
        case 'starmus/recorder-tick':
            return {
                ...state,
                recorder: {
                    ...state.recorder,
                    duration: typeof action.duration === 'number' ? action.duration : state.recorder.duration,
                    amplitude: typeof action.amplitude === 'number' ? action.amplitude : state.recorder.amplitude,
                },
            };

        // --- FIXED: Matches starmus-integrator.js ---
        case 'starmus/recorder-playback-state':
            return {
                ...state,
                recorder: {
                    ...state.recorder,
                    isPlaying: !!action.isPlaying,
                },
            };

        case 'starmus/mic-stop':
            return { ...state, status: 'processing' };

        case 'starmus/recording-available': // Replaced 'mic-complete' for consistency
            return {
                ...state,
                status: 'ready_to_submit',
                error: null,
                source: {
                    ...state.source,
                    kind: 'blob',
                    blob: action.payload.blob,
                    fileName: 'microphone-recording.webm',
                },
                submission: { progress: 0, isQueued: false },
            };

        case 'starmus/file-attached':
            return {
                ...state,
                status: 'ready_to_submit',
                error: null,
                source: {
                    ...state.source,
                    kind: 'file',
                    file: action.payload.file,
                    fileName: action.payload.file.name,
                },
                submission: { progress: 0, isQueued: false },
            };

        case 'starmus/submit-start':
            return {
                ...state,
                status: 'submitting',
                error: null,
                submission: { ...state.submission, progress: 0, isQueued: false },
            };

        case 'starmus/submit-progress':
            return {
                ...state,
                submission: { ...state.submission, progress: action.progress },
            };

        case 'starmus/submit-queued':
            return {
                ...state,
                status: 'complete',
                submission: { ...state.submission, progress: 0, isQueued: true },
            };

        case 'starmus/submit-complete':
            return {
                ...state,
                status: 'complete',
                submission: { ...state.submission, progress: 1, isQueued: false },
            };

        case 'starmus/error':
            return {
                ...state,
                error: action.payload || { message: 'Unknown error' },
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

export function createStore(initial = {}) {
    let state = { ...DEFAULT_INITIAL_STATE, ...initial };
    const listeners = new Set();

    return {
        getState: () => state,
        dispatch: (action) => {
            state = reducer(state, action);
            listeners.forEach((listener) => { try { listener(state); } catch (e) { console.error(e); } });
        },
        subscribe: (listener) => {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
    };
}