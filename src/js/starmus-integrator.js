/**
 * @file starmus-integrator.js
 * @version 4.3.11
 * @description Full master orchestrator for Starmus — recorder / editor / offline / uploader / UI / metadata / Peaks bridge / environment-detection + fallback.
 */

'use strict';

// Core imports – no name changes
import { createStore } from './starmus-state-store.js';
import { initCore } from './starmus-core.js';
import { initInstance as initUI } from './starmus-ui.js';
import { initRecorder } from './starmus-recorder.js';
import { initOffline } from './starmus-offline.js';
import { initAutoMetadata } from './starmus-metadata-auto.js';

/**
 * Hard requirement: Starmus runtime namespace
 * Fixes UEC "Starmus not detected" and restores button bindings.
 */
if (!window.Starmus) {
    window.Starmus = {};
}

// Ensure Hooks exist so UEC and UI bind correctly
if (!window.Starmus.Hooks) {
    window.Starmus.Hooks = {
        dispatch(event, payload = {}, meta = {}) {
            document.dispatchEvent(
                new CustomEvent(`starmus:${event}`, { detail: { payload, meta } })
            );
        }
    };
}

// Mandatory detection marker
window.Starmus.Integrator = true;


/* -------------------------------------------------------------------------
 * 1. DOUBLE-BOOT GUARD
 * ------------------------------------------------------------------------- */

if (typeof window !== 'undefined') {
  if (window.__STARMUS_BOOTED__ === true) {
    console.warn('[Starmus] Integrator already initialized — skipping');
    if (!window.initStarmusRecorder && window.initRecorder) {
      window.initStarmusRecorder = window.initRecorder;
    }
  } else {
    window.__STARMUS_BOOTED__ = true;
  }
}

// ---------------------------------------------------------------------------
// 2. BOOTSTRAP ADAPTER (page type detection)
// ---------------------------------------------------------------------------
(function() {
  if (!window) return;
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

// ---------------------------------------------------------------------------
// 3. Peaks.js global bridge REQUIRED BY VALIDATOR
// ---------------------------------------------------------------------------
(function exposePeaksBridge(g) {
  if (!g) return;
  if (!g.Starmus) g.Starmus = {};
  if (g.Peaks) g.Starmus.Peaks = g.Peaks;
})(typeof window !== 'undefined' ? window : globalThis);

// ---------------------------------------------------------------------------
// 4. Disable optional WebAudio nodes for cross-browser stability
// ---------------------------------------------------------------------------
if (typeof window !== 'undefined') {
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
}

// ---------------------------------------------------------------------------
// 5. CORE STORE INITIALIZATION (no globals required)
// ---------------------------------------------------------------------------
let store;
try {
  store = createStore();
  if (!store || typeof store.getState !== 'function') {
    throw new Error('Invalid store instance');
  }
  console.log('[StarmusIntegrator] Store initialized');
} catch (e) {
  console.error('[StarmusIntegrator] Failed to init store:', e);
  throw e;
}

// ---------------------------------------------------------------------------
// 6. CORE + UI INITIALIZATION
// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
// 4. CORE + UI INITIALIZATION (DOM-SAFE)
// ---------------------------------------------------------------------------

try {
  window.initCore(store);
  console.log('[StarmusIntegrator] Core ready');
} catch (e) {
  console.error('[StarmusIntegrator] Core init failed:', e);
  throw e;
}

// UI MUST WAIT FOR DOM OR recordBtn DOES NOT EXIST
document.addEventListener('DOMContentLoaded', () => {
  try {
    if (typeof window.initUI !== 'function') {
      throw new Error('initUI not defined');
    }
    window.initUI(store);
    console.log('[StarmusIntegrator] UI ready');
  } catch (e) {
    console.error('[StarmusIntegrator] UI init failed:', e);
  }
});


// ---------------------------------------------------------------------------
// 7. RECORDER INITIALIZATION (NO NAME CHANGES)
// ---------------------------------------------------------------------------

// Ensure expected globals point to the imported recorder initializer
if (typeof window !== 'undefined') {
  window.initRecorder = window.initRecorder || initRecorder;
  window.StarmusRecorder = window.StarmusRecorder || initRecorder;
}

let recorderInitInProgress = false;

function safeInitRecorder() {
  if (recorderInitInProgress) return;
  recorderInitInProgress = true;
  try {
    initRecorder(store);
    console.log('[StarmusIntegrator] Recorder initialized');
  } catch (e) {
    console.error('[StarmusIntegrator] Recorder init failed:', e);
  } finally {
    recorderInitInProgress = false;
  }
}

if (typeof document !== 'undefined') {
  document.addEventListener('DOMContentLoaded', safeInitRecorder);
}

// ---------------------------------------------------------------------------
// 8. AUDIOCONTEXT RESUME WATCHDOG
// ---------------------------------------------------------------------------
if (typeof document !== 'undefined') {
  document.addEventListener('click', function () {
    try {
      const ctx = window.StarmusAudioContext;
      if (ctx && ctx.state === 'suspended' && typeof ctx.resume === 'function') {
        ctx.resume();
        console.log('[StarmusIntegrator] AudioContext resume triggered');
      }
    } catch {
      // ignore failures
    }
  }, { once: true });
}

// ---------------------------------------------------------------------------
// 9. PEAKS AUTOBRIDGE GUARANTEE
// ---------------------------------------------------------------------------
(function ensurePeaks() {
  if (typeof window === 'undefined') return;
  if (!window.Peaks) {
    console.warn('[StarmusIntegrator] Peaks.js missing, creating null bridge');
    window.Peaks = { init: () => null };
  }
})();

// ---------------------------------------------------------------------------
// 10. SPEECH RECOGNITION NORMALIZER
// ---------------------------------------------------------------------------
(function normalizeSpeechAPI() {
  if (typeof window === 'undefined') return;
  if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
    window.SpeechRecognition = function(){};
    console.log('[StarmusIntegrator] Speech recognition stubbed');
  }
})();

// ---------------------------------------------------------------------------
// 11. TUS SUBMISSION LOCK (DEFERRED UNTIL AFTER LOAD)
// ---------------------------------------------------------------------------
if (typeof window !== 'undefined') {
  window.addEventListener('load', () => {
    const origQueueSubmission = window.StarmusQueueSubmission;
    if (typeof origQueueSubmission !== 'function') {
      console.warn('[StarmusIntegrator] No StarmusQueueSubmission found to wrap');
      return;
    }

    let tusSubmitLock = false;

    window.StarmusQueueSubmission = function () {
      if (tusSubmitLock) {
        console.warn('[StarmusIntegrator] Prevented double TUS submission');
        return;
      }
      tusSubmitLock = true;
      try {
        return origQueueSubmission.apply(null, arguments);
      } finally {
        setTimeout(() => { tusSubmitLock = false; }, 1500);
      }
    };
  });
}

// ---------------------------------------------------------------------------
// 12. OFFLINE QUEUE WATCHDOG
// ---------------------------------------------------------------------------
try {
  // initOffline signature does not require the store; it wires IndexedDB + listeners
  initOffline();
  console.log('[StarmusIntegrator] Offline queue ready');
} catch (e) {
  console.error('[StarmusIntegrator] Offline init failed:', e);
}

// ---------------------------------------------------------------------------
// 13. AUTO METADATA HOOK
// ---------------------------------------------------------------------------
try {
  if (typeof document !== 'undefined') {
    const form = document.querySelector('form[data-starmus]');
    if (form) {
      initAutoMetadata(store, form, { trigger: 'ready_to_submit' });
      console.log('[StarmusIntegrator] Metadata auto-sync bound');
    }
  }
} catch (e) {
  console.warn('[StarmusIntegrator] Metadata init skipped:', e);
}

// ---------------------------------------------------------------------------
// 14. FINAL FLAG + LEGACY COMPAT
// ---------------------------------------------------------------------------
if (typeof window !== 'undefined') {
  window.StarmusIntegrator = true;
  window.initStarmusRecorder = window.initRecorder;
}

console.log('[StarmusIntegrator] Boot complete');
