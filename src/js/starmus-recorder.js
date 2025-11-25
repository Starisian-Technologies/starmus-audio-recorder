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

/**
 * Emit telemetry events via StarmusHooks.
 */
function emitStarmusEvent(instanceId, event, payload = {}) {
    try {
        if (window.StarmusHooks && typeof window.StarmusHooks.doAction === 'function') {
            window.StarmusHooks.doAction('starmus_event', {
                instanceId,
                event,
                severity: payload.severity || 'info',
                message: payload.message || '',
                data: payload.data || {}
            });
        }
    } catch (e) {
        console.warn('[Starmus] Telemetry emit failed:', e);
    }
}

/**
 * PATCH 5: Wait for MediaStream track to be ready
 * Prevents "stream not ready" errors on ChromeOS and other devices.
 */
async function starmusWaitForTrack(stream) {
    const audioTrack = stream.getAudioTracks()[0];
    if (!audioTrack) {
        return;
    }

    if (audioTrack.readyState !== 'live') {
        await new Promise((resolve) => {
            const check = setInterval(() => {
                if (audioTrack.readyState === 'live') {
                    clearInterval(check);
                    resolve();
                }
            }, 50);
            // Timeout after 5 seconds
            setTimeout(() => {
                clearInterval(check);
                resolve();
            }, 5000);
        });
    }
}


function getSharedContext() {
    if (!sharedAudioContext || sharedAudioContext.state === 'closed') {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) {
            throw new Error('AudioContext not supported in this browser');
        }
        // PATCH 2: Create suspended, resume on user gesture
        sharedAudioContext = new Ctx({ latencyHint: 'interactive' });
        debugLog('[Recorder] Created new AudioContext, state:', sharedAudioContext.state);
        
        // Validate required AudioContext methods
        if (typeof sharedAudioContext.createMediaStreamSource !== 'function') {
            debugLog('[Recorder] ERROR: createMediaStreamSource not available');
            throw new Error('Browser does not support required audio API');
        }
        // Note: createMediaStreamDestination is optional - we fall back to minimal graph
    }
    return sharedAudioContext;
}

/**
 * PATCH 8: Safe wrapper for createMediaStreamDestination with legacy fallback.
 * Fixes method name typo (createMediaStreamAudioDestination doesn't exist).
 */
function createMediaStreamDestinationSafe(audioContext) {
    if (!audioContext) {
        throw new Error('No AudioContext provided');
    }

    const fn =
        audioContext.createMediaStreamDestination ||
        audioContext.createMediaStreamAudioDestination; // legacy fallback if it ever exists

    if (typeof fn !== 'function') {
        throw new Error('MediaStreamDestination not supported on this AudioContext');
    }

    const destNode = fn.call(audioContext);

    if (!destNode || !destNode.stream) {
        throw new Error('MediaStreamDestination node has no stream');
    }

    return destNode;
}

// PATCH 2: Resume AudioContext on first user interaction
document.addEventListener('click', async () => {
    if (sharedAudioContext && sharedAudioContext.state === 'suspended') {
        try {
            await sharedAudioContext.resume();
            debugLog('[Recorder] AudioContext resumed on user gesture');
        } catch (e) {
            debugLog('[Recorder] Failed to resume AudioContext:', e);
        }
    }
}, { once: true });

// PATCH 9: ChromeOS AudioContext watchdog - prevents auto-suspend after 0.3s
setInterval(async () => {
    if (!sharedAudioContext) {
        return;
    }
    if (sharedAudioContext.state === 'suspended') {
        try {
            await sharedAudioContext.resume();
            debugLog('[Recorder] Watchdog resumed AudioContext');
        } catch {
            // Silent fail
        }
    }
}, 500);

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

            // PATCH 3: Skip RMS when AudioContext not running (prevents false tier downgrade)
            if (audioContext.state !== 'running') {
                requestAnimationFrame(tick);
                return;
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

    // PATCH 8: Try createMediaStreamDestinationSafe first, fallback to minimal graph
    let destination;
    try {
        destination = createMediaStreamDestinationSafe(audioContext);
    } catch (err) {
        debugLog('[Recorder] createMediaStreamDestination not available:', err.message);
        debugLog('[Recorder] Using minimal graph fallback (MDN baseline)');
        const analyser = audioContext.createAnalyser();
        analyser.fftSize = 2048;
        source.connect(analyser);

        return {
            audioContext,
            destinationStream: rawStream, // Use raw stream for MediaRecorder
            analyser,
            nodes: [source, analyser],
            fallbackActive: true
        };
    }

    // Full processing graph when API available
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

    source.connect(highPass);
    highPass.connect(compressor);
    compressor.connect(analyser);
    analyser.connect(destination);

    return {
        audioContext,
        destinationStream: destination.stream,
        analyser,
        nodes: [source, highPass, compressor, analyser, destination],
        fallbackActive: false
    };
}

export function initRecorder(store, instanceId) {
    // SETUP MIC (Calibration only)
    CommandBus.subscribe('setup-mic', async (_payload, meta) => {
        if (meta.instanceId !== instanceId) {
            return;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            const msg = 'Microphone not supported.';
            emitStarmusEvent(instanceId, 'E_RECORDER_UNSUPPORTED', {
                severity: 'error',
                message: msg
            });
            store.dispatch({
                type: 'starmus/error',
                payload: { message: msg, retryable: false },
                error: { message: msg, retryable: false }
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

            emitStarmusEvent(instanceId, 'E_MIC_ACCESS', {
                severity: 'info',
                message: 'Microphone access granted, starting calibration'
            });

            store.dispatch({ type: 'starmus/calibration-start' });

            const calibrationResult = await calibrateAudioLevels(
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

            // Stop calibration stream
            rawStream.getTracks().forEach(track => track.stop());

            store.dispatch({
                type: 'starmus/calibration-complete',
                payload: { calibration: calibrationResult },
                calibration: calibrationResult
            });

        } catch (error) {
            const errorMsg = error.name === 'NotAllowedError'
                ? 'Microphone permission denied.'
                : 'Failed to access microphone.';
            
            emitStarmusEvent(instanceId, 'E_MIC_ACCESS', {
                severity: 'error',
                message: errorMsg,
                error: error.message
            });

            store.dispatch({
                type: 'starmus/error',
                payload: { message: errorMsg, retryable: true },
                error: { message: errorMsg, retryable: true }
            });
        }
    });

    // START RECORDING (after calibration)
    CommandBus.subscribe('start-recording', async (_payload, meta) => {
        if (meta.instanceId !== instanceId) {
            return;
        }

        console.log('[Recorder] start-recording command received for instance:', instanceId);

        const state = store.getState();
        console.log('[Recorder] Current state:', {
            status: state.status,
            calibrationComplete: state.calibration?.complete,
            tier: state.tier
        });
        
        // PATCH 4: Prevent recording during calibration
        if (state.status === 'calibrating') {
            console.warn('[Recorder] Cannot start recording while calibrating');
            store.dispatch({
                type: 'starmus/error',
                payload: { message: 'Please wait for calibration to complete.', retryable: true },
                error: { message: 'Cannot start recording during calibration', retryable: true }
            });
            return;
        }
        
        // Ensure calibration was completed
        if (!state.calibration || !state.calibration.complete) {
            console.error('[Recorder] Calibration not complete, cannot start recording');
            store.dispatch({
                type: 'starmus/error',
                payload: { message: 'Please setup your microphone first.', retryable: true },
                error: { message: 'Please setup your microphone first.', retryable: true }
            });
            return;
        }

        try {
            console.log('[Recorder] Getting audio settings...');
            const env = state.env || {};
            const config = window.starmusConfig || {};

            const audioSettings = getOptimalAudioSettings(env, config);
            console.log('[Recorder] Audio settings:', audioSettings);

            console.log('[Recorder] Requesting microphone access...');
            const rawStream = await navigator.mediaDevices.getUserMedia(
                audioSettings.constraints
            );
            console.log('[Recorder] Got rawStream:', rawStream, 'tracks:', rawStream.getTracks());

            const calibrationResult = state.calibration;

            // Proceed directly to recording (calibration already done)
            let audioContext, destinationStream, analyser, nodes;
            try {
                console.log('[Recorder] Setting up audio graph...');
                const audioGraph = setupAudioGraph(rawStream);
                audioContext = audioGraph.audioContext;
                destinationStream = audioGraph.destinationStream;
                analyser = audioGraph.analyser;
                nodes = audioGraph.nodes;
                
                console.log('[Recorder] Audio graph created successfully:', {
                    hasContext: !!audioContext,
                    hasDestStream: !!destinationStream,
                    hasAnalyser: !!analyser,
                    contextState: audioContext?.state
                });
            } catch (graphError) {
                console.error('[Recorder] Audio graph setup FAILED:', graphError);
                debugLog('[Recorder] Audio graph setup failed:', graphError);
                
                emitStarmusEvent(instanceId, 'E_AUDIO_GRAPH_FAIL', {
                    severity: 'warning',
                    message: 'Using minimal audio graph (MDN baseline)'
                });
                
                // PATCH 10: Do NOT downgrade tier - minimal graph is still Tier A
                // The setupAudioGraph already provides fallback with raw stream
                // So this error should not occur, but if it does, don't break recording
                
                rawStream.getTracks().forEach(track => track.stop());
                
                store.dispatch({
                    type: 'starmus/error',
                    payload: { message: 'Audio processing error. Please try again.', retryable: true },
                    error: graphError
                });
                return;
            }

            if (audioContext.state === 'suspended') {
                await audioContext.resume();
            }

            // PATCH 5: Wait for audio track to be ready
            await starmusWaitForTrack(destinationStream);

            // PATCH 3: Validate stream before creating MediaRecorder
            if (!destinationStream || typeof destinationStream.getTracks !== 'function') {
                console.error('[Recorder] No valid audio stream available before MediaRecorder');
                rawStream.getTracks().forEach(t => t.stop());
                store.dispatch({
                    type: 'starmus/error',
                    payload: { message: 'Microphone stream unavailable.', retryable: true },
                    error: { message: 'Invalid destination stream', retryable: true }
                });
                return;
            }

            const audioTracks = destinationStream.getAudioTracks();
            if (!audioTracks.length) {
                console.error('[Recorder] No audio tracks on stream');
                rawStream.getTracks().forEach(t => t.stop());
                store.dispatch({
                    type: 'starmus/error',
                    payload: { message: 'No audio tracks available.', retryable: true },
                    error: { message: 'Stream has no audio tracks', retryable: true }
                });
                return;
            }

            console.log('[Recorder] Stream validation passed:', {
                hasStream: !!destinationStream,
                trackCount: audioTracks.length,
                trackStates: audioTracks.map(t => ({ kind: t.kind, enabled: t.enabled, readyState: t.readyState }))
            });

            const recorderOptions = {};
            if (MediaRecorder.isTypeSupported(audioSettings.options.mimeType)) {
                recorderOptions.mimeType = audioSettings.options.mimeType;
                recorderOptions.audioBitsPerSecond =
                    audioSettings.options.audioBitsPerSecond;
            }

            // PATCH 1: Harden MediaRecorder creation with try/catch
            let mediaRecorder = null;
            try {
                mediaRecorder = new MediaRecorder(destinationStream, recorderOptions);
                console.log('[Recorder] MediaRecorder created successfully, state:', mediaRecorder.state);
            } catch (err) {
                console.error('[Recorder] MediaRecorder constructor failed:', err);
                rawStream.getTracks().forEach(t => t.stop());
                if (destinationStream !== rawStream) {
                    destinationStream.getTracks().forEach(t => t.stop());
                }
                store.dispatch({
                    type: 'starmus/error',
                    payload: { message: 'MediaRecorder could not be created: ' + (err.message || 'Unknown error'), retryable: true },
                    error: err
                });
                emitStarmusEvent(instanceId, 'E_RECORDER_CREATE_FAIL', {
                    severity: 'error',
                    message: 'MediaRecorder constructor failed',
                    error: err.message
                });
                return;
            }

            if (!mediaRecorder || mediaRecorder.state !== 'inactive') {
                console.error('[Recorder] MediaRecorder did not initialize properly, state:', mediaRecorder?.state);
                rawStream.getTracks().forEach(t => t.stop());
                if (destinationStream !== rawStream) {
                    destinationStream.getTracks().forEach(t => t.stop());
                }
                store.dispatch({
                    type: 'starmus/error',
                    payload: { message: 'MediaRecorder initialization failed.', retryable: true },
                    error: { message: 'MediaRecorder state invalid after creation', retryable: true }
                });
                return;
            }
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
                        let finalTranscript = '';
                        let interimTranscript = '';
                        
                        for (let i = 0; i < event.results.length; i++) {
                            const transcriptPiece = event.results[i][0].transcript;
                            if (event.results[i].isFinal) {
                                finalTranscript += transcriptPiece + ' ';
                            } else {
                                interimTranscript += transcriptPiece;
                            }
                        }
                        
                        // Fix 5: Dispatch interim results for real-time UX
                        if (interimTranscript) {
                            store.dispatch({
                                type: 'starmus/transcript-interim',
                                payload: { interim: interimTranscript },
                                interim: interimTranscript
                            });
                        }
                        
                        if (finalTranscript) {
                            transcript += finalTranscript;
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
                    emitStarmusEvent(instanceId, 'E_SPEECH_FAIL', {
                        severity: 'warning',
                        message: err?.message || 'Speech recognition initialization failed'
                    });
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
            
            // PATCH 2: Populate registry BEFORE dispatching mic-start
            // This ensures UI never shows "recording" without a recorder existing
            recorderRegistry.set(instanceId, recRef);
            
            // PATCH 7: Add telemetry on registry population
            debugLog('[Recorder] Registry populated', {
                instanceId,
                hasRecorder: !!mediaRecorder,
                streamTracks: destinationStream.getTracks().length,
                recorderState: mediaRecorder.state,
                hasAnalyser: !!analyser,
                hasContext: !!audioContext,
                contextState: audioContext.state
            });

            function meterLoop() {
                const active = recorderRegistry.get(instanceId);
                if (!active) {
                    return;
                }
                
                // Only stop the loop if recording is completely stopped
                const recState = active.mediaRecorder?.state;
                
                if (recState !== 'recording' && recState !== 'paused') {
                    return;
                }

                // AudioContext suspension recovery
                if (active.audioContext && active.audioContext.state === 'suspended') {
                    active.audioContext.resume().catch(e => {
                        emitStarmusEvent(instanceId, 'E_CTX_SUSPEND', {
                            severity: 'warning',
                            message: e?.message || 'AudioContext resume failed after suspension'
                        });
                    });
                }

                // Only read amplitude if actively recording (not paused)
                let amplitude = 0;
                if (recState === 'recording') {
                    analyser.getFloatTimeDomainData(meterBuffer);
                    let sum = 0;
                    for (let i = 0; i < meterBuffer.length; i++) {
                        sum += meterBuffer[i] * meterBuffer[i];
                    }
                    const rms = Math.sqrt(sum / meterBuffer.length);
                    amplitude = Math.min(100, Math.max(0, rms * 4000));
                }

                const elapsed = (performance.now() - startTime) / 1000;

                store.dispatch({
                    type: 'starmus/recorder-tick',
                    payload: { duration: elapsed, amplitude },
                    duration: elapsed,
                    amplitude
                });

                // Schedule next frame
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

                emitStarmusEvent(instanceId, 'REC_COMPLETE', {
                    severity: 'info',
                    message: 'Recording stopped and blob created',
                    data: {
                        mimeType: mediaRecorder.mimeType || 'audio/webm',
                        chunkCount: chunks.length
                    }
                });

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

            // PATCH 2: Now that registry is populated, change UI state
            console.log('[Recorder] Registry populated, now dispatching mic-start');
            store.dispatch({ type: 'starmus/mic-start' });
            
            // PATCH 2: Only then start the underlying recorder
            console.log('[Recorder] About to call mediaRecorder.start()');
            console.log('[Recorder] Pre-start state check:', {
                recorderExists: !!mediaRecorder,
                recorderState: mediaRecorder?.state,
                streamActive: destinationStream?.active,
                trackCount: destinationStream?.getTracks()?.length
            });
            
            try {
                mediaRecorder.start(3000); // 3-second chunks reduce memory pressure and offline queue size
                console.log('[Recorder] mediaRecorder.start() completed successfully');
                console.log('[Recorder] MediaRecorder state after start:', mediaRecorder.state);
            } catch (startError) {
                console.error('[Recorder] CRITICAL ERROR: mediaRecorder.start() failed:', startError);
                console.error('[Recorder] Error details:', {
                    name: startError.name,
                    message: startError.message,
                    recorderState: mediaRecorder?.state,
                    streamActive: destinationStream?.active
                });
                
                // Clean up the registry entry since recording failed
                recorderRegistry.delete(instanceId);
                
                // Stop all streams
                if (rawStream) {
                    rawStream.getTracks().forEach(t => t.stop());
                }
                if (destinationStream !== rawStream && destinationStream) {
                    destinationStream.getTracks().forEach(t => t.stop());
                }
                
                // Clean up audio nodes
                if (nodes) {
                    nodes.forEach(n => {
                        try { 
                            n.disconnect(); 
                        // eslint-disable-next-line no-empty
                        } catch {
                            // Node may already be disconnected
                        }
                    });
                }
                
                // Dispatch error to UI
                store.dispatch({
                    type: 'starmus/error',
                    payload: { 
                        message: 'Failed to start recording: ' + (startError.message || 'Unknown error'),
                        retryable: true 
                    },
                    error: startError
                });
                
                emitStarmusEvent(instanceId, 'E_RECORDER_START_FAIL', {
                    severity: 'error',
                    message: 'MediaRecorder.start() failed',
                    error: startError.message
                });
                
                return;
            }

            // Initial amplitude sample to avoid flat meter at start
            analyser.getFloatTimeDomainData(meterBuffer);
            let initialSum = 0;
            for (let i = 0; i < meterBuffer.length; i++) {
                initialSum += meterBuffer[i] * meterBuffer[i];
            }
            const initialRms = Math.sqrt(initialSum / meterBuffer.length);
            const initialAmplitude = Math.min(100, Math.max(0, initialRms * 4000));
            store.dispatch({
                type: 'starmus/recorder-tick',
                payload: { duration: 0, amplitude: initialAmplitude },
                duration: 0,
                amplitude: initialAmplitude
            });

            recRef.rafId = requestAnimationFrame(meterLoop);
        } catch (error) {
            console.error(error);
            emitStarmusEvent(instanceId, 'E_MIC_ACCESS', {
                severity: 'error',
                message: error?.message || 'Could not access microphone.'
            });
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
            // Don't cancel rafId - let the meter loop continue for duration updates
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
            // The meter loop is already running - it will pick up the new state
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
