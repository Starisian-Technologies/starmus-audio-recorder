/**
 * @file starmus-core.js
 * @version 4.5.0
 * @description Handles submission logic.
 * Production-grade: Final release with UI state resets and memory optimizations.
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
 * @param {object} env - Environment payload from init (fallback if state is empty).
 */
export function initCore(store, instanceId, env) {
    async function handleSubmit(formFields) {
        const state = store.getState();
        
        // Prevent variable shadowing (state.env vs arg env)
        const { source, calibration, env: stateEnv } = state;

        // Double-submit guard (prevents Android tap-bounce)
        if (state.status === 'submitting') {
            debugLog('[Upload] Ignored duplicate submit');
            return;
        }

        if (!source || (!source.blob && !source.file)) {
            store.dispatch({
                type: 'starmus/error',
                error: { message: 'Please record or attach audio before submitting.', retryable: false },
                status: state.status,
            });
            return;
        }

        const audioBlob = source.blob || source.file;
        const fileName = source.fileName || source.file?.name || 'recording.webm';

        // Calibration Data Integrity Check
        const calibrationData = (calibration && calibration.complete) ? {
            gain: calibration.gain,
            snr: calibration.snr,
            noiseFloor: calibration.noiseFloor,
            speechLevel: calibration.speechLevel,
            timestamp: calibration.timestamp || new Date().toISOString()
        } : null;

        // OPTIMIZATION: Freeze metadata to reduce GC churn and mutation risks on low-end devices
        const metadata = Object.freeze({
            transcript: source.transcript?.trim() || null,
            calibration: calibrationData,
            env: stateEnv || env || {}, 
        });

        // UI Feedback: Time Estimate
        const estimatedSeconds = estimateUploadTime(audioBlob.size, (stateEnv || env).network);
        const estimateText = formatUploadEstimate(estimatedSeconds);
        debugLog(`[Upload] File size: ${(audioBlob.size / 1024 / 1024).toFixed(2)}MB, estimated time: ${estimateText}`);

        store.dispatch({ type: 'starmus/submit-start' });

        // Shared Progress Callback
        const onProgress = (bytesUploaded, bytesTotal) => {
            const progress = bytesUploaded / bytesTotal;
            store.dispatch({ 
                type: 'starmus/submit-progress', 
                progress 
            });
        };

        try {
            // OPTIMIZATION: Offline Fast-Path
            if (!navigator.onLine) {
                debugLog('[Upload] Device is offline, skipping directly to queue');
                throw new Error('OFFLINE_FAST_PATH');
            }

            const useTus = isTusAvailable() && audioBlob.size > 1024 * 1024; // 1MB threshold
            
            if (useTus) {
                debugLog('[Upload] Using TUS resumable upload for', fileName);
                
                const uploadUrl = await uploadWithTus(
                    audioBlob,
                    fileName,
                    formFields,
                    metadata,
                    instanceId,
                    onProgress
                );
                
                debugLog('[Upload] TUS upload complete:', uploadUrl);
                store.dispatch({ type: 'starmus/submit-complete', uploadUrl });
                
                // CRITICAL FIX: Reset state to unlock UI
                store.dispatch({ type: 'starmus/ready' });
                
            } else {
                debugLog('[Upload] Using direct POST upload for', fileName);
                
                const response = await uploadDirect(
                    audioBlob,
                    fileName,
                    formFields,
                    metadata,
                    instanceId,
                    onProgress
                );
                
                debugLog('[Upload] Direct upload complete:', response);
                store.dispatch({ type: 'starmus/submit-complete', response });
                
                // CRITICAL FIX: Reset state to unlock UI
                store.dispatch({ type: 'starmus/ready' });
            }
            
        } catch (error) {
            if (error.message === 'OFFLINE_FAST_PATH') {
                debugLog('[Upload] Processing offline save...');
            } else {
                console.error('[Upload] Failed:', error);
            }
            
            const isConfigError = error.message === 'TUS_ENDPOINT_NOT_CONFIGURED' || 
                                  error.message === 'Direct upload endpoint not configured';
            
            if (!isConfigError) {
                try {
                    const submissionId = await queueSubmission(
                        instanceId,
                        audioBlob,
                        fileName,
                        formFields,
                        metadata
                    );
                    
                    debugLog('[Upload] Saved to offline queue:', submissionId);
                    
                    store.dispatch({
                        type: 'starmus/submit-queued',
                        submissionId,
                    });
                    
                    // CRITICAL FIX: Reset state to unlock UI after queuing
                    store.dispatch({ type: 'starmus/ready' });
                    
                    getPendingCount().then(count => {
                        debugLog(`[Upload] Queue depth: ${count}`);
                    });
                    
                } catch (queueError) {
                    console.error('[Upload] Offline save failed:', queueError);
                    
                    let msg = 'Upload failed and could not be saved.';
                    if (queueError.message && queueError.message.includes('too large')) {
                        msg = 'File too large to save offline.';
                    } else if (queueError.message === 'STORAGE_QUOTA_EXCEEDED') {
                        msg = 'Storage full. Cannot save offline.';
                    }

                    store.dispatch({
                        type: 'starmus/error',
                        error: { message: msg, retryable: false },
                        status: 'ready_to_submit',
                    });
                }
            } else {
                store.dispatch({
                    type: 'starmus/error',
                    error: { message: error.message || 'Upload failed.', retryable: false },
                    status: 'ready_to_submit',
                });
            }
        }
    }

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

    return { handleSubmit };
}
