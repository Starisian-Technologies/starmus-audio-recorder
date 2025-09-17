// FILE: starmus-audio-recorder-module.js (HOOKS-INTEGRATED)
/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * @module  StarmusAudioRecorder
 * @version 1.2.2
 * @file    The Core Recording Engine - Pure audio functionality with hooks integration
 */
(function(window) {
    'use strict';

    function debugInitBanner() {
        if (!window.isStarmusAdmin) return;
        const banner = document.createElement('div');
        banner.textContent = '[Starmus Recorder Module] JS Initialized';
        banner.style.cssText = 'position:fixed;top:48px;left:0;z-index:99999;background:#22a;color:#fff;padding:4px 12px;font:14px monospace;opacity:0.95';
        document.body.appendChild(banner);
        setTimeout(() => banner.remove(), 4000);
        secureLog('info', 'DEBUG: Recorder Module banner shown');
    }

    const hasMediaRecorder = !!(window.MediaRecorder && window.navigator.mediaDevices);
    const instances = Object.create(null);

    function isSafeId(id) { return typeof id === 'string' && /^[A-Za-z0-9_-]{1,100}$/.test(id); }
    function secureLog(level, msg, data) { if (console && console[level]) { console[level]('[Starmus Recorder]', msg, data || ''); } }
    function doAction(hook, ...args) { if (window.StarmusHooks?.doAction) { window.StarmusHooks.doAction(hook, ...args); } }
    function applyFilters(hook, value, ...args) { return window.StarmusHooks?.applyFilters ? window.StarmusHooks.applyFilters(hook, value, ...args) : value; }

    window.StarmusAudioRecorder = {
        init: function(options) {
            secureLog('info', 'RecorderModule.init called', options);
            debugInitBanner();
            return new Promise((resolve, reject) => {
                if (!hasMediaRecorder) {
                    secureLog('error', 'MediaRecorder API not supported.');
                    return reject(new Error('MediaRecorder API not supported.'));
                }
                const instanceId = options.formInstanceId;
                if (!isSafeId(instanceId)) {
                    secureLog('error', 'Invalid instance ID.', instanceId);
                    return reject(new Error('Invalid instance ID.'));
                }
                if (instanceId in instances) {
                    secureLog('info', 'Instance already exists', instanceId);
                    return resolve(true);
                }
                doAction('starmus_before_recorder_init', instanceId, options);
                navigator.mediaDevices.getUserMedia({
                    audio: {
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true,
                        sampleRate: 16000,  // Lower for bandwidth
                        channelCount: 1     // Mono only
                    }
                })
                    .then(stream => {
                        secureLog('info', 'getUserMedia success', stream);
                        const audioContext = new (window.AudioContext || window.webkitAudioContext)({ sampleRate: 16000 });
                        const source = audioContext.createMediaStreamSource(stream);
                        const analyser = audioContext.createAnalyser();
                        const gainNode = audioContext.createGain();
                        const compressor = audioContext.createDynamicsCompressor();
                        const filter = audioContext.createBiquadFilter();
                        // Optimize for speech
                        analyser.fftSize = 1024;  // Smaller for performance
                        filter.type = 'highpass';
                        filter.frequency.value = 80;  // Remove low-freq noise
                        compressor.threshold.value = -24;
                        compressor.knee.value = 30;
                        compressor.ratio.value = 12;
                        compressor.attack.value = 0.003;
                        compressor.release.value = 0.25;
                        source.connect(filter);
                        filter.connect(compressor);
                        compressor.connect(analyser);
                        compressor.connect(gainNode);
                        instances[instanceId] = {
                            stream, recorder: null, chunks: [], audioBlob: null,
                            isRecording: false, isPaused: false, startTime: 0,
                            ctx: audioContext, analyser, gain: gainNode, compressor, filter,
                            isCalibrated: false, volumeMonitorId: null,
                            speechRecognition: null, transcript: [], currentLanguage: null,
                            silenceStart: null, totalSilence: 0, peakVolume: 0, avgVolume: 0, volumeSamples: [],
                            sessionUUID: crypto.randomUUID ? crypto.randomUUID() : 'uuid-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9)
                        };
                        secureLog('info', 'Recorder instance created', instances[instanceId]);
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
            const DURATION = 15000;  // Longer for noisy environments

            doAction('starmus_calibration_started', instanceId);

            function tick() {
                const elapsed = performance.now() - startTime;
                const remaining = Math.ceil((DURATION - elapsed) / 1000);

                if (elapsed < 5000) onUpdateCallback('Be quiet for background noise (' + remaining + 's)');
                else if (elapsed < 10000) onUpdateCallback('Now speak clearly and loudly (' + remaining + 's)');
                else onUpdateCallback('Be quiet again (' + remaining + 's)');

                analyser.getFloatTimeDomainData(buffer);
                let sum = 0;
                for (let i = 0; i < buffer.length; i++) sum += buffer[i] * buffer[i];
                const rms = Math.sqrt(sum / buffer.length);
                samples.push(rms);

                const VOLUME_SCALE_FACTOR = 2000; // Convert RMS to 0-100 percentage
                onUpdateCallback(null, Math.min(100, rms * VOLUME_SCALE_FACTOR));

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
                let gain = snr < 3 ? 6.0 : Math.max(1.0, Math.min(4.0, 0.1 / Math.max(speechLevel, 1e-6)));
                gain = applyFilters('starmus_calibration_gain', gain, instanceId, { speechLevel, noiseFloor, snr });

                instance.gain.gain.setTargetAtTime(gain, instance.ctx.currentTime, 0.01);
                instance.isCalibrated = true;

                onUpdateCallback('Mic calibrated (gain ×' + gain.toFixed(1) + ', SNR: ' + snr.toFixed(1) + '). Ready to record.', null, true);
                doAction('starmus_calibration_complete', instanceId, { gain, snr, noiseFloor });
            }
            tick();
        },

        startVolumeMonitoring: function(instanceId, onVolumeChange) {
            if (!isSafeId(instanceId) || !(instanceId in instances)) return;
            const instance = instances[instanceId];
            const analyser = instance.analyser;
            const buffer = new Float32Array(analyser.fftSize);

            function update() {
                if (!instance.isRecording || instance.isPaused) {
                    instance.volumeMonitorId = null;
                    return;
                }

                analyser.getFloatTimeDomainData(buffer);
                const sum = buffer.reduce((acc, sample) => acc + sample * sample, 0);
                const rms = Math.sqrt(sum / buffer.length);
                const VOLUME_SCALE_FACTOR = 2000;
                const volume = Math.min(100, rms * VOLUME_SCALE_FACTOR);

                // Track audio quality metrics
                instance.volumeSamples.push(volume);
                instance.peakVolume = Math.max(instance.peakVolume, volume);
                instance.avgVolume = instance.volumeSamples.reduce((a, b) => a + b, 0) / instance.volumeSamples.length;

                // Silence detection (< 5% volume for corpus quality)
                const now = Date.now();
                if (volume < 5) {
                    if (!instance.silenceStart) instance.silenceStart = now;
                } else {
                    if (instance.silenceStart) {
                        instance.totalSilence += now - instance.silenceStart;
                        instance.silenceStart = null;
                    }
                }

                onVolumeChange(applyFilters('starmus_volume_level', volume, instanceId));
                instance.volumeMonitorId = requestAnimationFrame(update);
            }
            instance.volumeMonitorId = requestAnimationFrame(update);
        },

        startRecording: function(instanceId, language = 'en-US') {
            if (!isSafeId(instanceId) || !(instanceId in instances) || instances[instanceId].isRecording) return;
            const instance = instances[instanceId];

            // Initialize speech recognition if available
            if (window.SpeechRecognition || window.webkitSpeechRecognition) {
                const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
                instance.speechRecognition = new SpeechRecognition();
                instance.speechRecognition.continuous = true;
                instance.speechRecognition.interimResults = true;
                instance.speechRecognition.lang = language;
                instance.currentLanguage = language;

                instance.speechRecognition.onresult = (event) => {
                    const timestamp = Date.now() - instance.startTime;
                    for (let i = event.resultIndex; i < event.results.length; i++) {
                        const result = event.results[i];
                        const transcript = {
                            text: result[0].transcript,
                            confidence: result[0].confidence,
                            timestamp: timestamp,
                            isFinal: result.isFinal,
                            language: instance.currentLanguage
                        };

                        if (result.isFinal) {
                            instance.transcript.push(transcript);
                            doAction('starmus_speech_recognized', instanceId, transcript);
                        }
                    }
                };

                instance.speechRecognition.onerror = (event) => {
                    if (event.error === 'no-speech' || event.error === 'language-not-supported') {
                        // Mark as non-English/French
                        instance.transcript.push({
                            text: '[Non-transcribable language detected]',
                            confidence: 0,
                            timestamp: Date.now() - instance.startTime,
                            isFinal: true,
                            language: 'unknown'
                        });
                    }
                };
            }

            try {
                const destination = instance.ctx.createMediaStreamDestination();
                instance.gain.connect(destination);

                // Use lower bitrate for bandwidth
                const options = { audioBitsPerSecond: 32000 };
                if (MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) {
                    options.mimeType = 'audio/webm;codecs=opus';
                } else if (MediaRecorder.isTypeSupported('audio/mp4')) {
                    options.mimeType = 'audio/mp4';
                }

                instance.recorder = new MediaRecorder(destination.stream, options);
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
                if (instance.speechRecognition) {
                    instance.speechRecognition.start();
                }
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
            if (instance.speechRecognition) {
                instance.speechRecognition.stop();
            }

            // Final silence calculation
            if (instance.silenceStart) {
                instance.totalSilence += Date.now() - instance.silenceStart;
            }

            instance.isRecording = false;
            instance.isPaused = false;
        },

        getRecordingQuality: function(instanceId) {
            if (!isSafeId(instanceId) || !(instanceId in instances)) return null;
            const instance = instances[instanceId];
            const duration = Date.now() - instance.startTime;
            const silenceRatio = instance.totalSilence / duration;

            return {
                peakVolume: instance.peakVolume,
                avgVolume: instance.avgVolume,
                silenceRatio: silenceRatio,
                quality: instance.peakVolume > 20 && instance.avgVolume > 10 && silenceRatio < 0.7 ? 'good' : 'poor',
                warnings: [
                    ...(instance.peakVolume < 20 ? ['Low volume detected'] : []),
                    ...(silenceRatio > 0.7 ? ['Too much silence'] : []),
                    ...(!instance.isCalibrated ? ['Microphone not calibrated'] : [])
                ]
            };
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

        // NEW: Public method to safely get the paused state.
        isPaused: function(instanceId) {
            if (!isSafeId(instanceId) || !(instanceId in instances)) {
                return false;
            }
            return instances[instanceId].isPaused;
        },

        getSubmissionData: function(instanceId) {
            if (!isSafeId(instanceId) || !(instanceId in instances)) return null;
            const instance = instances[instanceId];
            if (instance.audioBlob) {
                const fileName = applyFilters('starmus_recorder_filename', 'starmus-recording.webm', instanceId);
                const recordingDuration = Date.now() - instance.startTime;
                const data = {
                    blob: instance.audioBlob,
                    fileName,
                    mimeType: instance.audioBlob.type || 'audio/webm',
                    size: instance.audioBlob.size,
                    metadata: {
                        // Unique identifiers
                        sessionUUID: instance.sessionUUID,
                        submissionUUID: crypto.randomUUID ? crypto.randomUUID() : 'sub-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9),

                        // Temporal data (all UTC normalized)
                        recordedAt: new Date(instance.startTime).toISOString(), // Already UTC
                        recordedAtLocal: new Date(instance.startTime).toString(), // Local for reference
                        duration: recordingDuration,
                        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,

                        // Audio technical specs
                        sampleRate: instance.ctx.sampleRate,
                        channelCount: 1,
                        bitDepth: 16,
                        codec: instance.audioBlob.type,
                        fileSize: instance.audioBlob.size,

                        // Device/browser fingerprint
                        userAgent: navigator.userAgent,
                        platform: navigator.platform,
                        language: navigator.language,
                        languages: navigator.languages,
                        screenResolution: `${screen.width}x${screen.height}`,
                        deviceMemory: navigator.deviceMemory || 'unknown',
                        hardwareConcurrency: navigator.hardwareConcurrency || 'unknown',
                        connection: navigator.connection ? {
                            effectiveType: navigator.connection.effectiveType,
                            downlink: navigator.connection.downlink,
                            rtt: navigator.connection.rtt
                        } : 'unknown',

                        // Audio processing chain
                        audioProcessing: {
                            echoCancellation: true,
                            noiseSuppression: true,
                            autoGainControl: true,
                            gainApplied: instance.gain.gain.value,
                            isCalibrated: instance.isCalibrated,
                            compressionUsed: true,
                            highpassFilter: '80Hz'
                        },

                        // Recording quality metrics for corpus
                        quality: {
                            peakVolume: instance.peakVolume,
                            avgVolume: instance.avgVolume,
                            silenceRatio: instance.totalSilence / recordingDuration,
                            volumeConsistency: instance.volumeSamples.length > 0 ?
                                (instance.volumeSamples.reduce((acc, v) => acc + Math.abs(v - instance.avgVolume), 0) / instance.volumeSamples.length) : 0
                        },

                        // Speech recognition data (timestamps normalized to UTC)
                        transcript: instance.transcript.map(t => ({
                            ...t,
                            timestampUTC: new Date(instance.startTime + t.timestamp).toISOString(),
                            timestampOffset: t.timestamp // Relative to recording start
                        })),
                        detectedLanguage: instance.currentLanguage,
                        hasTranscription: instance.transcript.length > 0,
                        speechRecognitionAvailable: !!(window.SpeechRecognition || window.webkitSpeechRecognition)
                    }
                };
                return applyFilters('starmus_submission_data', data, instanceId);
            }
            return null;
        },

        cleanup: function(instanceId) {
            if (!isSafeId(instanceId) || !(instanceId in instances)) return;
            const instance = instances[instanceId];
            if (instance.volumeMonitorId) {
                cancelAnimationFrame(instance.volumeMonitorId);
            }
            if (instance.stream) {
                instance.stream.getTracks().forEach(track => track.stop());
            }
            doAction('starmus_recorder_cleanup', instanceId);
            delete instances[instanceId];
        },

        // West African language validation - context-aware based on recording type
        validateWestAfricanLanguage: function(instanceId, recordingType = 'unknown') {
            if (!isSafeId(instanceId) || !(instanceId in instances)) return { isValid: false, reason: 'Invalid instance' };
            const instance = instances[instanceId];
            const duration = Date.now() - instance.startTime;

            const allText = instance.transcript.map(t => t.text).join(' ').toLowerCase().trim();
            const totalWords = allText ? allText.split(/\s+/).length : 0;
            const avgConfidence = instance.transcript.length > 0 ?
                instance.transcript.reduce((sum, t) => sum + t.confidence, 0) / instance.transcript.length : 0;

            const typeContext = recordingType.toLowerCase();

            // Single words - very lenient
            if (typeContext.includes('word') || (duration < 3000 && totalWords <= 2)) {
                const obviousEnglish = /^(hello|yes|no|the|and|is|are|you|me|my|your|good|bad|big|small|one|two|three)$/i.test(allText);
                const obviousFrench = /^(bonjour|oui|non|le|la|les|et|est|vous|moi|mon|votre|bon|mauvais|grand|petit|un|deux|trois)$/i.test(allText);

                if (avgConfidence > 0.95 && (obviousEnglish || obviousFrench)) {
                    return { isValid: false, reason: `Obvious ${obviousEnglish ? 'English' : 'French'} word detected`, recordingType: 'word' };
                }
                return { isValid: true, reason: 'Single word - likely West African', recordingType: 'word', avgConfidence };
            }

            // Phrases/proverbs - moderate validation
            if (typeContext.includes('phrase') || typeContext.includes('proverb') || typeContext.includes('saying') || (duration < 15000 && totalWords <= 15)) {
                const englishPattern = /\b(the|and|is|in|to|of|a|that|it|with|for|as|was|on|are|you|this|have|they|we|said|do|will|about|then|would|make|what|know|also|your|can|now|when|where|good|come|get|see|way|who|say)\b/g;
                const frenchPattern = /\b(le|la|les|de|des|du|et|est|dans|pour|avec|sur|sont|vous|que|qui|une|un|ce|ne|pas|tout|être|avoir|faire|dire|aller|voir|savoir|venir|vouloir|donner|parler)\b/g;

                const englishMatches = (allText.match(englishPattern) || []).length;
                const frenchMatches = (allText.match(frenchPattern) || []).length;
                const recognizedRatio = Math.max(englishMatches, frenchMatches) / Math.max(totalWords, 1);

                if (recognizedRatio > 0.6 && avgConfidence > 0.8) {
                    return { isValid: false, reason: `${Math.round(recognizedRatio * 100)}% ${englishMatches > frenchMatches ? 'English' : 'French'} phrase patterns`, recordingType: 'phrase' };
                }
                return { isValid: true, reason: 'Phrase/proverb - likely West African', recordingType: 'phrase', avgConfidence };
            }

            // Default validation for longer content
            const englishPattern = /\b(the|and|is|in|to|of|a|that|it|with|for|as|was|on|are|you|this|have|from|they|we|been|their|said|which|do|how|will|about|many|then|some|would|make|like|has|more|what|know|first|also|your|work|only|can|should|now|here|when|where|good|come|could|get|new|see|way|who|did|say|too|any)\b/g;
            const frenchPattern = /\b(le|la|les|de|des|du|et|est|dans|pour|avec|sur|sont|vous|que|qui|une|un|ce|se|ne|pas|tout|être|avoir|faire|dire|aller|voir|savoir|prendre|venir|vouloir|pouvoir|croire|donner|parler|porter|finir|partir|sentir|sortir|conduire)\b/g;

            const englishMatches = (allText.match(englishPattern) || []).length;
            const frenchMatches = (allText.match(frenchPattern) || []).length;
            const recognizedRatio = Math.max(englishMatches, frenchMatches) / Math.max(totalWords, 1);

            if (recognizedRatio > 0.4 && avgConfidence > 0.7) {
                return { isValid: false, reason: `${Math.round(recognizedRatio * 100)}% ${englishMatches > frenchMatches ? 'English' : 'French'} patterns detected`, recordingType: 'story' };
            }

            return { isValid: true, reason: 'Recording validated - likely West African language', recordingType: 'unknown', avgConfidence };
        },

        getTranscript: function(instanceId) {
            if (!isSafeId(instanceId) || !(instanceId in instances)) return [];
            return instances[instanceId].transcript || [];
        },

        // Debug only
        _instances: instances
    };
})(window);
