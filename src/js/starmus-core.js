/**
 * @file starmus-core.js
 * @version 5.1.0
 * @description Unified upload logic. Preserves full original submission pipeline with retry + offline queue.
 */

'use strict';

import './starmus-hooks.js'; 
import { uploadWithPriority, estimateUploadTime, formatUploadEstimate } from './starmus-tus.js'; 
import { queueSubmission, getPendingCount } from './starmus-offline.js';

// Defensive fallback for global integration
var Hooks = window.StarmusHooks || {};
var subscribe = Hooks.subscribe || function(){};
var debugLog  = Hooks.debugLog  || function(){};

export function initCore(store, instanceId, env) {

  function handleSubmit(formFields) {
    const state = store.getState();
    const source = state.source || {};
    const calibration = state.calibration || {};
    const stateEnv = state.env || env || {};

    if (state.status === 'submitting') return;

    const audioBlob = source.blob || source.file;
    const fileName = source.fileName || (source.file && source.file.name) || 'recording.webm';

    if (!audioBlob) {
      store.dispatch({
        type: 'starmus/error',
        payload: { message: 'Please record or attach audio before submitting.' },
      });
      return;
    }

    const metadata = {
      transcript: (source.transcript || '').trim() || null,
      calibration: calibration.complete ? {
        gain: calibration.gain,
        snr: calibration.snr,
        noiseFloor: calibration.noiseFloor,
        speechLevel: calibration.speechLevel,
        timestamp: calibration.timestamp || new Date().toISOString()
      } : null,
      env: stateEnv
    };

    // Upload estimation for user context
    const estimatedSeconds = estimateUploadTime(audioBlob.size, stateEnv.network);
    const estimateText = formatUploadEstimate(estimatedSeconds);
    debugLog(`[Upload] Estimated duration: ${estimateText}, size: ${(audioBlob.size / 1024 / 1024).toFixed(2)}MB`);

    store.dispatch({ type: 'starmus/submit-start' });

    function onProgress(bytesUploaded, bytesTotal) {
      store.dispatch({
        type: 'starmus/submit-progress',
        progress: bytesUploaded / bytesTotal
      });
    }

    Promise.resolve().then(() => {
      if (!navigator.onLine) throw new Error('OFFLINE_FAST_PATH');
      debugLog('[Upload] Starting unified upload pipeline...');

      return uploadWithPriority({
        blob: audioBlob,
        fileName,
        formFields,
        metadata,
        instanceId,
        onProgress
      });

    }).then(uploadResult => {
      debugLog('[Upload] Success:', uploadResult.method);
      store.dispatch({
        type: 'starmus/submit-complete',
        payload: uploadResult
      });

    }).catch(error => {
      const errMsg = error?.message || String(error);
      const isConfigError = /not configured/i.test(errMsg);
      const isRetryable = !isConfigError && errMsg !== 'TUS_ENDPOINT_NOT_CONFIGURED';

      if (errMsg === 'OFFLINE_FAST_PATH' || isRetryable) {
        debugLog('[Upload] Failed or offline. Attempting offline queue...');
        queueSubmission(instanceId, audioBlob, fileName, formFields, metadata)
          .then(submissionId => {
            debugLog('[Upload] Queued offline:', submissionId);
            store.dispatch({ type: 'starmus/submit-queued', submissionId });
            return getPendingCount();
          })
          .then(pendingCount => {
            debugLog(`[Upload] Offline queue size: ${pendingCount}`);
          })
          .catch(queueError => {
            console.error('[Upload] Queue save failed:', queueError);
            store.dispatch({
              type: 'starmus/error',
              payload: {
                message: 'Upload failed and could not be saved offline. ' +
                         (queueError.message === 'STORAGE_QUOTA_EXCEEDED'
                          ? 'Storage quota exceeded.'
                          : 'Please try again.')
              }
            });
          });

      } else {
        store.dispatch({
          type: 'starmus/error',
          payload: { message: errMsg || 'Upload failed.' }
        });
      }
    });
  }

  // Command Bus Subscriptions
  subscribe('submit', (payload, meta) => {
    if (meta?.instanceId === instanceId) {
      handleSubmit(payload.formFields || {});
    }
  });

  subscribe('continue', (payload, meta) => {
    if (meta?.instanceId === instanceId) {
      debugLog('[StarmusCore] Continue command');
      store.dispatch({ type: 'starmus/ui/step-continue', payload: {} });
    }
  });

  subscribe('reset', (_payload, meta) => {
    if (meta?.instanceId === instanceId) {
      store.dispatch({ type: 'starmus/reset' });
    }
  });

  return { handleSubmit };
}

// Browser global export
if (typeof window !== 'undefined') {
  window.initCore = initCore;
}

// CommonJS/Node export
if (typeof module !== 'undefined' && module.exports) {
  module.exports = {
    initCore
  };
}
