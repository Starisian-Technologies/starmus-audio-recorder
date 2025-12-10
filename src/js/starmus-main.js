/**
 * @file starmus-bootstrap.js
 * @version 4.5.0
 * @description Single authoritative bootstrap for Starmus runtime.
 * Loads hooks first, wires state/store, recorder, UI, offline queue,
 * transcript, metadata automation, and exposes stable globals.
 */

'use strict';

/* -------------------------------------------------------------------------
 * 0. REQUIRED GLOBALS (ORDER SENSITIVE)
 * ------------------------------------------------------------------------- */

// Peaks must exist BEFORE editor loads, but do not overwrite if already present
import Peaks from 'peaks.js';
if (!window.Peaks) window.Peaks = Peaks;

// Hooks MUST RUN FIRST – sets window.StarmusHooks + CommandBus
import './starmus-hooks.js';
import './starmus-state-store.js';

/* -------------------------------------------------------------------------
 * 1. CORE SUBSYSTEMS
 * ------------------------------------------------------------------------- */

// Upload + offline queue
import * as StarmusTus from './starmus-tus.js';          // corrected namespace import
import './starmus-offline.js';

// Recorder + UI + transcript controller
import './starmus-ui.js';
import './starmus-core.js';
import './starmus-recorder.js';

// Transcript module must expose BOTH class + init
import TranscriptModule, { StarmusTranscript } from './starmus-transcript-controller.js';

import './starmus-audio-editor.js';
import './starmus-metadata-auto.js';

// LAST: integrator must see globals
import './starmus-integrator.js';

/* -------------------------------------------------------------------------
 * 2. IMPORTS FOR GLOBAL EXPORT BRIDGE
 * ------------------------------------------------------------------------- */

import { createStore } from './starmus-state-store.js';
import { initCore } from './starmus-core.js';
import { initInstance as initUI } from './starmus-ui.js';
import { initRecorder } from './starmus-recorder.js';
import { getOfflineQueue, queueSubmission, initOffline } from './starmus-offline.js';
import { initAutoMetadata } from './starmus-metadata-auto.js';

/* -------------------------------------------------------------------------
 * 3. ASSERT HOOKS EXIST (fail fast)
 * ------------------------------------------------------------------------- */

if (!window.StarmusHooks) {
  throw new Error('[StarmusBootstrap] Hooks not registered before bootstrap — load order violation');
}

/* -------------------------------------------------------------------------
 * 4. GLOBAL API SURFACE — FINAL CONTRACT
 * ------------------------------------------------------------------------- */

window.createStore = createStore;
window.initCore = initCore;
window.initUI = initUI;

// SINGLE authoritative recorder entry
window.StarmusRecorder = initRecorder;

// Upload + priority queue
window.StarmusTus = StarmusTus;
window.StarmusOfflineQueue = getOfflineQueue;
window.StarmusQueueSubmission = queueSubmission;
window.initOffline = initOffline;

// Transcript controller — full module, not just class
window.StarmusTranscriptController = TranscriptModule;

// Metadata automation
window.initAutoMetadata = initAutoMetadata;

// Marker for integrator detection (no exports)
window.StarmusIntegrator = true;

console.log('[Starmus] Runtime globals wired');

/* -------------------------------------------------------------------------
 * 5. ES MODULE EXPORTS
 * ------------------------------------------------------------------------- */

export {
  createStore,
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
