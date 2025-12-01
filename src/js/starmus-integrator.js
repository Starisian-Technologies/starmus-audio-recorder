/**
 * @file starmus-integrator.js
 * @version 4.3.2
 * @description Master orchestrator. Tier detection (Chromebook-safe), fallback init, playback wiring.
 */

'use strict';

// BOOTSTRAP ADAPTER: Normalize editor/recorder/re-recorder data into single contract
(function() {
    // Detect page type and assign to unified bootstrap object
    if (window.STARMUS_EDITOR_DATA) {
        window.STARMUS_BOOTSTRAP = window.STARMUS_EDITOR_DATA;
        window.STARMUS_BOOTSTRAP.pageType = 'editor';
    }
    else if (window.STARMUS_RERECORDER_DATA) {
        window.STARMUS_BOOTSTRAP = window.STARMUS_RERECORDER_DATA;
        window.STARMUS_BOOTSTRAP.pageType = 'rerecorder';
    }
    else if (window.STARMUS_RECORDER_DATA) {
        window.STARMUS_BOOTSTRAP = window.STARMUS_RECORDER_DATA;
        window.STARMUS_BOOTSTRAP.pageType = 'recorder';
    }
})();

// PATCH 4: Disable optional audio nodes for cross-browser stability
window.Starmus_DisableOptionalNodes = true;

if (window.Starmus_DisableOptionalNodes) {
    // Hard block optional effects to avoid cross-browser crashes
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

// VENDORS
import * as tus from 'tus-js-client';
window.tus = tus;

import Peaks from 'peaks.js';

// AFTER: import Peaks from 'peaks.js';
window.Peaks = Peaks;

// ADD THIS LINE RIGHT BELOW IT
window.PeaksVersion = Peaks.prototype ? 'loaded' : 'missing';

// CORE
import { CommandBus } from './starmus-hooks.js';
import { createStore } from './starmus-state-store.js';
import { initInstance as initUI } from './starmus-ui.js';
import { initRecorder } from './starmus-recorder.js';
import { initCore } from './starmus-core.js';
import './starmus-tus.js';
import './starmus-transcript-controller.js';
import { getOfflineQueue } from './starmus-offline.js';
// Starmus Audio Editor (Peaks.js + annotation manager)
import './starmus-audio-editor.js';

/**
 * Emit global telemetry events via StarmusHooks.
 */
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

/**
 * PATCH 1: Unified AudioContext resume helper
 * Ensures AudioContext is running before any recording/calibration operation.
 */
async function starmusEnsureContext(ctx) {
    if (!ctx) {
        return;
    }
    if (ctx.state === 'suspended') {
        try {
            await ctx.resume();
            console.log('[Starmus] AudioContext resumed');
        } catch {
            console.warn('[Starmus] Failed to resume AudioContext');
        }
    }
}

getOfflineQueue()
    .then(() => console.log('[Starmus] Offline queue initialized'))
    .catch((err) => console.error('[Starmus] Offline queue init failed:', err));

// PATCH 7: Signal to SparxstarUEC that Starmus is present
window.SPARXSTAR = window.SPARXSTAR || {};
window.SPARXSTAR.StarmusReady = true;

const instances = new Map();

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

/**
 * Detect Tier - Chromebook-safe.
 */
function detectTier(env) {
    const caps = env.capabilities || {};
    const network = env.network || {};

    // Chromebook override
    if (/\bCrOS\b|Chrome OS/i.test(navigator.userAgent)) {
        return 'A';
    }

    if (!caps.mediaRecorder || !caps.webrtc) {
        return 'C';
    }

    const mem = getDeviceMemory();
    const threads = getHardwareConcurrency();

    if (mem && mem < 1) {
        return 'C';
    }

    if (threads) {
        if (threads === 1) {
            return 'C';
        }
        if (threads === 2) {
            return 'B';
        }
    }

    if (/wv|Crosswalk|Android WebView|Opera Mini/i.test(navigator.userAgent)) {
        return 'C';
    }

    if (network.effectiveType === '2g' || network.effectiveType === 'slow-2g') {
        return 'B';
    }

    if (mem && mem < 2) {
        return 'B';
    }

    return 'A';
}

async function refineTierAsync(tier) {
    if (tier === 'C') {
        return 'C';
    }

    if (navigator.storage && navigator.storage.estimate) {
        try {
            const estimate = await navigator.storage.estimate();
            const quotaMB = (estimate.quota || 0) / 1024 / 1024;
            if (quotaMB && quotaMB < 80) {
                return 'C';
            }
        } catch {
            // Storage API not available or failed
        }
    }

    if (navigator.permissions && navigator.permissions.query) {
        try {
            const status = await navigator.permissions.query({ name: 'microphone' });
            if (status.state === 'denied') {
                return 'C';
            }
        } catch {
            // Permissions API not available or failed
        }
    }

    return tier;
}

/**
 * PATCH 6: Utility to check if recording is actually supported
 * Exposed globally for SparxstarUEC tier detection override.
 */
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

// Expose for SparxstarUEC
window.StarmusApp = window.StarmusApp || {};
window.StarmusApp.isRecordingSupported = isRecordingSupported;

async function wireInstance(env, formEl) {
    let instanceId = formEl.getAttribute('data-starmus-id');
    if (!instanceId) {
        instanceId =
            'starmus_' +
            Date.now() +
            '_' +
            Math.random().toString(16).slice(2);
        formEl.setAttribute('data-starmus-id', instanceId);
    }

    if (instances.has(instanceId)) {
        return instanceId;
    }

    let tier = detectTier(env);
    tier = await refineTierAsync(tier);

    console.log(`[Starmus] Instance ${instanceId} -> Tier ${tier}`);
    
    emitStarmusEventGlobal('TIER_ASSIGN', {
        instanceId,
        severity: 'info',
        message: `Tier ${tier} assigned`,
        data: { tier }
    });

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

        // New UI elements
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
        if (elements.recorderContainer) {
            elements.recorderContainer.style.display = 'none';
        }
        if (elements.fallbackContainer) {
            elements.fallbackContainer.style.display = 'block';
        }
        if (window.StarmusHooks && window.StarmusHooks.doAction) {
            window.StarmusHooks.doAction('starmus_tier_c_revealed', instanceId, env);
        }
    }

    initUI(store, elements);
    initCore(store, instanceId, env);
    if (tier !== 'C') {
        initRecorder(store, instanceId);
    }

    instances.set(instanceId, { store, form: formEl, elements, tier });

    // PATCH 10: Subscribe to tier changes - but be defensive about downgrades
    // Only downgrade if explicitly set by unrecoverable errors, not transient failures
    store.subscribe(() => {
        const state = store.getState();
        // Only downgrade if state.fallbackActive is explicitly true AND tier is C
        // This prevents automatic downgrades from calibration or audio graph issues
        if (state.tier === 'C' && tier !== 'C' && state.fallbackActive === true) {
            // Runtime tier downgrade - show fallback UI
            const previousTier = tier;
            tier = 'C';
            
            if (elements.recorderContainer) {
                elements.recorderContainer.style.display = 'none';
            }
            if (elements.fallbackContainer) {
                elements.fallbackContainer.style.display = 'block';
            }
            
            console.log(`[Starmus] Instance ${instanceId} downgraded to Tier C`);
            
            // Emit telemetry for runtime tier downgrade
            emitStarmusEventGlobal('TIER_DOWNGRADE', {
                instanceId,
                severity: 'warning',
                message: `Runtime tier downgrade from ${previousTier} to C`,
                data: { previousTier, currentTier: 'C', reason: 'audio_graph_failure' }
            });
        }
    });

    const speechSupported =
        tier === 'A'
            ? !!(window.SpeechRecognition || window.webkitSpeechRecognition)
            : false;

    store.dispatch({
        type: 'starmus/init',
        payload: { instanceId, env, tier, speechSupported }
    });

    // CONTINUE (Step 1 -> Step 2) with full validation
    if (elements.continueBtn) {
        elements.continueBtn.addEventListener('click', (e) => {
            e.preventDefault();

            const step1 = elements.step1;
            if (!step1) {
                return;
            }

            const title = step1.querySelector('[name="starmus_title"]');
            const lang = step1.querySelector('[name="starmus_language"]');
            const type = step1.querySelector('[name="starmus_recording_type"]');
            const consent = step1.querySelector('[name="agreement_to_terms"]');
            const msgEl = elements.messageBox;

            const missing = [];
            if (!title || !title.value.trim()) {
                missing.push('Title');
            }
            if (!lang || !lang.value.trim()) {
                missing.push('Language');
            }
            if (!type || !type.value.trim()) {
                missing.push('Recording Type');
            }
            if (!consent || !consent.checked) {
                missing.push('Consent');
            }

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

            store.dispatch({ type: 'starmus/ui/step-continue' });

            // === IMMERSIVE MOBILE MODE ===
            if (window.innerWidth < 768) {
                const formContainer = formEl.closest('.starmus-recorder-form') || formEl;
                
                // Add CSS Classes for fullscreen mode
                formContainer.classList.add('starmus-immersive');
                document.body.classList.add('starmus-lock-scroll');

                // Push state to browser history (enables back button handling)
                history.pushState(
                    { starmusMode: 'immersive', instanceId: instanceId }, 
                    '', 
                    '#recording-mode'
                );

                // Handle Back Button
                const handlePopState = () => {
                    formContainer.classList.remove('starmus-immersive');
                    document.body.classList.remove('starmus-lock-scroll');
                    
                    // Remove close button if exists
                    const closeBtn = formContainer.querySelector('.starmus-close-immersive');
                    if (closeBtn) {
                        closeBtn.remove();
                    }

                    window.removeEventListener('popstate', handlePopState);
                };

                window.addEventListener('popstate', handlePopState);

                // Create floating close button
                if (!formContainer.querySelector('.starmus-close-immersive')) {
                    const closeBtn = document.createElement('button');
                    closeBtn.innerHTML = '&times;';
                    closeBtn.className = 'starmus-close-immersive';
                    closeBtn.setAttribute('aria-label', 'Close fullscreen mode');
                    
                    closeBtn.onclick = (e) => {
                        e.preventDefault();
                        history.back(); // Triggers the popstate event
                    };
                    
                    formContainer.style.position = 'relative';
                    formContainer.insertBefore(closeBtn, formContainer.firstChild);
                }
            }
        });
    }

    if (formEl.dataset.starmusRerecord === 'true') {
        store.dispatch({ type: 'starmus/ui/step-continue' });
    }

    // Recording controls
    if (tier !== 'C') {
        // Setup microphone (calibration)
        if (elements.setupMicBtn) {
            elements.setupMicBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                // PATCH 1: Ensure AudioContext is running
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
                // PATCH 1: Ensure AudioContext is running
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

    // Tier C / file-upload attach
    if (elements.fileInput) {
        elements.fileInput.addEventListener('change', () => {
            const file = elements.fileInput.files && elements.fileInput.files[0];
            if (file) {
                CommandBus.dispatch('attach-file', { file }, { instanceId });
            }
        });
    }

    // Submit
    formEl.addEventListener('submit', (e) => {
        e.preventDefault();
        const formData = new FormData(formEl);
        const formFields = {};
        formData.forEach((value, key) => {
            formFields[key] = value;
        });
        CommandBus.dispatch('submit', { formFields }, { instanceId });
    });

    // Reset
    if (elements.resetBtn) {
        elements.resetBtn.addEventListener('click', (e) => {
            e.preventDefault();
            store.dispatch({ type: 'starmus/reset' });
            CommandBus.dispatch('reset', {}, { instanceId });
        });
    }

    // Playback wiring (simple <audio> element, no Peaks for initial capture)
    let audioEl = null;
    let audioUrl = null;

    if (elements.playBtn) {
        elements.playBtn.addEventListener('click', (e) => {
            e.preventDefault();

            const state = store.getState();
            const source = state.source || {};
            const recorder = state.recorder || {};
            const blob = source.blob || source.file;

            if (!blob) {
                return;
            }

            if (!audioEl) {
                audioEl = new Audio();
                audioUrl = URL.createObjectURL(blob);
                audioEl.src = audioUrl;

                audioEl.addEventListener('ended', () => {
                    store.dispatch({
                        type: 'starmus/recorder-playback-state',
                        payload: { isPlaying: false },
                        isPlaying: false
                    });
                });
            }

            if (recorder.isPlaying) {
                audioEl.pause();
                store.dispatch({
                    type: 'starmus/recorder-playback-state',
                    payload: { isPlaying: false },
                    isPlaying: false
                });
            } else {
                audioEl
                    .play()
                    .then(() => {
                        store.dispatch({
                            type: 'starmus/recorder-playback-state',
                            payload: { isPlaying: true },
                            isPlaying: true
                        });
                    })
                    .catch((err) => {
                        console.error('[Starmus] Playback failed:', err);
                    });
            }
        });
    }

    // Cleanup audio on reset
    CommandBus.subscribe('reset', (_payload, meta) => {
        if (meta.instanceId !== instanceId) {
            return;
        }
        if (audioEl) {
            try {
                audioEl.pause();
            } catch {
                // Ignore playback errors during cleanup
            }
            audioEl = null;
        }
        if (audioUrl) {
            URL.revokeObjectURL(audioUrl);
            audioUrl = null;
        }
    });

    return instanceId;
}

// Entry point
async function onEnvironmentReady(event) {
    const env = event.detail || {};
    const forms = document.querySelectorAll('form[data-starmus="recorder"]');
    if (!forms || !forms.length) {
        return;
    }

    for (const formEl of forms) {
        await wireInstance(env, formEl);
    }
}

function initWithFallback() {
    const connection = getConnection();
    const networkInfo = connection
        ? {
              effectiveType: connection.effectiveType || 'unknown',
              downlink: connection.downlink || null,
              saveData: connection.saveData || false
          }
        : { effectiveType: 'unknown', downlink: null, saveData: false };

    const fallbackEnv = {
        browser: { userAgent: navigator.userAgent },
        device: {
            type: /mobile|android|iphone|ipad/i.test(navigator.userAgent)
                ? 'mobile'
                : 'desktop',
            memory: getDeviceMemory(),
            concurrency: getHardwareConcurrency()
        },
        capabilities: {
            webrtc: !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia),
            mediaRecorder: !!(navigator.mediaDevices && window.MediaRecorder),
            indexedDB: !!window.indexedDB
        },
        network: networkInfo
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
    
    // Expose for console testing, cross-plugin communication, and telemetry
    window.StarmusHooks = window.StarmusHooks || { doAction: CommandBus.dispatch };
    window.CommandBus = CommandBus;
}
