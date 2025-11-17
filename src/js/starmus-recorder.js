/**
 * @file starmus-recorder.js
 * @version 3.1.0
 * @description Stateless hardware controller for audio. Listens for commands,
 * controls MediaRecorder, and dispatches actions to the instance store.
 */
/* global window, navigator, MediaRecorder, Blob */
(function (window) {
    'use strict';

    if (!window.STARMUS) {
        window.STARMUS = {};
    }

    var STARMUS = window.STARMUS;
    var CommandBus = STARMUS.CommandBus;

    // Single active recording at a time (per page).
    var activeRecorder = null;
    var activeStream = null;
    var chunks = [];
    var recordingStartTime = 0;

    function init(store) {
        if (!CommandBus || !store) {
            return;
        }

        CommandBus.subscribe('start-mic', function (cmd) {
            var state = store.getState();
            if (cmd.meta.instanceId === state.instanceId) {
                handleStartMic(store);
            }
        });

        CommandBus.subscribe('stop-mic', function (cmd) {
            var state = store.getState();
            if (cmd.meta.instanceId === state.instanceId) {
                handleStopMic(store);
            }
        });

        CommandBus.subscribe('attach-file', function (cmd) {
            var state = store.getState();
            if (cmd.meta.instanceId === state.instanceId) {
                handleAttachFile(store, cmd.payload.file);
            }
        });
    }

    function handleStartMic(store) {
        var state = store.getState();
        var env = state.env || {};
        var tech = env.technical || {};
        var profile = tech.profile || {};
        var limited = profile.overallProfile === 'limited_capability';

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            store.dispatch({
                type: 'starmus/error',
                payload: {
                    code: 'recorder:unsupported',
                    message: 'This browser does not support audio recording.',
                    retryable: false
                }
            });
            return;
        }

        if (typeof MediaRecorder === 'undefined') {
            store.dispatch({
                type: 'starmus/error',
                payload: {
                    code: 'recorder:unsupported-mediarecorder',
                    message: 'This browser does not support MediaRecorder.',
                    retryable: false
                }
            });
            return;
        }

        if (activeRecorder && activeRecorder.state === 'recording') {
            // Already recording; ignore duplicate command.
            return;
        }

        var config = {
            mimeType: 'audio/webm;codecs=opus',
            audioBitsPerSecond: limited ? 32000 : 96000
        };

        navigator.mediaDevices.getUserMedia({ audio: true })
            .then(function (stream) {
                activeStream = stream;
                try {
                    activeRecorder = new MediaRecorder(stream, config);
                } catch (e) {
                    store.dispatch({
                        type: 'starmus/error',
                        payload: {
                            code: 'recorder:init-failed',
                            message: 'Unable to initialize audio recorder.',
                            retryable: false
                        }
                    });
                    stopStream();
                    return;
                }

                chunks = [];
                recordingStartTime = Date.now();

                activeRecorder.ondataavailable = function (event) {
                    if (event && event.data && event.data.size > 0) {
                        chunks.push(event.data);
                    }
                };

                activeRecorder.onerror = function (event) {
                    var message = (event && event.error && event.error.message) ? event.error.message : 'Unknown recorder error.';
                    store.dispatch({
                        type: 'starmus/error',
                        payload: {
                            code: 'recorder:media-error',
                            message: message,
                            retryable: false
                        }
                    });
                };

                activeRecorder.onstop = function () {
                    try {
                        var blob = new Blob(chunks.slice(), { type: config.mimeType });
                        var durationMs = Date.now() - recordingStartTime;
                        var fileName = 'recording-' + durationMs + 'ms-' + Date.now() + '.webm';

                        store.dispatch({
                            type: 'starmus/recorder/mic-stopped',
                            payload: {
                                blob: blob,
                                mimeType: config.mimeType,
                                fileName: fileName
                            }
                        });
                    } catch (e) {
                        store.dispatch({
                            type: 'starmus/error',
                            payload: {
                                code: 'recorder:blob-failed',
                                message: 'Failed to finalize audio recording.',
                                retryable: false
                            }
                        });
                    } finally {
                        stopStream();
                        activeRecorder = null;
                        chunks = [];
                    }
                };

                activeRecorder.start();
                store.dispatch({ type: 'starmus/recorder/mic-start' });
            })
            .catch(function (error) {
                var code = 'recorder:unknown';
                if (error && error.name === 'NotAllowedError') {
                    code = 'recorder:permission-denied';
                } else if (error && error.name === 'NotFoundError') {
                    code = 'recorder:no-mic';
                }

                store.dispatch({
                    type: 'starmus/error',
                    payload: {
                        code: code,
                        message: 'Could not access the microphone.',
                        retryable: false
                    }
                });
            });
    }

    function handleStopMic(store) {
        if (activeRecorder && activeRecorder.state === 'recording') {
            store.dispatch({ type: 'starmus/recorder/processing' });
            try {
                activeRecorder.stop();
            } catch (e) {
                store.dispatch({
                    type: 'starmus/error',
                    payload: {
                        code: 'recorder:stop-failed',
                        message: 'Failed to stop recording cleanly.',
                        retryable: false
                    }
                });
            }
        }
    }

    function handleAttachFile(store, file) {
        if (!(file instanceof File)) {
            store.dispatch({
                type: 'starmus/error',
                payload: {
                    code: 'file:invalid',
                    message: 'Please select a valid audio file to upload.',
                    retryable: false
                }
            });
            return;
        }

        store.dispatch({
            type: 'starmus/recorder/file-attached',
            payload: { file: file }
        });
    }

    function stopStream() {
        if (activeStream && activeStream.getTracks) {
            activeStream.getTracks().forEach(function (track) {
                try {
                    track.stop();
                } catch (e) { /* ignore */ }
            });
        }
        activeStream = null;
    }

    STARMUS.Recorder = {
        init: init
    };

}(window));
