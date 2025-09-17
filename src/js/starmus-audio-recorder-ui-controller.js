// FILE: starmus-audio-recorder-ui-controller.js (FINAL, PATCHED, WITH LOGGING)
/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * @module        StarmusUIController
 * @version       0.4.5
 * @since         0.1.0
 * @file          The UI Controller - starmus-audio-recorder-ui-controller.js (FINAL, PATCHED)
 */
(function(window, document) {
    'use strict';

    const CONFIG = { LOG_PREFIX: '[Starmus UI Controller]' };
    function log(level, msg, data) { if (console && console[level]) { console[level](CONFIG.LOG_PREFIX, msg, data || ''); } }

    // DEBUG PATCH: Add a visible log and alert to confirm initialization
    function debugInitBanner() {
        log('info', '[Starmus UI Controller] debugInitBanner called. window.isStarmusAdmin:', window.isStarmusAdmin);
        if (!window.isStarmusAdmin) return;
        const banner = document.createElement('div');
        banner.textContent = '[Starmus UI Controller] JS Initialized';
        banner.style.position = 'fixed';
        banner.style.top = '0';
        banner.style.left = '0';
        banner.style.zIndex = '99999';
        banner.style.background = '#222';
        banner.style.color = '#fff';
        banner.style.padding = '4px 12px';
        banner.style.fontSize = '14px';
        banner.style.fontFamily = 'monospace';
        banner.style.opacity = '0.95';
        document.body.appendChild(banner);
        setTimeout(() => banner.remove(), 4000);
        log('info', 'DEBUG: UI Controller banner shown');
    }
    function el(id) {
        log('debug', 'el() called for id:', id);
        return document.getElementById(id);
    }
    function safeId(id) { return typeof id === 'string' && /^[a-zA-Z0-9_-]{1,100}$/.test(id); }
    const Hooks = window.StarmusHooks;

    function sanitizeText(text) {
        if (typeof text !== 'string') return '';
        // eslint-disable-next-line no-control-regex
        return text.replace(/[\x00-\x1F\x7F<>"'&]/g, ' ').substring(0, 500);
    }

    function showUserMessage(instanceId, message, type = 'info') {
        log('debug', 'showUserMessage called', { instanceId, message, type });
        if (!safeId(instanceId)) {
            log('warn', 'showUserMessage: unsafe instanceId', instanceId);
            return;
        }
        const area = el(`starmus_recorder_status_${instanceId}`) || el(`starmus_step1_usermsg_${instanceId}`);
        if (area) {
            area.textContent = sanitizeText(message);
            area.setAttribute('data-status', type);
            area.style.display = message ? 'block' : 'none';
            log('debug', 'showUserMessage: updated area', area.id);
        } else {
            log('warn', 'showUserMessage: area not found for', instanceId);
        }
    }

    function updateRecorderUI(_instanceId, _state) { /* ... update logic ... */ }
    // --- THIS IS THE FINAL PIECE OF THE PUZZLE ---
    function startTimer(instanceId) {
        setInterval(() => {
            if (!safeId(instanceId)) return;
            const instance = window.StarmusAudioRecorder?._instances?.[instanceId];
            const timerEl = el(`starmus_timer_${instanceId}`);
            if (instance?.isRecording && !instance.isPaused && timerEl) {
                const elapsed = Date.now() - instance.startTime;
                const minutes = Math.floor(elapsed / 60000);
                const seconds = Math.floor((elapsed % 60000) / 1000);
                timerEl.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
            }
        }, 1000);
    }

    function buildRecorderUI(instanceId) {
        log('info', 'buildRecorderUI called for', instanceId);
        if (!safeId(instanceId)) return;
        const container = el(`starmus_recorder_container_${instanceId}`);
        if (!container) {
            log('error', 'Recorder container not found for instance:', instanceId);
            return;
        }

        const recorderHTML = `
            <div class="starmus-recorder-controls">
                <button type="button" id="starmus_record_btn_${instanceId}" class="starmus-btn starmus-btn--record">Record</button>
                <button type="button" id="starmus_stop_btn_${instanceId}" class="starmus-btn starmus-btn--stop" style="display:none;">Stop</button>
                <button type="button" id="starmus_pause_btn_${instanceId}" class="starmus-btn starmus-btn--pause" style="display:none;">Pause</button>
                <div id="starmus_timer_${instanceId}" class="starmus-recorder-timer">00:00</div>
            </div>
            <div class="starmus-recorder-status" id="starmus_recorder_status_${instanceId}">Ready to record.</div>
            <div class="starmus-volume-meter">
                <div id="starmus_volume_level_${instanceId}" class="starmus-volume-level"></div>
            </div>
        `;

        container.innerHTML = recorderHTML;
        log('debug', 'Recorder HTML inserted for', instanceId);

        const recordBtn = el(`starmus_record_btn_${instanceId}`);
        const stopBtn = el(`starmus_stop_btn_${instanceId}`);
        const pauseBtn = el(`starmus_pause_btn_${instanceId}`);
        const submitBtn = document.querySelector(`#${instanceId} #starmus_submit_btn_${instanceId}`);
        const statusArea = el(`starmus_recorder_status_${instanceId}`);

        if (!recordBtn || !stopBtn || !pauseBtn || !submitBtn || !statusArea) {
            log('error', 'One or more recorder UI elements could not be found after creation.');
            return;
        }

        recordBtn.addEventListener('click', () => {
            log('info', 'Record button clicked');
            window.StarmusAudioRecorder.startRecording(instanceId);
            window.StarmusAudioRecorder.startVolumeMonitoring(instanceId, (volume) => {
                const volumeEl = el(`starmus_volume_level_${instanceId}`);
                if (volumeEl) volumeEl.style.width = `${volume}%`;
            });
            recordBtn.style.display = 'none';
            stopBtn.style.display = 'inline-block';
            pauseBtn.style.display = 'inline-block';
            submitBtn.disabled = true;
            statusArea.textContent = 'Recording...';
            startTimer(instanceId);
        });

        stopBtn.addEventListener('click', () => {
            log('info', 'Stop button clicked');
            window.StarmusAudioRecorder.stopRecording(instanceId);
            stopBtn.style.display = 'none';
            pauseBtn.style.display = 'none';
            recordBtn.textContent = 'Record Again';
            recordBtn.style.display = 'inline-block';
            submitBtn.disabled = false;
            statusArea.textContent = 'Recording finished. Ready to submit.';
        });

        pauseBtn.addEventListener('click', () => {
            log('info', 'Pause/Resume button clicked');
            window.StarmusAudioRecorder.togglePause(instanceId);
            const isPaused = window.StarmusAudioRecorder?._instances?.[instanceId]?.isPaused || false;
            pauseBtn.textContent = isPaused ? 'Resume' : 'Pause';
            statusArea.textContent = isPaused ? 'Paused.' : 'Recording...';
        });
    }

    function handleContinueClick(formId) {
        log('info', 'handleContinueClick called for formId:', formId);
        if (!safeId(formId)) {
            log('warn', 'handleContinueClick: unsafe formId', formId);
            return;
        }
        const _form = el(formId);
        const step1 = el(`starmus_step1_${formId}`);
        const step2 = el(`starmus_step2_${formId}`);
        if (!_form || !step1 || !step2) {
            log('error', 'handleContinueClick: missing form or step elements', { _form, step1, step2 });
            return;
        }
        let allValid = true;
        for (const input of step1.querySelectorAll('[required]')) {
            log('debug', 'Checking required input', input.name, input.value);
            if (!input.checkValidity()) {
                showUserMessage(formId, `Please complete all required fields.`, 'error');
                if (typeof input.reportValidity === 'function') input.reportValidity();
                allValid = false;
                break;
            }
        }
        if (!allValid) {
            log('info', 'handleContinueClick: not all fields valid');
            return;
        }
        log('info', 'handleContinueClick: all fields valid, switching to step 2');
        step1.style.display = 'none';
        step2.style.display = 'block';
        showUserMessage(formId, '', 'info');
        const recorderInit = window.StarmusSubmissionsHandler.initRecorder(formId);
        log('debug', '[handleContinueClick] initRecorder returned:', recorderInit);
        if (recorderInit && typeof recorderInit.then === 'function') {
            recorderInit.then(() => {
                log('info', 'Recorder initialized, building UI for', formId);
                buildRecorderUI(formId);
            })
            .catch(err => {
                log('error', 'Recorder init failed, reverting to step 1.', err?.message);
                showUserMessage(formId, 'Unable to initialize recorder. Please try again.', 'error');
                step1.style.display = 'block';
                step2.style.display = 'none';
            });
        } else {
            log('error', '[handleContinueClick] initRecorder did not return a Promise. Value:', recorderInit);
            showUserMessage(formId, 'Unable to initialize recorder. Please reload the page or contact support.', 'error');
            step1.style.display = 'block';
            step2.style.display = 'none';
        }
    }

    function initializeForm(form) {
        const formId = form.id;
        log('info', 'initializeForm called for', formId, 'form:', form);
        if (!safeId(formId)) {
            log('warn', 'initializeForm: unsafe formId', formId);
            return;
        }
        if (form.getAttribute('data-starmus-bound')) {
            log('info', 'initializeForm: already bound', formId);
            return;
        }
        form.setAttribute('data-starmus-bound', '1');
        const continueBtn = form.querySelector(`#starmus_continue_btn_${formId}`);
        if (continueBtn) {
            log('info', 'initializeForm: found continue button', continueBtn.id, continueBtn);
            continueBtn.addEventListener('click', () => {
                log('info', 'Continue button clicked, system snapshot:', {
                    formId,
                    form,
                    location: window.location.href,
                    isStarmusAdmin: window.isStarmusAdmin,
                    documentReady: document.readyState,
                    scripts: Array.from(document.scripts).map(s => s.src),
                    forms: Array.from(document.querySelectorAll('form.starmus-audio-form')).map(f => f.id),
                    time: new Date().toISOString()
                });
                handleContinueClick(formId);
            });
        } else {
            log('error', 'CRITICAL: Could not find the continue button for form:', formId);
        }
        form.addEventListener('submit', event => {
            log('info', 'Form submit event for', formId);
            event.preventDefault();
            window.StarmusSubmissionsHandler.handleSubmit(formId, form);
        });
    }

    // --- FINAL, PATCHED INITIALIZATION LOGIC ---
    let uiInitialized = false;

    function init() {
        if (uiInitialized) {
            log('info', 'init: already initialized');
            return;
        }
        uiInitialized = true;
        log('info', 'UI Controller Initializing...');
        debugInitBanner();

        if (window.StarmusSubmissionsHandler && typeof window.StarmusSubmissionsHandler.init === 'function') {
            log('info', 'Calling StarmusSubmissionsHandler.init()');
            window.StarmusSubmissionsHandler.init();
        } else {
            log('warn', 'StarmusSubmissionsHandler not found or missing init');
        }
        const forms = document.querySelectorAll('form.starmus-audio-form');
        log('info', 'Found forms:', Array.from(forms).map(f => f.id));
        forms.forEach(form => {
            log('info', 'Initializing form:', form.id);
            initializeForm(form);
        });
        if (Hooks) {
            log('info', 'Calling Hooks.doAction(starmus_ui_ready)');
            Hooks.doAction('starmus_ui_ready');
        }
    }


    // SIMPLIFIED: Always initialize on DOMContentLoaded or immediately if DOM is ready
    log('info', '[Starmus UI Controller] Top-level script loaded. document.readyState:', document.readyState);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            log('info', '[Starmus UI Controller] DOMContentLoaded fired, calling init()');
            init();
        });
    } else {
        log('info', '[Starmus UI Controller] DOM already ready, calling init()');
        init();
    }

    window.StarmusUIController = { updateRecorderUI, showUserMessage, buildRecorderUI };

})(window, document);
