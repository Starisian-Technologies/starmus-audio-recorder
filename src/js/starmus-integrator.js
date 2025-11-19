/**
 * @file starmus-integrator.js
 * @version 4.0.0 (ES Module)
 * @description Master orchestrator and sole entry point for the Starmus app.
 */

'use strict';

import { CommandBus } from './starmus-hooks.js';
import { createStore } from './starmus-state-store.js';
import { initInstance as initUI } from './starmus-ui.js';
import { initRecorder } from './starmus-recorder.js';
import { initCore } from './starmus-core.js';

const instances = new Map();

/**
 * Wire a single <form data-starmus="recorder"> into the Starmus system.
 *
 * @param {object} env - Environment payload from sparxstar-user-environment-check.
 * @param {HTMLFormElement} formEl
 */
function wireInstance(env, formEl) {
    let instanceId = formEl.getAttribute('data-starmus-id');
    if (!instanceId) {
        instanceId = 'starmus_' + Date.now() + '_' + Math.random().toString(16).slice(2);
        formEl.setAttribute('data-starmus-id', instanceId);
    }

    const store = createStore({
        instanceId,
        env,
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
    };

    // Wire UI + recorder + core
    initUI(store, elements);
    initRecorder(store, instanceId);
    initCore(store, instanceId, env);

    instances.set(instanceId, { store, form: formEl, elements });

    const speechSupported = !!(window.SpeechRecognition || window.webkitSpeechRecognition);
    store.dispatch({
        type: 'starmus/init',
        payload: { instanceId, env: { ...env, speechSupported } },
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
 */
function onEnvironmentReady(event) {
    const env = event.detail || {};
    const forms = document.querySelectorAll('form[data-starmus="recorder"]');
    if (!forms || !forms.length) {
        return;
    }
    Array.prototype.forEach.call(forms, (formEl) => {
        wireInstance(env, formEl);
    });
}

// Listen once – the SparxStar Environment Check plugin dispatches this.
document.addEventListener('sparxstar:environment-ready', onEnvironmentReady, { once: true });

// Optional: expose instances map for debugging.
if (typeof window !== 'undefined') {
    window.STARMUS = window.STARMUS || {};
    window.STARMUS.instances = instances;
}
