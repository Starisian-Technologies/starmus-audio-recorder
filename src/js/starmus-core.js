/**
 * @file starmus-core.js
 * @version 4.5.1
 * @description Handles submission logic. Fixed completion state flow.
 */

'use strict';

import { CommandBus, debugLog } from './starmus-hooks.js';
import { uploadWithTus, uploadDirect, isTusAvailable } from './starmus-tus.js';
import { queueSubmission } from './starmus-offline.js';

export function initCore(store, instanceId, env) {
  async function handleSubmit(formFields) {
    const state = store.getState();
    const { source, calibration, env: stateEnv } = state;

    if (state.status === 'submitting') {
      return;
    }

    if (!source || (!source.blob && !source.file)) {
      store.dispatch({
        type: 'starmus/error',
        payload: { message: 'Please record or attach audio before submitting.' },
      });
      return;
    }

    const audioBlob = source.blob || source.file;
    const fileName = source.fileName || source.file?.name || 'recording.webm';

    // Prepare Metadata
    const metadata = Object.freeze({
      transcript: source.transcript?.trim() || null,
      calibration: calibration?.complete ? { ...calibration } : null,
      env: stateEnv || env || {},
    });

    store.dispatch({ type: 'starmus/submit-start' });

    const onProgress = (bytesUploaded, bytesTotal) => {
      store.dispatch({
        type: 'starmus/submit-progress',
        progress: bytesUploaded / bytesTotal,
      });
    };

    try {
      // Offline check
      if (!navigator.onLine) {
        throw new Error('OFFLINE_FAST_PATH');
      }

      const useTus = isTusAvailable() && audioBlob.size > 1024 * 1024;

      if (useTus) {
        debugLog('[Upload] Using TUS...');
        const uploadUrl = await uploadWithTus(
          audioBlob,
          fileName,
          formFields,
          metadata,
          instanceId,
          onProgress
        );
        store.dispatch({ type: 'starmus/submit-complete', payload: { uploadUrl } });
      } else {
        debugLog('[Upload] Using Direct...');
        const response = await uploadDirect(
          audioBlob,
          fileName,
          formFields,
          metadata,
          instanceId,
          onProgress
        );
        store.dispatch({ type: 'starmus/submit-complete', payload: { response } });
      }
    } catch (error) {
      const isConfigError = error.message.includes('not configured');

      if (error.message === 'OFFLINE_FAST_PATH' || !isConfigError) {
        try {
          debugLog('[Upload] Attempting offline queue...');
          const submissionId = await queueSubmission(
            instanceId,
            audioBlob,
            fileName,
            formFields,
            metadata
          );
          store.dispatch({ type: 'starmus/submit-queued', submissionId });
        } catch (queueError) {
          console.error('[Upload] Offline save failed:', queueError);
          store.dispatch({
            type: 'starmus/error',
            payload: { message: 'Upload failed and could not be saved offline.' },
          });
        }
      } else {
        store.dispatch({
          type: 'starmus/error',
          payload: { message: error.message || 'Upload failed.' },
        });
      }
    }
  }

  CommandBus.subscribe('submit', (payload, meta) => {
    if (meta.instanceId === instanceId) {
      handleSubmit(payload.formFields || {});
    }
  });

  CommandBus.subscribe('reset', (_payload, meta) => {
    if (meta.instanceId === instanceId) {
      store.dispatch({ type: 'starmus/reset' });
    }
  });

  return { handleSubmit };
}
