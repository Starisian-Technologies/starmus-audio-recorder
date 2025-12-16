/**
 * @file starmus-core.js
 * @version 6.3.0-FINAL-REDIRECT
 */

'use strict';

import './starmus-hooks.js';
import { uploadWithPriority, estimateUploadTime, formatUploadEstimate } from './starmus-tus.js';
import { queueSubmission, getPendingCount } from './starmus-offline.js';

var subscribe = window.StarmusHooks?.subscribe || function(){};

function detectTier() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return 'C';
    if (typeof MediaRecorder === 'undefined') return 'C';
    if (!window.AudioContext && !window.webkitAudioContext) return 'B';
    return 'A';
}

export function initCore(store, instanceId, env) {
  const tier = detectTier();
  store.dispatch({ type: 'starmus/tier-ready', payload: { tier: tier } });
  window.dispatchEvent(new CustomEvent('starmus-ready', { detail: { instanceId, tier } }));

 async function handleSubmit(formFields) {
    const state = store.getState();
    const source = state.source || {};
    const calibration = state.calibration || {};
    // Merge global UEC env with state env
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

    try {
      if (!navigator.onLine) throw new Error('OFFLINE_FAST_PATH');

      console.log('[StarmusCore] 🚀 Uploading...');
      
      const result = await uploadWithPriority({
        blob: audioBlob,
        fileName,
        formFields,
        metadata,
        instanceId,
        onProgress: (u, t) => store.dispatch({ type: 'starmus/submit-progress', progress: u/t })
      });

      console.log('[StarmusCore] ✅ Success:', result);
      
      // --- START: MODIFIED CODE ---
      
      let hookFired = false; // This will track if we are in a modal context.

      // This is the perfect place for our client-side hook.
      if (result.success) {
          const newAudioPostId = result.data?.post_id || result.post_id;

          if (newAudioPostId) {
              console.log('[StarmusCore] Firing starmusRecordingComplete event with Post ID:', newAudioPostId);
              if (window.parent && window.parent.jQuery) {
                  parent.jQuery(parent.document).trigger('starmusRecordingComplete', [{
                      audioPostId: newAudioPostId
                  }]);
                  hookFired = true; // We successfully notified a parent, so we are in a modal.
              }
          }
      }
      
      store.dispatch({ type: 'starmus/submit-complete', payload: result });
      
      // --- MODIFIED REDIRECT LOGIC ---
      if (result.success) {
          const redirect = result.data?.redirect_url || result.redirect_url;
          if (redirect) {
              console.log('[StarmusCore] Redirecting to:', redirect);
              setTimeout(() => window.location.href = redirect, 1500);
          } else if (!hookFired) {
              // ONLY run this fallback if we are NOT in a modal context.
              alert('Submission successful!');
              window.location.reload(); 
          }
      }
      // --- END: MODIFIED CODE ---

    } catch (error) {
      console.error('[StarmusCore] ❌ Upload Failed:', error.message);
      
      // Offline Fallback
      try {
          const submissionId = await queueSubmission(instanceId, audioBlob, fileName, formFields, metadata);
          store.dispatch({ type: 'starmus/submit-queued', submissionId });
          const pending = await getPendingCount();
          if (window.CommandBus) window.CommandBus.dispatch('starmus/offline/queue_updated', { count: pending });
      } catch (qe) {
          console.error('Offline Queue Failed:', qe);
          store.dispatch({ type: 'starmus/error', error: { message: 'Upload failed completely.' } });
      }
    }
  }

  subscribe('submit', (payload, meta) => {
    if (meta && meta.instanceId === instanceId) handleSubmit(payload.formFields || {});
  }, instanceId);

  subscribe('reset', (_p, meta) => {
    if (meta && meta.instanceId === instanceId) store.dispatch({ type: 'starmus/reset' });
  }, instanceId);

  subscribe('continue', (_p, meta) => {
    if (meta && meta.instanceId === instanceId) store.dispatch({ type: 'starmus/ui/step-continue' });
  }, instanceId);

  return { handleSubmit };
}

if (typeof window !== 'undefined') window.initCore = initCore;
