/**
 * @file starmus-main.js
 * @version 5.1.0-FIXED
 * @description Main Entry Point. Connects the App to the PHP Form ID.
 */

'use strict';

/* 1. GLOBALS & HOOKS */
import Peaks from 'peaks.js';
if (!window.Peaks) window.Peaks = Peaks;
import './starmus-hooks.js';

/* 2. MODULE IMPORTS */
import { createStore } from './starmus-state-store.js';
import { initCore } from './starmus-core.js';
import { initInstance as initUI } from './starmus-ui.js';
import { initRecorder } from './starmus-recorder.js';
import { initOffline, getOfflineQueue, queueSubmission } from './starmus-offline.js';
import { initAutoMetadata } from './starmus-metadata-auto.js';
import TranscriptModule from './starmus-transcript-controller.js';
import './starmus-integrator.js';

/* 3. SETUP STORE */
const store = createStore();
window.StarmusStore = store; 
console.log('[StarmusMain] Store initialized');

/* 4. BOOTSTRAP ON DOM READY */
document.addEventListener('DOMContentLoaded', () => {
  try {
    // --- CRITICAL FIX: FIND THE FORM ID ---
    // The PHP outputs <form data-starmus-instance="starmus_form_...">
    const form = document.querySelector('form[data-starmus-instance]');
    const instanceId = form ? form.getAttribute('data-starmus-instance') : null;

    if (!instanceId) {
        console.warn('[StarmusMain] ⚠️ No Starmus form found. UI will not initialize.');
        return; // Stop if no form
    }
    
    console.log('[StarmusMain] Booting for Instance ID:', instanceId);

    // --- WIRE EVERYTHING WITH THE ID ---
    
    // 1. Core (Uploads/Logic)
    initCore(store, instanceId);
    
    // 2. UI (Buttons/Views) - Pass ID explicitly
    initUI(store, {}, instanceId);
    
    // 3. Recorder (Audio/Mic) - Pass ID explicitly
    initRecorder(store, instanceId);

    // 4. Offline Queue & Metadata
    initOffline();
    if (form) {
      initAutoMetadata(store, form, { trigger: 'ready_to_submit' });
    }
    
  } catch (e) {
    console.error('[StarmusMain] Boot failed:', e);
  }
});

/* 5. EXPORTS */
window.StarmusRecorder = initRecorder;
window.StarmusTus = { queueSubmission };
window.StarmusOfflineQueue = getOfflineQueue;
window.StarmusTranscriptController = TranscriptModule;

console.log('[StarmusMain] Bundle Loaded');