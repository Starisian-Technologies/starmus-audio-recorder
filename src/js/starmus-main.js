/**
 * @file starmus-main.js
 * @version 7.0.0-EDITOR-AWARE
 * @description Main Entry Point. Detects if on Recorder or Editor page.
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
import { initOffline, queueSubmission } from './starmus-offline.js';
import { initAutoMetadata } from './starmus-metadata-auto.js';
import TranscriptModule from './starmus-transcript-controller.js';
import StarmusAudioEditor from './starmus-audio-editor.js'; // Import the editor module
import './starmus-integrator.js';

/* 3. SETUP STORE */
const store = createStore();
window.StarmusStore = store; 

/* 4. BOOTSTRAP ON DOM READY */
document.addEventListener('DOMContentLoaded', () => {
  try {
    // --- PAGE DETECTION ---
    const recorderForm = document.querySelector('form[data-starmus-instance]');
    const editorRoot = document.getElementById('starmus-editor-root');

    if (recorderForm) {
        // --- RECORDER WORKFLOW ---
        const instanceId = recorderForm.getAttribute('data-starmus-instance');
        console.log('[StarmusMain] Booting RECORDER for Instance ID:', instanceId);

        // Prevent native form submission
        recorderForm.addEventListener('submit', (e) => e.preventDefault());

        // Wire up Recorder modules
        initCore(store, instanceId);
        initUI(store, {}, instanceId);
        initRecorder(store, instanceId);
        initOffline();
        initAutoMetadata(store, recorderForm, {});
        
    } else if (editorRoot) {
        // --- EDITOR WORKFLOW ---
        console.log('[StarmusMain] Booting EDITOR...');
        if (window.STARMUS_EDITOR_DATA && window.STARMUS_EDITOR_DATA.audioUrl) {
            // The editor JS has its own internal logic
            StarmusAudioEditor.init();
        } else {
            console.warn('[StarmusMain] Editor root found, but STARMUS_EDITOR_DATA is missing or invalid.');
        }

    } else {
        console.warn('[StarmusMain] ⚠️ No Starmus form or editor found. UI will not initialize.');
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
window.StarmusAudioEditor = StarmusAudioEditor; // Expose editor globally