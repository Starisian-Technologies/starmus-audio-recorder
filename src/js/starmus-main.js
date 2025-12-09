// 0. PEAKS.JS GLOBAL SETUP (needed by editor)
import Peaks from 'peaks.js';
window.Peaks = Peaks;

// 1. MUST RUN FIRST: Defines global StarmusHooks + Store
import './starmus-hooks.js';
import './starmus-state-store.js';

// 2. Upload + Queue systems (used by Core)
import './starmus-tus.js';
import './starmus-offline.js';

// 3. Recorder + UI subsystems
import './starmus-ui.js';
import './starmus-core.js';
import './starmus-recorder.js';
import './starmus-transcript-controller.js';
import './starmus-audio-editor.js';
import './starmus-metadata-auto.js';

// 4. LAST: The orchestrator (starmus-integrator.js)
import './starmus-integrator.js';

// ======================================================================
// ABSOLUTE EXPORT BRIDGE (UN-SHAKEABLE)
// ======================================================================

/* global window */

// IMPORTS
import { createStore } from './starmus-state-store.js';
import { initCore } from './starmus-core.js';
import { initInstance as initUI } from './starmus-ui.js';
import { initRecorder } from './starmus-recorder.js';
import StarmusTus from './starmus-tus.js';
import { StarmusTranscript } from './starmus-transcript-controller.js';
import { getOfflineQueue, queueSubmission, initOffline } from './starmus-offline.js';
import { initAutoMetadata } from './starmus-metadata-auto.js';

// GLOBAL ASSIGNMENTS (UN-SHAKEABLE)
window.createStore = createStore;
// NOTE: StarmusHooks is already set by the IIFE - don't overwrite!
// window.StarmusHooks = StarmusHooks; // REMOVED: This overwrites the IIFE globals
window.initCore = initCore;
window.initUI = initUI;
window.initRecorder = initRecorder; // ✅ FIXED: don't access global.*
window.StarmusTus = StarmusTus;
window.StarmusTranscriptController = StarmusTranscript;
window.StarmusOfflineQueue = getOfflineQueue;
window.StarmusQueueSubmission = queueSubmission;
window.initOffline = initOffline;
window.initAutoMetadata = initAutoMetadata;

// Assign integrator to global so it can be detected
window.StarmusIntegrator = true;

// Assign recorder functions to satisfy integrator expectations
window.StarmusRecorder = initRecorder;
window.initStarmusRecorder = initRecorder;

console.log('[Starmus] Runtime globals wired');

// EXPORTS (ES MODULE)
export {
  createStore,
  // StarmusHooks, // REMOVED: This overwrites the IIFE globals
  initCore,
  initUI,
  initRecorder,
  StarmusTus,
  StarmusTranscript,
  getOfflineQueue,
  queueSubmission,
  initOffline,
  initAutoMetadata
};



