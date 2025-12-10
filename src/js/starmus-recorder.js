/**
 * @file starmus-recorder.js
 * @version 5.1.0-AUDIO-FIX
 * @description Recorder Logic. Fixed: Forces AudioContext to resume to prevent hanging.
 */

'use strict';

import { CommandBus, debugLog } from './starmus-hooks.js';

const recorderRegistry = new Map();
let sharedAudioContext = null;

/**
 * Emit telemetry events.
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
 * 1. Safe AudioContext Creation
 */
function getSharedContext() {
  const Ctx = window.AudioContext || window.webkitAudioContext;
  if (!Ctx) {
    throw new Error('AudioContext not supported in this browser');
  }
  if (!sharedAudioContext || sharedAudioContext.state === 'closed') {
    sharedAudioContext = new Ctx({ latencyHint: 'interactive' });
    
    // --- FIX: Expose globally so Integrator can keep it alive ---
    window.StarmusAudioContext = sharedAudioContext;
    
    console.log('[Recorder] Created AudioContext, state:', sharedAudioContext.state);
  }
  return sharedAudioContext;
}

/**
 * 2. Force Wake-Up Helper
 */
async function ensureContextRunning() {
    const ctx = getSharedContext();
    if (ctx.state === 'suspended') {
        console.log('[Recorder] Waking up AudioContext...');
        await ctx.resume();
    }
    return ctx;
}

/**
 * 3. Audio Graph Setup
 */
function setupAudioGraph(rawStream) {
  const audioContext = getSharedContext();
  const source = audioContext.createMediaStreamSource(rawStream);

  try {
    // Try standard node creation
    const destFn = audioContext.createMediaStreamDestination || audioContext.createMediaStreamAudioDestination;
    const destNode = destFn.call(audioContext);
    
    const analyser = audioContext.createAnalyser();
    analyser.fftSize = 2048;

    // Direct connect for simplicity and stability
    source.connect(analyser);
    analyser.connect(destNode);

    return {
      audioContext,
      destinationStream: destNode.stream,
      analyser,
      nodes: [source, analyser, destNode],
      fallbackActive: false
    };
  } catch (e) {
    console.warn('[Recorder] Audio graph fallback:', e);
    // Fallback: Just analyze the raw stream
    const analyser = audioContext.createAnalyser();
    analyser.fftSize = 2048;
    source.connect(analyser);
    
    return {
      audioContext,
      destinationStream: rawStream,
      analyser,
      nodes: [source, analyser],
      fallbackActive: true
    };
  }
}

/**
 * 4. Calibration with Safety Timeout
 */
async function calibrateAudioLevels(stream, onUpdate) {
  const audioContext = await ensureContextRunning(); // Ensure running!
  
  const analyser = audioContext.createAnalyser();
  analyser.fftSize = 2048;
  const mic = audioContext.createMediaStreamSource(stream);
  mic.connect(analyser);

  const buffer = new Float32Array(analyser.fftSize);
  const DURATION = 6000; // Shorter calibration for better UX
  const startTime = performance.now();

  return new Promise((resolve) => {
    function tick() {
      // FIX: If context suspends again, try to wake it, but don't block
      if (audioContext.state !== 'running') {
          audioContext.resume(); 
      }

      analyser.getFloatTimeDomainData(buffer);
      let sum = 0;
      for (let i = 0; i < buffer.length; i++) sum += buffer[i] * buffer[i];
      const rms = Math.sqrt(sum / buffer.length);
      
      const volumePercent = Math.min(100, rms * 2000);
      
      // Update UI
      if (onUpdate) onUpdate('Calibrating...', volumePercent, false);

      if (performance.now() - startTime < DURATION) {
        requestAnimationFrame(tick);
      } else {
        // cleanup
        mic.disconnect();
        analyser.disconnect();
        if (onUpdate) onUpdate('Ready', 0, true);
        resolve({ complete: true, gain: 1.0 }); // Simplified result
      }
    }
    tick();
  });
}

/**
 * MAIN INIT FUNCTION
 */
export function initRecorder(store, instanceId) {
  
  // A. SETUP MIC HANDLER
  CommandBus.subscribe('setup-mic', async (_payload, meta) => {
    if (meta.instanceId !== instanceId) return;

    if (!navigator.mediaDevices?.getUserMedia) {
      store.dispatch({ type: 'starmus/error', payload: { message: 'Microphone not supported.', retryable: false } });
      return;
    }

    try {
      console.log('[Recorder] Requesting Mic Access...');
      const rawStream = await navigator.mediaDevices.getUserMedia({ audio: true });
      
      // --- FIX: WAKE UP ENGINE IMMEDIATELY ---
      await ensureContextRunning();
      
      store.dispatch({ type: 'starmus/calibration-start' });

      // Run calibration
      const calibration = await calibrateAudioLevels(rawStream, (msg, vol, done) => {
          if(!done) store.dispatch({ type: 'starmus/calibration-update', message: msg, volumePercent: vol });
      });

      // Stop stream after calibration (user must click Record to start for real)
      rawStream.getTracks().forEach(t => t.stop());
      
      store.dispatch({ type: 'starmus/calibration-complete', payload: { calibration } });
      
    } catch (err) {
      console.error('[Recorder] Mic Setup Failed:', err);
      const msg = err.name === 'NotAllowedError' ? 'Microphone permission denied.' : 'Failed to access microphone.';
      store.dispatch({ type: 'starmus/error', payload: { message: msg, retryable: true } });
    }
  }, instanceId);

  // B. START RECORDING HANDLER
  CommandBus.subscribe('start-recording', async (_payload, meta) => {
    if (meta.instanceId !== instanceId) return;
    
    const state = store.getState();
    if (!state.calibration?.complete) {
        // Auto-setup if they skipped it
        console.log('[Recorder] Skipping calibration check for ease of use');
    }

    try {
      // 1. Get Stream
      const rawStream = await navigator.mediaDevices.getUserMedia({ audio: true });
      
      // 2. Setup Graph & Context
      const graph = setupAudioGraph(rawStream);
      await ensureContextRunning(); // CRITICAL

      // 3. MediaRecorder
      const mediaRecorder = new MediaRecorder(graph.destinationStream);
      const chunks = [];

      mediaRecorder.ondataavailable = (e) => {
        if (e.data.size > 0) chunks.push(e.data);
      };

      mediaRecorder.onstop = () => {
        const rec = recorderRegistry.get(instanceId);
        if (!rec) return;

        cancelAnimationFrame(rec.rafId);
        const blob = new Blob(chunks, { type: mediaRecorder.mimeType || 'audio/webm' });
        const fileName = `starmus-recording-${Date.now()}.webm`;

        store.dispatch({ type: 'starmus/recording-available', payload: { blob, fileName } });

        // Cleanup
        rawStream.getTracks().forEach(t => t.stop());
        graph.nodes.forEach(n => { try{n.disconnect()}catch(e){} });
        recorderRegistry.delete(instanceId);
      };

      // 4. Start
      recorderRegistry.set(instanceId, { mediaRecorder, rawStream, graph, rafId: null });
      mediaRecorder.start(1000); // 1s chunks
      store.dispatch({ type: 'starmus/mic-start' });

      // 5. Visualizer Loop
      const analyser = graph.analyser;
      const meterBuf = new Float32Array(analyser.fftSize);
      const startTs = performance.now();

      function meterLoop() {
        const rec = recorderRegistry.get(instanceId);
        if (!rec || rec.mediaRecorder.state !== 'recording') return;

        analyser.getFloatTimeDomainData(meterBuf);
        let sum = 0;
        for (let i=0; i<meterBuf.length; i++) sum += meterBuf[i]*meterBuf[i];
        const amplitude = Math.min(100, Math.sqrt(sum/meterBuf.length) * 4000);
        
        store.dispatch({ 
            type: 'starmus/recorder-tick', 
            duration: (performance.now() - startTs)/1000, 
            amplitude 
        });
        
        rec.rafId = requestAnimationFrame(meterLoop);
      }
      meterLoop();

    } catch (err) {
      console.error('[Recorder] Start Failed:', err);
      store.dispatch({ type: 'starmus/error', payload: { message: 'Recording failed to start: ' + err.message } });
    }
  }, instanceId);

  // C. CONTROLS
  CommandBus.subscribe('stop-mic', () => {
    const rec = recorderRegistry.get(instanceId);
    if (rec?.mediaRecorder?.state === 'recording') rec.mediaRecorder.stop();
  }, instanceId);

  CommandBus.subscribe('pause-mic', () => {
    const rec = recorderRegistry.get(instanceId);
    if (rec?.mediaRecorder?.state === 'recording') {
        rec.mediaRecorder.pause();
        store.dispatch({ type: 'starmus/mic-pause' });
    }
  }, instanceId);

  CommandBus.subscribe('resume-mic', () => {
    const rec = recorderRegistry.get(instanceId);
    if (rec?.mediaRecorder?.state === 'paused') {
        rec.mediaRecorder.resume();
        store.dispatch({ type: 'starmus/mic-resume' });
    }
  }, instanceId);

  CommandBus.subscribe('reset', () => {
    const rec = recorderRegistry.get(instanceId);
    if (rec) {
       try { rec.mediaRecorder.stop(); } catch(e){}
       rec.rawStream?.getTracks().forEach(t => t.stop());
       recorderRegistry.delete(instanceId);
    }
  }, instanceId);
}