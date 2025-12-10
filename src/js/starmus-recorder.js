/**
 * @file starmus-recorder.js
 * @version 5.2.0-PRODUCTION
 * @description Full-featured Recorder with Audio Graph and Safe Context Resume.
 */

'use strict';

import { CommandBus, debugLog } from './starmus-hooks.js';

const recorderRegistry = new Map();
let sharedAudioContext = null;

// --- TELEMETRY ---
function emitStarmusEvent(instanceId, event, payload = {}) {
  try {
    if (window.StarmusHooks?.doAction) {
      window.StarmusHooks.doAction('starmus_event', {
        instanceId,
        event,
        severity: payload.severity || 'info',
        message: payload.message || '',
        data: payload.data || {}
      });
    }
  } catch (e) { console.warn('Telemetry failed', e); }
}

// --- 1. SAFE AUDIO CONTEXT & WAKE LOCK ---
function getSharedContext() {
  const Ctx = window.AudioContext || window.webkitAudioContext;
  if (!Ctx) throw new Error('AudioContext not supported');

  if (!sharedAudioContext || sharedAudioContext.state === 'closed') {
    sharedAudioContext = new Ctx({ latencyHint: 'interactive' });
    window.StarmusAudioContext = sharedAudioContext; // Expose for integrator
  }
  return sharedAudioContext;
}

/**
 * CRITICAL FIX: Forces the AudioContext to wake up.
 * Browsers auto-suspend AudioContexts created without user gesture.
 * We call this inside the click handler to unblock it.
 */
async function ensureContextRunning() {
    const ctx = getSharedContext();
    if (ctx.state === 'suspended') {
        debugLog('[Recorder] Waking AudioContext...');
        await ctx.resume();
    }
    return ctx;
}

// --- 2. AUDIO GRAPH (Restored Complexity) ---
function setupAudioGraph(rawStream) {
  const ctx = getSharedContext();
  const source = ctx.createMediaStreamSource(rawStream);

  try {
    // Use Destination Node if available
    const destFn = ctx.createMediaStreamDestination || ctx.createMediaStreamAudioDestination;
    if (!destFn) throw new Error('No MediaStreamDestination');
    
    const destNode = destFn.call(ctx);

    // Filters & Compressor (Restored)
    const highPass = ctx.createBiquadFilter();
    highPass.type = 'highpass';
    highPass.frequency.value = 85;

    const compressor = ctx.createDynamicsCompressor();
    compressor.threshold.value = -20;
    compressor.knee.value = 40;
    compressor.ratio.value = 12;
    compressor.attack.value = 0;
    compressor.release.value = 0.25;

    const analyser = ctx.createAnalyser();
    analyser.fftSize = 2048;

    // Connect Graph
    source.connect(highPass);
    highPass.connect(compressor);
    compressor.connect(analyser);
    analyser.connect(destNode);

    return {
      audioContext: ctx,
      destinationStream: destNode.stream,
      analyser,
      nodes: [source, highPass, compressor, analyser, destNode]
    };
  } catch (e) {
    console.warn('[Recorder] Graph fallback:', e);
    // Fallback Graph
    const analyser = ctx.createAnalyser();
    analyser.fftSize = 2048;
    source.connect(analyser);
    return {
      audioContext: ctx,
      destinationStream: rawStream,
      analyser,
      nodes: [source, analyser]
    };
  }
}

// --- 3. CALIBRATION ---
async function calibrateAudioLevels(stream, onUpdate) {
  const ctx = await ensureContextRunning();
  const analyser = ctx.createAnalyser();
  analyser.fftSize = 2048;
  const mic = ctx.createMediaStreamSource(stream);
  mic.connect(analyser);

  const buffer = new Float32Array(analyser.fftSize);
  const startTime = performance.now();
  const DURATION = 6000; // 6s calibration

  return new Promise((resolve) => {
    function tick() {
      if (ctx.state !== 'running') ctx.resume();

      analyser.getFloatTimeDomainData(buffer);
      let sum = 0;
      for(let i=0; i<buffer.length; i++) sum += buffer[i]*buffer[i];
      const rms = Math.sqrt(sum / buffer.length);
      const vol = Math.min(100, rms * 2000);

      if(onUpdate) onUpdate('Calibrating...', vol, false);

      if (performance.now() - startTime < DURATION) {
        requestAnimationFrame(tick);
      } else {
        mic.disconnect();
        analyser.disconnect();
        if(onUpdate) onUpdate('Ready', 0, true);
        resolve({ complete: true, gain: 1.0 });
      }
    }
    tick();
  });
}

// --- 4. MAIN INIT ---
export function initRecorder(store, instanceId) {
  debugLog('[Recorder] Initializing listeners for:', instanceId);

  // A. SETUP MIC
  CommandBus.subscribe('setup-mic', async (_p, meta) => {
    if (meta.instanceId !== instanceId) return;

    try {
      const rawStream = await navigator.mediaDevices.getUserMedia({ audio: true });
      await ensureContextRunning(); // FIX

      emitStarmusEvent(instanceId, 'E_MIC_ACCESS', { message: 'Mic access granted' });
      store.dispatch({ type: 'starmus/calibration-start' });

      const calib = await calibrateAudioLevels(rawStream, (msg, vol, done) => {
        if(!done) store.dispatch({ type: 'starmus/calibration-update', message: msg, volumePercent: vol });
      });

      rawStream.getTracks().forEach(t => t.stop());
      store.dispatch({ type: 'starmus/calibration-complete', payload: { calibration: calib } });

    } catch (err) {
      console.error('[Recorder] Setup Failed:', err);
      store.dispatch({ type: 'starmus/error', payload: { message: 'Microphone access failed.', retryable: true } });
    }
  }, instanceId);

  // B. START RECORDING
  CommandBus.subscribe('start-recording', async (_p, meta) => {
    if (meta.instanceId !== instanceId) return;
    
    // Safety check for calibration
    if (store.getState().status === 'calibrating') return;

    try {
      const rawStream = await navigator.mediaDevices.getUserMedia({ audio: true });
      await ensureContextRunning(); // FIX

      const graph = setupAudioGraph(rawStream);
      
      const mediaRecorder = new MediaRecorder(graph.destinationStream);
      const chunks = [];

      mediaRecorder.ondataavailable = e => { if (e.data.size > 0) chunks.push(e.data); };
      
      mediaRecorder.onstop = () => {
        const rec = recorderRegistry.get(instanceId);
        if(!rec) return;

        cancelAnimationFrame(rec.rafId);
        const blob = new Blob(chunks, { type: mediaRecorder.mimeType || 'audio/webm' });
        const fileName = `rec-${Date.now()}.webm`;

        emitStarmusEvent(instanceId, 'REC_COMPLETE', { data: { size: blob.size } });
        store.dispatch({ type: 'starmus/recording-available', payload: { blob, fileName } });

        rawStream.getTracks().forEach(t => t.stop());
        graph.destinationStream.getTracks().forEach(t => t.stop());
        graph.nodes.forEach(n => { try{n.disconnect()}catch(_){} });
        
        recorderRegistry.delete(instanceId);
      };

      recorderRegistry.set(instanceId, { mediaRecorder, rawStream, graph, rafId: null });
      
      mediaRecorder.start(1000);
      store.dispatch({ type: 'starmus/mic-start' });

      // Visualizer Loop
      const analyser = graph.analyser;
      const buf = new Float32Array(analyser.fftSize);
      const startTs = performance.now();

      function loop() {
        const rec = recorderRegistry.get(instanceId);
        if (!rec || mediaRecorder.state !== 'recording') return;

        analyser.getFloatTimeDomainData(buf);
        let s = 0; for(let i=0; i<buf.length; i++) s+=buf[i]*buf[i];
        const amp = Math.min(100, Math.sqrt(s/buf.length) * 4000);

        store.dispatch({ 
            type: 'starmus/recorder-tick', 
            duration: (performance.now() - startTs)/1000, 
            amplitude: amp 
        });
        rec.rafId = requestAnimationFrame(loop);
      }
      loop();

    } catch (e) {
      console.error('[Recorder] Start Error:', e);
      store.dispatch({ type: 'starmus/error', payload: { message: 'Recording failed to start.' } });
    }
  }, instanceId);

  // C. CONTROLS
  CommandBus.subscribe('stop-mic', () => {
      const rec = recorderRegistry.get(instanceId);
      if(rec?.mediaRecorder?.state === 'recording') {
          rec.mediaRecorder.stop();
          store.dispatch({ type: 'starmus/mic-stop' });
      }
  }, instanceId);

  CommandBus.subscribe('pause-mic', () => {
      const rec = recorderRegistry.get(instanceId);
      if(rec?.mediaRecorder?.state === 'recording') {
          rec.mediaRecorder.pause();
          store.dispatch({ type: 'starmus/mic-pause' });
      }
  }, instanceId);

  CommandBus.subscribe('resume-mic', () => {
      const rec = recorderRegistry.get(instanceId);
      if(rec?.mediaRecorder?.state === 'paused') {
          rec.mediaRecorder.resume();
          store.dispatch({ type: 'starmus/mic-resume' });
      }
  }, instanceId);
  
  CommandBus.subscribe('reset', () => {
      const rec = recorderRegistry.get(instanceId);
      if(rec) {
          try { rec.mediaRecorder.stop(); } catch(_){}
          rec.rawStream?.getTracks().forEach(t => t.stop());
          recorderRegistry.delete(instanceId);
      }
  }, instanceId);
}