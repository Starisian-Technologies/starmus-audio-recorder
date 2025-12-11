/**
 * @file starmus-main.js
 * @version 7.1.0-OFFLINE-FIX
 * @description Main Entry Point. Fixed offline queue import.
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
// CRITICAL FIX: Added getOfflineQueue to imports
import { initOffline, queueSubmission, getOfflineQueue } from './starmus-offline.js';
import { initAutoMetadata } from './starmus-metadata-auto.js';
import TranscriptModule from './starmus-transcript-controller.js';
import { default as StarmusAudioEditor } from './starmus-audio-editor.js'; 
import './starmus-integrator.js';

/* 3. SETUP STORE */
const store = createStore();
window.StarmusStore = store; 

/* 4. BOOTSTRAP ON DOM READY */
document.addEventListener('DOMContentLoaded', () => {
  try {
    const recorderForm = document.querySelector('form[data-starmus-instance]');
    const editorRoot = document.getElementById('starmus-editor-root');

    if (recorderForm) {
        const instanceId = recorderForm.getAttribute('data-starmus-instance');
        console.log('[StarmusMain] Booting RECORDER for ID:', instanceId);

        recorderForm.addEventListener('submit', (e) => e.preventDefault());

        initCore(store, instanceId);
        initUI(store, {}, instanceId);
        initRecorder(store, instanceId);
        initOffline();
        initAutoMetadata(store, recorderForm, {});
        
    } else if (editorRoot) {
        console.log('[StarmusMain] Booting EDITOR...');
        if (window.STARMUS_EDITOR_DATA && window.STARMUS_EDITOR_DATA.audioUrl) {
            StarmusAudioEditor.init();
        } else {
            console.warn('[StarmusMain] Editor data missing.');
        }

    } else {
        console.warn('[StarmusMain] ⚠️ No Starmus form or editor found.');
    }
    
  } catch (e) {
    console.error('[StarmusMain] Boot failed:', e);
  }
});

/* 5. EXPORTS */
window.StarmusRecorder = initRecorder;
window.StarmusTus = { queueSubmission };
// This line was crashing because getOfflineQueue wasn't imported above
window.StarmusOfflineQueue = getOfflineQueue; 
window.StarmusTranscriptController = TranscriptModule;
window.StarmusAudioEditor = StarmusAudioEditor;