// FILE: starmus-audio-recorder-ui-controller.js (FINAL, PATCHED, WITH LOGGING)
/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * @module  StarmusUIController
 * @version 1.2.2
 * @file    The UI Manager - Linter-clean, secure, and resilient.
 */
(function(window, document) { 
    'use strict';

    const CONFIG = { LOG_PREFIX: '[Starmus UI Controller]' };
    function log(level, msg, data) { if (console && console[level]) { console[level](CONFIG.LOG_PREFIX, msg, data || ''); } }
    function el(id) { return document.getElementById(id); }
    function safeId(id) { return typeof id === 'string' && /^[a-zA-Z0-9_-]{1,100}$/.test(id); }
    const Hooks = window.StarmusHooks;

    function sanitizeText(text) {
        if (typeof text !== 'string') return '';
        return text.replace(/[\x00-\x1F\x7F<>"'&]/g, ' ').substring(0, 500);
    }

    function showUserMessage(instanceId, message, type = 'info') {
        if (!safeId(instanceId)) return;
        const area = el(`starmus_recorder_status_${instanceId}`) || el(`starmus_step1_usermsg_${instanceId}`);
        if (area) {
            area.textContent = sanitizeText(message);
            area.setAttribute('data-status', type);
            area.style.display = message ? 'block' : 'none';
        }
    }

    function updateRecorderUI(instanceId, state) {
        // (Your update logic here)
    }

    function buildRecorderUI(instanceId) {
        // (Your UI building logic here)
    }

    function handleContinueClick(formId) {
        if (!safeId(formId)) return;
        const step1 = el(`starmus_step1_${formId}`);
        const step2 = el(`starmus_step2_${formId}`);

        let allValid = true;
        for (const input of step1.querySelectorAll('[required]')) {
            if (!input.checkValidity()) {
                showUserMessage(formId, `Please complete all required fields.`, 'error');
                if (typeof input.reportValidity === 'function') input.reportValidity();
                allValid = false;
                break;
            }
        }
        if (!allValid) return;

        step1.style.display = 'none';
        step2.style.display = 'block';
        showUserMessage(formId, '', 'info');

        window.StarmusSubmissionsHandler.initRecorder(formId)
            .then(() => {
                buildRecorderUI(formId);
            })
            .catch(err => {
                log('error', 'Recorder init failed, reverting to step 1.', err?.message);
                step1.style.display = 'block';
                step2.style.display = 'none';
            });
    }

    function initializeForm(form) {
        const formId = form.id;
        if (!safeId(formId) || form.getAttribute('data-starmus-bound')) return;
        form.setAttribute('data-starmus-bound', '1');

        const continueBtn = form.querySelector(`#starmus_continue_btn_${formId}`);
        if (continueBtn) {
            continueBtn.addEventListener('click', () => handleContinueClick(formId));
        } else {
            log('error', 'Could not find the continue button for form:', formId);
        }

        form.addEventListener('submit', event => {
            event.preventDefault();
            window.StarmusSubmissionsHandler.handleSubmit(formId, form);
        });
    }

    // --- FINAL, PATCHED INITIALIZATION LOGIC ---

    let uiInitialized = false;

    function init() {
        if (uiInitialized) return;
        uiInitialized = true;

        log('info', 'UI Controller Initializing...');

        if (window.StarmusSubmissionsHandler && typeof window.StarmusSubmissionsHandler.init === 'function') {
            window.StarmusSubmissionsHandler.init();
        }

        const forms = document.querySelectorAll('form.starmus-audio-form');
        forms.forEach(initializeForm);

        Hooks.doAction('starmus_ui_ready');
    }

    Hooks.addAction('starmus_hooks_ready', () => {
        log('info', '✅ Hook "starmus_hooks_ready" was received.');
        init();
    });

    if (Hooks.hasFired && Hooks.hasFired('starmus_hooks_ready')) {
        log('info', '🟡 Hook "starmus_hooks_ready" had already fired. Manually initializing now.');
        init();
    }

    window.StarmusUIController = {
        updateRecorderUI,
        showUserMessage,
        buildRecorderUI
    };

})(window, document);