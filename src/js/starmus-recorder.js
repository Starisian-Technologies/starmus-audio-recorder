/**
 * @file starmus-recorder.js
 * @version 4.5.0
 * @description Handles microphone recording, Calibration, Speech Rec, and Visualizer.
 * Merged: Production logic + Visualizer UI updates + Bug fixes.
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
 * Uses 3-phase calibration: quiet → speech → quiet
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
        const DURATION = 15000; // 15 seconds
        const VOLUME_SCALE_FACTOR = 2000;
        
        function tick() {
            const elapsed = performance.now() - startTime;
            const remaining = Math.ceil((DURATION - elapsed) / 1000);
            
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
            for (let i = 0; i < buffer.length; i++) {sum += buffer[i] * buffer[i];}
            const rms = Math.sqrt(sum / buffer.length);

            if (!Number.isFinite(rms)) {
                requestAnimationFrame(tick);
                return;
            }

            samples.push(rms);
            const volumePercent = Math.min(100, rms * VOLUME_SCALE_FACTOR);
            
            if (onUpdate) {onUpdate(message, volumePercent, false);}
            
            if (elapsed < DURATION) {
                requestAnimationFrame(tick);
            } else {
                done();
            }
        }
        
        function done() {
            const third = Math.floor(samples.length / 3);
            const quietSamples = samples.slice(0, third);
            const speechSamples = samples.slice(third, third * 2);
            
            const noiseFloor = quietSamples.reduce((a, b) => a + b, 0) / quietSamples.length;
            const speechLevel = speechSamples.reduce((a, b) => a + b, 0) / speechSamples.length;
            const snr = speechLevel / Math.max(noiseFloor, 1e-6);
            
            const gain = snr < 3 ? 6.0 : Math.max(1.0, Math.min(4.0, 0.1 / Math.max(speechLevel, 1e-6)));
            
            const calibration = {
                gain: parseFloat(gain.toFixed(3)),
                snr: parseFloat(snr.toFixed(3)),
                noiseFloor: parseFloat(noiseFloor.toFixed(6)),
                speechLevel: parseFloat(speechLevel.toFixed(6)),
                timestamp: new Date().toISOString()
            };
            
            try { microphone.disconnect(); } catch {
                // Ignore disconnect errors (already disconnected)
            }
            try { analyser.disconnect(); } catch {
                // Ignore disconnect errors (already disconnected)
            }
            
            if (onUpdate) {onUpdate(`Ready. Mic calibrated (gain ×${gain.toFixed(1)})`, null, true);}
            resolve(calibration);
        }
        
        tick();
    });
}

function getOptimalAudioSettings(env, config) {
    const network = env?.network || {};
    const allowedMimes = config?.allowedMimeTypes || {};
    
    let mimeType = 'audio/webm;codecs=opus'; 
    if (allowedMimes['mp4']) {mimeType = 'audio/mp4';}
    else if (allowedMimes['wav']) {mimeType = 'audio/wav';}
    
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
            mimeType: mimeType,
            audioBitsPerSecond: 24000
        }
    };
    
    if (network.effectiveType === '4g' || network.downlink > 2) {
        settings.constraints.audio.sampleRate = 48000;
        settings.options.audioBitsPerSecond = 128000;
    }
    
    return settings;
}

/**
 * Sets up the audio node chain.
 * INCLUDES ANALYSER FOR VISUALIZER.
 */
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
    
    // VISUALIZER NODE
    const analyser = audioContext.createAnalyser();
    analyser.fftSize = 2048;

    const destination = audioContext.createMediaStreamAudioDestination();
    
    source.connect(highPass);
    highPass.connect(compressor);
    compressor.connect(analyser); // Analyser sits before destination
    analyser.connect(destination);
    
    return {
        audioContext,
        destinationStream: destination.stream,
        analyser, // Exposed for meterLoop
        nodes: [source, highPass, compressor, analyser, destination] 
    };
}

export function initRecorder(store, instanceId) {
    CommandBus.subscribe('start-mic', async (_payload, meta) => {
        if (meta.instanceId !== instanceId) {return;}

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            store.dispatch({
                type: 'starmus/error',
                error: { message: 'Microphone not supported.', retryable: false },
            });
            return;
        }

        try {
            const state = store.getState();
            const env = state.env || {};
            const config = window.starmusConfig || {};
            
            const audioSettings = getOptimalAudioSettings(env, config);
            
            // 1. Get RAW Stream
            const rawStream = await navigator.mediaDevices.getUserMedia(audioSettings.constraints);
            
            // 2. Calibrate
            store.dispatch({ type: 'starmus/calibration-start' });
            const calibration = await calibrateAudioLevels(rawStream, (message, volumePercent, isDone) => {
                if (isDone) {
                    store.dispatch({ type: 'starmus/calibration-complete', calibration });
                } else {
                    store.dispatch({ type: 'starmus/calibration-update', message, volumePercent });
                }
            });
            
            // 3. Process Audio
            const { audioContext, destinationStream, analyser, nodes } = setupAudioGraph(rawStream);

            if (audioContext.state === 'suspended') {
                await audioContext.resume();
            }

            // 4. MediaRecorder
            const recorderOptions = {};
            if (MediaRecorder.isTypeSupported(audioSettings.options.mimeType)) {
                recorderOptions.mimeType = audioSettings.options.mimeType;
                recorderOptions.audioBitsPerSecond = audioSettings.options.audioBitsPerSecond;
            }
            
            const mediaRecorder = new MediaRecorder(destinationStream, recorderOptions);
            const chunks = [];
            let transcript = '';

            // 5. Speech Rec
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            let recognition = null;
            
            if (SpeechRecognition && env.speechSupported) {
                try {
                    recognition = new SpeechRecognition();
                    recognition.continuous = true;
                    recognition.interimResults = true;
                    recognition.lang = 'en-US'; 
                    
                    recognition.onresult = (event) => {
                        let currentTranscript = '';
                        for (let i = 0; i < event.results.length; i++) {
                            if (event.results[i].isFinal) {currentTranscript += event.results[i][0].transcript + ' ';}
                        }
                        transcript += currentTranscript;
                        store.dispatch({ type: 'starmus/transcript-update', transcript: transcript.trim() });
                    };
                    recognition.start();
                } catch (err) {
                    debugLog('Speech Rec failed:', err);
                }
            }

            // --- VISUALIZER & TIMER LOOP ---
            const startTime = performance.now();
            const meterBuffer = new Float32Array(analyser.fftSize);

            function meterLoop() {
                if (mediaRecorder.state !== 'recording') {return;}

                analyser.getFloatTimeDomainData(meterBuffer);
                let sum = 0;
                for (let i = 0; i < meterBuffer.length; i++) {sum += meterBuffer[i] * meterBuffer[i];}
                const rms = Math.sqrt(sum / meterBuffer.length);
                const amplitude = Math.min(100, Math.max(0, rms * 4000)); 

                const elapsed = (performance.now() - startTime) / 1000;

                store.dispatch({
                    type: 'starmus/recorder-tick',
                    duration: elapsed,
                    amplitude: amplitude
                });

                recRef.rafId = requestAnimationFrame(meterLoop);
            }

            // --- CRITICAL FIX: Define ref BEFORE attaching listeners to avoid ReferenceError ---
            const recRef = { 
                mediaRecorder, 
                chunks, 
                rawStream, 
                processedStream: destinationStream, 
                audioContext,
                audioNodes: nodes,
                recognition, 
                transcript, 
                calibration,
                rafId: null 
            };
            
            recorderRegistry.set(instanceId, recRef);

            // Listeners
            mediaRecorder.ondataavailable = (e) => {
                if (e.data && e.data.size > 0) {chunks.push(e.data);}
            };

            mediaRecorder.onstop = () => {
                if (recRef.rafId) {cancelAnimationFrame(recRef.rafId);}
                if (recRef.recognition) {try { recRef.recognition.stop(); } catch {
                    // Ignore speech recognition stop errors
                }}

                const blob = new Blob(chunks, { type: mediaRecorder.mimeType || 'audio/webm' });
                const fileName = `starmus-recording-${Date.now()}.webm`;

                store.dispatch({
                    type: 'starmus/recording-available',
                    payload: { blob, fileName }
                });

                // Cleanup
                if (recRef.rawStream) {recRef.rawStream.getTracks().forEach(t => t.stop());}
                if (recRef.processedStream) {recRef.processedStream.getTracks().forEach(t => t.stop());}
                if (recRef.audioNodes) {recRef.audioNodes.forEach(n => { try { n.disconnect(); } catch {
                    // Ignore node disconnect errors (already disconnected)
                } });}
                
                recorderRegistry.delete(instanceId);
            };

            // Start
            store.dispatch({ type: 'starmus/mic-start' });
            mediaRecorder.start(1000);
            
            // Start loop
            recRef.rafId = requestAnimationFrame(meterLoop);

        } catch (error) {
            console.error(error);
            store.dispatch({
                type: 'starmus/error',
                error: { message: 'Could not access microphone.' },
            });
        }
    });

    CommandBus.subscribe('stop-mic', (_payload, meta) => {
        if (meta.instanceId !== instanceId) {return;}
        const rec = recorderRegistry.get(instanceId);
        if (rec?.mediaRecorder?.state === 'recording') {
            store.dispatch({ type: 'starmus/mic-stop' });
            rec.mediaRecorder.stop();
        }
    });

    CommandBus.subscribe('reset', (_payload, meta) => {
        if (meta.instanceId !== instanceId) {return;}
        const rec = recorderRegistry.get(instanceId);
        if (rec) {
            if (rec.mediaRecorder && rec.mediaRecorder.state !== 'inactive') {rec.mediaRecorder.stop();}
            if (rec.rafId) {cancelAnimationFrame(rec.rafId);}
            if (rec.rawStream) {rec.rawStream.getTracks().forEach(t => t.stop());}
            recorderRegistry.delete(instanceId);
        }
    });
}