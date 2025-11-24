/**
 * @file starmus-recorder.js
 * @version 4.4.0
 * @description Handles microphone recording with Web Audio API processing.
 * Production-grade: Hardened for Safari iOS leaks, Android NaN bugs, and low-end device crashes.
 */

'use strict';

import { CommandBus, debugLog } from './starmus-hooks.js';

// Registry to track active recording instances
// instanceId -> { mediaRecorder, chunks, rawStream, processedStream, audioContext, recognition, transcript, calibration }
const recorderRegistry = new Map();

/**
 * Shared AudioContext Singleton.
 * Prevents "dual-context" freezes on Android and prevents iOS limits on active contexts.
 */
let sharedAudioContext = null;

/**
 * Get or create the shared AudioContext instance.
 * @returns {AudioContext} Shared audio context
 */
function getSharedContext() {
    if (!sharedAudioContext || sharedAudioContext.state === 'closed') {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        // 'latencyHint: playback' tells the browser to use larger buffers,
        // saving significant CPU and battery on low-end devices.
        sharedAudioContext = new Ctx({ latencyHint: 'playback' });
    }
    return sharedAudioContext;
}

/**
 * Analyze audio stream to determine optimal gain adjustment.
 * Uses 3-phase calibration: quiet → speech → quiet
 * 
 * @param {MediaStream} stream - The audio stream from getUserMedia
 * @param {Function} onUpdate - Callback for UI updates (message, volumePercent, isDone)
 * @returns {Promise<object>} Calibration data
 */
async function calibrateAudioLevels(stream, onUpdate) {
    return new Promise((resolve) => {
        const audioContext = getSharedContext();
        const analyser = audioContext.createAnalyser();
        analyser.fftSize = 2048;
        
        // Create a temporary source node just for calibration
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
            
            // Get RMS (Root Mean Square) volume
            analyser.getFloatTimeDomainData(buffer);
            let sum = 0;
            for (let i = 0; i < buffer.length; i++) {
                sum += buffer[i] * buffer[i];
            }
            const rms = Math.sqrt(sum / buffer.length);

            // GUARD: Android WebRTC (especially on Tecno/Infinix) can return 
            // NaN/Infinity for the first few frames. Ignore them to prevent math errors.
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
            const third = Math.floor(samples.length / 3);
            const quietSamples = samples.slice(0, third);
            const speechSamples = samples.slice(third, third * 2);
            
            const noiseFloor = quietSamples.reduce((a, b) => a + b, 0) / quietSamples.length;
            const speechLevel = speechSamples.reduce((a, b) => a + b, 0) / speechSamples.length;
            const snr = speechLevel / Math.max(noiseFloor, 1e-6);
            
            // Adaptive gain calculation
            const gain = snr < 3 ? 6.0 : Math.max(1.0, Math.min(4.0, 0.1 / Math.max(speechLevel, 1e-6)));
            
            const calibration = {
                gain: parseFloat(gain.toFixed(3)),
                snr: parseFloat(snr.toFixed(3)),
                noiseFloor: parseFloat(noiseFloor.toFixed(6)),
                speechLevel: parseFloat(speechLevel.toFixed(6)),
                timestamp: new Date().toISOString()
            };
            
            // CLEANUP: Disconnect nodes to prevent memory leaks, but KEEP context open.
            try {
                microphone.disconnect();
            } catch {
                // Ignore if already disconnected
            }
            try {
                analyser.disconnect();
            } catch {
                // Ignore if already disconnected
            }
            
            const finalMessage = `Ready. Mic calibrated (gain ×${gain.toFixed(1)})`;
            if (onUpdate) {
                onUpdate(finalMessage, null, true);
            }
            
            resolve(calibration);
        }
        
        tick();
    });
}

/**
 * Determine optimal audio recording settings based on environment.
 */
function getOptimalAudioSettings(env, config) {
    const network = env?.network || {};
    const device = env?.device || {};
    const allowedMimes = config?.allowedMimeTypes || {};
    
    // Determine best MIME type (Opus preferred)
    let mimeType = 'audio/webm;codecs=opus'; 
    if (allowedMimes['mp4'] || allowedMimes['m4a']) {
        mimeType = 'audio/mp4';
    } else if (allowedMimes['wav']) {
        mimeType = 'audio/wav';
    }
    
    // Default settings for constrained networks (West Africa baseline)
    const settings = {
        constraints: {
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
                sampleRate: 16000, // 16kHz for voice
                channelCount: 1 // Mono
            }
        },
        options: {
            mimeType: mimeType,
            audioBitsPerSecond: 24000 // 24kbps for 2G/3G
        }
    };
    
    // Upgrade for better networks
    const effectiveType = network.effectiveType;
    const downlink = network.downlink;
    const isMobile = device.type === 'mobile';
    
    if (effectiveType === '4g' || downlink > 2) {
        // High-quality settings for 4G
        settings.constraints.audio.sampleRate = 48000; // 48kHz studio quality
        settings.constraints.audio.channelCount = 2; // Stereo if available
        settings.options.audioBitsPerSecond = 128000; // 128kbps
    } else if (effectiveType === '3g' || downlink > 0.5) {
        // Medium quality for 3G
        settings.constraints.audio.sampleRate = 22050; // 22kHz
        settings.options.audioBitsPerSecond = 48000; // 48kbps
    }
    
    if (network.saveData) {
        settings.constraints.audio.sampleRate = 16000;
        settings.constraints.audio.channelCount = 1;
        settings.options.audioBitsPerSecond = 16000;
    }
    
    if (!isMobile && (effectiveType === '4g' || downlink > 5)) {
        settings.constraints.audio.sampleRate = 48000;
        settings.options.audioBitsPerSecond = 192000;
    }
    
    return settings;
}

/**
 * Builds the Web Audio API graph.
 * Source -> HighPass (85Hz) -> Compressor -> Destination
 * 
 * @param {MediaStream} rawStream 
 * @returns {object} { audioContext, destinationStream, nodes[] }
 */
function setupAudioGraph(rawStream) {
    const audioContext = getSharedContext();
    
    const source = audioContext.createMediaStreamSource(rawStream);
    
    // 1. High-pass Filter: Remove wind/rumble
    const highPass = audioContext.createBiquadFilter();
    highPass.type = 'highpass';
    highPass.frequency.value = 85; 
    
    // 2. Dynamics Compressor: Balance volume
    const compressor = audioContext.createDynamicsCompressor();
    compressor.threshold.value = -20;
    compressor.knee.value = 40;
    compressor.ratio.value = 12;
    compressor.attack.value = 0;
    compressor.release.value = 0.25;
    
    // 3. Destination
    const destination = audioContext.createMediaStreamAudioDestination();
    
    // Connect chain
    source.connect(highPass);
    highPass.connect(compressor);
    compressor.connect(destination);
    
    return {
        audioContext,
        destinationStream: destination.stream,
        // Return all nodes including destination for proper graph teardown
        nodes: [source, highPass, compressor, destination] 
    };
}

/**
 * Wires microphone + file logic for a specific instance.
 */
export function initRecorder(store, instanceId) {
    CommandBus.subscribe('start-mic', async (_payload, meta) => {
        if (meta.instanceId !== instanceId) {
            return;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            store.dispatch({
                type: 'starmus/error',
                error: { message: 'Microphone is not supported in this browser.', retryable: false },
                status: 'idle',
            });
            return;
        }

        try {
            const state = store.getState();
            const env = state.env || {};
            const config = (window && window.starmusConfig) ? window.starmusConfig : {};
            
            const audioSettings = getOptimalAudioSettings(env, config);
            debugLog('Using audio settings:', audioSettings);
            
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
            
            // 3. Process Audio (using Shared Context)
            const { audioContext, destinationStream, nodes } = setupAudioGraph(rawStream);

            if (audioContext.state === 'suspended') {
                await audioContext.resume();
            }

            // 4. Prepare MediaRecorder
            // Safari Safety: Check MIME support carefully before assigning bitrate
            const recorderOptions = {};
            if (MediaRecorder.isTypeSupported(audioSettings.options.mimeType)) {
                recorderOptions.mimeType = audioSettings.options.mimeType;
                recorderOptions.audioBitsPerSecond = audioSettings.options.audioBitsPerSecond;
            } else {
                debugLog('Preferred MIME not supported, using browser default');
            }
            
            // Record the PROCESSED stream
            const mediaRecorder = new MediaRecorder(destinationStream, recorderOptions);
            const chunks = [];
            let transcript = '';

            // 5. Speech Recognition (Optional)
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            let recognition = null;
            
            if (SpeechRecognition && env.speechSupported) {
                try {
                    recognition = new SpeechRecognition();
                    recognition.continuous = true;
                    recognition.interimResults = true;
                    recognition.maxAlternatives = 1;
                    
                    const formEl = recorderRegistry.get(instanceId)?.form || 
                                 document.querySelector(`[data-starmus-id="${instanceId}"]`);
                    const langInput = formEl?.querySelector('[name="starmus_language"]');
                    recognition.lang = langInput?.value || 'en-US';
                    
                    recognition.onresult = (event) => {
                        let currentTranscript = '';
                        for (let i = 0; i < event.results.length; i++) {
                            const result = event.results[i];
                            if (result.isFinal) {
                                currentTranscript += result[0].transcript + ' ';
                            }
                        }
                        transcript += currentTranscript;
                        store.dispatch({ type: 'starmus/transcript-update', transcript: transcript.trim() });
                    };
                    
                    recognition.start();
                } catch (err) {
                    debugLog('Failed to start speech recognition:', err);
                }
            }

            // Store instance data
            recorderRegistry.set(instanceId, { 
                mediaRecorder, 
                chunks, 
                rawStream, 
                processedStream: destinationStream, 
                audioContext,
                audioNodes: nodes,
                recognition, 
                transcript, 
                calibration,
                startTime: null, // Will be set when recording starts
                monitorInterval: null, // For duration/amplitude tracking
            });

            mediaRecorder.addEventListener('dataavailable', (event) => {
                if (event.data && event.data.size > 0) {
                    chunks.push(event.data);
                }
            });

            mediaRecorder.addEventListener('stop', () => {
                const rec = recorderRegistry.get(instanceId);
                if (!rec) {
                    return;
                }
                
                // Clear monitoring interval
                if (rec.monitorInterval) {
                    clearInterval(rec.monitorInterval);
                    rec.monitorInterval = null;
                }
                
                if (rec.recognition) {
                    try {
                        rec.recognition.stop();
                    } catch {
                        // Ignore if already stopped
                    }
                }
                
                const blob = new Blob(rec.chunks, { type: rec.mediaRecorder.mimeType || 'audio/webm' });
                const fileName = `starmus-recording-${Date.now()}.webm`;

                debugLog('Mic recording complete', instanceId, fileName);

                store.dispatch({
                    type: 'starmus/mic-complete',
                    blob,
                    fileName,
                    transcript: rec.transcript || '',
                });

                // --- SAFARI/iOS CLEANUP SEQUENCE (STRICT ORDER) ---
                
                // 1. Stop all MediaStream tracks (raw + processed) FIRST
                // Crucial for Safari/iOS to prevent "Input buffer detached" and CPU leaks
                if (rec.rawStream) {
                    rec.rawStream.getTracks().forEach(t => {
                        try {
                            t.stop();
                        } catch {
                            // Ignore if already stopped
                        }
                    });
                }
                if (rec.processedStream) {
                    rec.processedStream.getTracks().forEach(t => {
                        try {
                            t.stop();
                        } catch {
                            // Ignore if already stopped
                        }
                    });
                }

                // 2. Disconnect all audio nodes (source, filters, compressor, destination)
                if (rec.audioNodes) {
                    rec.audioNodes.forEach(node => {
                        try {
                            node.disconnect();
                        } catch {
                            // Ignore if already disconnected
                        }
                    });
                }            recorderRegistry.delete(instanceId);
            });

            store.dispatch({ type: 'starmus/mic-start' });
            mediaRecorder.start(1000);
            
            // Track recording duration and amplitude
            const rec = recorderRegistry.get(instanceId);
            if (rec) {
                rec.startTime = Date.now();
                
                // Create analyzer for amplitude monitoring
                const amplitudeAnalyser = audioContext.createAnalyser();
                amplitudeAnalyser.fftSize = 256;
                const amplitudeSource = audioContext.createMediaStreamSource(rawStream);
                amplitudeSource.connect(amplitudeAnalyser);
                
                const amplitudeBuffer = new Uint8Array(amplitudeAnalyser.frequencyBinCount);
                
                rec.monitorInterval = setInterval(() => {
                    // Update duration
                    const duration = (Date.now() - rec.startTime) / 1000;
                    
                    // Get amplitude (0-100)
                    amplitudeAnalyser.getByteFrequencyData(amplitudeBuffer);
                    let sum = 0;
                    for (let i = 0; i < amplitudeBuffer.length; i++) {
                        sum += amplitudeBuffer[i];
                    }
                    const avgAmplitude = sum / amplitudeBuffer.length;
                    const amplitude = Math.min(100, (avgAmplitude / 255) * 100);
                    
                    store.dispatch({ 
                        type: 'starmus/recorder-update', 
                        duration, 
                        amplitude 
                    });
                }, 100); // Update 10x per second
            }
        } catch (error) {
            console.error(error);
            store.dispatch({
                type: 'starmus/error',
                error: { message: 'Could not access microphone.', retryable: true },
                status: 'idle',
            });
        }
    });

    // Stop mic
    CommandBus.subscribe('stop-mic', (_payload, meta) => {
        if (meta.instanceId !== instanceId) {
            return;
        }

        const rec = recorderRegistry.get(instanceId);
        if (!rec || !rec.mediaRecorder) {
            return;
        }

        if (rec.mediaRecorder.state === 'recording') {
            store.dispatch({ type: 'starmus/mic-stop' });
            rec.mediaRecorder.stop();
            // Cleanup handled in mediaRecorder.onstop
        }
    });

    // Attach file from input
    CommandBus.subscribe('attach-file', (payload, meta) => {
        if (meta.instanceId !== instanceId) {
            return;
        }
        const file = payload.file;
        if (!file) {
            return;
        }

        debugLog('File attached', instanceId, file.name);

        store.dispatch({ type: 'starmus/file-attached', file });
    });

    // Reset
    CommandBus.subscribe('reset', (_payload, meta) => {
        if (meta.instanceId !== instanceId) {
            return;
        }
        
        const rec = recorderRegistry.get(instanceId);
        if (rec) {
            if (rec.mediaRecorder && rec.mediaRecorder.state === 'recording') {
                rec.mediaRecorder.stop();
            }
            
            // Aggressive cleanup for reset (Same order as stop)
            if (rec.rawStream) {
                rec.rawStream.getTracks().forEach(t => t.stop());
            }
            if (rec.processedStream) {
                rec.processedStream.getTracks().forEach(t => t.stop());
            }

            if (rec.audioNodes) {
                rec.audioNodes.forEach(node => {
                    try {
                        node.disconnect();
                    } catch {
                        // Ignore if already disconnected
                    }
                });
            }
            
            recorderRegistry.delete(instanceId);
        }
    });
}
