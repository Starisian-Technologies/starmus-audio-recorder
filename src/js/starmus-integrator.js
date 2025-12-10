/**
 * @file starmus-integrator.js
 * @version 4.3.10
 * @description Full master orchestrator for Starmus — recorder / editor / offline / uploader / UI / metadata / Peaks bridge / environment-detection + fallback.
 */

'use strict';

// ─── DOUBLE-BOOT GUARD ──────────────────────────────────────────────
if (window.__STARMUS_BOOTED__ === true) {
  console.warn('[Starmus] Integrator already initialized — skipping');
  if (!window.initStarmusRecorder && window.initRecorder) {
    window.initStarmusRecorder = window.initRecorder;
  }
} else {  // <<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<< THIS ELSE OPENS A BLOCK
  window.__STARMUS_BOOTED__ = true;

// ─── BOOTSTRAP ADAPTER ──────────────────────────────────────────────
(function() {
  if (window.STARMUS_EDITOR_DATA) {
    window.STARMUS_BOOTSTRAP = window.STARMUS_EDITOR_DATA;
    window.STARMUS_BOOTSTRAP.pageType = 'editor';
  } else if (window.STARMUS_RERECORDER_DATA) {
    window.STARMUS_BOOTSTRAP = window.STARMUS_RERECORDER_DATA;
    window.STARMUS_BOOTSTRAP.pageType = 'rerecorder';
  } else if (window.STARMUS_RECORDER_DATA) {
    window.STARMUS_BOOTSTRAP = window.STARMUS_RECORDER_DATA;
    window.STARMUS_BOOTSTRAP.pageType = 'recorder';
  }
})();

// ─── PEAKS BRIDGE (validator requires this) ─────────────────────────
(function exposePeaksBridge(g) {
  if (!g.Starmus) g.Starmus = {};
  if (g.Peaks) g.Starmus.Peaks = g.Peaks;
})(typeof window !== 'undefined' ? window : globalThis);

// ─── OPTIONAL WEB AUDIO NODES DISABLE ───────────────────────────────
window.Starmus_DisableOptionalNodes = true;
if (window.Starmus_DisableOptionalNodes) {
  const CtxProto = (window.AudioContext || window.webkitAudioContext)?.prototype;
  if (CtxProto) {
    if (CtxProto.createConstantSource) {
      CtxProto.createConstantSource = function () {
        throw new Error('ConstantSourceNode disabled for stability.');
      };
    }
    if (CtxProto.createIIRFilter) {
      CtxProto.createIIRFilter = function () {
        throw new Error('IIRFilterNode disabled for stability.');
      };
    }
  }
}

// ─── GLOBAL READINESS VALIDATION ────────────────────────────────────
function assertGlobal(name) {
  if (!(name in window)) {
    console.error('[StarmusIntegrator] Missing global:', name);
    throw new Error('Starmus runtime missing global: ' + name);
  }
}

[
  'createStore',
  'initCore',
  'initUI',
  'initRecorder',
  'StarmusTus',
  'StarmusOfflineQueue',
  'StarmusQueueSubmission',
  'initOffline',
  'initAutoMetadata'
].forEach(assertGlobal);

// ─── STORE INIT ─────────────────────────────────────────────────────
let store;
try {
  store = window.createStore();
  if (!store || typeof store.getState !== 'function') throw new Error('Invalid store');
  console.log('[StarmusIntegrator] Store initialized');
} catch (e) {
  console.error('[StarmusIntegrator] Failed to init store:', e);
  throw e;
}

// ─── CORE + UI ──────────────────────────────────────────────────────
try {
  window.initCore(store);
  window.initUI(store);
  console.log('[StarmusIntegrator] Core + UI ready');
} catch (e) {
  console.error('[StarmusIntegrator] UI/Core init failed:', e);
  throw e;
}

// ─── RECORDER INIT ─────────────────────────────────────────────────
let recorderInitInProgress = false;
const _recorderStarted = false;

function safeInitRecorder() {
  if (recorderInitInProgress) return;
  recorderInitInProgress = true;
  try {
    window.initRecorder(store);
    console.log('[StarmusIntegrator] Recorder initialized');
  } catch (e) {
    console.error('[StarmusIntegrator] Recorder init failed:', e);
  } finally {
    recorderInitInProgress = false;
  }
}

document.addEventListener('DOMContentLoaded', safeInitRecorder);

// ─── AUDIOCONTEXT RESUME ───────────────────────────────────────────
document.addEventListener('click', function () {
  try {
    const ctx = window.StarmusAudioContext;
    if (ctx && ctx.state === 'suspended' && typeof ctx.resume === 'function') {
      ctx.resume();
      console.log('[StarmusIntegrator] AudioContext resume triggered');
    }
  } catch {}
}, { once: true });

// ─── ENSURE PEAKS ───────────────────────────────────────────────────
(function ensurePeaks() {
  if (!window.Peaks) {
    console.warn('[StarmusIntegrator] Peaks.js missing, creating null bridge');
    window.Peaks = { init: () => null };
  }
})();

// ─── SPEECH API NORMALIZER ──────────────────────────────────────────
(function normalizeSpeechAPI() {
  if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
    window.SpeechRecognition = function(){};
    console.log('[StarmusIntegrator] Speech recognition stubbed');
  }
})();

// ─── TUS SUBMISSION LOCK ────────────────────────────────────────────
let tusSubmitLock = false;
const origQueueSubmission = window.StarmusQueueSubmission;
window.StarmusQueueSubmission = function () {
  if (tusSubmitLock) {
    console.warn('[StarmusIntegrator] Prevented double TUS submission');
    return;
  }
  tusSubmitLock = true;
  try {
    origQueueSubmission.apply(null, arguments);
  } finally {
    setTimeout(() => (tusSubmitLock = false), 1500);
  }
};

// ─── OFFLINE QUEUE ──────────────────────────────────────────────────
try {
  window.initOffline(store);
  console.log('[StarmusIntegrator] Offline queue ready');
} catch (e) {
  console.error('[StarmusIntegrator] Offline init failed:', e);
}

// ─── AUTO METADATA ─────────────────────────────────────────────────
try {
  const form = document.querySelector('form[data-starmus]');
  if (form) {
    window.initAutoMetadata(store, form, { trigger: 'ready_to_submit' });
    console.log('[StarmusIntegrator] Metadata auto-sync bound');
  }
} catch (e) {
  console.warn('[StarmusIntegrator] Metadata init skipped:', e);
}

// ─── LEGACY FLAGS ──────────────────────────────────────────────────
window.StarmusIntegrator = true;
window.initStarmusRecorder = window.initRecorder;

console.log('[StarmusIntegrator] Boot complete');

} // END OF ELSE BLOCK
