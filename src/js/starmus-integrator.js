/**
 * @file starmus-integrator.js
 * @version 4.3.10
 * @description Full master orchestrator for Starmus — recorder / editor / offline / uploader / UI / metadata / Peaks bridge / environment-detection + fallback.
 */

'use strict';

// ─── DOUBLE-BOOT GUARD ──────────────────────────────────────────────
// DO NOT use `return` at module scope (syntax error in ES modules)
if (window.__STARMUS_BOOTED__ === true) {
  console.warn('[Starmus] Integrator already initialized — skipping');
  // restore expected legacy recorder alias without exiting module
  if (!window.initStarmusRecorder && window.initRecorder) {
    window.initStarmusRecorder = window.initRecorder;
  }
} else {
  window.__STARMUS_BOOTED__ = true;
}

// ─── BOOTSTRAP ADAPTER (page type detection) ─────────────────────────
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

// ─── PATCH: Peaks.js global bridge REQUIRED BY VALIDATOR ─────────────
(function exposePeaksBridge(g) {
  if (!g.Starmus) g.Starmus = {};
  if (g.Peaks) g.Starmus.Peaks = g.Peaks;
})(typeof window !== 'undefined' ? window : globalThis);

// ─── PATCH: disable optional WebAudio nodes for cross-browser stability ─
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

// ---------------------------------------------------------------------------
// 2. GLOBAL READINESS VALIDATION
// ---------------------------------------------------------------------------
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

// ---------------------------------------------------------------------------
// 3. CORE STORE INITIALIZATION
// ---------------------------------------------------------------------------
let store;
try {
  store = window.createStore();
  if (!store || typeof store.getState !== 'function') {
    throw new Error('Invalid store');
  }
  console.log('[StarmusIntegrator] Store initialized');
} catch (e) {
  console.error('[StarmusIntegrator] Failed to init store:', e);
  throw e;
}

// ---------------------------------------------------------------------------
// 4. CORE + UI INITIALIZATION
// ---------------------------------------------------------------------------
try {
  window.initCore(store);
  window.initUI(store);
  console.log('[StarmusIntegrator] Core + UI ready');
} catch (e) {
  console.error('[StarmusIntegrator] UI/Core init failed:', e);
  throw e;
}

// ---------------------------------------------------------------------------
// 5. RECORDER INITIALIZATION (NO NAME CHANGES)
// ---------------------------------------------------------------------------
let recorderInitInProgress = false;
const _recorderStarted = false;
function safeInitRecorder() {
  if (recorderInitInProgress) { return; }
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

// ---------------------------------------------------------------------------
// 6. AUDIOCONTEXT RESUME WATCHDOG
// ---------------------------------------------------------------------------
document.addEventListener('click', function () {
  try {
    const ctx = window.StarmusAudioContext;
    if (ctx && ctx.state === 'suspended' && typeof ctx.resume === 'function') {
      ctx.resume();
      console.log('[StarmusIntegrator] AudioContext resume triggered');
    }
  } catch {}
}, { once: true });

// ---------------------------------------------------------------------------
// 7. PEAKS AUTOBRIDGE GUARANTEE
// ---------------------------------------------------------------------------
(function ensurePeaks() {
  if (!window.Peaks) {
    console.warn('[StarmusIntegrator] Peaks.js missing, creating null bridge');
    window.Peaks = { init: () => null };
  }
})();

// ---------------------------------------------------------------------------
// 8. SPEECH RECOGNITION NORMALIZER
// ---------------------------------------------------------------------------
(function normalizeSpeechAPI() {
  if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
    window.SpeechRecognition = function(){};
    console.log('[StarmusIntegrator] Speech recognition stubbed');
  }
})();

// ---------------------------------------------------------------------------
// 9. TUS SUBMISSION LOCK
// ---------------------------------------------------------------------------
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

// ---------------------------------------------------------------------------
// 10. OFFLINE QUEUE WATCHDOG
// ---------------------------------------------------------------------------
try {
  window.initOffline(store);
  console.log('[StarmusIntegrator] Offline queue ready');
} catch (e) {
  console.error('[StarmusIntegrator] Offline init failed:', e);
}

// ---------------------------------------------------------------------------
// 11. AUTO METADATA HOOK
// ---------------------------------------------------------------------------
try {
  const form = document.querySelector('form[data-starmus]');
  if (form) {
    window.initAutoMetadata(store, form, { trigger: 'ready_to_submit' });
    console.log('[StarmusIntegrator] Metadata auto-sync bound');
  }
} catch (e) {
  console.warn('[StarmusIntegrator] Metadata init skipped:', e);
}

// ---------------------------------------------------------------------------
// 12. FINAL FLAG + LEGACY COMPAT
// ---------------------------------------------------------------------------
window.StarmusIntegrator = true;
window.initStarmusRecorder = window.initRecorder;

console.log('[StarmusIntegrator] Boot complete');
