/**
 * @file starmus-state-store.js
 * @version 3.1.0
 * @description Lightweight Redux-style state store and master reducer
 * for each Starmus instance.
 */
/* global window */
(function (window) {
    'use strict';

    if (!window.STARMUS) {
        window.STARMUS = {};
    }

    var STARMUS = window.STARMUS;

    function createInitialState() {
        return {
            instanceId: null,
            env: null,
            status: 'uninitialized', // uninitialized -> idle -> recording -> processing -> ready_to_submit -> submitting -> complete -> error
            error: null,             // { code, message, retryable }
            source: {
                type: null,          // 'mic' | 'file'
                file: null,          // File (for uploads from disk)
                blob: null,          // Blob (for mic recordings)
                mimeType: null,
                fileName: null
            },
            submission: {
                progress: 0,         // 0..1
                strategy: null,      // 'direct' | 'tus_chunked'
                isQueued: false,
                lastError: null
            },
            speech: {
                isSupported: false,
                isEnabled: false,
                transcript: ''
            }
        };
    }

    function starmusReducer(state, action) {
        if (!state) {
            state = createInitialState();
        }
        if (!action || typeof action.type !== 'string') {
            return state;
        }

        switch (action.type) {
            case 'starmus/init':
                return {
                    instanceId: action.payload.instanceId || null,
                    env: action.payload.env || null,
                    status: 'idle',
                    error: null,
                    source: {
                        type: null,
                        file: null,
                        blob: null,
                        mimeType: null,
                        fileName: null
                    },
                    submission: {
                        progress: 0,
                        strategy: null,
                        isQueued: false,
                        lastError: null
                    },
                    speech: {
                        isSupported: !!action.payload.speechSupported,
                        isEnabled: false,
                        transcript: ''
                    }
                };

            case 'starmus/recorder/mic-start':
                return {
                    ...state,
                    status: 'recording',
                    source: {
                        type: 'mic',
                        file: null,
                        blob: null,
                        mimeType: null,
                        fileName: null
                    },
                    error: null
                };

            case 'starmus/recorder/processing':
                return {
                    ...state,
                    status: 'processing'
                };

            case 'starmus/recorder/mic-stopped':
                return {
                    ...state,
                    status: 'ready_to_submit',
                    source: {
                        ...state.source,
                        type: 'mic',
                        file: null,
                        blob: action.payload.blob,
                        mimeType: action.payload.mimeType,
                        fileName: action.payload.fileName || ('recording-' + Date.now() + '.webm')
                    },
                    error: null
                };

            case 'starmus/recorder/file-attached':
                return {
                    ...state,
                    status: 'ready_to_submit',
                    source: {
                        type: 'file',
                        file: action.payload.file,
                        blob: null,
                        mimeType: action.payload.file.type || null,
                        fileName: action.payload.file.name || null
                    },
                    error: null
                };

            case 'starmus/submission/start':
                return {
                    ...state,
                    status: 'submitting',
                    submission: {
                        ...state.submission,
                        progress: 0,
                        strategy: action.payload.strategy || null,
                        isQueued: false
                    },
                    error: null
                };

            case 'starmus/submission/progress':
                return {
                    ...state,
                    submission: {
                        ...state.submission,
                        progress: typeof action.payload.progress === 'number'
                            ? Math.min(Math.max(action.payload.progress, 0), 1)
                            : state.submission.progress
                    }
                };

            case 'starmus/submission/queued':
                return {
                    ...state,
                    status: 'ready_to_submit',
                    submission: {
                        ...state.submission,
                        isQueued: true,
                        progress: 0
                    },
                    error: {
                        code: 'submission:queued',
                        message: 'Recording saved offline and will be retried automatically.',
                        retryable: true
                    }
                };

            case 'starmus/submission/complete':
                return {
                    ...state,
                    status: 'complete',
                    submission: {
                        ...state.submission,
                        progress: 1,
                        isQueued: false
                    },
                    error: null
                };

            case 'starmus/error':
                return {
                    ...state,
                    status: 'error',
                    submission: {
                        ...state.submission,
                        lastError: action.payload.code || null
                    },
                    error: {
                        code: action.payload.code || 'unknown',
                        message: action.payload.message || 'An unexpected error occurred.',
                        retryable: !!action.payload.retryable
                    }
                };

            case 'starmus/reset':
                return {
                    ...createInitialState(),
                    instanceId: state.instanceId,
                    env: state.env,
                    speech: state.speech,
                    status: 'idle'
                };

            default:
                return state;
        }
    }

    function createStore(reducer) {
        var currentReducer = typeof reducer === 'function' ? reducer : starmusReducer;
        var currentState = currentReducer(undefined, { type: '__INIT__' });
        var listeners = new Set();

        function getState() {
            return currentState;
        }

        function dispatch(action) {
            currentState = currentReducer(currentState, action || {});
            listeners.forEach(function (listener) {
                try {
                    listener(currentState);
                } catch (e) {
                    if (window.console && console.error) {
                        console.error('STARMUS store listener error:', e);
                    }
                }
            });
        }

        function subscribe(listener) {
            if (typeof listener !== 'function') {
                return function () { /* no-op */ };
            }
            listeners.add(listener);
            return function unsubscribe() {
                listeners.delete(listener);
            };
        }

        return {
            getState: getState,
            dispatch: dispatch,
            subscribe: subscribe
        };
    }

    STARMUS.StateStore = {
        create: function () {
            return createStore(starmusReducer);
        },
        reducer: starmusReducer
    };

}(window));
