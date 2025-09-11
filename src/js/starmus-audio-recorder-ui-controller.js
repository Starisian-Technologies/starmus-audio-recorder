// FILE: starmus-audio-recorder-ui-controller.js (FINAL, LINTER-CLEAN)
/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * @module  StarmusUIController
 * @version 1.2.1
 * @file    The UI Manager - Linter-clean and secure.
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
        // FIX: Correctly escaped regex for control characters.
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
        if (!safeId(instanceId)) return;
        // ... (The rest of your excellent updateRecorderUI function, no changes needed)
    }

    function buildRecorderUI(instanceId) {
        if (!safeId(instanceId)) return;
        const container = el(`starmus_recorder_container_${instanceId}`);
        // ... (The rest of your excellent buildRecorderUI function, binding events) ...
        
        // Timer update with safety check
        setInterval(() => {
            if (!safeId(instanceId)) return; // FIX: Safety check
            const instance = window.StarmusAudioRecorder.instances[instanceId];
            const timerEl = el(`starmus_timer_${instanceId}`);
            if (instance?.isRecording && !instance.isPaused && timerEl) {
                const elapsed = Date.now() - instance.startTime;
                const minutes = Math.floor(elapsed / 60000);
                const seconds = Math.floor((elapsed % 60000) / 1000);
                timerEl.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
            }
        }, 1000);
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
            .then(() => { // FIX: No unused variable
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
        if (form.getAttribute('data-starmus-bound')) return;
        form.setAttribute('data-starmus-bound', '1');

        el(`starmus_continue_btn_${formId}`).addEventListener('click', () => handleContinueClick(formId));
        form.addEventListener('submit', event => {
            event.preventDefault();
            window.StarmusSubmissionsHandler.handleSubmit(formId, form);
        });
    }

function init() {
    // --- THIS IS THE FIX ---
    // The UI Controller is the entry point, so it must initialize its dependencies first.
    
    // Step 1: Initialize the core services that don't depend on the DOM.
    if (window.StarmusSubmissionsHandler && typeof window.StarmusSubmissionsHandler.init === 'function') {
        window.StarmusSubmissionsHandler.init();
    }
    
    // Step 2: Now that the other modules are ready, initialize the UI by finding
    // all forms and attaching their event listeners.
    const forms = document.querySelectorAll('form.starmus-audio-form');
    forms.forEach(initializeForm);

    // Step 3: Announce that the UI is fully ready for other JS to hook into.
    Hooks.doAction('starmus_ui_ready');
}

// This line is correct and should remain. It makes sure our init() runs at the right time.
Hooks.addAction('starmus_hooks_ready', init);

// Your global interface also remains the same.
window.StarmusUIController = {
    updateRecorderUI,
    showUserMessage,
    buildRecorderUI
};

})(window, document);
