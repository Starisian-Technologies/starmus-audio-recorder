/**
 * @file starmus-recorder.js
 * @version 5.9.0-TRANSCRIPTION
 * @description Recorder with 3-Phase Calibration + Live Speech Recognition.
 */

'use strict';

import { CommandBus } from './starmus-hooks.js';

const recorderRegistry = new Map();
let sharedAudioContext = null;

// Speech API Support
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
            
            // Chromebook/Mobile Sensitivity Boost (*10)
            const volume = Math.min(100, avg * 10); 
            if (volume > maxVolume) maxVolume = volume;

            const elapsed = Date.now() - startTime;
            let message = '';

            if (elapsed < 5000) {
                const countdown = Math.ceil((5000 - elapsed) / 1000);
                message = `Silence check... ${countdown}`;
            } else if (elapsed < 10000) {
                const countdown = Math.ceil((10000 - elapsed) / 1000);
                message = `Speak normally... ${countdown}`;
            } else if (elapsed < 15000) {
                message = 'Finalizing...';
            } else {
                source.disconnect();
                analyser.disconnect();
                if (onUpdate) onUpdate('Calibration Complete', 0, true);
                resolve({ complete: true, gain: 1.0, speechLevel: maxVolume });
                return;
            }

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
         if(!done) store.dispatch({ type: 'starmus/calibration-update', message: msg, volumePercent: vol });
      });
      stream.getTracks().forEach(t => t.stop()); 
      store.dispatch({ type: 'starmus/calibration-complete', payload: { calibration } });
    } catch (e) {
      console.error(e);
      store.dispatch({ type: 'starmus/error', payload: { message: 'Mic access failed.' } });
    } 
  });

  // 2. START RECORDING (With Transcription)
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
      
      // --- TRANSCRIPTION SETUP ---
      let recognition = null;
      let finalTranscript = '';
      
      if (SpeechRecognition) {
          recognition = new SpeechRecognition();
          recognition.continuous = true;
          recognition.interimResults = true;
          recognition.lang = 'en-US'; // Default to English, or grab from store if needed
          
          recognition.onresult = (event) => {
              let interim = '';
              for (let i = event.resultIndex; i < event.results.length; ++i) {
                  if (event.results[i].isFinal) {
                      finalTranscript += event.results[i][0].transcript + ' ';
                      store.dispatch({ type: 'starmus/transcript-update', transcript: finalTranscript });
                  } else {
                      interim += event.results[i][0].transcript;
                  }
              }
              store.dispatch({ type: 'starmus/transcript-interim', interim: interim });
          };
          
          recognition.onerror = (e) => console.warn('[Recorder] Speech API Error:', e.error);
          // Auto-restart logic if it cuts out while recording
          recognition.onend = () => {
              const recState = recorderRegistry.get(instanceId);
              if (recState && mediaRecorder.state === 'recording') {
                  try { recognition.start(); } catch(e){}
              }
          };
          
          try { recognition.start(); } catch(e) { console.warn('Speech start failed', e); }
      }
      // ---------------------------

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
         const amp = Math.min(100, (sum/buf.length) * 10); 
         store.dispatch({ type: 'starmus/recorder-tick', duration: (Date.now()-startTs)/1000, amplitude: amp });
         rec.rafId = requestAnimationFrame(visLoop);
      }
      visLoop();
    } catch (e) {
      store.dispatch({ type: 'starmus/error', payload: { message: 'Recording failed.' } });
    }
  });

  // 3. STOP & PAUSE
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
         if(rec.recognition) rec.recognition.stop(); // API doesn't really pause, so we stop
         store.dispatch({ type: 'starmus/mic-pause' });
     }
  });

  CommandBus.subscribe('resume-mic', (_p, meta) => {
     if (meta?.instanceId !== instanceId) return;
     const rec = recorderRegistry.get(instanceId);
     if(rec?.mediaRecorder?.state === 'paused') {
         rec.mediaRecorder.resume();
         // Attempt to restart speech
         if(rec.recognition) {
             try { rec.recognition.start(); } catch(e){}
         }
         store.dispatch({ type: 'starmus/mic-resume' });
     }
  });
}