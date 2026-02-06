/**
 * @file starmus-recorder.js
 * @version 6.4.0-BUILD-FIX
 * @description Audio recording functionality with MediaRecorder API, microphone calibration,
 * real-time speech recognition, and visual amplitude feedback. Handles complete recording
 * lifecycle from setup through stop with explicit exports for build system.
 */

"use strict";

import { CommandBus } from "./starmus-hooks.js";
import sparxstarIntegration from "./starmus-sparxstar-integration.js";
import EnhancedCalibration from "./starmus-enhanced-calibration.js";

/**
 * Registry of active recorder instances mapped by instanceId.
 * Stores MediaRecorder, animation frame ID, and speech recognition objects.
 * @type {Map<string, Object>}
 * @property {MediaRecorder} mediaRecorder - MediaRecorder instance for audio capture
 * @property {number|null} rafId - RequestAnimationFrame ID for visual updates
 * @property {LanguageSignalAnalyzer|null} signalAnalyzer - Language policy analyzer
 */
const recorderRegistry = new Map();

/**
 * Shared AudioContext instance for all recorder instances.
 * Reused to avoid multiple context creation and ensure proper resource management.
 * @type {AudioContext|null}
 */
let sharedAudioContext = null;

/**
 * Speech Recognition API with webkit fallback.
 * Used by LanguageSignalAnalyzer for policy enforcement.
 * @type {function|undefined}
 */
const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

/**
 * Language Signal Analyzer - Geographic Policy Enforcement
 * Detects colonial language violations based on user location.
 * Runs silently on cloned stream without affecting audio recording.
 */
class LanguageSignalAnalyzer {
    constructor({ tier, country, maxDuration = 20000 }) {
        this.tier = tier;
        this.country = country;
        this.maxDuration = maxDuration;

        this.probeLanguages = this.getProbeLanguages();

        this.results = {
            signal_analysis: {
                country: country,
                probe_language: this.probeLanguages[0] || null,
            },
            violation_flags: {
                /* intentionally empty */
            },
            timing_hints: [],
        };

        this._abort = false;
    }

    getProbeLanguages() {
        if (this.tier === "C") {
            return [];
        }

        switch (this.country) {
        case "GM":
            return ["en-US"]; // Gambia - English probe only
        case "SN":
        case "GN":
        case "ML":
            return ["fr-FR"]; // Francophone - French probe only
        default:
            return ["en-US"]; // Unknown - English fallback
        }
    }

    async analyze(inputStream) {
        if (this.tier === "C") {
            return this.audioOnlySignals(inputStream);
        }

        // CRITICAL: Clone stream, never touch original
        const clonedStream = inputStream.clone();

        // Single probe - no sequential risk
        if (this.probeLanguages.length > 0) {
            await this.runViolationProbe(clonedStream, this.probeLanguages[0]);
        }

        this.calculateViolationFlags();
        return this.results;
    }

    runViolationProbe(stream, language) {
        return new Promise((resolve) => {
            if (!SpeechRecognition) {
                return resolve();
            }

            const rec = new SpeechRecognition();
            rec.lang = language;
            rec.continuous = false;
            rec.interimResults = false;
            rec.maxAlternatives = 1;

            let totalWords = 0;
            let weightedConfidence = 0;
            let lastTime = null;

            rec.onresult = (e) => {
                for (const res of e.results) {
                    if (!res.isFinal) {
                        continue;
                    }

                    const text = res[0].transcript.trim();
                    const conf = res[0].confidence ?? 0;
                    const words = text ? text.split(/\s+/).length : 0;
                    const currentTime = e.timeStamp / 1000;

                    totalWords += words;
                    weightedConfidence += conf * words;

                    // Add timing boundaries for AI alignment
                    if (lastTime !== null) {
                        this.results.timing_hints.push({
                            start: lastTime,
                            end: currentTime,
                            type: "speech",
                            lang: language,
                            violation_confidence: conf,
                        });
                    }

                    lastTime = currentTime;
                }
            };

            rec.onend = () => {
                const langCode = language.split("-")[0];

                this.results.signal_analysis[`${langCode}_word_count`] = totalWords;
                this.results.signal_analysis[`${langCode}_violation_confidence`] = totalWords
                    ? weightedConfidence / totalWords
                    : 0;

                resolve();
            };

            rec.start();
            setTimeout(() => {
                try {
                    rec.stop();
                } catch (_e) {
                    if (sparxstarIntegration.isAvailable) {
                        sparxstarIntegration.reportError("media_recorder_stop_error", {
                            error: _e,
                        });
                    }
                }
            }, this.maxDuration);
        });
    }

    calculateViolationFlags() {
        const analysis = this.results.signal_analysis;

        // Gambia: English violation detection
        if (this.country === "GM") {
            const enWords = analysis.en_word_count || 0;
            const enConf = analysis.en_violation_confidence || 0;

            // Guard against ultra-short probes
            if (enWords < 3) {
                this.results.violation_flags = {
                    mostly_english: false,
                    detection_quality: "insufficient",
                };
                return;
            }

            const estimatedTotalWords = enWords + 5;

            this.results.violation_flags = {
                mostly_english: enWords > 8 && enConf > 0.6 && enWords >= 0.6 * estimatedTotalWords,

                violation_reason:
                    enWords > 8 ? "Recording appears mostly English for this location" : null,

                estimated_local_content: Math.max(0, 1 - enWords / estimatedTotalWords),
                detection_quality: enWords >= 8 ? "sufficient" : "insufficient",
            };
        }

        // Senegal: French violation detection
        else if (this.country === "SN") {
            const frWords = analysis.fr_word_count || 0;
            const frConf = analysis.fr_violation_confidence || 0;

            if (frWords < 3) {
                this.results.violation_flags = {
                    mostly_french: false,
                    detection_quality: "insufficient",
                };
                return;
            }

            const estimatedTotalWords = frWords + 5;

            this.results.violation_flags = {
                mostly_french: frWords > 8 && frConf > 0.6 && frWords >= 0.6 * estimatedTotalWords,

                violation_reason:
                    frWords > 8 ? "Recording appears mostly French for this location" : null,

                estimated_local_content: Math.max(0, 1 - frWords / estimatedTotalWords),
                detection_quality: frWords >= 8 ? "sufficient" : "insufficient",
            };
        }
    }

    audioOnlySignals(stream) {
        return new Promise((resolve) => {
            const ctx = new AudioContext();
            const src = ctx.createMediaStreamSource(stream);
            const analyser = ctx.createAnalyser();

            analyser.fftSize = 1024;
            src.connect(analyser);

            const buf = new Uint8Array(analyser.frequencyBinCount);
            const speechBoundaries = [];
            let lastState = "silence";
            const startTime = performance.now();

            const detectSpeechBoundaries = () => {
                if (this._abort) {
                    return;
                }

                analyser.getByteTimeDomainData(buf);
                const rms = Math.sqrt(buf.reduce((s, v) => s + (v - 128) ** 2, 0) / buf.length);

                const state = rms > 10 ? "speech" : "silence";
                if (state !== lastState) {
                    speechBoundaries.push({
                        time: (performance.now() - startTime) / 1000,
                        transition: `${lastState}_to_${state}`,
                    });
                    lastState = state;
                }
            };

            const interval = setInterval(detectSpeechBoundaries, 100);

            setTimeout(() => {
                clearInterval(interval);
                src.disconnect();

                resolve({
                    signal_analysis: {
                        audio_only: true,
                        country: this.country,
                    },
                    timing_hints: speechBoundaries,
                    violation_flags: {
                        estimated_local_content: 1,
                        detection_quality: "audio_only",
                    },
                });
            }, this.maxDuration);
        });
    }

    stop() {
        this._abort = true;
    }
}

/**
 * Gets or creates shared AudioContext with optimal settings.
 * Creates new context if none exists or previous was closed.
 * Sets global window.StarmusAudioContext reference.
 *
 * @function
 * @returns {AudioContext} Shared AudioContext instance
 * @throws {Error} When Audio API is not supported in browser
 */
function getContext() {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) {
        throw new Error("Audio API not supported");
    }
    if (!sharedAudioContext || sharedAudioContext.state === "closed") {
        sharedAudioContext = new Ctx({ latencyHint: "interactive" });
        window.StarmusAudioContext = sharedAudioContext;
    }
    return sharedAudioContext;
}

/**
 * Wakes up AudioContext if suspended due to browser autoplay policies.
 * Must be called after user interaction to enable audio processing.
 *
 * @async
 * @function
 * @returns {Promise<AudioContext>} Promise resolving to active AudioContext
 */
async function wakeAudio() {
    const ctx = getContext();
    console.debug("[AUDIOCTX]", ctx.state);
    if (ctx.state === "suspended") {
        try {
            await ctx.resume();
            console.debug("[AUDIOCTX]", ctx.state);
        } catch (e) {
            console.warn("[Audio] Resume failed:", e.message);
        }
    }
    return ctx;
}

/**
 * Performs microphone calibration with three-phase process.
 * Measures background noise, speech levels, and optimizes settings over 15 seconds.
 * Provides real-time feedback through onUpdate callback.
 *
 * @async
 * @function
 * @param {MediaStream} stream - Audio stream from getUserMedia
 * @param {function} onUpdate - Callback for calibration progress updates
 * @param {string} onUpdate.message - Current calibration phase message
 * @param {number} onUpdate.volumePercent - Volume level (0-100)
 * @param {boolean} onUpdate.isComplete - Whether calibration finished
 * @returns {Promise<Object>} Calibration results
 * @returns {boolean} returns.complete - Always true when resolved
 * @returns {number} returns.gain - Audio gain multiplier (currently 1.0)
 * @returns {number} returns.speechLevel - Maximum detected volume level
 *
 * @description Calibration phases:
 * - Phase 1 (0-5s): Measure background noise
 * - Phase 2 (5-10s): Detect speech levels
 * - Phase 3 (10-15s): Optimize settings
 */
async function _doCalibration(stream, onUpdate) {
    const ctx = await wakeAudio();
    const source = ctx.createMediaStreamSource(stream);
    const analyser = ctx.createAnalyser();
    analyser.fftSize = 2048;
    source.connect(analyser);

    const data = new Uint8Array(analyser.frequencyBinCount);
    const startTime = Date.now();
    let maxVolume = 0;

    return new Promise((resolve) => {
        function loop() {
            analyser.getByteFrequencyData(data);
            let sum = 0;
            for (let i = 0; i < data.length; i++) {
                sum += data[i];
            }
            const avg = sum / data.length;

            // Proper SPL calculation for calibration
            const voltageRatio = avg / 255;
            const dbV = 20 * Math.log10(Math.max(voltageRatio, 1e-6));
            const micSensitivity = -50; // Typical condenser mic sensitivity
            const dbSPL = dbV - micSensitivity + 94;
            const volume = Math.min(100, Math.max(0, (dbSPL - 30) * 1.67)); // 30-90 dB SPL -> 0-100%
            if (volume > maxVolume) {
                maxVolume = volume;
            }

            const elapsed = Date.now() - startTime;
            let message = "";

            if (elapsed < 5000) {
                const sec = Math.ceil((5000 - elapsed) / 1000);
                message = `Step 1: Measuring background noise (${sec}s)...`;
            } else if (elapsed < 10000) {
                message = "Step 2: Speak your name clearly...";
            } else if (elapsed < 15000) {
                message = "Step 3: Optimizing settings...";
            } else {
                source.disconnect();
                analyser.disconnect();
                if (onUpdate) {
                    onUpdate("Microphone Calibrated", 0, true);
                }
                resolve({ complete: true, gain: 1.0, speechLevel: maxVolume });
                return;
            }

            if (onUpdate) {
                onUpdate(message, volume, false);
            }
            requestAnimationFrame(loop);
        }
        loop();
    });
}

/**
 * Initializes recorder functionality for a specific instance.
 * Sets up CommandBus event handlers for microphone setup, recording control,
 * speech recognition, and real-time amplitude visualization.
 *
 * @function
 * @exports initRecorder
 * @param {Object} store - Redux-style store for state management
 * @param {function} store.dispatch - Function to dispatch state actions
 * @param {string} instanceId - Unique identifier for this recorder instance
 * @returns {void}
 *
 * @description Registers handlers for these commands:
 * - 'setup-mic': Request microphone access and perform calibration
 * - 'start-recording': Begin audio recording with speech recognition
 * - 'stop-mic': Stop recording and save audio blob
 * - 'pause-mic': Pause ongoing recording
 * - 'resume-mic': Resume paused recording
 *
 * All commands are filtered by instanceId to support multiple recorder instances.
 */
function initRecorder(store, instanceId) {
    console.log("[Recorder] 🎧 Listening for commands for ID:", instanceId);

    /**
     * Handler for 'setup-mic' command.
     * Requests microphone permissions, performs enhanced calibration, and updates store.
     * @listens CommandBus~setup-mic
     */
    // 1. SETUP MIC
    CommandBus.subscribe("setup-mic", async (_p, meta) => {
        if (meta?.instanceId !== instanceId) {
            return;
        }
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            await wakeAudio();

            store.dispatch({ type: "starmus/calibration-start" });

            // Use enhanced calibration with SPARXSTAR integration
            const enhancedCalibration = new EnhancedCalibration();
            await enhancedCalibration.init();

            const calibration = await enhancedCalibration.performCalibration(
                stream,
                (msg, vol, done, extra) => {
                    if (!done) {
                        store.dispatch({
                            type: "starmus/calibration-update",
                            message: msg,
                            volumePercent: vol,
                            extra:
                                extra ||
                                {
                                    /* intentionally empty */
                                },
                        });
                    }
                },
            );

            stream.getTracks().forEach((t) => t.stop());
            store.dispatch({ type: "starmus/calibration-complete", payload: { calibration } });
        } catch (e) {
            console.error("[Recorder] Calibration failed:", e);

            // Report calibration error to SPARXSTAR
            if (sparxstarIntegration.isAvailable) {
                sparxstarIntegration.reportError("calibration_setup_failed", {
                    error: e.message,
                    instanceId,
                    userAgent: navigator.userAgent,
                });
            }

            store.dispatch({
                type: "starmus/error",
                payload: { message: "Microphone access denied." },
            });
        }
    });

    /**
     * Handler for 'start-recording' command.
     * Creates MediaRecorder with optimized settings based on SPARXSTAR environment data.
     * @listens CommandBus~start-recording
     */
    // 2. START RECORDING
    CommandBus.subscribe("start-recording", async (_p, meta) => {
        if (meta?.instanceId !== instanceId) {
            return;
        }
        try {
            // Get optimized settings from SPARXSTAR
            const envData = sparxstarIntegration.getEnvironmentData();
            const settings =
                envData.recordingSettings ||
                {
                    /* intentionally empty */
                };

            // Apply tier-based audio constraints
            const audioConstraints = {
                audio: {
                    sampleRate: settings.sampleRate || 16000,
                    channelCount: settings.channels || 1,
                    echoCancellation: settings.enableEchoCancellation !== false,
                    noiseSuppression: settings.enableNoiseSupression !== false,
                    autoGainControl: settings.enableAutoGainControl !== false,
                },
            };

            console.log(
                "[Recorder] Using optimized constraints for tier",
                envData.tier,
                audioConstraints,
            );

            const stream = await navigator.mediaDevices.getUserMedia(audioConstraints);
            const ctx = await wakeAudio();
            const source = ctx.createMediaStreamSource(stream);

            // Create gain node for proper audio level control
            const gainNode = ctx.createGain();
            const dest = ctx.createMediaStreamDestination();

            // Get calibration gain from store if available
            const state = store.getState();
            const calibrationGain = state.calibration?.gain || 1.0;

            // Set gain using proper AudioParam method
            gainNode.gain.setValueAtTime(calibrationGain, ctx.currentTime);

            // Connect: source -> gain -> destination
            source.connect(gainNode);
            gainNode.connect(dest);

            // MediaRecorder with optimized options
            const mediaRecorderOptions = {
                mimeType: "audio/webm;codecs=opus",
                audioBitsPerSecond: settings.bitrate || 32000,
            };

            const mediaRecorder = new MediaRecorder(dest.stream, mediaRecorderOptions);
            console.debug("[RECORDER]", mediaRecorder.state, "with options:", mediaRecorderOptions);
            const chunks = [];

            // Language Signal Analyzer - Policy Enforcement Layer
            let signalAnalyzer = null;
            const deviceTier = store.getState()?.env?.tier || "B";
            const userCountry = store.getState()?.env?.country || "GM";

            if (deviceTier !== "C") {
                signalAnalyzer = new LanguageSignalAnalyzer({
                    tier: deviceTier,
                    country: userCountry,
                    maxDuration: 20000,
                });

                // Silent observer - never blocks recording
                signalAnalyzer
                    .analyze(stream)
                    .then((signals) => {
                        store.dispatch({
                            type: "starmus/signal-analysis-complete",
                            payload: signals,
                        });
                    })
                    .catch((err) => {
                        console.warn("[Recorder] Signal analysis failed:", err.message);
                    });
            }

            // MediaRecorder event handlers with chunk size optimization
            const chunkInterval = settings.chunkSize || 1000;
            mediaRecorder.ondataavailable = (e) => {
                if (e.data.size > 0) {
                    chunks.push(e.data);
                }
            };
            mediaRecorder.onstop = () => {
                const rec = recorderRegistry.get(instanceId);
                if (rec?.rafId) {
                    cancelAnimationFrame(rec.rafId);
                }
                if (signalAnalyzer) {
                    signalAnalyzer.stop();
                }
                const blob = new Blob(chunks, { type: "audio/webm" });

                // Report recording completion to SPARXSTAR
                if (sparxstarIntegration.isAvailable) {
                    sparxstarIntegration.reportError("recording_completed", {
                        duration: (Date.now() - startTime) / 1000,
                        fileSize: blob.size,
                        tier: envData.tier,
                        settings: settings,
                    });
                }

                store.dispatch({
                    type: "starmus/recording-available",
                    payload: { blob, fileName: `rec-${Date.now()}.webm` },
                });
                stream.getTracks().forEach((t) => t.stop());
                try {
                    source.disconnect();
                } catch (e) {
                    console.debug("[ANALYZER]", "disconnect failed");
                    if (sparxstarIntegration.isAvailable) {
                        sparxstarIntegration.reportError("audio_node_disconnect_failed", {
                            error: e,
                        });
                    }
                }
                recorderRegistry.delete(instanceId);
            };

            recorderRegistry.set(instanceId, { mediaRecorder, rafId: null, signalAnalyzer });
            const startTime = Date.now();
            mediaRecorder.start(chunkInterval);
            console.debug("[RECORDER]", mediaRecorder.state);
            store.dispatch({ type: "starmus/mic-start" });

            // Amplitude visualization setup
            const analyser = ctx.createAnalyser();
            console.debug("[ANALYZER]", analyser ? "attached" : "missing");
            source.connect(analyser);
            const buf = new Uint8Array(analyser.frequencyBinCount);
            const visualStartTs = Date.now();

            /**
             * Animation loop for real-time amplitude visualization.
             * Updates store with duration and amplitude data.
             */
            function visLoop() {
                const rec = recorderRegistry.get(instanceId);
                if (!rec || mediaRecorder.state !== "recording") {
                    return;
                }
                analyser.getByteFrequencyData(buf);
                let sum = 0;
                for (let x = 0; x < buf.length; x++) {
                    sum += buf[x];
                }
                const rawAmp = sum / buf.length;

                // Proper SPL calculation assuming typical mic sensitivity (-50 dBV/Pa)
                // Convert raw amplitude to voltage ratio, then to dB SPL
                const voltageRatio = rawAmp / 255; // Normalize to 0-1
                const dbV = 20 * Math.log10(Math.max(voltageRatio, 1e-6)); // Prevent log(0)
                const micSensitivity = -50; // Typical condenser mic sensitivity in dBV/Pa
                const dbSPL = dbV - micSensitivity + 94; // Convert to dB SPL

                // Map dB SPL to visual meter (30-90 dB SPL -> 0-100%)
                const amp = Math.min(100, Math.max(0, (dbSPL - 30) * 1.67));
                store.dispatch({
                    type: "starmus/recorder-tick",
                    duration: (Date.now() - visualStartTs) / 1000,
                    amplitude: amp,
                });
                rec.rafId = requestAnimationFrame(visLoop);
            }
            visLoop();
        } catch (e) {
            console.error("[Recorder] Recording failed:", e);

            // Report error to SPARXSTAR
            if (sparxstarIntegration.isAvailable) {
                sparxstarIntegration.reportError("recording_failed", {
                    error: e.message,
                    instanceId,
                    userAgent: navigator.userAgent,
                });
            }

            store.dispatch({ type: "starmus/error", payload: { message: "Recording failed." } });
        }
    });

    /**
     * Handler for 'stop-mic' command.
     * Stops MediaRecorder and speech recognition, triggers audio blob creation.
     * @listens CommandBus~stop-mic
     */
    // 3. STOP / PAUSE / RESUME
    CommandBus.subscribe("stop-mic", (_p, meta) => {
        if (meta?.instanceId !== instanceId) {
            return;
        }
        const rec = recorderRegistry.get(instanceId);
        if (rec?.mediaRecorder?.state === "recording" || rec?.mediaRecorder?.state === "paused") {
            rec.mediaRecorder.stop();
            if (rec.signalAnalyzer) {
                rec.signalAnalyzer.stop();
            }
            store.dispatch({ type: "starmus/mic-stop" });
        }
    });

    /**
     * Handler for 'pause-mic' command.
     * Pauses MediaRecorder and stops speech recognition temporarily.
     * @listens CommandBus~pause-mic
     */
    CommandBus.subscribe("pause-mic", (_p, meta) => {
        if (meta?.instanceId !== instanceId) {
            return;
        }
        const rec = recorderRegistry.get(instanceId);
        if (rec?.mediaRecorder?.state === "recording") {
            rec.mediaRecorder.pause();
            if (rec.signalAnalyzer) {
                rec.signalAnalyzer.stop();
            }
            store.dispatch({ type: "starmus/mic-pause" });
        }
    });

    /**
     * Handler for 'resume-mic' command.
     * Resumes MediaRecorder and restarts speech recognition.
     * @listens CommandBus~resume-mic
     */
    CommandBus.subscribe("resume-mic", (_p, meta) => {
        if (meta?.instanceId !== instanceId) {
            return;
        }
        const rec = recorderRegistry.get(instanceId);
        if (rec?.mediaRecorder?.state === "paused") {
            rec.mediaRecorder.resume();
            // Note: Signal analyzer doesn't restart on resume - single probe only
            store.dispatch({ type: "starmus/mic-resume" });
        }
    });
}

/**
 * Explicit export for build system compatibility.
 * Exports initRecorder function for use in other modules.
 * @exports {function} initRecorder
 */
// EXPLICIT EXPORT FOR ROLLUP
export { initRecorder };
