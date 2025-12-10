/**
 * @file starmus‑integrator.js
 * @version 4.3.9‑production
 * @description Full master orchestrator for Starmus — recorder / editor / offline / uploader / UI / metadata / Peaks bridge / environment‑detection + fallback.
 */

'use strict';

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

// ─── PATCH 4: disable optional WebAudio nodes for cross‑browser stability ─
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

// ─── VENDOR LIBRARIES & GLOBAL BRIDGES ──────────────────────────────

// TUS‑js client (resumable uploads)
import * as tus from 'tus-js-client';
window.tus = tus;

// Peaks.js is now set up in starmus-main.js before any modules that need it
// But we still provide the Starmus.Peaks bridge for external systems
(function exposePeaksBridge(g) {
  if (!g.Starmus) g.Starmus = {};
  // Peaks global is already set up by starmus-main.js
  if (g.Peaks) {
    g.Starmus.Peaks = g.Peaks;
  }
})(typeof window !== 'undefined' ? window : globalThis);

// ─── CORE MODULES & DEPENDENCIES ────────────────────────────────────
import { CommandBus } from './starmus-hooks.js';
import { createStore } from './starmus-state-store.js';
import { initInstance as initUI } from './starmus-ui.js';
import { initRecorder } from './starmus-recorder.js';
import { initCore } from './starmus-core.js';
import './starmus-tus.js';
import './starmus-transcript-controller.js';
import { getOfflineQueue } from './starmus-offline.js';

// Audio editor is imported in main sequence where Peaks is available

// ─── GLOBAL PRESENCE SIGNAL FOR EXTERNAL SYSTEMS (e.g. SparxstarUEC) ─
window.SPARXSTAR = window.SPARXSTAR || {};
window.SPARXSTAR.StarmusReady = true;

// ─── OFFLINE QUEUE INIT ─────────────────────────────────────────────
getOfflineQueue()
  .then(() => console.log('[Starmus] Offline queue initialized'))
  .catch((err) => console.error('[Starmus] Offline queue init failed:', err));

// ─── TELEMETRY / EVENT EMITTER ─────────────────────────────────────
function emitStarmusEventGlobal(event, payload = {}) {
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
    console.warn('[Starmus] Global telemetry emit failed:', e);
  }
}

// ─── Utility: resume AudioContext if suspended ──────────────────────
async function starmusEnsureContext(ctx) {
  if (!ctx) return;
  if (ctx.state === 'suspended') {
    try { await ctx.resume(); } catch { /* swallow */ }
    console.log('[Starmus] AudioContext resumed');
  }
}

// ─── Device / Network Detection Helpers ─────────────────────────────
function getDeviceMemory() {
  try { return navigator.deviceMemory || null; } catch { return null; }
}
function getHardwareConcurrency() {
  try { return navigator.hardwareConcurrency || null; } catch { return null; }
}
function getConnection() {
  try {
    return navigator.connection || navigator.mozConnection || navigator.webkitConnection || null;
  } catch {
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
  if (mem != null && mem < 1) {
    return 'C';
  }
  if (threads != null) {
    if (threads === 1) return 'C';
    if (threads === 2) return 'B';
  }
  if (/wv|Crosswalk|Android WebView|Opera Mini/i.test(navigator.userAgent)) {
    return 'C';
  }
  if (network.effectiveType === '2g' || network.effectiveType === 'slow-2g') {
    return 'B';
  }
  if (mem != null && mem < 2) {
    return 'B';
  }
  return 'A';
}

if (!String.prototype.trim) {
  String.prototype.trim = function () {
    return this.replace(/^\s+|\s+$/g, '');
  };
}

// ─── Upload Time Estimator ─────────────────────────────────────────
function estimateUploadTime(bytes, network) {
  const downlink = network?.downlink || null; // in Mbps
    if (downlink && downlink > 0) {
        const uploadSpeedMbps = downlink / 2;
        const uploadSpeedBps = uploadSpeedMbps * 1024 * 1024 / 8;
        return bytes / uploadSpeedBps;
    }
    return bytes / (256 * 1024 / 8);
}

function formatUploadEstimate(seconds) {
  if (seconds < 60) {
    return `${Math.round(seconds)} seconds`;
  } else {
    const mins = Math.floor(seconds / 60);
    const secs = Math.round(seconds % 60);
    return `${mins} minutes${secs > 0 ? ' ' + secs + ' seconds' : ''}`;
  }
}

async function refineTierAsync(tier) {
  if (tier === 'C') return 'C';
  if (navigator.storage && navigator.storage.estimate) {
    try {
      const estimate = await navigator.storage.estimate();
      const quotaMB = (estimate.quota || 0) / 1024 / 1024;
      if (quotaMB && quotaMB < 80) {
        return 'C';
      }
    } catch {/* ignore */}
  }
  if (navigator.permissions && navigator.permissions.query) {
    try {
      const status = await navigator.permissions.query({ name: 'microphone' });
      if (status.state === 'denied') return 'C';
    } catch {/* ignore */}
  }
  return tier;
}

function isRecordingSupported() {
  try {
    const hasMediaDevices = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    const hasMediaRecorder = !!window.MediaRecorder;
    const hasAudioContext = !!(window.AudioContext || window.webkitAudioContext);
    return hasMediaDevices && hasMediaRecorder && hasAudioContext;
  } catch {
    return false;
  }
}

window.StarmusApp = window.StarmusApp || {};
window.StarmusApp.isRecordingSupported = isRecordingSupported;

// ─── Hidden‑field injection before final form submit (telemetry, metadata, waveform, fingerprint) ─
function populateHiddenFields(store, formEl) {
  const state = store.getState();
  const env = state.env || {};
  const calibration = state.calibration || {};
  const source = state.source || {};

  const inject = (name, value) => {
    const el = formEl.querySelector(`[name="${name}"]`);
    if (el) {
      el.value = value;
    }
  };

  if (env && Object.keys(env).length > 0) {
    inject('_starmus_env', JSON.stringify(env));
  }
  if (calibration.complete) {
    inject('_starmus_calibration', JSON.stringify(calibration));
  }
  if (source.transcript) {
    inject('first_pass_transcription', source.transcript.trim());
  }
  if (source.waveform_data) {
    inject('waveform_json', JSON.stringify(source.waveform_data));
  }
  inject('user_agent', navigator.userAgent || '');
  inject('device_fingerprint', env?.identifiers?.visitorId || '');
}

// ─── Main instance wiring logic: UI, recorder/editor, tier fallback, form handling, playback, etc. ─
const instances = new Map();

async function wireInstance(env, formEl) {
  let instanceId = formEl.getAttribute('data-starmus-id');
  if (!instanceId) {
    instanceId = 'starmus_' + Date.now() + '_' + Math.random().toString(16).slice(2);
    formEl.setAttribute('data-starmus-id', instanceId);
  }
  if (instances.has(instanceId)) {
    return instanceId;
  }

  let tier = detectTier(env);
  tier = await refineTierAsync(tier);

  console.log(`[Starmus] Instance ${instanceId} → Tier ${tier}`);
  emitStarmusEventGlobal('TIER_ASSIGN', { instanceId, severity: 'info', message: `Tier ${tier} assigned`, data: { tier } });

  const store = createStore({ instanceId, env, tier });

  const elements = {
    step1: formEl.querySelector('.starmus-step-1'),
    step2: formEl.querySelector('.starmus-step-2'),
    continueBtn: formEl.querySelector('[data-starmus-action="continue"]'),
    messageBox: formEl.querySelector('[data-starmus-message-box]'),
    setupMicBtn: formEl.querySelector('[data-starmus-action="setup-mic"]'),
    setupContainer: formEl.querySelector('[data-starmus-setup-container]'),
    recordBtn: formEl.querySelector('[data-starmus-action="record"]'),
    pauseBtn: formEl.querySelector('[data-starmus-action="pause"]'),
    resumeBtn: formEl.querySelector('[data-starmus-action="resume"]'),
    stopBtn: formEl.querySelector('[data-starmus-action="stop"]'),
    submitBtn: formEl.querySelector('[data-starmus-action="submit"]'),
    resetBtn: formEl.querySelector('[data-starmus-action="reset"]'),
    fileInput: formEl.querySelector('input[type="file"]'),
    statusEl: formEl.querySelector('[data-starmus-status]'),
    progressEl: formEl.querySelector('[data-starmus-progress]'),
    progressWrap: formEl.querySelector('.starmus-progress-wrap'),
    recorderContainer: formEl.querySelector('[data-starmus-recorder-container]'),
    fallbackContainer: formEl.querySelector('[data-starmus-fallback-container]'),

    timer: formEl.querySelector('[data-starmus-timer]'),
    timerElapsed: formEl.querySelector('.starmus-timer-elapsed'),
    durationProgress: formEl.querySelector('[data-starmus-duration-progress]'),
    volumeMeter: formEl.querySelector('[data-starmus-volume-meter]'),
    waveformBox: formEl.querySelector('[data-starmus-waveform]'),
    reviewControls: formEl.querySelector('.starmus-review-controls'),
    playBtn: formEl.querySelector('[data-starmus-action="play"]'),
    transcriptBox: formEl.querySelector('[data-starmus-transcript]')
  };

  if (tier === 'C') {
    if (elements.recorderContainer) elements.recorderContainer.style.display = 'none';
    if (elements.fallbackContainer) elements.fallbackContainer.style.display = 'block';
    if (window.StarmusHooks?.doAction) {
      window.StarmusHooks.doAction('starmus_tier_c_revealed', instanceId, env);
    }
  }

  initUI(store, elements);
  initCore(store, instanceId, env);
  if (tier !== 'C') {
    initRecorder(store, instanceId);
  }

  instances.set(instanceId, { store, form: formEl, elements, tier });

  store.subscribe(() => {
    const s = store.getState();
    if (s.tier === 'C' && tier !== 'C' && s.fallbackActive === true) {
      const prev = tier;
      tier = 'C';
      if (elements.recorderContainer) elements.recorderContainer.style.display = 'none';
      if (elements.fallbackContainer) elements.fallbackContainer.style.display = 'block';
      console.warn(`[Starmus] Instance ${instanceId} downgraded from ${prev} → C`);
      emitStarmusEventGlobal('TIER_DOWNGRADE', {
        instanceId,
        severity: 'warning',
        message: `Runtime tier downgrade from ${prev} to C`,
        data: { previousTier: prev, currentTier: 'C', reason: 'audio_graph_failure' }
      });
    }
  });

  const speechSupported = tier === 'A' ? !!(window.SpeechRecognition || window.webkitSpeechRecognition) : false;
  store.dispatch({ type: 'starmus/init', payload: { instanceId, env, tier, speechSupported } });

  if (elements.continueBtn) {
    elements.continueBtn.addEventListener('click', (e) => {
      console.log('[Starmus Integrator] Continue button clicked!');
      e.preventDefault();
      const step1 = elements.step1;
      if (!step1) {
        console.log('[Starmus Integrator] No step1 element found');
        return;
      }

      const title = step1.querySelector('[name="starmus_title"]');
      const lang  = step1.querySelector('[name="starmus_language"]');
      const type  = step1.querySelector('[name="starmus_recording_type"]');
      const consent = step1.querySelector('[name="agreement_to_terms"]');
      const msgEl = elements.messageBox;

      const missing = [];
      if (!title || !title.value.trim()) missing.push('Title');
      if (!lang  || !lang.value.trim())  missing.push('Language');
      if (!type  || !type.value.trim())  missing.push('Recording Type');
      if (!consent || !consent.checked) missing.push('Consent');

      if (missing.length > 0) {
        if (msgEl) {
          msgEl.textContent = 'Missing: ' + missing.join(', ');
          msgEl.style.display = 'block';
        }
        return;
      }
      if (msgEl) {
        msgEl.textContent = '';
        msgEl.style.display = 'none';
      }

      console.log('[Starmus Integrator] Dispatching step-continue action');
      store.dispatch({ type: 'starmus/ui/step-continue' });
      console.log('[Starmus Integrator] Step-continue dispatched, current state:', store.getState());

      if (window.innerWidth < 768) {
        const formContainer = formEl.closest('.starmus-recorder-form') || formEl;
        formContainer.classList.add('starmus-immersive');
        document.body.classList.add('starmus-lock-scroll');
        history.pushState({ starmusMode: 'immersive', instanceId }, '', '#recording-mode');

        const handlePop = () => {
          formContainer.classList.remove('starmus-immersive');
          document.body.classList.remove('starmus-lock-scroll');
          const btn = formContainer.querySelector('.starmus-close-immersive');
          if (btn) btn.remove();
          window.removeEventListener('popstate', handlePop);
        };
        window.addEventListener('popstate', handlePop);

        if (!formContainer.querySelector('.starmus-close-immersive')) {
          const btn = document.createElement('button');
          btn.innerHTML = '&times;';
          btn.className = 'starmus-close-immersive';
          btn.setAttribute('aria-label', 'Close fullscreen mode');
          btn.onclick = (ev) => {
            ev.preventDefault();
            history.back();
          };
          formContainer.style.position = 'relative';
          formContainer.insertBefore(btn, formContainer.firstChild);
        }
      }
    });
  }

  if (formEl.dataset.starmusRerecord === 'true') {
    store.dispatch({ type: 'starmus/ui/step-continue' });
  }

  if (tier !== 'C') {
    if (elements.setupMicBtn) {
      elements.setupMicBtn.addEventListener('click', async (e) => {
        e.preventDefault();
        const ctx = window.AudioContext || window.webkitAudioContext;
        if (ctx) {
          const sharedCtx = new ctx({ latencyHint: 'interactive' });
          await starmusEnsureContext(sharedCtx);
        }
        CommandBus.dispatch('setup-mic', {}, { instanceId });
      });
    }
    if (elements.recordBtn) {
      elements.recordBtn.addEventListener('click', async (e) => {
        e.preventDefault();
        const ctx = window.AudioContext || window.webkitAudioContext;
        if (ctx) {
          const sharedCtx = new ctx({ latencyHint: 'interactive' });
          await starmusEnsureContext(sharedCtx);
        }
        CommandBus.dispatch('start-recording', {}, { instanceId });
      });
    }
    if (elements.pauseBtn) {
      elements.pauseBtn.addEventListener('click', (e) => {
        e.preventDefault();
        CommandBus.dispatch('pause-mic', {}, { instanceId });
      });
    }
    if (elements.resumeBtn) {
      elements.resumeBtn.addEventListener('click', (e) => {
        e.preventDefault();
        CommandBus.dispatch('resume-mic', {}, { instanceId });
      });
    }
    if (elements.stopBtn) {
      elements.stopBtn.addEventListener('click', (e) => {
        e.preventDefault();
        CommandBus.dispatch('stop-mic', {}, { instanceId });
      });
    }
  }

  if (elements.fileInput) {
    elements.fileInput.addEventListener('change', () => {
      const file = elements.fileInput.files?.[0];
      if (file) {
        CommandBus.dispatch('attach-file', { file }, { instanceId });
      }
    });
  }

  formEl.addEventListener('submit', (e) => {
    e.preventDefault();
    populateHiddenFields(store, formEl);
    const fd = new FormData(formEl);
    const formFields = {};
    fd.forEach((v, k) => formFields[k] = v);
    CommandBus.dispatch('submit', { formFields }, { instanceId });
  });

  if (elements.resetBtn) {
    elements.resetBtn.addEventListener('click', (e) => {
      e.preventDefault();
      store.dispatch({ type: 'starmus/reset' });
      CommandBus.dispatch('reset', {}, { instanceId });
    });
  }

  let audioEl = null, audioUrl = null;
  if (elements.playBtn) {
    elements.playBtn.addEventListener('click', (e) => {
      e.preventDefault();
      const state = store.getState();
      const source = state.source || {};
      const blob = source.blob || source.file;
      if (!blob) return;

      if (!audioEl) {
        audioEl = new Audio();
        audioUrl = URL.createObjectURL(blob);
        audioEl.src = audioUrl;
        audioEl.addEventListener('ended', () => {
          store.dispatch({ type: 'starmus/recorder-playback-state', payload: { isPlaying: false } });
        });
      }

      const isPlaying = store.getState().recorder?.isPlaying;
      if (isPlaying) {
        audioEl.pause();
        store.dispatch({ type: 'starmus/recorder-playback-state', payload: { isPlaying: false } });
      } else {
        audioEl.play().then(() => {
          store.dispatch({ type: 'starmus/recorder-playback-state', payload: { isPlaying: true } });
        }).catch((err) => console.error('[Starmus] Playback failed:', err));
      }
    });
  }

  CommandBus.subscribe('reset', (_p, meta) => {
    if (meta.instanceId !== instanceId) return;
    if (audioEl) try { audioEl.pause(); } catch {}
    if (audioUrl) { URL.revokeObjectURL(audioUrl); }
    audioEl = null;
    audioUrl = null;
  });

  return instanceId;
}

// ─── ENTRY: wire instances after environment ready or fallback ─────────
async function onEnvironmentReady(event) {
  const env = event.detail || {};
  const forms = document.querySelectorAll('form[data-starmus="recorder"]');
  if (!forms?.length) return;
  for (const formEl of forms) {
    await wireInstance(env, formEl);
  }
}

function initWithFallback() {
  const connection = getConnection();
  const net = connection ? {
    effectiveType: connection.effectiveType || 'unknown',
    downlink: connection.downlink || null,
    saveData: connection.saveData || false
  } : { effectiveType: 'unknown', downlink: null, saveData: false };

  const fallbackEnv = {
    browser: { userAgent: navigator.userAgent },
    device: {
      type: /mobile|android|iphone|ipad/i.test(navigator.userAgent) ? 'mobile' : 'desktop',
      memory: getDeviceMemory(),
      concurrency: getHardwareConcurrency()
    },
    capabilities: {
      webrtc: !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia),
      mediaRecorder: !!(navigator.mediaDevices && window.MediaRecorder),
      indexedDB: !!window.indexedDB
    },
    network: net
  };

  emitStarmusEventGlobal('E_ENV_FALLBACK_INIT', {
    severity: 'warning',
    message: 'Environment-ready event not fired; using fallback env',
    data: { fallbackEnv }
  });

  onEnvironmentReady({ detail: fallbackEnv });
}

let environmentReady = false;
let fallbackTimer = null;
document.addEventListener('sparxstar:environment-ready', (event) => {
  environmentReady = true;
  if (fallbackTimer) {
    clearTimeout(fallbackTimer);
    fallbackTimer = null;
  }
  onEnvironmentReady(event);
});

fallbackTimer = setTimeout(() => {
  if (!environmentReady) {
    console.warn('[Starmus] Using fallback initialization');
    initWithFallback();
  }
}, 2000);

if (typeof window !== 'undefined') {
  window.STARMUS = window.STARMUS || {};
  window.STARMUS.instances = instances;
  window.StarmusHooks = window.StarmusHooks || { doAction: CommandBus.dispatch };
  window.CommandBus = CommandBus;
}

// ─── EXPORTS ───────────────────────────────────────────────────────
export {
  wireInstance,
  populateHiddenFields,
  emitStarmusEventGlobal,
  isRecordingSupported,
  starmusEnsureContext,
  getDeviceMemory,
  getHardwareConcurrency,
  getConnection,
  detectTier,
  refineTierAsync
};
export default {
  wireInstance, populateHiddenFields, emitStarmusEventGlobal,
  isRecordingSupported, starmusEnsureContext,
  getDeviceMemory, getHardwareConcurrency, getConnection,
  detectTier, refineTierAsync
};
