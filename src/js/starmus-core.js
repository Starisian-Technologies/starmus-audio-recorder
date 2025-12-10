/**
 * @file starmus-core.js
 * @version 6.0.0-TIER-LOGIC
 * @description Logic Core: Uploads, Tier Detection, Offline Queue.
 */

'use strict';

import './starmus-hooks.js';
import { uploadWithPriority, estimateUploadTime, formatUploadEstimate } from './starmus-tus.js';
import { queueSubmission, getPendingCount } from './starmus-offline.js';

var Hooks = window.StarmusHooks || {};
var subscribe = Hooks.subscribe || function(){};
var debugLog = Hooks.debugLog    || function(){};

/**
 * Determine Capability Tier
 * Tier A: MediaRecorder + AudioContext (Full Viz + Rec)
 * Tier B: MediaRecorder Only (Rec, No Viz)
 * Tier C: File Input Only (Old Android/iOS)
 */
function detectTier() {
    // 1. Check for basic API
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        return 'C';
    }
    // 2. Check for MediaRecorder
    if (typeof MediaRecorder === 'undefined') {
        return 'C';
    }
    // 3. Check for AudioContext (Required for Visualizer/Calibration)
    const AudioContext = window.AudioContext || window.webkitAudioContext;
    if (!AudioContext) {
        return 'B'; // Can record, but no meter/viz
    }
    
    return 'A'; // Full capability
}

export function initCore(store, instanceId, env) {
  debugLog('[StarmusCore] initCore', instanceId);

  // 1. RUN TIER DETECTION
  const tier = detectTier();
  console.log('[StarmusCore] 📱 Device Tier Detected:', tier);
  
  // Dispatch to store so UI updates immediately
  store.dispatch({ type: 'starmus/tier-ready', payload: { tier: tier } });

  // 2. HANDSHAKE WITH UEC
  // We fire an event to tell SparxstarUEC we are ready to receive data
  window.dispatchEvent(new CustomEvent('starmus-ready', { detail: { instanceId, tier } }));

  async function handleSubmit(formFields) {
    const state = store.getState();
    const source = state.source || {};
    const calibration = state.calibration || {};
    // Merge global env (from UEC) with local state env
    const stateEnv = { ...state.env, ...env };

    const audioBlob = source.blob || source.file;
    // Fallback filename logic
    const fileName  = source.fileName || (source.file ? source.file.name : `rec-${Date.now()}.webm`);

    if (!audioBlob) {
      store.dispatch({
        type: 'starmus/error',
        error: { message: 'Please record or attach audio before submitting.', retryable: false },
        status: state.status,
      });
      return;
    }

    // Build Metadata Object
    const metadata = {
      transcript: source.transcript?.trim() || null,
      calibration: calibration.complete ? {
        gain: calibration.gain,
        speechLevel: calibration.speechLevel
      } : null,
      env: stateEnv, // This contains the UEC data
      tier: tier // Send the detected tier to server
    };

    const estimated = estimateUploadTime(audioBlob.size, stateEnv.network);
    store.dispatch({ type: 'starmus/submit-start' });

    const onProgress = (uploaded, total) => {
      store.dispatch({ type: 'starmus/submit-progress', progress: uploaded/total });
    };

    try {
      if (!navigator.onLine) throw new Error('OFFLINE_FAST_PATH');

      const result = await uploadWithPriority({
        blob: audioBlob,
        fileName,
        formFields,
        metadata,
        instanceId,
        onProgress
      });

      store.dispatch({ type: 'starmus/submit-complete', payload: result });

    } catch (error) {
      debugLog('[StarmusCore] Upload error', error);
      
      // AUTO-QUEUE for Offline
      debugLog('[StarmusCore] Saving to offline queue');
      try {
          const submissionId = await queueSubmission(instanceId, audioBlob, fileName, formFields, metadata);
          store.dispatch({ type: 'starmus/submit-queued', submissionId });
          
          // Force update the UI listener
          const pending = await getPendingCount();
          window.CommandBus.dispatch('starmus/offline/queue_updated', { count: pending });
          
      } catch (qe) {
          store.dispatch({
            type: 'starmus/error',
            error: { message: 'Upload failed and storage full.', retryable: false },
            status: 'ready_to_submit'
          });
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