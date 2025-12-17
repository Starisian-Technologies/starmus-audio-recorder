/**
 * @file starmus-recorder.js
 * @version 6.4.0-BUILD-FIX
 * @description Audio recording functionality with MediaRecorder API, microphone calibration,
 * real-time speech recognition, and visual amplitude feedback. Handles complete recording
 * lifecycle from setup through stop with explicit exports for build system.
 */

'use strict';

import { CommandBus } from './starmus-hooks.js';

/**
 * Registry of active recorder instances mapped by instanceId.
 * Stores MediaRecorder, animation frame ID, and speech recognition objects.
 * @type {Map<string, Object>}
 * @property {MediaRecorder} mediaRecorder - MediaRecorder instance for audio capture
 * @property {number|null} rafId - RequestAnimationFrame ID for visual updates
 * @property {SpeechRecognition|null} recognition - Speech recognition instance
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
 * Used for real-time transcription during recording.
 * @type {function|undefined}
 */
const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

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
  if (!Ctx) throw new Error('Audio API not supported');
  if (!sharedAudioContext || sharedAudioContext.state === 'closed') {
    sharedAudioContext = new Ctx({ latencyHint: 'interactive' });
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
    if (ctx.state === 'suspended') await ctx.resume();
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
async function doCalibration(stream, onUpdate) {
    const ctx = await wakeAudio();
    const source = ctx.createMediaStreamSource(stream);
    const analyser = ctx.createAnalyser();
    analyser.fftSize = 2048;
    source.connect(analyser);

    const data = new Uint8Array(analyser.frequencyBinCount);
    const startTime = Date.now();
    let maxVolume = 0;
    
    return new Promise(resolve => {
        function loop() {
            analyser.getByteFrequencyData(data);
            let sum = 0;
            for(let i=0; i<data.length; i++) sum += data[i];
            const avg = sum / data.length;
            const volume = Math.min(100, avg * 10); 
            if (volume > maxVolume) maxVolume = volume;

            const elapsed = Date.now() - startTime;
            let message = '';

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
                if (onUpdate) onUpdate('Microphone Calibrated', 0, true);
                resolve({ complete: true, gain: 1.0, speechLevel: maxVolume });
                return;
            }

            if (onUpdate) onUpdate(message, volume, false);
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
  console.log('[Recorder] 🎧 Listening for commands for ID:', instanceId);

  /**
   * Handler for 'setup-mic' command.
   * Requests microphone permissions, performs calibration, and updates store.
   * @listens CommandBus~setup-mic
   */
  // 1. SETUP MIC
  CommandBus.subscribe('setup-mic', async (_p, meta) => {
    if (meta?.instanceId !== instanceId) return; 
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      await wakeAudio();
      store.dispatch({ type: 'starmus/calibration-start' });
      const calibration = await doCalibration(stream, (msg, vol, done) => {
         if(!done) store.dispatch({ type: 'starmus/calibration-update', message: msg, volumePercent: vol });
      });
      stream.getTracks().forEach(t => t.stop()); 
      store.dispatch({ type: 'starmus/calibration-complete', payload: { calibration } });
    } catch (e) {
      store.dispatch({ type: 'starmus/error', payload: { message: 'Microphone access denied.' } });
    } 
  });

  /**
   * Handler for 'start-recording' command.
   * Creates MediaRecorder, sets up speech recognition, and starts amplitude visualization.
   * @listens CommandBus~start-recording
   */
  // 2. START RECORDING
  CommandBus.subscribe('start-recording', async (_p, meta) => {
    if (meta?.instanceId !== instanceId) return;
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const ctx = await wakeAudio();
      const source = ctx.createMediaStreamSource(stream);
      const dest = ctx.createMediaStreamDestination();
      source.connect(dest);
      
      const mediaRecorder = new MediaRecorder(dest.stream);
      const chunks = [];
      
      // Speech recognition setup
      let recognition = null;
      if (SpeechRecognition) {
          try {
              recognition = new SpeechRecognition();
              recognition.continuous = true;
              recognition.interimResults = true;
              recognition.lang = 'en-US';
              recognition.onresult = (event) => {
                  let final = '';
                  for (let i = event.resultIndex; i < event.results.length; ++i) {
                      if (event.results[i].isFinal) final += event.results[i][0].transcript + ' ';
                  }
                  if(final) store.dispatch({ type: 'starmus/transcript-update', transcript: final });
              };
              recognition.start();
          } catch(e) {}
      }

      // MediaRecorder event handlers
      mediaRecorder.ondataavailable = e => { if (e.data.size > 0) chunks.push(e.data); };
      mediaRecorder.onstop = () => {
        const rec = recorderRegistry.get(instanceId);
        if(rec) cancelAnimationFrame(rec.rafId);
        if(recognition) recognition.stop();
        const blob = new Blob(chunks, { type: 'audio/webm' });
        store.dispatch({ type: 'starmus/recording-available', payload: { blob, fileName: `rec-${Date.now()}.webm` } });
        stream.getTracks().forEach(t => t.stop());
        recorderRegistry.delete(instanceId);
      };

      recorderRegistry.set(instanceId, { mediaRecorder, rafId: null, recognition });
      mediaRecorder.start(1000);
      store.dispatch({ type: 'starmus/mic-start' });

      // Amplitude visualization setup
      const analyser = ctx.createAnalyser();
      source.connect(analyser);
      const buf = new Uint8Array(analyser.frequencyBinCount);
      const startTs = Date.now();
      
      /**
       * Animation loop for real-time amplitude visualization.
       * Updates store with duration and amplitude data.
       */
      function visLoop() {
         const rec = recorderRegistry.get(instanceId);
         if(!rec || mediaRecorder.state !== 'recording') return;
         analyser.getByteFrequencyData(buf);
         let sum=0; for(let x=0; x<buf.length; x++) sum+=buf[x];
         const amp = Math.min(100, (sum/buf.length) * 10); 
         store.dispatch({ type: 'starmus/recorder-tick', duration: (Date.now()-startTs)/1000, amplitude: amp });
         rec.rafId = requestAnimationFrame(visLoop);
      }
      visLoop();
    } catch (e) {
      store.dispatch({ type: 'starmus/error', payload: { message: 'Recording failed.' } });
    }
  });

  /**
   * Handler for 'stop-mic' command.
   * Stops MediaRecorder and speech recognition, triggers audio blob creation.
   * @listens CommandBus~stop-mic
   */
  // 3. STOP / PAUSE / RESUME
  CommandBus.subscribe('stop-mic', (_p, meta) => {
     if (meta?.instanceId !== instanceId) return;
     const rec = recorderRegistry.get(instanceId);
     if(rec?.mediaRecorder?.state === 'recording' || rec?.mediaRecorder?.state === 'paused') {
         rec.mediaRecorder.stop();
         if(rec.recognition) rec.recognition.stop();
         store.dispatch({ type: 'starmus/mic-stop' });
     }
  });
  
  /**
   * Handler for 'pause-mic' command.
   * Pauses MediaRecorder and stops speech recognition temporarily.
   * @listens CommandBus~pause-mic
   */
  CommandBus.subscribe('pause-mic', (_p, meta) => {
     if (meta?.instanceId !== instanceId) return;
     const rec = recorderRegistry.get(instanceId);
     if(rec?.mediaRecorder?.state === 'recording') {
         rec.mediaRecorder.pause();
         if(rec.recognition) rec.recognition.stop();
         store.dispatch({ type: 'starmus/mic-pause' });
     }
  });

  /**
   * Handler for 'resume-mic' command.
   * Resumes MediaRecorder and restarts speech recognition.
   * @listens CommandBus~resume-mic
   */
  CommandBus.subscribe('resume-mic', (_p, meta) => {
     if (meta?.instanceId !== instanceId) return;
     const rec = recorderRegistry.get(instanceId);
     if(rec?.mediaRecorder?.state === 'paused') {
         rec.mediaRecorder.resume();
         if(rec.recognition) try { rec.recognition.start(); } catch(e){}
         store.dispatch({ type: 'starmus/mic-resume' });
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