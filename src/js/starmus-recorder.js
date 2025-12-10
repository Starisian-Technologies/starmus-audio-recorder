/**
 * @file starmus-recorder.js
 * @version 5.7.0-PHASED
 * @description Recorder with 3-Phase Calibration (Silence/Talk/Silence).
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

async function wakeAudio() {
    const ctx = getContext();
    if (ctx.state === 'suspended') {
        await ctx.resume();
    }
    return ctx;
}

/**
 * 3-Phase Calibration (15 Seconds Total):
 * 0-5s:  Silence (Measure Noise Floor)
 * 5-10s: Talk (Measure Gain)
 * 10-15s: Finalize/Processing
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
            // 1. Calculate Volume
            analyser.getByteFrequencyData(data);
            let sum = 0;
            for(let i=0; i<data.length; i++) sum += data[i];
            const avg = sum / data.length;
            
            // Visual multiplier for the UI meter
            const volume = Math.min(100, avg * 5); 
            if (volume > maxVolume) maxVolume = volume;

            // 2. Determine Phase
            const elapsed = Date.now() - startTime;
            let message = '';

            if (elapsed < 5000) {
                // Phase 1: Silence
                const countdown = Math.ceil((5000 - elapsed) / 1000);
                message = `Shh... measuring background noise (${countdown})`;
            } else if (elapsed < 10000) {
                // Phase 2: Talk
                const countdown = Math.ceil((10000 - elapsed) / 1000);
                message = `Please speak clearly into the mic... (${countdown})`;
            } else if (elapsed < 12000) {
                // Phase 3: Finalizing
                message = 'Optimizing settings...';
            } else {
                // Done
                source.disconnect();
                analyser.disconnect();
                if (onUpdate) onUpdate('Calibration Complete', 0, true);
                resolve({ 
                    complete: true, 
                    gain: 1.0,
                    speechLevel: maxVolume
                });
                return;
            }

            // 3. Update UI
            if (onUpdate) onUpdate(message, volume, false);
            
            requestAnimationFrame(loop);
        }
        loop();
    });
}

export function initRecorder(store, instanceId) {
  console.log('[Recorder] 🎧 Listening for commands for ID:', instanceId);

  // 1. SETUP MIC
  CommandBus.subscribe('setup-mic', async (_p, meta) => {
    if (meta?.instanceId !== instanceId) return; 

    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      await wakeAudio();

      store.dispatch({ type: 'starmus/calibration-start' });

      const calibration = await doCalibration(stream, (msg, vol, done) => {
         if(!done) {
             store.dispatch({ 
                 type: 'starmus/calibration-update', 
                 message: msg, 
                 volumePercent: vol 
             });
         }
      });

      stream.getTracks().forEach(t => t.stop()); 
      store.dispatch({ type: 'starmus/calibration-complete', payload: { calibration } });

    } catch (e) {
      console.error('[Recorder] Setup Failed:', e);
      store.dispatch({ type: 'starmus/error', payload: { message: 'Mic access failed. Please allow permissions.' } });
    }
  });

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

      // Visualizer Loop
      const analyser = ctx.createAnalyser();
      source.connect(analyser);
      const buf = new Uint8Array(analyser.frequencyBinCount);
      const startTs = Date.now();

      function visLoop() {
         const rec = recorderRegistry.get(instanceId);
         if(!rec || mediaRecorder.state !== 'recording') return;
         
         analyser.getByteFrequencyData(buf);
         let sum=0; for(let x=0; x<buf.length; x++) sum+=buf[x];
         
         // Amplify for visualizer
         const amp = Math.min(100, (sum/buf.length) * 5); 
         
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

  // 3. STOP & PAUSE
  CommandBus.subscribe('stop-mic', (_p, meta) => {
     if (meta?.instanceId !== instanceId) return;
     const rec = recorderRegistry.get(instanceId);
     if(rec?.mediaRecorder?.state === 'recording' || rec?.mediaRecorder?.state === 'paused') {
         rec.mediaRecorder.stop();
         store.dispatch({ type: 'starmus/mic-stop' });
     }
  });
  
  CommandBus.subscribe('pause-mic', (_p, meta) => {
     if (meta?.instanceId !== instanceId) return;
     const rec = recorderRegistry.get(instanceId);
     if(rec?.mediaRecorder?.state === 'recording') {
         rec.mediaRecorder.pause();
         store.dispatch({ type: 'starmus/mic-pause' });
     }
  });

  CommandBus.subscribe('resume-mic', (_p, meta) => {
     if (meta?.instanceId !== instanceId) return;
     const rec = recorderRegistry.get(instanceId);
     if(rec?.mediaRecorder?.state === 'paused') {
         rec.mediaRecorder.resume();
         store.dispatch({ type: 'starmus/mic-resume' });
     }
  });
}