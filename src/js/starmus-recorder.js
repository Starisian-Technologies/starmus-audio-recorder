/**
 * @file starmus-recorder.js
 * @version 6.4.0-BUILD-FIX
 * @description Recorder logic with Explicit Exports at the bottom.
 */

'use strict';

import { CommandBus } from './starmus-hooks.js';

const recorderRegistry = new Map();
let sharedAudioContext = null;
const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

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
    if (ctx.state === 'suspended') await ctx.resume();
    return ctx;
}

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

function initRecorder(store, instanceId) {
  console.log('[Recorder] 🎧 Listening for commands for ID:', instanceId);

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

      const analyser = ctx.createAnalyser();
      source.connect(analyser);
      const buf = new Uint8Array(analyser.frequencyBinCount);
      const startTs = Date.now();
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
  
  CommandBus.subscribe('pause-mic', (_p, meta) => {
     if (meta?.instanceId !== instanceId) return;
     const rec = recorderRegistry.get(instanceId);
     if(rec?.mediaRecorder?.state === 'recording') {
         rec.mediaRecorder.pause();
         if(rec.recognition) rec.recognition.stop();
         store.dispatch({ type: 'starmus/mic-pause' });
     }
  });

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

// EXPLICIT EXPORT FOR ROLLUP
export { initRecorder };