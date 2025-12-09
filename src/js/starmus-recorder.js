/**
 * @file starmus-recorder-merged.js
 * @version merged‑5.0.0
 * @description Merged, unified recorder module for Starmus.
 *   Combines calibration, audio graph setup (with fallback), visualizer/meter,
 *   MediaRecorder, and safe teardown. Exposes single initRecorder API.
 */

'use strict';

import { CommandBus, debugLog } from './starmus-hooks.js';

const recorderRegistry = new Map();
let sharedAudioContext = null;

/**
 * Emit telemetry events via StarmusHooks.
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
 * Wait for MediaStream track to be ready (for browsers/devices where track may not be instantly live).
 */
async function starmusWaitForTrack(stream) {
  const tracks = (stream.getAudioTracks && stream.getAudioTracks()) || [];
  if (!tracks.length) {
    return;
  }
  const t = tracks[0];
  if (t.readyState === 'live') {
    return;
  }
  return new Promise((resolve) => {
    let checks = 0;
    const iv = setInterval(() => {
      if (t.readyState === 'live' || checks > 100) {
        clearInterval(iv);
        resolve();
      }
      checks += 1;
    }, 50);
    setTimeout(() => {
      clearInterval(iv);
      resolve();
    }, 5000);
  });
}

function getSharedContext() {
  const Ctx = window.AudioContext || window.webkitAudioContext;
  if (!Ctx) {
    throw new Error('AudioContext not supported in this browser');
  }
  if (!sharedAudioContext || sharedAudioContext.state === 'closed') {
    sharedAudioContext = new Ctx({ latencyHint: 'interactive' });
    debugLog('[Recorder] Created AudioContext, state:', sharedAudioContext.state);
    if (typeof sharedAudioContext.createMediaStreamSource !== 'function') {
      throw new Error('AudioContext lacks createMediaStreamSource');
    }
  }
  return sharedAudioContext;
}

function createDestinationSafe(audioContext) {
  const fn =
    audioContext.createMediaStreamDestination ||
    audioContext.createMediaStreamAudioDestination;
  if (typeof fn !== 'function') {
    throw new Error('MediaStreamDestination not supported');
  }
  const dest = fn.call(audioContext);
  if (!dest || !dest.stream) {
    throw new Error('Destination stream invalid');
  }
  return dest;
}

function setupAudioGraph(rawStream) {
  const audioContext = getSharedContext();
  const source = audioContext.createMediaStreamSource(rawStream);

  try {
    const destNode = createDestinationSafe(audioContext);

    const highPass = audioContext.createBiquadFilter();
    highPass.type = 'highpass';
    highPass.frequency.value = 85;

    const compressor = audioContext.createDynamicsCompressor();
    compressor.threshold.value = -20;
    compressor.knee.value = 40;
    compressor.ratio.value = 12;
    compressor.attack.value = 0;
    compressor.release.value = 0.25;

    const analyser = audioContext.createAnalyser();
    analyser.fftSize = 2048;

    source.connect(highPass);
    highPass.connect(compressor);
    compressor.connect(analyser);
    analyser.connect(destNode);

    return {
      audioContext,
      destinationStream: destNode.stream,
      analyser,
      nodes: [source, highPass, compressor, analyser, destNode],
      fallbackActive: false
    };
  } catch (e) {
    debugLog('[Recorder] Audio graph failed — fallback to raw stream:', e.message);
    const analyser2 = audioContext.createAnalyser();
    analyser2.fftSize = 2048;
    source.connect(analyser2);
    return {
      audioContext,
      destinationStream: rawStream,
      analyser: analyser2,
      nodes: [source, analyser2],
      fallbackActive: true
    };
  }
}

async function calibrateAudioLevels(stream, onUpdate) {
  const audioContext = getSharedContext();
  const analyser = audioContext.createAnalyser();
  analyser.fftSize = 2048;
  const mic = audioContext.createMediaStreamSource(stream);
  mic.connect(analyser);

  const buffer = new Float32Array(analyser.fftSize);
  const samples = [];
  const DURATION = 15000;
  const VOLUME_SCALE_FACTOR = 2000;
  const startTime = performance.now();

  return new Promise((resolve) => {
    function tick() {
      if (audioContext.state !== 'running') {
        requestAnimationFrame(tick);
        return;
      }
      analyser.getFloatTimeDomainData(buffer);
      let sum = 0;
      for (let i = 0; i < buffer.length; i++) sum += buffer[i] * buffer[i];
      const rms = Math.sqrt(sum / buffer.length);
      if (!isFinite(rms)) {
        requestAnimationFrame(tick);
        return;
      }
      samples.push(rms);
      const volumePercent = Math.min(100, rms * VOLUME_SCALE_FACTOR);
      if (onUpdate) onUpdate('', volumePercent, false);

      if (performance.now() - startTime < DURATION) {
        requestAnimationFrame(tick);
      } else {
        done();
      }
    }

    function done() {
      const third = Math.max(1, Math.floor(samples.length / 3));
      const quiet = samples.slice(0, third);
      const speech = samples.slice(third, third * 2);

      const avg = (arr) => (arr.length ? arr.reduce((a, b) => a + b, 0) / arr.length : 0);

      const noiseFloor = avg(quiet);
      const speechLevel = avg(speech);
      const snr = speechLevel / Math.max(noiseFloor, 1e-6);
      const gain = snr < 3 ? 6.0 : Math.max(1.0, Math.min(4.0, 0.1 / Math.max(speechLevel, 1e-6)));

      const calibration = {
        gain: parseFloat(gain.toFixed(3)),
        snr: parseFloat(snr.toFixed(3)),
        noiseFloor: parseFloat(noiseFloor.toFixed(6)),
        speechLevel: parseFloat(speechLevel.toFixed(6)),
        timestamp: new Date().toISOString()
      };

      try { mic.disconnect(); } catch (_) {}
      try { analyser.disconnect(); } catch (_) {}

      if (onUpdate) onUpdate('', null, true);
      resolve(calibration);
    }

    tick();
  });
}

export function initRecorder(store, instanceId) {
  // (Optional) Unsubscribe previous subscriptions if your CommandBus supports it
  CommandBus.unsubscribeInstance && CommandBus.unsubscribeInstance(instanceId);

  CommandBus.subscribe('setup-mic', async (_payload, meta) => {
    if (meta.instanceId !== instanceId) return;

    if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
      const msg = 'Microphone not supported.';
      emitStarmusEvent(instanceId, 'E_RECORDER_UNSUPPORTED', { severity: 'error', message: msg });
      store.dispatch({ type: 'starmus/error', payload: { message: msg, retryable: false } });
      return;
    }

    try {
      const rawStream = await navigator.mediaDevices.getUserMedia({ audio: true });
      emitStarmusEvent(instanceId, 'E_MIC_ACCESS', { severity: 'info', message: 'Mic access granted — calibrating' });
      store.dispatch({ type: 'starmus/calibration-start' });

      const calibration = await calibrateAudioLevels(rawStream, (msg, volume, done) => {
        store.dispatch({ type: 'starmus/calibration-update', message: msg, volumePercent: volume });
      });

      rawStream.getTracks().forEach(t => t.stop());
      store.dispatch({ type: 'starmus/calibration-complete', payload: { calibration }, calibration });
    } catch (err) {
      const errorMsg = err.name === 'NotAllowedError' ? 'Microphone permission denied.' : 'Failed to access microphone.';
      emitStarmusEvent(instanceId, 'E_MIC_ACCESS', { severity: 'error', message: errorMsg, data: { error: err.message } });
      store.dispatch({ type: 'starmus/error', payload: { message: errorMsg, retryable: true } });
    }
  });

  CommandBus.subscribe('start-recording', async (_payload, meta) => {
    if (meta.instanceId !== instanceId) return;
    const state = store.getState();

    if (state.status === 'calibrating') {
      store.dispatch({ type: 'starmus/error', payload: { message: 'Please wait for calibration to complete.', retryable: true } });
      return;
    }
    if (!state.calibration || !state.calibration.complete) {
      store.dispatch({ type: 'starmus/error', payload: { message: 'Please setup your microphone first.', retryable: true } });
      return;
    }

    try {
      const rawStream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const graph = setupAudioGraph(rawStream);

      if (graph.audioContext.state === 'suspended') {
        await graph.audioContext.resume();
      }

      await starmusWaitForTrack(graph.destinationStream);

      const dest = graph.destinationStream;
      const tracks = dest.getAudioTracks();
      if (!tracks.length) throw new Error('No audio tracks available');

      const mediaRecorder = new MediaRecorder(dest);
      const chunks = [];

      mediaRecorder.ondataavailable = (e) => {
        if (e.data && e.data.size > 0) chunks.push(e.data);
      };

      mediaRecorder.onstop = () => {
        const rec = recorderRegistry.get(instanceId);
        if (!rec) return;

        if (rec.rafId) cancelAnimationFrame(rec.rafId);
        const blob = new Blob(chunks, { type: mediaRecorder.mimeType || 'audio/webm' });
        const fileName = `starmus-recording-${Date.now()}.webm`;

        emitStarmusEvent(instanceId, 'REC_COMPLETE', {
          severity: 'info',
          message: 'Recording stopped and blob created',
          data: { mimeType: mediaRecorder.mimeType || 'audio/webm', chunkCount: chunks.length }
        });

        store.dispatch({ type: 'starmus/recording-available', payload: { blob, fileName } });

        rawStream.getTracks().forEach(t => t.stop());
        dest.getTracks().forEach(t => t.stop());
        graph.nodes.forEach(n => {
          try { n.disconnect(); } catch (_) {}
        });
        recorderRegistry.delete(instanceId);
      };

      recorderRegistry.set(instanceId, { mediaRecorder, rawStream, graph, rafId: null });

      mediaRecorder.start(3000);
      store.dispatch({ type: 'starmus/mic-start' });

      const analyser = graph.analyser;
      const meterBuf = new Float32Array(analyser.fftSize);
      const startTs = performance.now();

      function meterLoop() {
        const rec = recorderRegistry.get(instanceId);
        if (!rec || rec.mediaRecorder.state !== 'recording') return;

        try {
          analyser.getFloatTimeDomainData(meterBuf);
          let sum = 0;
          for (let i = 0; i < meterBuf.length; i++) sum += meterBuf[i] * meterBuf[i];
          const rms = Math.sqrt(sum / meterBuf.length);
          const amplitude = Math.min(100, rms * 4000);
          const elapsed = (performance.now() - startTs) / 1000;
          store.dispatch({ type: 'starmus/recorder-tick', duration: elapsed, amplitude });
        } catch (_) {
          // ignore analyser errors
        }
        rec.rafId = requestAnimationFrame(meterLoop);
      }

      requestAnimationFrame(meterLoop);
    } catch (err) {
      console.error('[Recorder] ERROR start-recording:', err);
      emitStarmusEvent(instanceId, 'E_RECORDER_START_FAIL', { severity: 'error', message: err.message });
      store.dispatch({ type: 'starmus/error', payload: { message: 'Recording failed to start.', retryable: true } });
    }
  });

  CommandBus.subscribe('stop-mic', (_payload, meta) => {
    if (meta.instanceId !== instanceId) return;
    const rec = recorderRegistry.get(instanceId);
    if (rec && rec.mediaRecorder && rec.mediaRecorder.state === 'recording') {
      rec.mediaRecorder.stop();
      store.dispatch({ type: 'starmus/mic-stop' });
    }
  });

  CommandBus.subscribe('pause-mic', (_payload, meta) => {
    if (meta.instanceId !== instanceId) return;
    const rec = recorderRegistry.get(instanceId);
    if (rec && rec.mediaRecorder && rec.mediaRecorder.state === 'recording' && typeof rec.mediaRecorder.pause === 'function') {
      store.dispatch({ type: 'starmus/mic-pause' });
      rec.mediaRecorder.pause();
    }
  });

  CommandBus.subscribe('resume-mic', (_payload, meta) => {
    if (meta.instanceId !== instanceId) return;
    const rec = recorderRegistry.get(instanceId);
    if (rec && rec.mediaRecorder && rec.mediaRecorder.state === 'paused' && typeof rec.mediaRecorder.resume === 'function') {
      store.dispatch({ type: 'starmus/mic-resume' });
      rec.mediaRecorder.resume();
    }
  });

  CommandBus.subscribe('reset', (_payload, meta) => {
    if (meta.instanceId !== instanceId) return;
    const rec = recorderRegistry.get(instanceId);
    if (rec) {
      try {
        if (rec.mediaRecorder && rec.mediaRecorder.state !== 'inactive') {
          rec.mediaRecorder.stop();
        }
      } catch (_) {}
      if (rec.rafId) cancelAnimationFrame(rec.rafId);
      if (rec.rawStream) rec.rawStream.getTracks().forEach(t => t.stop());
      if (rec.graph && rec.graph.destinationStream) {
        rec.graph.destinationStream.getTracks().forEach(t => t.stop());
      }
      if (rec.graph && rec.graph.nodes) {
        rec.graph.nodes.forEach(n => {
          try { n.disconnect(); } catch (_) {}
        });
      }
      recorderRegistry.delete(instanceId);
    }
  });
}
