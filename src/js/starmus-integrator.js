/**
 * @file starmus-integrator.js
 * @version 4.3.5‑loader
 * @description Master orchestrator with dynamic recorder loader for Tier‑A/B or Tier‑C fallback.
 */
import './starmus-hooks.js';
  import './starmus-state-store.js';
  import './starmus-ui.js';
  import './starmus-core.js';
  import './starmus-recorder.js';
  import './starmus-tus.js';
  import './starmus-transcript-controller.js';
  import './starmus-offline.js'; 

(function (global) {
  'use strict'; 

  global.Starmus = global.Starmus || {};

  (function () {
    if (global.STARMUS_EDITOR_DATA) {
      global.STARMUS_BOOTSTRAP = global.STARMUS_EDITOR_DATA;
      global.STARMUS_BOOTSTRAP.pageType = 'editor';
    } else if (global.STARMUS_RERECORDER_DATA) {
      global.STARMUS_BOOTSTRAP = global.STARMUS_RERECORDER_DATA;
      global.STARMUS_BOOTSTRAP.pageType = 'rerecorder';
    } else if (global.STARMUS_RECORDER_DATA) {
      global.STARMUS_BOOTSTRAP = global.STARMUS_RECORDER_DATA;
      global.STARMUS_BOOTSTRAP.pageType = 'recorder';
    }
  })();

  global.Starmus_DisableOptionalNodes = true;
  if (global.Starmus_DisableOptionalNodes) {
    var CtxProto = (global.AudioContext || global.webkitAudioContext || {}).prototype;
    if (CtxProto) {
      if (CtxProto.createConstantSource) {
        CtxProto.createConstantSource = function () {
          throw new Error('ConstantSourceNode disabled');
        };
      }
      if (CtxProto.createIIRFilter) {
        CtxProto.createIIRFilter = function () {
          throw new Error('IIRFilterNode disabled');
        };
      }
    }
  }

  (function exposePeaksBridge(g) {
    try {
      if (typeof g.Peaks === 'function' || typeof g.Peaks === 'object') {
        g.Starmus.Peaks = g.Peaks;
        return;
      }
      Object.defineProperty(g, 'Peaks', {
        get: function () {
          return g.Starmus.Peaks;
        },
        set: function (v) {
          g.Starmus.Peaks = v;
        },
        configurable: true
      });
    } catch (e) {
      console.warn('[Starmus] Peaks.js exposure failed:', e);
    }
  })(global);

  var CommandBus = global.StarmusHooks;
  var createStore = global.createStore;
  var initUI = global.initUI;
  var initCore = global.initCore;
  var initOfflineQueue = global.initOffline;

  function checkDependencies() {
    // Re-check globals in case they were set after initial load
    CommandBus = global.StarmusHooks;
    createStore = global.createStore;
    initUI = global.initUI;
    initCore = global.initCore;
    initOfflineQueue = global.initOffline;

    if (!CommandBus || !createStore || !initUI || !initCore || !initOfflineQueue) {
      console.error('[Starmus Integrator] Critical dependencies missing — cannot initialize.');
      console.error('Available:', {
        CommandBus: !!CommandBus,
        createStore: !!createStore,
        initUI: !!initUI,
        initCore: !!initCore,
        initOfflineQueue: !!initOfflineQueue
      });
      return false;
    }
    return true;
  }

  function emitStarmusEventGlobal(event, payload) {
    try {
      if (global.StarmusHooks && typeof global.StarmusHooks.doAction === 'function') {
        global.StarmusHooks.doAction('starmus_event', {
          instanceId: payload.instanceId || null,
          event: event,
          severity: payload.severity || 'info',
          message: payload.message || '',
          data: payload.data || {}
        });
      }
    } catch (e) {
      console.warn('[Starmus] Global telemetry emit failed:', e);
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
    var caps = env.capabilities || {};
    var network = env.network || {};

    if (/\bCrOS\b|Chrome OS/i.test(navigator.userAgent)) {
      return 'A';
    }
    if (!caps.mediaRecorder || !caps.webrtc) {
      return 'C';
    }

    var mem = getDeviceMemory();
    var threads = getHardwareConcurrency();
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
        var est = await navigator.storage.estimate();
        var quotaMB = (est.quota || 0) / 1024 / 1024;
        if (quotaMB && quotaMB < 80) return 'C';
      } catch (_) {}
    }
    if (navigator.permissions && navigator.permissions.query) {
      try {
        var status = await navigator.permissions.query({ name: 'microphone' });
        if (status.state === 'denied') return 'C';
      } catch (_) {}
    }
    return tier;
  }

  function isRecordingSupportedEnv() {
    try {
      var hasGet = !!(navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function');
      var hasMR = typeof global.MediaRecorder === 'function';
      var hasAC = !!(global.AudioContext || global.webkitAudioContext);
      return hasGet && hasMR && hasAC;
    } catch (_) {
      return false;
    }
  }

  global.StarmusApp = global.StarmusApp || {};
  global.StarmusApp.isRecordingSupported = isRecordingSupportedEnv;

  initOfflineQueue().catch(function (e) {
    console.error('[Starmus] Offline queue init failed:', e);
  });

  var instances = {};

  function loadScript(url, onload, onerror) {
    var s = document.createElement('script');
    s.src = url;
    s.async = true;
    s.onload = onload;
    s.onerror = onerror;
    document.head.appendChild(s);
  }

  function wireInstance(env, formEl) {
    // Check dependencies before proceeding
    if (!checkDependencies()) {
      console.warn('[Starmus] Retrying dependency check in 500ms...');
      setTimeout(function() {
        if (checkDependencies()) {
          wireInstance(env, formEl);
        } else {
          console.error('[Starmus] Final dependency check failed - cannot proceed');
        }
      }, 500);
      return;
    }

    var instanceId = formEl.getAttribute('data-starmus-id');
    if (!instanceId) {
      instanceId = 'starmus_' + Date.now() + '_' + Math.random().toString(16).slice(2);
      formEl.setAttribute('data-starmus-id', instanceId);
    }
    if (instances[instanceId]) return instanceId;

    var tier = detectTier(env);
    refineTierAsync(tier).then(function (finalTier) {
      console.log('[Starmus] Instance', instanceId, '-> Tier', finalTier);
      emitStarmusEventGlobal('TIER_ASSIGN', {
        instanceId: instanceId,
        severity: 'info',
        message: 'Tier ' + finalTier + ' assigned',
        data: { tier: finalTier }
      });

      var store = createStore({ instanceId: instanceId, env: env, tier: finalTier });

      // Tier‑ready dispatch, once
      if (!store.getState().tier) {
        store.dispatch({ type: 'starmus/tier-ready', payload: { tier: finalTier } });
      }

      var elements = {
        step1: formEl.querySelector('.starmus-step-1'),
        step2: formEl.querySelector('.starmus-step-2'),
        continueBtn: formEl.querySelector('[data-starmus-action="continue"]'),
        messageBox: formEl.querySelector('[data-starmus-message-box]'),
        setupMicBtn: formEl.querySelector('[data-starmus-action="setup-mic"]'),
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
        fallbackContainer: formEl.querySelector('[data-starmus-fallback-container]')
      };

      initUI(store, elements);
      initCore(store, instanceId, env);

      function loadAppropriateRecorder() {
        var useLegacy = (finalTier === 'C') || !isRecordingSupportedEnv();

        if (useLegacy) {
          if (elements.recorderContainer) elements.recorderContainer.style.display = 'none';
          if (elements.fallbackContainer) elements.fallbackContainer.style.display = 'block';

          loadScript(
            'starmus-recorder-legacy.js',
            function () {
              if (typeof global.initStarmusRecorderLegacy === 'function') {
                global.initStarmusRecorderLegacy(store, instanceId);
              } else {
                store.dispatch({ type: 'starmus/error', payload: { message: 'Legacy recorder not available', retryable: false } });
              }
            },
            function () {
              console.error('[Starmus] Failed to load legacy recorder.');
              store.dispatch({ type: 'starmus/error', payload: { message: 'Legacy recorder load failed', retryable: false } });
            }
          );
        } else {
          loadScript(
            'starmus-recorder.js',
            function () {
              if (typeof global.initStarmusRecorder === 'function') {
                global.initStarmusRecorder(store, instanceId);
              } else {
                console.warn('[Starmus] Recorder init missing — falling back');
                loadScript('starmus-recorder-legacy.js', function () {
                  if (typeof global.initStarmusRecorderLegacy === 'function') {
                    global.initStarmusRecorderLegacy(store, instanceId);
                  } else {
                    store.dispatch({ type: 'starmus/error', payload: { message: 'Recorder init failed', retryable: false } });
                  }
                });
              }
            },
            function () {
              console.warn('[Starmus] Full recorder load failed — falling back to legacy');
              loadScript('starmus-recorder-legacy.js', function () {
                if (typeof global.initStarmusRecorderLegacy === 'function') {
                  global.initStarmusRecorderLegacy(store, instanceId);
                } else {
                  store.dispatch({ type: 'starmus/error', payload: { message: 'Recorder fallback load failed', retryable: false } });
                }
              });
            }
          );
        }
      }

      loadAppropriateRecorder();

      instances[instanceId] = { store: store, form: formEl, elements: elements, tier: finalTier };
    });
  }

  function onEnvReady(event) {
    var env = event.detail || {};
    var forms = document.querySelectorAll('form[data-starmus="recorder"]');
    if (!forms || forms.length === 0) return;
    for (var i = 0; i < forms.length; i++) {
      wireInstance(env, forms[i]);
    }
  }

  function fallbackInit() {
    var conn = getConnectionInfo() || {};
    var fallbackEnv = {
      browser: { userAgent: navigator.userAgent },
      device: {
        type: /mobile|android|iphone|ipad/i.test(navigator.userAgent) ? 'mobile' : 'desktop',
        memory: getDeviceMemory(),
        concurrency: getHardwareConcurrency()
      },
      capabilities: {
        webrtc: !!(navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function'),
        mediaRecorder: !!global.MediaRecorder,
        indexedDB: !!global.indexedDB
      },
      network: {
        effectiveType: conn.effectiveType || 'unknown',
        downlink: conn.downlink || null,
        saveData: conn.saveData || false
      }
    };
    emitStarmusEventGlobal('E_ENV_FALLBACK_INIT', { severity: 'warning', message: 'Fallback env used', data: { fallbackEnv } });
    onEnvReady({ detail: fallbackEnv });
  }

  var envReady = false;
  var fallbackTimer = null;

  document.addEventListener('sparxstar:environment-ready', function (event) {
    envReady = true;
    if (fallbackTimer) {
      clearTimeout(fallbackTimer);
      fallbackTimer = null;
    }
    onEnvReady(event);
  });

  fallbackTimer = setTimeout(function () {
    if (!envReady) {
      console.warn('[Starmus] environment-ready not fired; using fallback.');
      fallbackInit();
    }
  }, 2000);

  global.Starmus.instances = instances;

})(typeof window !== 'undefined' ? window : globalThis);

if (typeof module !== 'undefined' && module.exports) {
  module.exports = global.Starmus;
}