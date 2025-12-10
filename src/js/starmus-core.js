/**
 * @file starmus-core.js
 * @version 6.1.0-DEBUG-UPLOAD
 * @description Logic Core. improved error logging for upload failures.
 */

'use strict';

import './starmus-hooks.js';
import { uploadWithPriority, estimateUploadTime, formatUploadEstimate } from './starmus-tus.js';
import { queueSubmission, getPendingCount } from './starmus-offline.js';

var Hooks = window.StarmusHooks || {};
var subscribe = Hooks.subscribe || function(){};
var debugLog = Hooks.debugLog    || function(){};

function detectTier() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return 'C';
    if (typeof MediaRecorder === 'undefined') return 'C';
    const AudioContext = window.AudioContext || window.webkitAudioContext;
    if (!AudioContext) return 'B';
    return 'A';
}

export function initCore(store, instanceId, env) {
  debugLog('[StarmusCore] initCore', instanceId);

  const tier = detectTier();
  console.log('[StarmusCore] 📱 Device Tier Detected:', tier);
  store.dispatch({ type: 'starmus/tier-ready', payload: { tier: tier } });
  window.dispatchEvent(new CustomEvent('starmus-ready', { detail: { instanceId, tier } }));

  async function handleSubmit(formFields) {
    const state = store.getState();
    const source = state.source || {};
    const calibration = state.calibration || {};
    const stateEnv = { ...state.env, ...env };

    const audioBlob = source.blob || source.file;
    const fileName  = source.fileName || (source.file ? source.file.name : `rec-${Date.now()}.webm`);

    if (!audioBlob) {
      alert('No audio recording found.');
      return;
    }

    const metadata = {
      transcript: source.transcript?.trim() || null,
      calibration: calibration.complete ? {
        gain: calibration.gain,
        speechLevel: calibration.speechLevel
      } : null,
      env: stateEnv,
      tier: tier
    };

    store.dispatch({ type: 'starmus/submit-start' });

    const onProgress = (uploaded, total) => {
      store.dispatch({ type: 'starmus/submit-progress', progress: uploaded/total });
    };

    try {
      if (!navigator.onLine) throw new Error('OFFLINE_FAST_PATH');

      console.log('[StarmusCore] 🚀 Attempting Upload via Priority Pipeline...');
      
      const result = await uploadWithPriority({
        blob: audioBlob,
        fileName,
        formFields,
        metadata,
        instanceId,
        onProgress
      });

      console.log('[StarmusCore] ✅ Upload Success:', result);
      store.dispatch({ type: 'starmus/submit-complete', payload: result });
      
      // Dispatch legacy event for page redirects
      if(result.redirect_url) {
          window.location.href = result.redirect_url;
      }

    } catch (error) {
      // --- CRITICAL DEBUG LOGGING ---
      console.error('[StarmusCore] ❌ Upload Failed:', error.message);
      if (error.cause) console.error('   ↳ Cause:', error.cause);
      // -----------------------------

      console.log('[StarmusCore] 💾 Queuing for Offline sync...');

      try {
          const submissionId = await queueSubmission(instanceId, audioBlob, fileName, formFields, metadata);
          store.dispatch({ type: 'starmus/submit-queued', submissionId });
          
          const pending = await getPendingCount();
          if (window.CommandBus) window.CommandBus.dispatch('starmus/offline/queue_updated', { count: pending });
          
      } catch (qe) {
          console.error('[StarmusCore] ☠️ Offline Queue Failed:', qe);
          alert('Upload failed and could not be saved. Please try again.');
          store.dispatch({ type: 'starmus/error', error: { message: 'Upload failed.' } });
      }
    }
  }

  subscribe('submit', (payload, meta) => {
    if (meta && meta.instanceId === instanceId) {
      handleSubmit(payload.formFields || {});
    }
  }, instanceId);

  subscribe('reset', (_p, meta) => {
    if (meta && meta.instanceId === instanceId) {
      store.dispatch({ type: 'starmus/reset' });
    }
  }, instanceId);

  subscribe('continue', (_p, meta) => {
    if (meta && meta.instanceId === instanceId) {
      store.dispatch({ type: 'starmus/ui/step-continue' });
    }
  }, instanceId);

  return { handleSubmit };
}

if (typeof window !== 'undefined') window.initCore = initCore;