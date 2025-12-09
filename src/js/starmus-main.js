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

// 4. LAST: The orchestrator (starmus-integrator.js)
import './starmus-integrator.js';

// ======================================================================
// STARMUS GLOBAL EXPORT BRIDGE (MANDATORY FOR WORDPRESS RUNTIME)
// ======================================================================

/* eslint-disable no-undef */

// Core state + dispatch
window.createStore = createStore;
window.StarmusHooks = StarmusHooks;

// Core initializers
window.initCore = initCore;
window.initUI = initUI;

// Recorder APIs
window.StarmusRecorder = StarmusRecorder;
window.StarmusRecorderLegacy = StarmusRecorderLegacy;

// TUS Upload
window.StarmusTus = StarmusTus;

// Transcript Controller
window.StarmusTranscriptController = StarmusTranscriptController;

// Offline Queue
window.StarmusOfflineQueue = getOfflineQueue;
window.StarmusQueueSubmission = queueSubmission;

// Debug flag confirmation
console.log('[Starmus] Globals wired:', {
  createStore: typeof window.createStore,
  initCore: typeof window.initCore,
  initUI: typeof window.initUI,
  Recorder: typeof window.StarmusRecorder,
  Tus: typeof window.StarmusTus,
  Transcript: typeof window.StarmusTranscriptController
});

