/**
 * @file starmus-core.js
 * @version 4.2.0
 * @description Handles submission (upload) and reset business logic with TUS support and offline queue.
 */

'use strict';

import { CommandBus, debugLog } from './starmus-hooks.js';
import { uploadWithTus, uploadDirect, isTusAvailable, estimateUploadTime, formatUploadEstimate } from './starmus-tus.js';
import { queueSubmission, getPendingCount } from './starmus-offline.js';

/**
 * Wire submission logic for a specific instance.
 *
 * @param {object} store
 * @param {string} instanceId
 * @param {object} env - Environment payload from sparxstar-user-environment-check.
 */
export function initCore(store, instanceId, env) {
    async function handleSubmit(formFields) {
        const state = store.getState();
        const { source, calibration, env } = state;

        if (!source || (!source.blob && !source.file)) {
            store.dispatch({
                type: 'starmus/error',
                error: { message: 'Please record or attach audio before submitting.', retryable: false },
                status: state.status,
            });
            return;
        }

        // Prepare audio blob and filename
        const audioBlob = source.blob || source.file;
        const fileName = source.fileName || source.file?.name || 'recording.webm';

        // Build metadata payload
        const metadata = {
            transcript: source.transcript?.trim() || null,
            calibration: calibration.complete ? {
                gain: calibration.gain,
                snr: calibration.snr,
                noiseFloor: calibration.noiseFloor,
                speechLevel: calibration.speechLevel,
                timestamp: calibration.timestamp || new Date().toISOString()
            } : null,
            env: env || {},
        };

        // Estimate upload time for user feedback
        const estimatedSeconds = estimateUploadTime(audioBlob.size, env.network);
        const estimateText = formatUploadEstimate(estimatedSeconds);
        debugLog(`[Upload] File size: ${(audioBlob.size / 1024 / 1024).toFixed(2)}MB, estimated time: ${estimateText}`);

        store.dispatch({ type: 'starmus/submit-start' });

        try {
            // Decide whether to use TUS or direct upload
            const useTus = isTusAvailable() && audioBlob.size > 1024 * 1024; // Use TUS for files > 1MB
            
            if (useTus) {
                debugLog('[Upload] Using TUS resumable upload for', fileName);
                
                const uploadUrl = await uploadWithTus(
                    audioBlob,
                    fileName,
                    formFields,
                    metadata,
                    instanceId,
                    (bytesUploaded, bytesTotal) => {
                        const progress = bytesUploaded / bytesTotal;
                        store.dispatch({ 
                            type: 'starmus/submit-progress', 
                            progress 
                        });
                    }
                );
                
                debugLog('[Upload] TUS upload complete:', uploadUrl);
                store.dispatch({ type: 'starmus/submit-complete', uploadUrl });
                
            } else {
                debugLog('[Upload] Using direct POST upload for', fileName);
                
                const response = await uploadDirect(
                    audioBlob,
                    fileName,
                    formFields,
                    metadata,
                    instanceId
                );
                
                debugLog('[Upload] Direct upload complete:', response);
                store.dispatch({ type: 'starmus/submit-complete', response });
            }
            
        } catch (error) {
            console.error('[Upload] Failed:', error);
            
            // Determine if error is retryable
            const isRetryable = error.message !== 'TUS_ENDPOINT_NOT_CONFIGURED' && 
                               error.message !== 'Direct upload endpoint not configured';
            
            // If retryable, save to offline queue for later
            if (isRetryable) {
                debugLog('[Upload] Saving to offline queue for retry');
                
                try {
                    const submissionId = await queueSubmission(
                        instanceId,
                        audioBlob,
                        fileName,
                        formFields,
                        metadata
                    );
                    
                    debugLog('[Upload] Saved to offline queue:', submissionId);
                    
                    // Update UI to show queued status
                    store.dispatch({
                        type: 'starmus/submit-queued',
                        submissionId,
                    });
                    
                    // Check pending count for user feedback
                    const pendingCount = await getPendingCount();
                    debugLog(`[Upload] ${pendingCount} submission(s) in offline queue`);
                    
                } catch (queueError) {
                    console.error('[Upload] Failed to save to offline queue:', queueError);
                    
                    store.dispatch({
                        type: 'starmus/error',
                        error: { 
                            message: 'Upload failed and could not be saved for retry. ' + 
                                    (queueError.message === 'STORAGE_QUOTA_EXCEEDED' 
                                        ? 'Storage quota exceeded.' 
                                        : 'Please try again.'),
                            retryable: false 
                        },
                        status: 'ready_to_submit',
                    });
                }
            } else {
                // Non-retryable error (configuration issue)
                store.dispatch({
                    type: 'starmus/error',
                    error: { 
                        message: error.message || 'Upload failed.', 
                        retryable: false 
                    },
                    status: 'ready_to_submit',
                });
            }
        }
    }

    // CommandBus hook
    CommandBus.subscribe('submit', (payload, meta) => {
        if (meta.instanceId !== instanceId) {
            return;
        }
        handleSubmit(payload.formFields || {});
    });

    CommandBus.subscribe('reset', (_payload, meta) => {
        if (meta.instanceId !== instanceId) {
            return;
        }
        store.dispatch({ type: 'starmus/reset' });
    });

    return {
        handleSubmit,
    };
}
