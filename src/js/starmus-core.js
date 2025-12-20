/**
 * @file starmus-core.js
 * @version 6.3.0-FINAL-REDIRECT
 * @description Core submission and upload handling for Starmus audio recorder.
 * Manages browser tier detection, submission flow, and offline fallbacks.
 */

'use strict';

import './starmus-hooks.js';
import { uploadWithPriority } from './starmus-tus.js';
import { queueSubmission, getPendingCount } from './starmus-offline.js';
import sparxstarIntegration from './starmus-sparxstar-integration.js';

/**
 * Hook subscription function from StarmusHooks or fallback no-op.
 * @type {function}
 */
const subscribe = window.StarmusHooks?.subscribe || function(){};

/**
 * Detects browser capabilities and assigns appropriate tier classification.
 * Enhanced with SPARXSTAR environment detection for optimal performance.
 * 
 * @function
 * @returns {string} Browser tier classification:
 *   - 'A': Full support (MediaRecorder + AudioContext + getUserMedia + good network/device)
 *   - 'B': Limited support (basic capabilities but network/device constraints)
 *   - 'C': Minimal support (no MediaRecorder, getUserMedia, or very poor conditions)
 */
function detectTier(environmentData = null) {
    // Basic browser capability check
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {return 'C';}
    if (typeof MediaRecorder === 'undefined') {return 'C';}
    
    // If we have SPARXSTAR environment data, use it for enhanced detection
    if (environmentData && environmentData.tier) {
        console.log('[Core] Using SPARXSTAR tier:', environmentData.tier);
        return environmentData.tier;
    }
    
    // Fallback to basic detection
    if (!window.AudioContext && !window.webkitAudioContext) {return 'B';}
    return 'A';
}

/**
 * Initializes the core Starmus functionality for a specific instance.
 * Sets up event handlers, submission logic, and browser tier detection.
 * 
 * @function
 * @exports initCore
 * @param {Object} store - Redux-style store for state management
 * @param {string} instanceId - Unique identifier for this recorder instance
 * @param {Object} env - Environment data from UEC/SparxstarUEC integration
 * @returns {Object} Object containing handleSubmit function for manual invocation
 */
export function initCore(store, instanceId, env) {
  // Initialize SPARXSTAR integration first
  sparxstarIntegration.init().then(environmentData => {
    const _tier = detectTier(environmentData);
    
    // Merge environment data with existing env
    const enhancedEnv = {
      ...env,
      ...environmentData,
      tier: _tier,
      sparxstar_available: sparxstarIntegration.isAvailable
    };
    
    // Update store with enhanced environment data
    store.dispatch({ 
      type: 'starmus/environment-ready', 
      payload: { 
        tier: _tier,
        environment: enhancedEnv,
        recordingSettings: environmentData.recordingSettings
      } 
    });
    
    // Legacy tier-ready event for backward compatibility
    store.dispatch({ type: 'starmus/tier-ready', payload: { tier: _tier } });
    
    window.dispatchEvent(new CustomEvent('starmus-ready', { 
      detail: { instanceId, tier: _tier, environment: enhancedEnv } 
    }));
    
    console.log('[Core] Environment initialized:', {
      tier: _tier,
      sparxstar: sparxstarIntegration.isAvailable,
      network: enhancedEnv.network?.type,
      device: enhancedEnv.device?.type
    });
  }).catch(error => {
    console.error('[Core] Environment initialization failed:', error);
    // Fallback to basic detection
    const _tier = detectTier();
    store.dispatch({ type: 'starmus/tier-ready', payload: { tier: _tier } });
    window.dispatchEvent(new CustomEvent('starmus-ready', { detail: { instanceId, tier: _tier } }));
  });

 /**
   * Handles audio submission with upload priority and offline fallback.
   * Processes form fields, metadata, calibration data, and manages upload flow.
   * 
   * @async
   * @function
   * @param {Object} formFields - Form data including consent, language, and metadata
   * @param {string} formFields.consent - User consent agreement status
   * @param {string} formFields.language - Recording language selection
   * @returns {Promise<void>} Resolves when submission is complete or queued
   * @throws {Error} When upload fails and offline fallback also fails
   */
 async function handleSubmit(formFields) {
    const state = store.getState();
    const source = state.source || {};
    const calibration = state.calibration || {};
    
    // Get current environment data from SPARXSTAR
    const currentEnvData = sparxstarIntegration.getEnvironmentData();
    
    // Merge all environment sources
    const stateEnv = { 
      ...state.env, 
      ...env, 
      ...currentEnvData,
      submission_timestamp: Date.now()
    };

    const audioBlob = source.blob || source.file;
    const fileName  = source.fileName || (source.file ? source.file.name : `rec-${Date.now()}.webm`);

    if (!audioBlob) {
      alert('No audio recording found.');
      return;
    }

    /**
     * Metadata object containing transcript, calibration, and environment data.
     * @type {Object}
     * @property {string|null} transcript - User-provided transcript text
     * @property {Object|null} calibration - Audio calibration settings if complete
     * @property {Object} env - Merged environment data from state and UEC
     * @property {string} tier - Browser capability tier (A/B/C)
     */
    const metadata = {
      transcript: source.transcript?.trim() || null,
      calibration: calibration.complete ? {
        gain: calibration.gain,
        speechLevel: calibration.speechLevel
      } : null,
      env: stateEnv,
      tier: _tier
    };

    store.dispatch({ type: 'starmus/submit-start' });

    try {
      if (!navigator.onLine) {throw new Error('OFFLINE_FAST_PATH');}

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
      
      /**
       * Tracks whether we successfully fired a parent window hook.
       * Used to determine if we're in a modal context.
       * @type {boolean}
       */
      let hookFired = false; // This will track if we are in a modal context.

      // This is the perfect place for our client-side hook.
      if (result.success) {
          const newAudioPostId = result.data?.post_id || result.post_id;

          if (newAudioPostId) {
              console.log('[StarmusCore] Firing starmusRecordingComplete event with Post ID:', newAudioPostId);
              // Only trigger event if parent is same-origin
              try {
                  if (
                      window.parent &&
                      window.parent !== window &&
                      window.parent.jQuery
                  ) {
                      // Attempt to access a property to verify same-origin
                      void window.parent.location.href;
                      parent.jQuery(parent.document).trigger('starmusRecordingComplete', [{
                          audioPostId: newAudioPostId
                      }]);
                      hookFired = true; // We successfully notified a parent, so we are in a modal.
                  }
              } catch (_e) {
                  // Cross-origin access denied; do not trigger event
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
      
      // Report error to SPARXSTAR if available
      if (sparxstarIntegration.isAvailable) {
        sparxstarIntegration.reportError('upload_failed', {
          error: error.message,
          instanceId,
          tier: stateEnv.tier,
          network: stateEnv.network,
          fileSize: audioBlob.size
        });
      }
      
      // Offline Fallback
      try {
          const submissionId = await queueSubmission(instanceId, audioBlob, fileName, formFields, metadata);
          store.dispatch({ type: 'starmus/submit-queued', submissionId });
          const pending = await getPendingCount();
          if (window.CommandBus) {window.CommandBus.dispatch('starmus/offline/queue_updated', { count: pending });}
      } catch (qe) {
          console.error('Offline Queue Failed:', qe);
          store.dispatch({ type: 'starmus/error', error: { message: 'Upload failed completely.' } });
      }
    }
  }

  /**
   * Event handler subscriptions for StarmusHooks integration.
   * Sets up listeners for submit, reset, and continue events filtered by instanceId.
   */
  subscribe('submit', (payload, meta) => {
    if (meta && meta.instanceId === instanceId) {handleSubmit(payload.formFields || {});}
  }, instanceId);

  subscribe('reset', (_p, meta) => {
    if (meta && meta.instanceId === instanceId) {store.dispatch({ type: 'starmus/reset' });}
  }, instanceId);

  subscribe('continue', (_p, meta) => {
    if (meta && meta.instanceId === instanceId) {store.dispatch({ type: 'starmus/ui/step-continue' });}
  }, instanceId);

  return { handleSubmit };
}

/**
 * Global export for browser environments.
 * Makes initCore available on window object for direct script loading.
 */
if (typeof window !== 'undefined') {window.initCore = initCore;}
