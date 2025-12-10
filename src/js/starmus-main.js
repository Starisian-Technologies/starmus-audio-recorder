/**
 * @file starmus-main.js
 * @version 5.7.0-SECURE
 */

'use strict';

import Peaks from 'peaks.js';
if (!window.Peaks) window.Peaks = Peaks;
import './starmus-hooks.js';

import { createStore } from './starmus-state-store.js';
import { initCore } from './starmus-core.js';
import { initInstance as initUI } from './starmus-ui.js';
import { initRecorder } from './starmus-recorder.js';
import { initOffline, queueSubmission } from './starmus-offline.js';
import { initAutoMetadata } from './starmus-metadata-auto.js';
import TranscriptModule from './starmus-transcript-controller.js';
import './starmus-integrator.js';

const store = createStore();
window.StarmusStore = store; 

document.addEventListener('DOMContentLoaded', () => {
  try {
    const form = document.querySelector('form[data-starmus-instance]');
    const instanceId = form ? form.getAttribute('data-starmus-instance') : null;

    if (!instanceId) {
        console.warn('[StarmusMain] ⚠️ No Starmus form found.');
        return; 
    }
    
    // --- CRITICAL: Prevent Default Submit ---
    form.addEventListener('submit', (e) => {
        e.preventDefault(); 
        console.log('[StarmusMain] Native submit blocked. Use the UI button.');
    });

    console.log('[StarmusMain] Booting for Instance ID:', instanceId);

    initCore(store, instanceId);
    initUI(store, {}, instanceId);
    initRecorder(store, instanceId);
    initOffline();
    
    if (form) {
      initAutoMetadata(store, form, { trigger: 'ready_to_submit' });
    }
    
  } catch (e) {
    console.error('[StarmusMain] Boot failed:', e);
  }
});

window.StarmusRecorder = initRecorder;
window.StarmusTus = { queueSubmission };