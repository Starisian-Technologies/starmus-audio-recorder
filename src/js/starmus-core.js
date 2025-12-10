/**
 * @file starmus-core.js
 * @version 5.1.0‑restored
 * @description Full submission core: TUS/direct upload, offline queue fallback, SparxstarUEC handshake, event dispatch, commandbus integration.
 */

'use strict';

import './starmus-hooks.js';
import { uploadWithPriority, isTusAvailable, estimateUploadTime, formatUploadEstimate } from './starmus-tus.js';
import { queueSubmission, getPendingCount } from './starmus-offline.js';

// Global hooks (legacy‑compatible)
var Hooks = window.StarmusHooks || {};
var subscribe = Hooks.subscribe || function(){};
var debugLog = Hooks.debugLog    || function(){};

export function initCore(store, instanceId, env) {
  debugLog('[StarmusCore] initCore', instanceId, env);

  // 🔔 Restore global readiness + handshake for SparxstarUEC (or any external listener)
  try {
    window.dispatchEvent(new CustomEvent('starmus-ready', { detail: { instanceId } }));
    debugLog('[StarmusCore] dispatched starmus-ready event');
  } catch (e) {
    debugLog('[StarmusCore] failed to dispatch starmus-ready', e);
  }

  async function handleSubmit(formFields) {
    const state = store.getState();
    const source = state.source || {};
    const calibration = state.calibration || {};
    const stateEnv = state.env || env || {};

    const audioBlob = source.blob || source.file;
    const fileName  = source.fileName || (source.file && source.file.name) || 'recording.webm';

    if (!audioBlob) {
      store.dispatch({
        type: 'starmus/error',
        error: { message: 'Please record or attach audio before submitting.', retryable: false },
        status: state.status,
      });
      return;
    }

    const metadata = {
      transcript: source.transcript?.trim() || null,
      calibration: calibration.complete ? {
        gain: calibration.gain,
        snr: calibration.snr,
        noiseFloor: calibration.noiseFloor,
        speechLevel: calibration.speechLevel,
        timestamp: calibration.timestamp || new Date().toISOString()
      } : null,
      env: stateEnv
    };

    const estimated = estimateUploadTime(audioBlob.size, stateEnv.network);
    debugLog(`[StarmusCore] Upload size: ${(audioBlob.size/1024/1024).toFixed(2)} MB, estimated time: ${formatUploadEstimate(estimated)}`);

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

      debugLog('[StarmusCore] Upload succeeded via', result.method, result);

      store.dispatch({ type: 'starmus/submit-complete', payload: result });

      // 🔔 Notify external listeners that upload finished
      try {
        window.dispatchEvent(new CustomEvent('starmus-upload-finished', { detail: { instanceId, method: result.method } }));
      } catch (_) {
        /* swallow */
      }

    } catch (error) {
      debugLog('[StarmusCore] Upload error', error);

      const msg = error?.message || '';
      const isConfigError = msg.includes('not configured');

      if (error.message === 'OFFLINE_FAST_PATH' || !isConfigError) {
        debugLog('[StarmusCore] Saving submission to offline queue for retry');

        try {
          const submissionId = await queueSubmission(instanceId, audioBlob, fileName, formFields, metadata);
          store.dispatch({ type: 'starmus/submit-queued', submissionId });

          const pending = await getPendingCount();
          debugLog(`[StarmusCore] Offline queue count: ${pending}`);

          window.dispatchEvent(new CustomEvent('starmus-offline-queued', { detail: { instanceId, pending } }));

        } catch (qe) {
          console.error('[StarmusCore] Failed to save offline submission', qe);
          store.dispatch({
            type: 'starmus/error',
            error: { message: 'Upload failed and could not be saved offline.', retryable: false },
            status: 'ready_to_submit'
          });
        }
      } else {
        store.dispatch({
          type: 'starmus/error',
          error: { message: error.message || 'Upload failed.', retryable: false },
          status: 'ready_to_submit'
        });
      }
    }
  }

  // Subscribe command bus events (restore full original handlers)
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

// Legacy global export (for non‑module usage)
if (typeof window !== 'undefined') {
  window.initCore = initCore;
}
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { initCore };
}
