/**
 * @file starmus-integrator.js
 * @version 4.3.0 (ES Module)
 * @description Master orchestrator and sole entry point for the Starmus app.
 * Fixed Tier C submission bug and fallback initialization timing.
 */

'use strict';

import { CommandBus } from './starmus-hooks.js';
import { createStore } from './starmus-state-store.js';
import { initInstance as initUI } from './starmus-ui.js';
import { initRecorder } from './starmus-recorder.js';
import { initCore } from './starmus-core.js';
import './starmus-tus.js'; 
import { getOfflineQueue } from './starmus-offline.js';

// Initialize offline queue on page load
getOfflineQueue().then(() => {
    console.log('[Starmus] Offline queue initialized');
}).catch(err => {
    console.error('[Starmus] Offline queue init failed:', err);
});

const instances = new Map();

/**
 * Safe navigator property access to prevent crashes on older browsers.
 */
function getDeviceMemory() {
    try {
        return navigator.deviceMemory || null;
    } catch {
        return null;
    }
}

function getHardwareConcurrency() {
    try {
        return navigator.hardwareConcurrency || null;
    } catch {
        return null;
    }
}

function getConnection() {
    try {
        return navigator.connection || navigator.mozConnection || navigator.webkitConnection || null;
    } catch {
        return null;
    }
}

/**
 * Detect device tier for progressive degradation.
 */
function detectTier(env) {
    const caps = env.capabilities || {};
    const network = env.network || {};
    
    // TIER C: Critical failures
    if (!caps.mediaRecorder || !caps.webrtc) {
        return 'C';
    }
    
    const deviceMemory = getDeviceMemory();
    const hardwareConcurrency = getHardwareConcurrency();
    
    if (deviceMemory && deviceMemory < 1) {
        return 'C';
    }
    if (hardwareConcurrency && hardwareConcurrency < 2) {
        return 'C';
    }
    
    // WebView check (unreliable WebRTC implementation on older Android)
    if (/wv|Crosswalk|Android WebView|Opera Mini/i.test(navigator.userAgent)) {
        return 'C';
    }
    
    // TIER B: Degraded mode
    if (network.effectiveType === '2g' || network.effectiveType === 'slow-2g') {
        return 'B';
    }
    if (deviceMemory && deviceMemory < 2) {
        return 'B';
    }
    
    // TIER A: Full features
    return 'A';
}

async function refineTierAsync(initialTier) {
    if (initialTier === 'C') {
        return 'C';
    }
    
    if (navigator.storage && navigator.storage.estimate) {
        try {
            const estimate = await navigator.storage.estimate();
            const quotaMB = (estimate.quota || 0) / 1024 / 1024;
            if (quotaMB < 80) {
                return 'C';
            }
        } catch {
            // Ignore storage estimate failures
        }
    }
    
    if (navigator.permissions && navigator.permissions.query) {
        try {
            const status = await navigator.permissions.query({ name: 'microphone' });
            if (status.state === 'denied') {
                return 'C';
            }
        } catch {
            // Ignore permission query failures
        }
    }
    
    return initialTier;
}

/**
 * Wire a single <form data-starmus="recorder"> into the Starmus system.
 */
async function wireInstance(env, formEl) {
    let instanceId = formEl.getAttribute('data-starmus-id');
    if (!instanceId) {
        instanceId = 'starmus_' + Date.now() + '_' + Math.random().toString(16).slice(2);
        formEl.setAttribute('data-starmus-id', instanceId);
    }

    // GUARD: Prevent double-wiring in SPA/AJAX reloads
    if (instances.has(instanceId)) {
        console.warn(`[Starmus] Instance ${instanceId} already wired, skipping`);
        return instanceId;
    }

    let tier = detectTier(env);
    tier = await refineTierAsync(tier);
    
    console.log(`[Starmus] Instance ${instanceId} detected as Tier ${tier}`);

    const store = createStore({ instanceId, env, tier });

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
        recorderContainer: formEl.querySelector('[data-starmus-recorder-container]'),
        fallbackContainer: formEl.querySelector('[data-starmus-fallback-container]'),
    };

    // --- TIER C UI HANDLING ---
    if (tier === 'C') {
        console.log('[Starmus] Tier C: Revealing fallback, hiding recorder');
        if (elements.recorderContainer) {
            elements.recorderContainer.style.display = 'none';
        }
        if (elements.fallbackContainer) {
            elements.fallbackContainer.style.display = 'block';
        }
        
        if (window.StarmusHooks?.doAction) {
            window.StarmusHooks.doAction('starmus_tier_c_revealed', instanceId, env);
        }
    }

    // --- INITIALIZATION ---
    
    // UI and Core are needed for ALL tiers (including fallback upload)
    initUI(store, elements);
    initCore(store, instanceId, env);

    // Recorder is ONLY for Tier A/B
    if (tier !== 'C') {
        initRecorder(store, instanceId);
    }

    instances.set(instanceId, { store, form: formEl, elements, tier });

    const speechSupported = tier === 'A' ? !!(window.SpeechRecognition || window.webkitSpeechRecognition) : false;
    
    store.dispatch({
        type: 'starmus/init',
        payload: { instanceId, env, tier, speechSupported },
    });

    // --- EVENT LISTENERS ---

    // 1. Continue Button (Step 1 -> Step 2)
    if (elements.continueBtn && elements.step1) {
        elements.continueBtn.addEventListener('click', (event) => {
            event.preventDefault();

            const step1 = elements.step1;
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
        });
    } else if (formEl.dataset.starmusRerecord === 'true') {
        store.dispatch({ type: 'starmus/ui/step-continue' });
    }

    // 2. Recording Controls (Only attach for Tier A/B)
    if (tier !== 'C') {
        if (elements.recordBtn) {
            elements.recordBtn.addEventListener('click', (e) => {
                e.preventDefault();
                CommandBus.dispatch('start-mic', {}, { instanceId });
            });
        }
        if (elements.stopBtn) {
            elements.stopBtn.addEventListener('click', (e) => {
                e.preventDefault();
                CommandBus.dispatch('stop-mic', {}, { instanceId });
            });
        }
    }

    // 3. File Input (Crucial for Tier C Fallback)
    if (elements.fileInput) {
        elements.fileInput.addEventListener('change', () => {
            const file = elements.fileInput.files && elements.fileInput.files[0];
            if (file) {
                CommandBus.dispatch('attach-file', { file }, { instanceId });
            }
        });
    }

    // 4. Submit Handler (Crucial for ALL Tiers)
    formEl.addEventListener('submit', (event) => {
        event.preventDefault();
        const formData = new FormData(formEl);
        const formFields = {};
        formData.forEach((value, key) => {
            formFields[key] = value;
        });

        CommandBus.dispatch('submit', { formFields }, { instanceId });
    });

    // 5. Reset Handler
    if (elements.resetBtn) {
        elements.resetBtn.addEventListener('click', (e) => {
            e.preventDefault();
            CommandBus.dispatch('reset', {}, { instanceId });
        });
    }

    return instanceId;
}

/**
 * Entry point
 */
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

/**
 * Fallback initialization.
 * Removed async Battery calls to ensure synchronous fallback execution.
 */
function initWithFallback() {
    const connection = getConnection();
    const networkInfo = connection ? {
        effectiveType: connection.effectiveType || 'unknown',
        downlink: connection.downlink || null,
        saveData: connection.saveData || false
    } : {
        effectiveType: 'unknown',
        downlink: null,
        saveData: false
    };
    
    const fallbackEnv = {
        browser: {
            userAgent: navigator.userAgent
        },
        device: {
            type: /mobile|android|iphone|ipad/i.test(navigator.userAgent) ? 'mobile' : 'desktop',
            memory: getDeviceMemory(),
            concurrency: getHardwareConcurrency(),
            battery: null // Skip async battery check for fallback to prevent race conditions
        },
        capabilities: {
            webrtc: !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia),
            mediaRecorder: !!(navigator.mediaDevices && window.MediaRecorder),
            indexedDB: !!window.indexedDB,
        },
        network: networkInfo
    };
    
    onEnvironmentReady({ detail: fallbackEnv });
}

// Listen once
let environmentReady = false;
document.addEventListener('sparxstar:environment-ready', (event) => {
    environmentReady = true;
    onEnvironmentReady(event);
}, { once: true });

// Fallback safety net (2s)
setTimeout(() => {
    if (!environmentReady) {
        console.warn('[Starmus] Using fallback initialization');
        initWithFallback();
    }
}, 2000);

// Expose instances map for debugging
if (typeof window !== 'undefined') {
    window.STARMUS = window.STARMUS || {};
    window.STARMUS.instances = instances;
}
