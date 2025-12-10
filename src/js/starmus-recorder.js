/**
 * @file starmus-recorder.js
 * @version 5.3.0-BULLETPROOF
 * @description Recorder with Global Subscription + Local Filtering.
 */

'use strict';

import { CommandBus } from './starmus-hooks.js';

const recorderRegistry = new Map();
let sharedAudioContext = null;

function getContext() {
  const Ctx = window.AudioContext || window.webkitAudioContext;
  if (!Ctx) throw new Error('Audio API not supported');
  if (!sharedAudioContext || sharedAudioContext.state === 'closed') {
    sharedAudioContext = new Ctx({ latencyHint: 'interactive' });
    window.StarmusAudioContext = sharedAudioContext;
  }
  return sharedAudioContext;
}

// Helper: Ensure audio engine is awake
async function wakeAudio() {
    const ctx = getContext();
    if (ctx.state === 'suspended') {
        console.log('[Recorder] Resuming Audio Context...');
        await ctx.resume();
    }
    return ctx;
}

async function doCalibration(stream, onUpdate) {
    const ctx = await wakeAudio();
    const source = ctx.createMediaStreamSource(stream);
    const analyser = ctx.createAnalyser();
    analyser.fftSize = 2048;
    source.connect(analyser);

    const data = new Uint8Array(analyser.frequencyBinCount);
    const start = Date.now();

    return new Promise(resolve => {
        function loop() {
            analyser.getByteFrequencyData(data);
            let sum = 0;
            for(let i=0; i<data.length; i++) sum += data[i];
            const avg = sum / data.length;
            const pct = Math.min(100, avg * 3); // Sensitivity boost

            if (onUpdate) onUpdate('Adjusting levels...', pct, false);

            if (Date.now() - start < 4000) {
                requestAnimationFrame(loop);
            } else {
                source.disconnect();
                analyser.disconnect();
                if (onUpdate) onUpdate('Ready', 0, true);
                resolve({ complete: true, gain: 1.0 });
            }
        }
        loop();
    });
}

export function initRecorder(store, instanceId) {
  console.log('[Recorder] 🎧 Listening for commands for ID:', instanceId);

  // 1. SETUP MIC (Global Listen -> Local Filter)
  CommandBus.subscribe('setup-mic', async (_p, meta) => {
    // --- LOCAL FILTER ---
    if (meta?.instanceId !== instanceId) return; 

    console.log('[Recorder] 🎤 Setup Mic Triggered!');

    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      await wakeAudio();

      store.dispatch({ type: 'starmus/calibration-start' });

      const calibration = await doCalibration(stream, (msg, vol, done) => {
         if(!done) store.dispatch({ type: 'starmus/calibration-update', message: msg, volumePercent: vol });
      });

      stream.getTracks().forEach(t => t.stop()); // Clean up
      store.dispatch({ type: 'starmus/calibration-complete', payload: { calibration } });

    } catch (e) {
      console.error('[Recorder] Setup Failed:', e);
      store.dispatch({ type: 'starmus/error', payload: { message: 'Mic access failed: ' + e.message } });
    }
  });

  // 2. START RECORDING
  CommandBus.subscribe('start-recording', async (_p, meta) => {
    if (meta?.instanceId !== instanceId) return;

    console.log('[Recorder] ⏺️ Start Recording Triggered!');

    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const ctx = await wakeAudio();
      
      const source = ctx.createMediaStreamSource(stream);
      const dest = ctx.createMediaStreamDestination();
      source.connect(dest);

      const mediaRecorder = new MediaRecorder(dest.stream);
      const chunks = [];

      mediaRecorder.ondataavailable = e => { if (e.data.size > 0) chunks.push(e.data); };
      
      mediaRecorder.onstop = () => {
        const rec = recorderRegistry.get(instanceId);
        if(rec) cancelAnimationFrame(rec.rafId);
        
        const blob = new Blob(chunks, { type: 'audio/webm' });
        const fileName = `rec-${Date.now()}.webm`;
        store.dispatch({ type: 'starmus/recording-available', payload: { blob, fileName } });
        
        stream.getTracks().forEach(t => t.stop());
        recorderRegistry.delete(instanceId);
      };

      recorderRegistry.set(instanceId, { mediaRecorder, rafId: null });
      mediaRecorder.start(1000);
      store.dispatch({ type: 'starmus/mic-start' });

      // Visualizer
      const analyser = ctx.createAnalyser();
      source.connect(analyser);
      const buf = new Uint8Array(analyser.frequencyBinCount);
      const startTs = Date.now();

      function visLoop() {
         const rec = recorderRegistry.get(instanceId);
         if(!rec || mediaRecorder.state !== 'recording') return;
         
         analyser.getByteFrequencyData(buf);
         let sum=0; for(let x=0; x<buf.length; x++) sum+=buf[x];
         const amp = Math.min(100, (sum/buf.length)*3);
         
         store.dispatch({ 
             type: 'starmus/recorder-tick', 
             duration: (Date.now()-startTs)/1000, 
             amplitude: amp 
         });
         rec.rafId = requestAnimationFrame(visLoop);
      }
      visLoop();

    } catch (e) {
      console.error('[Recorder] Start Failed:', e);
      store.dispatch({ type: 'starmus/error', payload: { message: 'Recording failed.' } });
    }
  });

  // 3. STOP
  CommandBus.subscribe('stop-mic', (_p, meta) => {
     if (meta?.instanceId !== instanceId) return;
     const rec = recorderRegistry.get(instanceId);
     if(rec?.mediaRecorder?.state === 'recording') {
         rec.mediaRecorder.stop();
         store.dispatch({ type: 'starmus/mic-stop' });
     }
  });
}