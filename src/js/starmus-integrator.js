/**
 * @file starmus‑integrator.js
 * @version 5.0.0‑integrator (with correct global bridges)
 * @description Master orchestrator: sets up store, UI, recorder (if supported), offline queue, tier detection/fallback, and global bridges for external libs (StarmusTus, Peaks).
 */

'use strict';

// --- External / vendor libraries — import and re‑expose globally under original names ---

import * as tus from 'tus-js-client';
if (typeof window !== 'undefined') {
  window.StarmusTus = tus;
}

import Peaks from 'peaks.js';
if (typeof window !== 'undefined') {
  // original‑style global bridge wrapper for Peaks
  (function exposePeaksBridge(g) {
    try {
      if (typeof g.Peaks === 'function' || typeof g.Peaks === 'object') {
        g.Starmus = g.Starmus || {};
        g.Starmus.Peaks = g.Peaks;
        return;
      }
      Object.defineProperty(g, 'Peaks', {
        get: function () {
          g.Starmus = g.Starmus || {};
          return g.Starmus.Peaks;
        },
        set: function (v) {
          g.Starmus = g.Starmus || {};
          g.Starmus.Peaks = v;
        },
        configurable: true
      });
      // Assign the imported Peaks to global
      g.Peaks = Peaks;
      g.Starmus.Peaks = Peaks;
    } catch (e) {
      console.warn('[Starmus] Peaks.js exposure failed:', e);
    }
  })(window);
}

// --- Core modules & API imports ---

import { subscribe as busSubscribe, dispatch as busDispatch, debugLog } from './starmus-hooks.js';
import { createStore } from './starmus-state-store.js';
import { initInstance as initUI } from './starmus-ui.js';
import { initRecorder } from './starmus-recorder.js';
import { initCore } from './starmus-core.js';
import { initOffline } from './starmus-offline.js';

// --- Internal state for instances ---

const instances = new Map();

// --- Utility / environment detection ---

function emitGlobalEvent(event, payload = {}) {
  try {
    if (window.StarmusHooks && typeof window.StarmusHooks.doAction === 'function') {
      window.StarmusHooks.doAction('starmus_event', {
        instanceId: payload.instanceId || null,
        event,
        severity: payload.severity || 'info',
        message: payload.message || '',
        data: payload.data || {}
      });
    }
  } catch (e) {
    console.warn('[Starmus Integrator] emitGlobalEvent failed:', e);
  }
}

function getDeviceMemory() {
  try {
    return navigator.deviceMemory || null;
  } catch (_) {
    return null;
  }
}

function getHardwareConcurrency() {
  try {
    return navigator.hardwareConcurrency || null;
  } catch (_) {
    return null;
  }
}

function getConnectionInfo() {
  try {
    return navigator.connection || navigator.mozConnection || navigator.webkitConnection || null;
  } catch (_) {
    return null;
  }
}

function detectTier(env) {
  const caps = env.capabilities || {};
  const network = env.network || {};

  if (/\bCrOS\b|Chrome OS/i.test(navigator.userAgent)) {
    return 'A';
  }
  if (!caps.mediaRecorder || !caps.webrtc) {
    return 'C';
  }
  const mem = getDeviceMemory();
  const threads = getHardwareConcurrency();
  if (mem && mem < 1) return 'C';
  if (threads) {
    if (threads === 1) return 'C';
    if (threads === 2) return 'B';
  }
  if (/wv|Crosswalk|Android WebView|Opera Mini/i.test(navigator.userAgent)) {
    return 'C';
  }
  if (network.effectiveType === '2g' || network.effectiveType === 'slow-2g') {
    return 'B';
  }
  if (mem && mem < 2) return 'B';

  return 'A';
}

async function refineTierAsync(tier) {
  if (tier === 'C') return 'C';

  if (navigator.storage && navigator.storage.estimate) {
    try {
      const est = await navigator.storage.estimate();
      const quotaMB = (est.quota || 0) / 1024 / 1024;
      if (quotaMB && quotaMB < 80) {
        return 'C';
      }
    } catch (_) { /* ignore */ }
  }

  if (navigator.permissions && navigator.permissions.query) {
    try {
      const perm = await navigator.permissions.query({ name: 'microphone' });
      if (perm.state === 'denied') {
        return 'C';
      }
    } catch (_) { /* ignore */ }
  }

  return tier;
}

// --- Core wiring per form / recorder instance ---

async function wireInstance(env, formEl) {
  let instanceId = formEl.getAttribute('data-starmus-id');
  if (!instanceId) {
    instanceId = 'starmus_' + Date.now() + '_' + Math.random().toString(16).substr(2);
    formEl.setAttribute('data-starmus-id', instanceId);
  }
  if (instances.has(instanceId)) {
    return instanceId;
  }

  let tier = detectTier(env);
  tier = await refineTierAsync(tier);

  debugLog(`[Starmus] Instance ${instanceId} → Tier ${tier}`);
  emitGlobalEvent('TIER_ASSIGN', { instanceId, severity: 'info', message: `Tier ${tier} assigned`, data: { tier } });

  const store = createStore({ instanceId, env, tier });

  // Collect UI elements relevant to recorder + fallback UI
  const elements = {
    formEl,
    step1: formEl.querySelector('.starmus-step-1'),
    step2: formEl.querySelector('.starmus-step-2'),
    messageBox: formEl.querySelector('[data-starmus-message-box]'),
    statusEl: formEl.querySelector('[data-starmus-status]'),
    recorderContainer: formEl.querySelector('[data-starmus-recorder-container]'),
    fallbackContainer: formEl.querySelector('[data-starmus-fallback-container]'),
    recordBtn: formEl.querySelector('[data-starmus-action="record"]'),
    pauseBtn: formEl.querySelector('[data-starmus-action="pause"]'),
    resumeBtn: formEl.querySelector('[data-starmus-action="resume"]'),
    stopBtn: formEl.querySelector('[data-starmus-action="stop"]'),
    submitBtn: formEl.querySelector('[data-starmus-action="submit"]'),
    resetBtn: formEl.querySelector('[data-starmus-action="reset"]'),
    playBtn: formEl.querySelector('[data-starmus-action="play"]'),
    timer: formEl.querySelector('[data-starmus-timer]'),
    volumeMeter: formEl.querySelector('[data-starmus-volume-meter]'),
    durationProgress: formEl.querySelector('[data-starmus-duration-progress]'),
    // Additional UI elements (waveform, transcript, etc.) can be added similarly
  };

  // Initialize UI module
  initUI(store, elements);

  // Core logic
  initCore(store, instanceId, env);

  // Offline queue
  await initOffline().catch(err => {
    console.warn('[Starmus] offline init error', err);
  });

  // Dispatch init action
  store.dispatch({ type: 'starmus/init', payload: { instanceId, env, tier } });

  const supportsRecording = !!(
    navigator.mediaDevices &&
    navigator.mediaDevices.getUserMedia &&
    window.MediaRecorder &&
    (window.AudioContext || window.webkitAudioContext)
  );

  if (tier === 'C' || !supportsRecording) {
    // Show fallback UI
    if (elements.recorderContainer) elements.recorderContainer.style.display = 'none';
    if (elements.fallbackContainer) elements.fallbackContainer.style.display = 'block';
    store.dispatch({ type: 'starmus/tier-ready', payload: { tier: 'C', fallbackActive: true } });
  } else {
    // Initialize recorder for real recording
    initRecorder(store, instanceId);
  }

  // Form submit handler
  formEl.addEventListener('submit', (e) => {
    e.preventDefault();
    // Gather form fields if needed
    busDispatch('submit', { /* payload: form data */ }, { instanceId });
  });

  // Reset / cleanup handler
  if (elements.resetBtn) {
    elements.resetBtn.addEventListener('click', (e) => {
      e.preventDefault();
      store.dispatch({ type: 'starmus/reset' });
      busDispatch('reset', {}, { instanceId });
    });
  }

  // Recording controls wiring
  if (supportsRecording && tier !== 'C') {
    if (elements.recordBtn) {
      elements.recordBtn.addEventListener('click', (e) => {
        e.preventDefault();
        busDispatch('start-recording', {}, { instanceId });
      });
    }
    if (elements.pauseBtn) {
      elements.pauseBtn.addEventListener('click', (e) => {
        e.preventDefault();
        busDispatch('pause-mic', {}, { instanceId });
      });
    }
    if (elements.resumeBtn) {
      elements.resumeBtn.addEventListener('click', (e) => {
        e.preventDefault();
        busDispatch('resume-mic', {}, { instanceId });
      });
    }
    if (elements.stopBtn) {
      elements.stopBtn.addEventListener('click', (e) => {
        e.preventDefault();
        busDispatch('stop-mic', {}, { instanceId });
      });
    }
  }

  instances.set(instanceId, { store, elements, tier });
  return instanceId;
}

function initAll() {
  const connection = getConnectionInfo() || {};
  const fallbackEnv = {
    browser: { userAgent: navigator.userAgent },
    device: {
      type: /mobile|android|iphone|ipad/i.test(navigator.userAgent) ? 'mobile' : 'desktop',
      memory: getDeviceMemory(),
      concurrency: getHardwareConcurrency()
    },
    capabilities: {
      webrtc: !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia),
      mediaRecorder: !!window.MediaRecorder,
      indexedDB: !!window.indexedDB
    },
    network: {
      effectiveType: connection.effectiveType || 'unknown',
      downlink: connection.downlink || null,
      saveData: connection.saveData || false
    }
  };

  const forms = document.querySelectorAll('form[data-starmus="recorder"]');
  forms.forEach(formEl => {
    wireInstance(fallbackEnv, formEl).catch(err => {
      console.error('[Starmus] wireInstance failed for form', formEl, err);
    });
  });
}

// Kick off initialization
initAll();

// Export for external usage
export { wireInstance, instances as StarmusInstances };
