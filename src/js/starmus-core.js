/**
 * @file starmus-core.js
 * @version 5.0.2
 * @description Handles submission logic. Uses the unified priority upload pipeline.
 * Corrected: Uses global access for StarmusHooks to match starmus-hooks.js ES5 structure.
 */

'use strict';

import './starmus-hooks.js'; 
import { uploadWithPriority } from './starmus-tus.js'; 
import { queueSubmission } from './starmus-offline.js';

// ✅ Defensive fallback for WKWebView race conditions
var Hooks = window.StarmusHooks || {};
var subscribe = Hooks.subscribe || function(){};
var debugLog  = Hooks.debugLog  || function(){};

export function initCore(store, instanceId, env) {

  // Function is now synchronous and relies on the global access
  function handleSubmit(formFields) {
    const state = store.getState();
    const source = state.source || {};
    const calibration = state.calibration || {};
    const stateEnv = state.env || env || {};

    if (state.status === 'submitting') return;

    var audioBlob = source.blob || source.file;
    var fileName = source.fileName || (source.file && source.file.name) || 'recording.webm';

    if (!audioBlob) {
      store.dispatch({
        type: 'starmus/error',
        payload: { message: 'Please record or attach audio before submitting.' },
      });
      return;
    }

    var metadata = {
      transcript: (source.transcript || '').trim() || null,
      // Deep copy to ensure original state isn't mutated during async ops
      calibration: calibration.complete ? JSON.parse(JSON.stringify(calibration)) : null, 
      env: stateEnv
    };

    store.dispatch({ type: 'starmus/submit-start' });

    function onProgress(bytesUploaded, bytesTotal) {
      store.dispatch({
        type: 'starmus/submit-progress',
        progress: bytesUploaded / bytesTotal
      });
    }

    // Wrap the entire async flow in a Promise.resolve() chain for ES5 safety
    // and consistent error handling (even on synchronous failures like the OFFLINE_FAST_PATH)
    Promise.resolve().then(function () {
      if (!navigator.onLine) {
        throw new Error('OFFLINE_FAST_PATH');
      }

      debugLog('[Upload] Starting Priority Upload Pipeline...');
      
      // CRITICAL: Call with the single object payload (as determined in the previous step)
      return uploadWithPriority({
        blob: audioBlob,
        fileName: fileName,
        formFields: formFields,
        metadata: metadata,
        instanceId: instanceId,
        onProgress: onProgress
      });

    }).then(function (uploadResult) {
      debugLog('[Upload] Upload Succeeded via', uploadResult.method, uploadResult);
      store.dispatch({
        type: 'starmus/submit-complete',
        payload: uploadResult
      });
      
    }).catch(function (error) {
      var isConfigError = error && error.message && error.message.indexOf('not configured') !== -1;

      if (error.message === 'OFFLINE_FAST_PATH' || !isConfigError) {
        debugLog('[Upload] Upload failed/offline. Attempting offline queue...');
        
        // Queue submission
        queueSubmission(instanceId, audioBlob, fileName, formFields, metadata)
          .then(function (submissionId) {
            store.dispatch({ type: 'starmus/submit-queued', submissionId: submissionId });
          })
          .catch(function (queueError) {
            console.error('[Upload] Offline save failed:', queueError);
            store.dispatch({
              type: 'starmus/error',
              payload: { message: 'Upload failed and could not be saved offline.' }
            });
          });
      } else {
        // Handle fatal configuration error
        store.dispatch({
          type: 'starmus/error',
          payload: { message: error.message || 'Upload failed.' }
        });
      }
    });
  }

  // Command Bus Subscriptions
  subscribe('submit', function (payload, meta) {
    if (meta && meta.instanceId === instanceId) {
      handleSubmit(payload.formFields || {});
    }
  });

  subscribe('reset', function (_payload, meta) {
    if (meta && meta.instanceId === instanceId) {
      store.dispatch({ type: 'starmus/reset' });
    }
  });

  return { handleSubmit: handleSubmit };
}
// at end of the file
if (typeof window !== 'undefined') {
    window.initCore = initCore;
}