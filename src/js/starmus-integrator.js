/**
 * @file starmus-integrator.js
 * @version 4.2.0 (ES Module)
 * @description Master orchestrator and sole entry point for the Starmus app.
 * Includes TUS resumable upload support and offline queue integration.
 */

'use strict';

import { CommandBus } from './starmus-hooks.js';
import { createStore } from './starmus-state-store.js';
import { initInstance as initUI } from './starmus-ui.js';
import { initRecorder } from './starmus-recorder.js';
import { initCore } from './starmus-core.js';
import './starmus-tus.js'; // Load TUS integration module
import { getOfflineQueue } from './starmus-offline.js'; // Load offline queue

// Initialize offline queue on page load
getOfflineQueue().then(queue => {
    console.log('[Starmus] Offline queue initialized and ready');
}).catch(err => {
    console.error('[Starmus] Failed to initialize offline queue:', err);
});

const instances = new Map();

/**
 * Detect device tier for progressive degradation.
 * Tier A: Full features (modern device, good network)
 * Tier B: Recording only, no waveform (weak device or slow network)
 * Tier C: File upload fallback only (very old browser or blocked permissions)
 * 
 * @param {object} env - Environment data from UEC or fallback
 * @returns {string} 'A', 'B', or 'C'
 */
function detectTier(env) {
    const caps = env.capabilities || {};
    const network = env.network || {};
    
    // TIER C: Critical failures - disable recorder, show file upload
    
    // 1. Browser limitation - no MediaRecorder support
    if (!caps.mediaRecorder || !caps.webrtc) {
        console.log('[Tier Detection] Tier C: No MediaRecorder support');
        return 'C';
    }
    
    // 2. Hardware weakness - RAM < 1GB or CPU < 2 threads
    const deviceMemory = navigator.deviceMemory; // GB
    const hardwareConcurrency = navigator.hardwareConcurrency; // CPU threads
    
    if (deviceMemory && deviceMemory < 1) {
        console.log('[Tier Detection] Tier C: Low RAM (<1GB)');
        return 'C';
    }
    
    if (hardwareConcurrency && hardwareConcurrency < 2) {
        console.log('[Tier Detection] Tier C: Low CPU (<2 threads)');
        return 'C';
    }
    
    // 3. Suspicious WebView environments (unreliable recorder)
    const ua = navigator.userAgent;
    const isWebView = /wv|Crosswalk|Android WebView|Opera Mini/i.test(ua);
    
    if (isWebView) {
        console.log('[Tier Detection] Tier C: WebView environment detected');
        return 'C';
    }
    
    // TIER B: Degraded mode - recording works but UI is minimal
    
    // Low network quality - 2G/slow-2g
    if (network.effectiveType === '2g' || network.effectiveType === 'slow-2g') {
        console.log('[Tier Detection] Tier B: Slow network (2G)');
        return 'B';
    }
    
    // Marginal RAM (1-2GB) - can record but avoid heavy UI
    if (deviceMemory && deviceMemory < 2) {
        console.log('[Tier Detection] Tier B: Marginal RAM (1-2GB)');
        return 'B';
    }
    
    // TIER A: Full features
    console.log('[Tier Detection] Tier A: Full features enabled');
    return 'A';
}

/**
 * Async tier checks that require permission queries or storage estimates.
 * 
 * @param {string} initialTier - Tier from synchronous detection
 * @returns {Promise<string>} Final tier after async checks
 */
async function refineTierAsync(initialTier) {
    // If already Tier C, no need to check further
    if (initialTier === 'C') {
        return 'C';
    }
    
    // Check storage quota
    if (navigator.storage && navigator.storage.estimate) {
        try {
            const estimate = await navigator.storage.estimate();
            const quotaMB = (estimate.quota || 0) / 1024 / 1024;
            
            if (quotaMB < 80) {
                console.log('[Tier Detection] Tier C: Storage quota too low (<80MB)');
                return 'C';
            }
        } catch (e) {
            console.warn('[Tier Detection] Could not estimate storage:', e);
        }
    }
    
    // Check microphone permission state
    if (navigator.permissions && navigator.permissions.query) {
        try {
            const permissionStatus = await navigator.permissions.query({ name: 'microphone' });
            
            if (permissionStatus.state === 'denied') {
                console.log('[Tier Detection] Tier C: Microphone permission denied');
                return 'C';
            }
        } catch (e) {
            // Some browsers don't support microphone permission query
            console.warn('[Tier Detection] Could not query microphone permission:', e);
        }
    }
    
    return initialTier;
}

/**
 * Wire a single <form data-starmus="recorder"> into the Starmus system.
 *
 * @param {object} env - Environment payload from sparxstar-user-environment-check.
 * @param {HTMLFormElement} formEl
 */
async function wireInstance(env, formEl) {
    let instanceId = formEl.getAttribute('data-starmus-id');
    if (!instanceId) {
        instanceId = 'starmus_' + Date.now() + '_' + Math.random().toString(16).slice(2);
        formEl.setAttribute('data-starmus-id', instanceId);
    }

    // Detect device tier BEFORE initializing heavy components
    let tier = detectTier(env);
    tier = await refineTierAsync(tier);
    
    console.log(`[Starmus] Instance ${instanceId} detected as Tier ${tier}`);

    const store = createStore({
        instanceId,
        env,
        tier,
    });

    const elements = {
        step1: formEl.querySelector('.starmus-step-1'),
        step2: formEl.querySelector('.starmus-step-2'),
        continueBtn:
            formEl.querySelector('[data-starmus-action="continue"]') ||
            formEl.querySelector('.starmus-btn-continue'),
        messageBox:
            formEl.querySelector('[data-starmus-message-box]') ||
            formEl.querySelector('[id^="starmus_step1_usermsg_"]'),
        recordBtn: formEl.querySelector('[data-starmus-action="record"]'),
        stopBtn: formEl.querySelector('[data-starmus-action="stop"]'),
        submitBtn: formEl.querySelector('[data-starmus-action="submit"]'),
        resetBtn: formEl.querySelector('[data-starmus-action="reset"]'),
        fileInput:
            formEl.querySelector('input[type="file"][data-starmus-file]') ||
            formEl.querySelector('input[type="file"]'),
        statusEl: formEl.querySelector('[data-starmus-status]'),
        progressEl: formEl.querySelector('[data-starmus-progress]'),
        recorderContainer: formEl.querySelector('[data-starmus-recorder-container]') || formEl.querySelector('#starmus_recorder_container_' + instanceId),
        fallbackContainer: formEl.querySelector('[data-starmus-fallback-container]') || formEl.querySelector('#starmus_fallback_container_' + instanceId),
    };

    // TIER C: Show file upload fallback, skip recorder initialization
    if (tier === 'C') {
        console.log('[Starmus] Tier C mode: Revealing file upload fallback');
        
        // Hide recorder UI
        if (elements.recorderContainer) {
            elements.recorderContainer.style.display = 'none';
        }
        
        // Show fallback file upload UI
        if (elements.fallbackContainer) {
            elements.fallbackContainer.style.display = 'block';
        }
        
        // Still initialize core (for file uploads) but skip recorder
        initCore(store, instanceId, env);
        initUI(store, elements);
        
        instances.set(instanceId, { store, form: formEl, elements, tier });
        
        const speechSupported = false; // Disable speech recognition in Tier C
        store.dispatch({
            type: 'starmus/init',
            payload: { instanceId, env, tier, speechSupported },
        });
        
        // Hook for telemetry
        if (window.StarmusHooks?.doAction) {
            window.StarmusHooks.doAction('starmus_tier_c_revealed', instanceId, env);
        }
        
        return instanceId;
    }

    // TIER A/B: Initialize full or degraded recorder
    initUI(store, elements);
    initRecorder(store, instanceId);
    initCore(store, instanceId, env);

    instances.set(instanceId, { store, form: formEl, elements, tier });

    const speechSupported = tier === 'A' ? !!(window.SpeechRecognition || window.webkitSpeechRecognition) : false;
    store.dispatch({
        type: 'starmus/init',
        payload: { instanceId, env, tier, speechSupported },
    });

    // Detect if this is a re-recorder (single-step form)
    const isRerecorder = formEl.dataset.starmusRerecord === 'true';

    // --- Step 1 → Step 2 "Continue" button (only for two-step forms) ---
    if (elements.continueBtn && elements.step1 && elements.step2) {
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
    } else if (isRerecorder) {
        // For re-recorder, automatically initialize as if we're on step 2
        store.dispatch({ type: 'starmus/ui/step-continue' });
    }

    // --- Mic buttons via CommandBus ---
    if (elements.recordBtn) {
        elements.recordBtn.addEventListener('click', (event) => {
            event.preventDefault();
            CommandBus.dispatch('start-mic', {}, { instanceId });
        });
    }

    if (elements.stopBtn) {
        elements.stopBtn.addEventListener('click', (event) => {
            event.preventDefault();
            CommandBus.dispatch('stop-mic', {}, { instanceId });
        });
    }

    // --- File attachment ---
    if (elements.fileInput) {
        elements.fileInput.addEventListener('change', () => {
            const file = elements.fileInput.files && elements.fileInput.files[0];
            if (!file) {
                return;
            }
            CommandBus.dispatch('attach-file', { file }, { instanceId });
        });
    }

    // --- Submit handler ---
    formEl.addEventListener('submit', (event) => {
        event.preventDefault();
        const formData = new FormData(formEl);
        const formFields = {};
        formData.forEach((value, key) => {
            formFields[key] = value;
        });

        CommandBus.dispatch('submit', { formFields }, { instanceId });
    });

    // --- Reset handler ---
    if (elements.resetBtn) {
        elements.resetBtn.addEventListener('click', (event) => {
            event.preventDefault();
            CommandBus.dispatch('reset', {}, { instanceId });
        });
    }

    return instanceId;
}

/**
 * Entry point: waits for sparxstar-user-environment-check to fire,
 * then wires all recorder forms on the page.
 * Includes fallback if environment check doesn't fire within 2 seconds.
 */
async function onEnvironmentReady(event) {
    const env = event.detail || {};
    const forms = document.querySelectorAll('form[data-starmus="recorder"]');
    if (!forms || !forms.length) {
        return;
    }
    
    // Wire each form (async due to tier detection)
    for (const formEl of forms) {
        await wireInstance(env, formEl);
    }
}

/**
 * Fallback initialization with complete environment detection.
 * Includes all telemetry for tier detection and adaptive features.
 */
function initWithFallback() {
    const hasMediaRecorder = !!(navigator.mediaDevices && window.MediaRecorder);
    const hasWebRTC = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    
    // Detect network conditions
    const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    const networkInfo = connection ? {
        effectiveType: connection.effectiveType || 'unknown', // '4g', '3g', '2g', 'slow-2g'
        downlink: connection.downlink || null, // Mbps
        rtt: connection.rtt || null, // round-trip time in ms
        saveData: connection.saveData || false
    } : {
        effectiveType: 'unknown',
        downlink: null,
        rtt: null,
        saveData: false
    };
    
    // Complete device telemetry
    const deviceMemory = navigator.deviceMemory || null; // GB
    const hardwareConcurrency = navigator.hardwareConcurrency || null; // CPU threads
    const screenWidth = screen.width || 0;
    const screenHeight = screen.height || 0;
    
    // Battery state (if available)
    let batteryInfo = null;
    if (navigator.getBattery) {
        navigator.getBattery().then(battery => {
            batteryInfo = {
                charging: battery.charging,
                level: battery.level, // 0-1
            };
        }).catch(() => {
            // Battery API not supported
        });
    }
    
    const fallbackEnv = {
        browser: {
            name: navigator.userAgent.includes('Chrome') ? 'Chrome' : 
                   navigator.userAgent.includes('Firefox') ? 'Firefox' :
                   navigator.userAgent.includes('Safari') ? 'Safari' : 'Unknown',
            version: 'unknown',
            userAgent: navigator.userAgent
        },
        device: {
            type: /mobile|android|iphone|ipad|tablet/i.test(navigator.userAgent) ? 'mobile' : 'desktop',
            os: navigator.platform || 'unknown',
            memory: deviceMemory, // GB (null if unavailable)
            concurrency: hardwareConcurrency, // CPU threads (null if unavailable)
            screen: `${screenWidth}x${screenHeight}`,
            battery: batteryInfo,
        },
        capabilities: {
            webrtc: hasWebRTC,
            mediaRecorder: hasMediaRecorder,
            indexedDB: !!window.indexedDB,
            serviceWorker: 'serviceWorker' in navigator,
            permissions: 'permissions' in navigator,
            storage: 'storage' in navigator && 'estimate' in navigator.storage,
        },
        network: networkInfo
    };
    
    onEnvironmentReady({ detail: fallbackEnv });
}

// Listen once – the SparxStar Environment Check plugin dispatches this.
let environmentReady = false;
document.addEventListener('sparxstar:environment-ready', (event) => {
    environmentReady = true;
    onEnvironmentReady(event);
}, { once: true });

// Fallback: if environment check doesn't fire within 2s, initialize anyway
setTimeout(() => {
    if (!environmentReady) {
        console.warn('[Starmus] sparxstar:environment-ready not received, using fallback initialization');
        initWithFallback();
    }
}, 2000);

// Optional: expose instances map for debugging.
if (typeof window !== 'undefined') {
    window.STARMUS = window.STARMUS || {};
    window.STARMUS.instances = instances;
}
