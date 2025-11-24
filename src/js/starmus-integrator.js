/**
 * @file starmus-integrator.js
 * @version 4.3.1
 * @description Master orchestrator. Fixed Tier C submission, Fallback timing, and Playback state sync.
 */

'use strict';

// ============================
// VENDOR LIBRARIES (BUNDLED)
// ============================
import * as tus from 'tus-js-client';
window.tus = tus;

import Peaks from 'peaks.js';
window.Peaks = Peaks;

// ============================
// CORE STARMUS MODULES
// ============================
import { CommandBus } from './starmus-hooks.js';
import { createStore } from './starmus-state-store.js';
import { initInstance as initUI } from './starmus-ui.js';
import { initRecorder } from './starmus-recorder.js';
import { initCore } from './starmus-core.js';
import './starmus-tus.js'; 
import { getOfflineQueue } from './starmus-offline.js';

// Initialize offline queue
getOfflineQueue().then(() => {
    console.log('[Starmus] Offline queue initialized');
}).catch(err => {
    console.error('[Starmus] Offline queue init failed:', err);
});

const instances = new Map();

// Safe navigators
function getDeviceMemory() { try { return navigator.deviceMemory || null; } catch { return null; } }
function getHardwareConcurrency() { try { return navigator.hardwareConcurrency || null; } catch { return null; } }
function _getConnection() { try { return navigator.connection || navigator.mozConnection || navigator.webkitConnection || null; } catch { return null; } }

/**
 * Detect Tier - FIXED FOR CHROMEBOOKS
 */
function detectTier(env) {
    const caps = env.capabilities || {};
    const network = env.network || {};
    
    // 1. CHROMEBOOK OVERRIDE (Fixes "Live recording not supported")
    if (/\bCrOS\b|Chrome OS/i.test(navigator.userAgent)) {
        return 'A';
    }

    // 2. Critical Failures
    if (!caps.mediaRecorder || !caps.webrtc) {return 'C';}
    
    const mem = getDeviceMemory();
    const threads = getHardwareConcurrency();

    // 3. Memory Check
    if (mem && mem < 1) {return 'C';}

    // 4. Thread Check (Relaxed for Chromebooks/Mid-range)
    if (threads) {
        if (threads === 1) {return 'C';}
        if (threads === 2) {return 'B';} // Allow dual-core
    }
    
    // 5. Blocklist
    if (/wv|Crosswalk|Android WebView|Opera Mini/i.test(navigator.userAgent)) {return 'C';}
    
    // 6. Network
    if (network.effectiveType === '2g' || network.effectiveType === 'slow-2g') {return 'B';}

    // 7. Low Memory Degrade
    if (mem && mem < 2) {return 'B';}

    return 'A';
}

async function refineTierAsync(tier) {
    if (tier === 'C') {return 'C';}
    
    // Optional: Check storage quota
    if (navigator.storage && navigator.storage.estimate) {
        try {
            const estimate = await navigator.storage.estimate();
            if ((estimate.quota || 0) / 1024 / 1024 < 80) {return 'C';}
        } catch {
            // Ignore storage estimate failures (not critical)
        }
    }
    
    // Optional: Check Permissions (don't block, just downgrade if denied)
    if (navigator.permissions && navigator.permissions.query) {
        try {
            const status = await navigator.permissions.query({ name: 'microphone' });
            if (status.state === 'denied') {return 'C';}
        } catch {
            // Ignore permission query failures (not supported on all browsers)
        }
    }
    
    return tier;
}

async function wireInstance(env, formEl) {
    let instanceId = formEl.getAttribute('data-starmus-id');
    if (!instanceId) {
        instanceId = 'starmus_' + Date.now() + '_' + Math.random().toString(16).slice(2);
        formEl.setAttribute('data-starmus-id', instanceId);
    }

    if (instances.has(instanceId)) {return instanceId;}

    let tier = detectTier(env);
    tier = await refineTierAsync(tier);
    
    console.log(`[Starmus] Instance ${instanceId} -> Tier ${tier}`);

    const store = createStore({ instanceId, env, tier });

    // DOM SELECTORS (Includes new UI elements)
    const elements = {
        step1: formEl.querySelector('.starmus-step-1'),
        step2: formEl.querySelector('.starmus-step-2'),
        continueBtn: formEl.querySelector('[data-starmus-action="continue"]'),
        messageBox: formEl.querySelector('[data-starmus-message-box]'),
        recordBtn: formEl.querySelector('[data-starmus-action="record"]'),
        stopBtn: formEl.querySelector('[data-starmus-action="stop"]'),
        submitBtn: formEl.querySelector('[data-starmus-action="submit"]'),
        resetBtn: formEl.querySelector('[data-starmus-action="reset"]'),
        fileInput: formEl.querySelector('input[type="file"]'),
        statusEl: formEl.querySelector('[data-starmus-status]'),
        progressEl: formEl.querySelector('[data-starmus-progress]'),
        progressWrap: formEl.querySelector('.starmus-progress-wrap'),
        recorderContainer: formEl.querySelector('[data-starmus-recorder-container]'),
        fallbackContainer: formEl.querySelector('[data-starmus-fallback-container]'),
        
        // New Visualizer & Playback Elements
        timer: formEl.querySelector('[data-starmus-timer]'),
        volumeMeter: formEl.querySelector('[data-starmus-volume-meter]'),
        waveformBox: formEl.querySelector('[data-starmus-waveform]'),
        reviewControls: formEl.querySelector('.starmus-review-controls'),
        playBtn: formEl.querySelector('[data-starmus-action="play"]'),
        transcriptBox: formEl.querySelector('[data-starmus-transcript]'),
    };

    // --- TIER C UI HANDLING ---
    if (tier === 'C') {
        if (elements.recorderContainer) {elements.recorderContainer.style.display = 'none';}
        if (elements.fallbackContainer) {elements.fallbackContainer.style.display = 'block';}
        if (window.StarmusHooks?.doAction) {window.StarmusHooks.doAction('starmus_tier_c_revealed', instanceId, env);}
    }

    // --- INIT ---
    initUI(store, elements);
    initCore(store, instanceId, env);
    if (tier !== 'C') {initRecorder(store, instanceId);}

    instances.set(instanceId, { store, form: formEl, elements, tier });

    const speechSupported = tier === 'A' ? !!(window.SpeechRecognition || window.webkitSpeechRecognition) : false;
    store.dispatch({ type: 'starmus/init', payload: { instanceId, env, tier, speechSupported } });

    // --- EVENT LISTENERS ---

    if (elements.continueBtn) {
        elements.continueBtn.addEventListener('click', (e) => {
            e.preventDefault();
            // Basic validation checks... (Shortened for brevity, assumes HTML5 validation handles most)
            const step1 = elements.step1;
            const title = step1.querySelector('[name="starmus_title"]');
            if (!title || !title.value.trim()) {
                if(elements.messageBox) { elements.messageBox.textContent = "Missing Title"; elements.messageBox.style.display = 'block'; }
                return;
            }
            store.dispatch({ type: 'starmus/ui/step-continue' });
        });
    }
    
    if (formEl.dataset.starmusRerecord === 'true') {
        store.dispatch({ type: 'starmus/ui/step-continue' });
    }

    if (tier !== 'C') {
        if (elements.recordBtn) {elements.recordBtn.addEventListener('click', (e) => { e.preventDefault(); CommandBus.dispatch('start-mic', {}, { instanceId }); });}
        if (elements.stopBtn) {elements.stopBtn.addEventListener('click', (e) => { e.preventDefault(); CommandBus.dispatch('stop-mic', {}, { instanceId }); });}
    }

    if (elements.fileInput) {elements.fileInput.addEventListener('change', () => { if(elements.fileInput.files[0]) {CommandBus.dispatch('attach-file', { file: elements.fileInput.files[0] }, { instanceId });} });}

    formEl.addEventListener('submit', (e) => {
        e.preventDefault();
        const formData = new FormData(formEl);
        const formFields = {};
        formData.forEach((value, key) => { formFields[key] = value; });
        CommandBus.dispatch('submit', { formFields }, { instanceId });
    });

    if (elements.resetBtn) {elements.resetBtn.addEventListener('click', (e) => { e.preventDefault(); CommandBus.dispatch('reset', {}, { instanceId }); });}

    // --- PLAYBACK LOGIC (Fixed to sync with Store) ---
    let audioEl = null;
    let audioUrl = null;

    if (elements.playBtn) {
        elements.playBtn.addEventListener('click', (e) => {
            e.preventDefault();

            const state = store.getState();
            const { source, recorder } = state;
            const blob = source.blob || source.file; 

            if (!blob) {return;}

            if (!audioEl) {
                audioEl = new Audio();
                audioUrl = URL.createObjectURL(blob);
                audioEl.src = audioUrl;
                
                audioEl.addEventListener('ended', () => {
                    store.dispatch({ type: 'starmus/recorder-playback-state', isPlaying: false });
                });
            }

            if (recorder.isPlaying) {
                audioEl.pause();
                store.dispatch({ type: 'starmus/recorder-playback-state', isPlaying: false });
            } else {
                audioEl.play().then(() => {
                    store.dispatch({ type: 'starmus/recorder-playback-state', isPlaying: true });
                }).catch(err => {
                    console.error('[Starmus] Playback failed:', err);
                });
            }
        });
    }

    // Cleanup audio on reset
    CommandBus.subscribe('reset', (_p, meta) => {
        if (meta.instanceId === instanceId && audioEl) {
            audioEl.pause();
            audioEl = null;
            if (audioUrl) {URL.revokeObjectURL(audioUrl);}
        }
    });

    return instanceId;
}

/**
 * Entry point
 */
async function onEnvironmentReady(event) {
    const env = event.detail || {};
    const forms = document.querySelectorAll('form[data-starmus="recorder"]');
    for (const formEl of forms) {
        await wireInstance(env, formEl);
    }
}

function initWithFallback() {
    const fallbackEnv = {
        capabilities: { webrtc: !!(navigator.mediaDevices && window.MediaRecorder) },
        network: { effectiveType: 'unknown' },
        device: { memory: getDeviceMemory(), concurrency: getHardwareConcurrency() }
    };
    onEnvironmentReady({ detail: fallbackEnv });
}

let environmentReady = false;
let fallbackTimer = null;

document.addEventListener('sparxstar:environment-ready', (event) => {
    environmentReady = true;
    if (fallbackTimer) {clearTimeout(fallbackTimer);}
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
}