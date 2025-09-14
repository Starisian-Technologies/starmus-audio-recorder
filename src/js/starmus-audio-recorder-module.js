// FILE: starmus-audio-recorder-module.js (REFACTORED WITH HOOKS + PATCHES)
/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * @module  StarmusAudioRecorder
 * @version 1.2.1
 * @file    The Core Recording Engine - Pure audio functionality with hooks integration
 */
(function(window) { 
    'use strict'; 

    const hasMediaRecorder = !!(window.MediaRecorder && window.navigator.mediaDevices);
    const instances = Object.create(null);

    function isSafeId(id) {
        return typeof id === 'string' && /^[a-zA-Z0-9_-]{1,100}$/.test(id);
    }

    function secureLog(level, message, data) {
        if (console && console[level]) {
            console[level]('[Starmus Recorder]', message, data || '');
        }
    }

    function doAction(hook, ...args) {
        if (window.StarmusHooks) {
            window.StarmusHooks.doAction(hook, ...args);
        }
    }

    function applyFilters(hook, value, ...args) {
        return window.StarmusHooks ?
            window.StarmusHooks.applyFilters(hook, value, ...args) : value;
    }

    window.StarmusAudioRecorder = {
        init: function(options) {
            return new Promise((resolve, reject) => {
                if (!hasMediaRecorder) return reject(new Error('MediaRecorder API not supported.'));

                const instanceId = options.formInstanceId;
                if (!isSafeId(instanceId)) return reject(new Error('Invalid instance ID.'));
                if (instanceId in instances) return resolve(true);

                doAction('starmus_before_recorder_init', instanceId, options);

                navigator.mediaDevices.getUserMedia({ audio: true })
                    .then(stream => {
                        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                        const source = audioContext.createMediaStreamSource(stream);
                        const analyser = audioContext.createAnalyser();
                        const gainNode = audioContext.createGain();
                        analyser.fftSize = 2048;

                        source.connect(analyser);
                        analyser.connect(gainNode);

                        instances[instanceId] = {
                            stream, recorder: null, chunks: [], audioBlob: null,
                            isRecording: false, isPaused: false, startTime: 0,
                            ctx: audioContext, analyser, gain: gainNode, isCalibrated: false
                        };

                        doAction('starmus_after_recorder_init', instanceId);
                        resolve(true);
                    })
                    .catch(error => {
                        secureLog('error', 'Microphone access denied.', error.message);
                        doAction('starmus_recorder_init_failed', instanceId, error);
                        reject(new Error('Microphone permission is required.'));
                    });
            });
        },

        calibrate: function(instanceId, onUpdateCallback) {
            if (!isSafeId(instanceId) || !(instanceId in instances)) return;
            const instance = instances[instanceId];
            const analyser = instance.analyser;
            const buffer = new Float32Array(analyser.fftSize);
            const samples = [];
            const startTime = performance.now();
            const DURATION = 10000;

            doAction('starmus_calibration_started', instanceId);

            function tick() {
                const elapsed = performance.now() - startTime;
                const remaining = Math.ceil((DURATION - elapsed) / 1000);

                if (elapsed < 3000) onUpdateCallback('Be quiet for background noise (' + remaining + 's)');
                else if (elapsed < 7000) onUpdateCallback('Now speak normally (' + remaining + 's)');
                else onUpdateCallback('Be quiet again (' + remaining + 's)');

                analyser.getFloatTimeDomainData(buffer);
                let sum = 0;
                for (let i = 0; i < buffer.length; i++) sum += buffer[i] * buffer[i];
                const rms = Math.sqrt(sum / buffer.length);
                samples.push(rms);

                onUpdateCallback(null, Math.min(100, rms * 2000));

                if (elapsed < DURATION) {
                    requestAnimationFrame(tick);
                } else {
                    done();
                }
            }

            function done() {
                const avg = samples.reduce((a, b) => a + b, 0) / Math.max(1, samples.length);
                let gain = Math.max(0.5, Math.min(8.0, 0.05 / Math.max(1e-6, avg)));
                gain = applyFilters('starmus_calibration_gain', gain, instanceId, avg);
                instance.gain.gain.setTargetAtTime(gain, instance.ctx.currentTime, 0.01);
                instance.isCalibrated = true;

                onUpdateCallback('Mic calibrated (gain ×' + gain.toFixed(2) + '). Ready to record.', null, true);
                doAction('starmus_calibration_complete', instanceId, gain);
            }
            tick();
        },

        startVolumeMonitoring: function(instanceId, onVolumeChange) {
            if (!isSafeId(instanceId) || !(instanceId in instances)) return;
            const instance = instances[instanceId];
            const analyser = instance.analyser;
            const buffer = new Float32Array(analyser.fftSize);

            function update() {
                if (!instance.isRecording || instance.isPaused) return;

                analyser.getFloatTimeDomainData(buffer);
                let sum = 0;
                for (let i = 0; i < buffer.length; i++) sum += buffer[i] * buffer[i];
                const rms = Math.sqrt(sum / buffer.length);
                const volume = Math.min(100, rms * 2000);

                onVolumeChange(applyFilters('starmus_volume_level', volume, instanceId));
                requestAnimationFrame(update);
            }
            update();
        },

        startRecording: function(instanceId) {
            if (!isSafeId(instanceId) || !(instanceId in instances) || instances[instanceId].isRecording) return;
            const instance = instances[instanceId];

            try {
                const destination = instance.ctx.createMediaStreamDestination();
                instance.gain.connect(destination);

                instance.recorder = new MediaRecorder(destination.stream);
                instance.chunks = [];
                instance.audioBlob = null;
                instance.isRecording = true;
                instance.isPaused = false;
                instance.startTime = Date.now();

                instance.recorder.ondataavailable = event => {
                    if (event.data.size > 0) instance.chunks.push(event.data);
                };

                instance.recorder.onstop = () => {
                    instance.audioBlob = new Blob(instance.chunks, {
                        type: instance.recorder.mimeType || 'audio/webm'
                    });
                    doAction('starmus_recording_stopped', instanceId, instance.audioBlob);
                };

                instance.recorder.start();
                doAction('starmus_recording_started', instanceId);

            } catch (error) {
                secureLog('error', 'Failed to start recording.', error.message);
                doAction('starmus_recording_failed', instanceId, error);
            }
        },

        stopRecording: function(instanceId) {
            if (!isSafeId(instanceId) || !(instanceId in instances)) return;
            const instance = instances[instanceId];
            if (instance.recorder && instance.recorder.state !== 'inactive') {
                instance.recorder.stop();
            }
            instance.isRecording = false;
            instance.isPaused = false;
        },

        togglePause: function(instanceId) {
            if (!isSafeId(instanceId) || !(instanceId in instances)) return;
            const instance = instances[instanceId];
            if (!instance.recorder) return;

            if (instance.isPaused && instance.recorder.state === 'paused') {
                instance.recorder.resume();
                instance.isPaused = false;
                doAction('starmus_recording_resumed', instanceId);
            } else if (instance.recorder.state === 'recording') {
                instance.recorder.pause();
                instance.isPaused = true;
                doAction('starmus_recording_paused', instanceId);
            }
        },

        getSubmissionData: function(instanceId) {
            if (!isSafeId(instanceId) || !(instanceId in instances)) return null;
            const instance = instances[instanceId];
            if (instance.audioBlob) {
                const fileName = applyFilters('starmus_recorder_filename', 'starmus-recording.webm', instanceId);
                const data = {
                    blob: instance.audioBlob,
                    fileName,
                    mimeType: instance.audioBlob.type || 'audio/webm',
                    size: instance.audioBlob.size,
                    metadata: { recordedAt: new Date(instance.startTime).toISOString() }
                };
                return applyFilters('starmus_submission_data', data, instanceId);
            }
            return null;
        },

        cleanup: function(instanceId) {
            if (!isSafeId(instanceId) || !(instanceId in instances)) return;
            const instance = instances[instanceId];
            if (instance.stream) {
                instance.stream.getTracks().forEach(track => track.stop());
            }
            doAction('starmus_recorder_cleanup', instanceId);
            delete instances[instanceId];
        },

        // Debug only
        _instances: instances
    };
})(window);
