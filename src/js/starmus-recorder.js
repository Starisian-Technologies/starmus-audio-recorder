/**
 * @file starmus-recorder.js
 * @version 4.5.3
 * @description Handles microphone recording, Calibration, Speech Rec, and Visualizer.
 * Production-ready: payload-compatible actions and safe meter loop.
 */

'use strict';

import { CommandBus, debugLog } from './starmus-hooks.js';

const recorderRegistry = new Map();
let sharedAudioContext = null;

function getSharedContext() {
    if (!sharedAudioContext || sharedAudioContext.state === 'closed') {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        sharedAudioContext = new Ctx({ latencyHint: 'playback' });
    }
    return sharedAudioContext;
}

/**
 * Analyze audio stream to determine optimal gain adjustment.
 */
async function calibrateAudioLevels(stream, onUpdate) {
    return new Promise((resolve) => {
        const audioContext = getSharedContext();
        const analyser = audioContext.createAnalyser();
        analyser.fftSize = 2048;

        const microphone = audioContext.createMediaStreamSource(stream);
        microphone.connect(analyser);

        const buffer = new Float32Array(analyser.fftSize);
        const samples = [];
        const startTime = performance.now();
        const DURATION = 15000;
        const VOLUME_SCALE_FACTOR = 2000;

        function tick() {
            const elapsed = performance.now() - startTime;
            const remaining = Math.max(0, Math.ceil((DURATION - elapsed) / 1000));

            let message = '';
            if (elapsed < 5000) {
                message = `Be quiet for background noise (${remaining}s)`;
            } else if (elapsed < 10000) {
                message = `Now speak at normal volume (${remaining}s)`;
            } else {
                message = `Be quiet again (${remaining}s)`;
            }

            analyser.getFloatTimeDomainData(buffer);
            let sum = 0;
            for (let i = 0; i < buffer.length; i++) {
                sum += buffer[i] * buffer[i];
            }
            const rms = Math.sqrt(sum / buffer.length);

            if (!Number.isFinite(rms)) {
                requestAnimationFrame(tick);
                return;
            }

            samples.push(rms);
            const volumePercent = Math.min(100, rms * VOLUME_SCALE_FACTOR);

            if (onUpdate) {
                onUpdate(message, volumePercent, false);
            }

            if (elapsed < DURATION) {
                requestAnimationFrame(tick);
            } else {
                done();
            }
        }

        function done() {
            const third = Math.max(1, Math.floor(samples.length / 3));
            const quietSamples = samples.slice(0, third);
            const speechSamples = samples.slice(third, third * 2);

            const avg = (arr) =>
                arr.length ? arr.reduce((a, b) => a + b, 0) / arr.length : 0;

            const noiseFloor = avg(quietSamples);
            const speechLevel = avg(speechSamples);
            const snr = speechLevel / Math.max(noiseFloor, 1e-6);

            const gain = snr < 3
                ? 6.0
                : Math.max(1.0, Math.min(4.0, 0.1 / Math.max(speechLevel, 1e-6)));

            const calibration = {
                gain: parseFloat(gain.toFixed(3)),
                snr: parseFloat(snr.toFixed(3)),
                noiseFloor: parseFloat(noiseFloor.toFixed(6)),
                speechLevel: parseFloat(speechLevel.toFixed(6)),
                timestamp: new Date().toISOString()
            };

            try {
                microphone.disconnect();
            } catch {
                // Microphone may already be disconnected
            }
            try {
                analyser.disconnect();
            } catch {
                // Analyser may already be disconnected
            }

            if (onUpdate) {
                onUpdate(`Ready. Mic calibrated (gain ×${gain.toFixed(1)})`, null, true);
            }
            resolve(calibration);
        }

        tick();
    });
}

function getOptimalAudioSettings(env, config) {
    const network = (env && env.network) || {};
    const allowedMimes = (config && config.allowedMimeTypes) || {};

    let mimeType = 'audio/webm;codecs=opus';
    if (allowedMimes.mp4) {
        mimeType = 'audio/mp4';
    } else if (allowedMimes.wav) {
        mimeType = 'audio/wav';
    }

    const settings = {
        constraints: {
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
                sampleRate: 16000,
                channelCount: 1
            }
        },
        options: {
            mimeType,
            audioBitsPerSecond: 24000
        }
    };

    if (network.effectiveType === '4g' || (network.downlink || 0) > 2) {
        settings.constraints.audio.sampleRate = 48000;
        settings.options.audioBitsPerSecond = 128000;
    }

    return settings;
}

function setupAudioGraph(rawStream) {
    const audioContext = getSharedContext();
    const source = audioContext.createMediaStreamSource(rawStream);

    const highPass = audioContext.createBiquadFilter();
    highPass.type = 'highpass';
    highPass.frequency.value = 85;

    const compressor = audioContext.createDynamicsCompressor();
    compressor.threshold.value = -20;
    compressor.knee.value = 40;
    compressor.ratio.value = 12;
    compressor.attack.value = 0;
    compressor.release.value = 0.25;

    const analyser = audioContext.createAnalyser();
    analyser.fftSize = 2048;

    const destination = audioContext.createMediaStreamAudioDestination();

    source.connect(highPass);
    highPass.connect(compressor);
    compressor.connect(analyser);
    analyser.connect(destination);

    return {
        audioContext,
        destinationStream: destination.stream,
        analyser,
        nodes: [source, highPass, compressor, analyser, destination]
    };
}

export function initRecorder(store, instanceId) {
    // START MIC
    CommandBus.subscribe('start-mic', async (_payload, meta) => {
        if (meta.instanceId !== instanceId) {
            return;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            store.dispatch({
                type: 'starmus/error',
                payload: { message: 'Microphone not supported.', retryable: false },
                error: { message: 'Microphone not supported.', retryable: false }
            });
            return;
        }

        try {
            const state = store.getState();
            const env = state.env || {};
            const config = window.starmusConfig || {};

            const audioSettings = getOptimalAudioSettings(env, config);

            const rawStream = await navigator.mediaDevices.getUserMedia(
                audioSettings.constraints
            );

            const needsCalibration = !state.calibration || !state.calibration.complete;
            
            let calibrationResult = state.calibration || {};
            
            if (needsCalibration) {
                store.dispatch({ type: 'starmus/calibration-start' });

                calibrationResult = await calibrateAudioLevels(
                    rawStream,
                    (message, volumePercent, isDone) => {
                        if (!isDone) {
                            store.dispatch({
                                type: 'starmus/calibration-update',
                                payload: { message, volumePercent },
                                message,
                                volumePercent
                            });
                        }
                    }
                );

                // Dispatch calibration complete AFTER await
                store.dispatch({
                    type: 'starmus/calibration-complete',
                    payload: calibrationResult,
                    calibration: calibrationResult
                });                // Stop the calibration stream - user will click Record again
                rawStream.getTracks().forEach(track => track.stop());
                return;
            }

            // If already calibrated, proceed directly to recording
            const {
                audioContext,
                destinationStream,
                analyser,
                nodes
            } = setupAudioGraph(rawStream);

            if (audioContext.state === 'suspended') {
                await audioContext.resume();
            }

            const recorderOptions = {};
            if (MediaRecorder.isTypeSupported(audioSettings.options.mimeType)) {
                recorderOptions.mimeType = audioSettings.options.mimeType;
                recorderOptions.audioBitsPerSecond =
                    audioSettings.options.audioBitsPerSecond;
            }

            const mediaRecorder = new MediaRecorder(destinationStream, recorderOptions);
            const chunks = [];
            let transcript = '';

            const SpeechRecognition =
                window.SpeechRecognition || window.webkitSpeechRecognition;
            let recognition = null;

            const speechSupported = !!(SpeechRecognition && env.speechSupported);

			if (speechSupported) {
				try {
					recognition = new SpeechRecognition();
					recognition.continuous = true;
					recognition.interimResults = true;
					recognition.lang = config.speechRecognitionLang || 'en-US';                    recognition.onresult = (event) => {
                        let currentTranscript = '';
                        for (let i = 0; i < event.results.length; i++) {
                            if (event.results[i].isFinal) {
                                currentTranscript +=
                                    event.results[i][0].transcript + ' ';
                            }
                        }
                        if (currentTranscript) {
                            transcript += currentTranscript;
                            const normalized = transcript.trim();
                            store.dispatch({
                                type: 'starmus/transcript-update',
                                payload: { transcript: normalized },
                                transcript: normalized
                            });
                        }
                    };

                    recognition.start();
                } catch (err) {
                    debugLog('Speech Rec failed:', err);
                }
            }

            // VISUALIZER LOOP
            const startTime = performance.now();
            const meterBuffer = new Float32Array(analyser.fftSize);

            const recRef = {
                mediaRecorder,
                chunks,
                rawStream,
                processedStream: destinationStream,
                audioContext,
                audioNodes: nodes,
                analyser,
                recognition,
                transcript,
                calibration: calibrationResult,
                startTime,
                rafId: null
            };
            recorderRegistry.set(instanceId, recRef);

            function meterLoop() {
                const active = recorderRegistry.get(instanceId);
                if (!active || active.mediaRecorder.state !== 'recording') {
                    return;
                }

                analyser.getFloatTimeDomainData(meterBuffer);
                let sum = 0;
                for (let i = 0; i < meterBuffer.length; i++) {
                    sum += meterBuffer[i] * meterBuffer[i];
                }
                const rms = Math.sqrt(sum / meterBuffer.length);
                const amplitude = Math.min(100, Math.max(0, rms * 4000));

                const elapsed = (performance.now() - startTime) / 1000;

                store.dispatch({
                    type: 'starmus/recorder-tick',
                    payload: { duration: elapsed, amplitude },
                    duration: elapsed,
                    amplitude
                });

                active.rafId = requestAnimationFrame(meterLoop);
            }

            mediaRecorder.ondataavailable = (e) => {
                if (e.data && e.data.size > 0) {
                    chunks.push(e.data);
                }
            };

            mediaRecorder.onstop = () => {
                const activeRec = recorderRegistry.get(instanceId);
                if (!activeRec) {
                    return;
                }

                if (activeRec.rafId) {
                    cancelAnimationFrame(activeRec.rafId);
                }

                if (activeRec.recognition) {
                    try {
                        activeRec.recognition.stop();
                    } catch {
                        // Speech recognition may already be stopped
                    }
                }

                const blob = new Blob(chunks, {
                    type: mediaRecorder.mimeType || 'audio/webm'
                });
                const fileName = `starmus-recording-${Date.now()}.webm`;

                store.dispatch({
                    type: 'starmus/recording-available',
                    payload: { blob, fileName }
                });

                if (activeRec.rawStream) {
                    activeRec.rawStream.getTracks().forEach((t) => t.stop());
                }
                if (activeRec.processedStream) {
                    activeRec.processedStream.getTracks().forEach((t) => t.stop());
                }
                if (activeRec.audioNodes) {
                    activeRec.audioNodes.forEach((n) => {
                        try {
                            n.disconnect();
                        } catch {
                            // Node may already be disconnected
                        }
                    });
                }

                recorderRegistry.delete(instanceId);
            };

            store.dispatch({ type: 'starmus/mic-start' });
            mediaRecorder.start(1000);

            recRef.rafId = requestAnimationFrame(meterLoop);
        } catch (error) {
            console.error(error);
            store.dispatch({
                type: 'starmus/error',
                payload: { message: 'Could not access microphone.' },
                error: { message: 'Could not access microphone.' }
            });
        }
    });

    // STOP MIC
    CommandBus.subscribe('stop-mic', (_payload, meta) => {
        if (meta.instanceId !== instanceId) {
            return;
        }
        const rec = recorderRegistry.get(instanceId);
        if (rec && rec.mediaRecorder && rec.mediaRecorder.state === 'recording') {
            store.dispatch({ type: 'starmus/mic-stop' });
            rec.mediaRecorder.stop();
        }
    });

    // PAUSE MIC
    CommandBus.subscribe('pause-mic', (_payload, meta) => {
        if (meta.instanceId !== instanceId) {
            return;
        }
        const rec = recorderRegistry.get(instanceId);
        if (rec && rec.mediaRecorder && rec.mediaRecorder.state === 'recording') {
            store.dispatch({ type: 'starmus/mic-pause' });
            rec.mediaRecorder.pause();
            if (rec.rafId) {
                cancelAnimationFrame(rec.rafId);
                rec.rafId = null;
            }
        }
    });

    // RESUME MIC
    CommandBus.subscribe('resume-mic', (_payload, meta) => {
        if (meta.instanceId !== instanceId) {
            return;
        }
        const rec = recorderRegistry.get(instanceId);
        if (rec && rec.mediaRecorder && rec.mediaRecorder.state === 'paused') {
            store.dispatch({ type: 'starmus/mic-resume' });
            rec.mediaRecorder.resume();
            
            // Restart the meter loop
            function resumeMeterLoop() {
                const active = recorderRegistry.get(instanceId);
                if (!active || active.mediaRecorder.state !== 'recording') {
                    return;
                }
                
                const meterBuffer = new Float32Array(active.analyser.fftSize);
                active.analyser.getFloatTimeDomainData(meterBuffer);
                let sum = 0;
                for (let i = 0; i < meterBuffer.length; i++) {
                    sum += meterBuffer[i] * meterBuffer[i];
                }
                const rms = Math.sqrt(sum / meterBuffer.length);
                const amplitude = Math.min(100, Math.max(0, rms * 4000));
                
                // Calculate elapsed time from original startTime
                const elapsed = (performance.now() - active.startTime) / 1000;
                
                store.dispatch({
                    type: 'starmus/recorder-tick',
                    payload: { duration: elapsed, amplitude },
                    duration: elapsed,
                    amplitude
                });
                
                active.rafId = requestAnimationFrame(resumeMeterLoop);
            }
            
            rec.rafId = requestAnimationFrame(resumeMeterLoop);
        }
    });

    // RESET
    CommandBus.subscribe('reset', (_payload, meta) => {
        if (meta.instanceId !== instanceId) {
            return;
        }
        const rec = recorderRegistry.get(instanceId);
        if (rec) {
            if (rec.mediaRecorder && rec.mediaRecorder.state !== 'inactive') {
                try {
                    rec.mediaRecorder.stop();
                } catch {
                    // MediaRecorder may already be stopped
                }
            }
            if (rec.rafId) {
                cancelAnimationFrame(rec.rafId);
            }
            if (rec.rawStream) {
                rec.rawStream.getTracks().forEach((t) => t.stop());
            }
            if (rec.processedStream) {
                rec.processedStream.getTracks().forEach((t) => t.stop());
            }
            recorderRegistry.delete(instanceId);
        }
    });
}
