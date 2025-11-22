/**
 * @file starmus-recorder.js
 * @version 4.2.0
 * @description Handles microphone recording with Web Audio API processing (Highpass/Compression) for low-end devices.
 */

'use strict';

import { CommandBus, debugLog } from './starmus-hooks.js';

// instanceId -> { mediaRecorder, chunks, rawStream, processedStream, audioContext, recognition, transcript, calibration }
const recorderRegistry = new Map();

/**
 * Shared AudioContext to prevent dual-context freeze on Android/iOS.
 * Reused for both calibration and recording.
 */
let sharedAudioContext = null;

/**
 * Get or create the shared AudioContext instance.
 * 
 * @returns {AudioContext} Shared audio context
 */
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
 * 
 * @param {MediaStream} stream - The audio stream from getUserMedia
 * @param {Function} onUpdate - Callback for UI updates (message, volumePercent, isDone)
 * @returns {Promise<object>} Calibration data with recommended gain adjustment
 */
async function calibrateAudioLevels(stream, onUpdate) {
    return new Promise((resolve) => {
        // Use shared context to prevent dual-context freeze on Android/iOS
        const audioContext = getSharedContext();
        const analyser = audioContext.createAnalyser();
        analyser.fftSize = 2048;
        const buffer = new Float32Array(analyser.fftSize);
        const microphone = audioContext.createMediaStreamSource(stream);
        
        microphone.connect(analyser);
        
        const samples = [];
        const startTime = performance.now();
        const DURATION = 15000; // 15 seconds total
        const VOLUME_SCALE_FACTOR = 2000;
        
        function tick() {
            const elapsed = performance.now() - startTime;
            const remaining = Math.ceil((DURATION - elapsed) / 1000);
            
            // 3-phase calibration with updated messaging
            let message = '';
            if (elapsed < 5000) {
                message = `Be quiet for background noise (${remaining}s)`;
            } else if (elapsed < 10000) {
                message = `Now speak at normal volume (${remaining}s)`; // Changed from "loudly"
            } else {
                message = `Be quiet again (${remaining}s)`;
            }
            
            // Analyze current volume
            analyser.getFloatTimeDomainData(buffer);
            let sum = 0;
            for (let i = 0; i < buffer.length; i++) {
                sum += buffer[i] * buffer[i];
            }
            const rms = Math.sqrt(sum / buffer.length);
            samples.push(rms);
            
            // Convert to 0-100 percentage for UI
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
            // Separate quiet and speech samples
            const quietSamples = samples.slice(0, Math.floor(samples.length * 0.33));
            const speechSamples = samples.slice(Math.floor(samples.length * 0.33), Math.floor(samples.length * 0.67));
            
            const noiseFloor = quietSamples.reduce((a, b) => a + b, 0) / quietSamples.length;
            const speechLevel = speechSamples.reduce((a, b) => a + b, 0) / speechSamples.length;
            const snr = speechLevel / Math.max(noiseFloor, 1e-6);
            
            // Adaptive gain based on SNR
            const gain = snr < 3 ? 6.0 : Math.max(1.0, Math.min(4.0, 0.1 / Math.max(speechLevel, 1e-6)));
            
            const calibration = {
                gain: parseFloat(gain.toFixed(3)),
                snr: parseFloat(snr.toFixed(3)),
                noiseFloor: parseFloat(noiseFloor.toFixed(6)),
                speechLevel: parseFloat(speechLevel.toFixed(6)),
                timestamp: new Date().toISOString()
            };
            
            // Clean up calibration nodes (but keep shared context alive)
            microphone.disconnect();
            analyser.disconnect();
            
            const finalMessage = `Ready to record. Mic calibrated (gain ×${gain.toFixed(1)}, SNR: ${snr.toFixed(1)})`;
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
 * 
 * @param {object} env - Environment data (network, device, capabilities)
 * @param {object} config - starmusConfig from WordPress (includes allowedMimeTypes)
 * @returns {object} MediaRecorder options and constraints
 */
function getOptimalAudioSettings(env, config) {
    const network = env?.network || {};
    const device = env?.device || {};
    const allowedMimes = config?.allowedMimeTypes || {};
    
    // Determine best MIME type from admin settings
    let mimeType = 'audio/webm;codecs=opus'; // Gold standard for low-bandwidth voice
    if (allowedMimes['webm'] || allowedMimes['weba']) {
        mimeType = 'audio/webm;codecs=opus';
    } else if (allowedMimes['mp4'] || allowedMimes['m4a']) {
        mimeType = 'audio/mp4';
    } else if (allowedMimes['ogg'] || allowedMimes['oga']) {
        mimeType = 'audio/ogg;codecs=opus';
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
    
    // Respect data-saver mode
    if (network.saveData) {
        settings.constraints.audio.sampleRate = 16000;
        settings.constraints.audio.channelCount = 1;
        settings.options.audioBitsPerSecond = 16000; // 16kbps minimum
    }
    
    // Desktop can handle higher quality
    if (!isMobile && (effectiveType === '4g' || downlink > 5)) {
        settings.constraints.audio.sampleRate = 48000;
        settings.options.audioBitsPerSecond = 192000; // 192kbps for desktop
    }
    
    return settings;
}

/**
 * Builds the Web Audio API graph for processing voice.
 * Adds Highpass filter (for wind/rumble) and Compressor (for even volume).
 * 
 * @param {MediaStream} rawStream - Raw audio stream from getUserMedia
 * @returns {object} { audioContext, destinationStream }
 */
function setupAudioGraph(rawStream) {
    // Use shared context to prevent dual-context issues
    const audioContext = getSharedContext();
    
    const source = audioContext.createMediaStreamSource(rawStream);
    
    // 1. High-pass Filter: Cuts low frequencies (wind, handling noise, engine rumble)
    const highPass = audioContext.createBiquadFilter();
    highPass.type = 'highpass';
    highPass.frequency.value = 85; // Cut everything below 85Hz
    
    // 2. Dynamics Compressor: Balances loud laughs and quiet whispers
    const compressor = audioContext.createDynamicsCompressor();
    compressor.threshold.value = -20;
    compressor.knee.value = 40;
    compressor.ratio.value = 12;
    compressor.attack.value = 0;
    compressor.release.value = 0.25;
    
    // 3. Destination: Where the MediaRecorder will listen
    const destination = audioContext.createMediaStreamAudioDestination();
    
    // Connect the chain: Source -> Filter -> Compressor -> Destination
    source.connect(highPass);
    highPass.connect(compressor);
    compressor.connect(destination);
    
    // If needed for monitoring (hearing yourself), connect to audioContext.destination
    // But usually avoided to prevent feedback loops without headphones
    
    return {
        audioContext,
        destinationStream: destination.stream
    };
}

/**
 * Wires microphone + file logic for a specific instance.
 *
 * @param {object} store
 * @param {string} instanceId
 */
export function initRecorder(store, instanceId) {
    // Start mic
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
            
            // Detect actual sample rate to prevent mismatches on Android/iOS
            const ctx = getSharedContext();
            const actualRate = ctx.sampleRate;
            debugLog('Actual sampleRate:', actualRate);
            
            // 2. Calibrate (Using RAW stream to accurately detect noise floor)
            store.dispatch({ type: 'starmus/calibration-start' });
            
            const calibration = await calibrateAudioLevels(rawStream, (message, volumePercent, isDone) => {
                if (isDone) {
                    store.dispatch({ 
                        type: 'starmus/calibration-complete',
                        calibration 
                    });
                } else {
                    store.dispatch({
                        type: 'starmus/calibration-update',
                        message,
                        volumePercent
                    });
                }
            });
            
            debugLog('Calibration complete:', calibration);
            if (window.StarmusHooks?.doAction) {
                window.StarmusHooks.doAction('starmus_calibration_complete', instanceId, calibration);
            }
            
            // 3. Process Audio (Filter & Compress)
            // This ensures better quality on low-end mics before it hits the recorder
            const { audioContext, destinationStream } = setupAudioGraph(rawStream);

            // Resume context if suspended (common on mobile)
            if (audioContext.state === 'suspended') {
                await audioContext.resume();
            }

            // 4. Prepare MediaRecorder
            let recorderOptions = audioSettings.options;
            if (!MediaRecorder.isTypeSupported(recorderOptions.mimeType)) {
                recorderOptions = {}; // Fallback to browser default
                debugLog('Preferred MIME not supported, using browser default');
            }
            
            // IMPORTANT: Record the PROCESSED stream, not the rawStream
            const mediaRecorder = new MediaRecorder(destinationStream, recorderOptions);
            const chunks = [];
            let transcript = '';

            // 5. Setup Speech Recognition (Uses RAW stream usually, to avoid double processing latency)
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            let recognition = null;
            
            if (SpeechRecognition && env.speechSupported) {
                try {
                    recognition = new SpeechRecognition();
                    recognition.continuous = true;
                    recognition.interimResults = true;
                    recognition.maxAlternatives = 1;
                    
                    // Set language from form if available, default to English
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
                    
                    recognition.onerror = (event) => debugLog('Speech recognition error:', event.error);
                    recognition.start();
                } catch (err) {
                    debugLog('Failed to start speech recognition:', err);
                    recognition = null;
                }
            }

            // Store everything including audioContext for cleanup
            recorderRegistry.set(instanceId, { 
                mediaRecorder, 
                chunks, 
                rawStream, 
                processedStream: destinationStream, 
                audioContext, 
                recognition, 
                transcript, 
                calibration 
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
                
                if (rec.recognition) {
                    try { rec.recognition.stop(); } catch (err) { debugLog(err); }
                }
                
                const blob = new Blob(rec.chunks, { type: rec.mediaRecorder.mimeType || 'audio/webm' });
                const fileName = `starmus-recording-${Date.now()}.webm`; // Or determine ext from mimeType

                debugLog('Mic recording complete', instanceId, fileName);

                store.dispatch({
                    type: 'starmus/mic-complete',
                    blob,
                    fileName,
                    transcript: rec.transcript || '',
                });

                // Cleanup Audio Tracks
                if (rec.rawStream) {
                    rec.rawStream.getTracks().forEach(track => track.stop());
                }
                if (rec.processedStream) {
                    rec.processedStream.getTracks().forEach(track => track.stop());
                }
                
                // Shared context stays alive; do nothing with audioContext.close()

                recorderRegistry.delete(instanceId);
            });

            store.dispatch({ type: 'starmus/mic-start' });
            // Slice into 1s chunks so data isn't lost if crash occurs
            mediaRecorder.start(1000);
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
            // Aggressive cleanup for reset
            if (rec.rawStream) {
                rec.rawStream.getTracks().forEach(t => t.stop());
            }
            if (rec.processedStream) {
                rec.processedStream.getTracks().forEach(t => t.stop());
            }
            // Shared context stays alive; do nothing with audioContext.close()
            
            recorderRegistry.delete(instanceId);
        }
    });
}
