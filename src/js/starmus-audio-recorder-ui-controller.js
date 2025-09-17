// FILE: starmus-audio-recorder-ui-controller.js (HOOKS-INTEGRATED)
/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * @module  StarmusUIController
 * @version 1.2.1
 * @file    The UI Controller - Pure UI handling with hooks integration
 */
(function(window, document) {
    'use strict';

    const CONFIG = { LOG_PREFIX: '[Starmus UI Controller]' };
    function log(level, msg, data) { if (console && console[level]) { console[level](CONFIG.LOG_PREFIX, msg, data || ''); } }

    function debugInitBanner() {
        if (!window.isStarmusAdmin) return;
        const banner = document.createElement('div');
        banner.textContent = '[Starmus UI Controller] JS Initialized';
        banner.style.cssText = 'position:fixed;top:0;left:0;z-index:99999;background:#222;color:#fff;padding:4px 12px;font:14px monospace;opacity:0.95';
        document.body.appendChild(banner);
        setTimeout(() => banner.remove(), 4000);
        log('info', 'DEBUG: UI Controller banner shown');
    }

    function el(id) { return document.getElementById(id); }
    function safeId(id) { return typeof id === 'string' && /^[A-Za-z0-9_-]{1,100}$/.test(id); }
    function s(str) { return typeof str === 'string' ? str.replace(/[<>"'&]/g, '').substring(0, 500) : ''; }
    function doAction(hook, ...args) { if (window.StarmusHooks?.doAction) { window.StarmusHooks.doAction(hook, ...args); } }
    function applyFilters(hook, value, ...args) { return window.StarmusHooks?.applyFilters ? window.StarmusHooks.applyFilters(hook, value, ...args) : value; }

    function showUserMessage(instanceId, text, type = 'info') {
        if (!safeId(instanceId)) return;
        const area = el(`starmus_recorder_status_${instanceId}`) || 
                     el(`starmus_step1_usermsg_${instanceId}`) ||
                     el(`starmus_calibration_status_${instanceId}`);
        if (area) {
            area.textContent = s(text);
            area.setAttribute('data-status', type);
            area.style.display = text ? 'block' : 'none';
        }
    }

    const timers = {};
    function startTimer(instanceId) {
        if (!safeId(instanceId) || timers[instanceId]) return;
        timers[instanceId] = setInterval(() => {
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

    function stopTimer(instanceId) {
        if (timers[instanceId]) {
            clearInterval(timers[instanceId]);
            delete timers[instanceId];
        }
    }

    function buildRecorderUI(instanceId) {
        if (!safeId(instanceId)) return;
        const container = el(`starmus_recorder_container_${instanceId}`);
        if (!container) return;

        container.innerHTML = `
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

        const recordBtn = el(`starmus_record_btn_${instanceId}`);
        const stopBtn = el(`starmus_stop_btn_${instanceId}`);
        const pauseBtn = el(`starmus_pause_btn_${instanceId}`);
        const submitBtn = document.querySelector(`#${instanceId} #starmus_submit_btn_${instanceId}`);
        const statusArea = el(`starmus_recorder_status_${instanceId}`);

        if (!recordBtn || !stopBtn || !pauseBtn || !submitBtn || !statusArea) return;

        recordBtn.addEventListener('click', () => {
            doAction('starmus_record_start', instanceId);
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
            doAction('starmus_record_stop', instanceId);
            window.StarmusAudioRecorder.stopRecording(instanceId);
            stopTimer(instanceId);
            stopBtn.style.display = 'none';
            pauseBtn.style.display = 'none';
            recordBtn.textContent = 'Record Again';
            recordBtn.style.display = 'inline-block';
            submitBtn.disabled = false;
            statusArea.textContent = 'Recording finished. Ready to submit.';
        });

        pauseBtn.addEventListener('click', () => {
            window.StarmusAudioRecorder.togglePause(instanceId);
            const isPaused = window.StarmusAudioRecorder?._instances?.[instanceId]?.isPaused || false;
            pauseBtn.textContent = isPaused ? 'Resume' : 'Pause';
            statusArea.textContent = isPaused ? 'Paused.' : 'Recording...';
            doAction('starmus_record_pause', instanceId, isPaused);
        });
    }

    function handleContinueClick(formId) {
        if (!safeId(formId)) return;
        const form = el(formId);
        const step1 = el(`starmus_step1_${formId}`);
        const step2 = el(`starmus_step2_${formId}`);
        if (!form || !step1 || !step2) return;

        let allValid = true;
        for (const input of step1.querySelectorAll('[required]')) {
            if (!input.checkValidity()) {
                showUserMessage(formId, 'Please complete all required fields.', 'error');
                if (typeof input.reportValidity === 'function') input.reportValidity();
                allValid = false;
                break;
            }
        }
        if (!allValid) return;

        doAction('starmus_step_continue', formId, form);
        step1.style.display = 'none';
        step2.style.display = 'block';
        showUserMessage(formId, '', 'info');

        if (!window.StarmusSubmissionsHandler?.initRecorder) {
            showUserMessage(formId, 'A critical component is missing. Please reload.', 'error');
            step1.style.display = 'block';
            step2.style.display = 'none';
            return;
        }
        
        window.StarmusSubmissionsHandler.initRecorder(formId)
            .then(() => {
                buildRecorderUI(formId);
                doAction('starmus_recorder_ui_built', formId);
            })
            .catch(err => {
                showUserMessage(formId, 'Could not initialize microphone. Please check permissions and try again.', 'error');
                step1.style.display = 'block';
                step2.style.display = 'none';
                doAction('starmus_recorder_init_failed', formId, err);
            });
    }

    function initializeForm(form) {
        const formId = form.id;
        if (!safeId(formId) || form.getAttribute('data-starmus-bound')) return;
        
        form.setAttribute('data-starmus-bound', '1');
        doAction('starmus_form_init', formId, form);
        
        const continueBtn = form.querySelector(`#starmus_continue_btn_${formId}`);
        if (continueBtn) {
            continueBtn.addEventListener('click', () => handleContinueClick(formId));
        }
        
        let isSubmitting = false;
        form.addEventListener('submit', event => {
            event.preventDefault();
            if (isSubmitting) return;
            if (!window.StarmusSubmissionsHandler?.handleSubmit) {
                showUserMessage(formId, 'Cannot submit. A critical component is missing.', 'error');
                return;
            }
            isSubmitting = true;
            doAction('starmus_form_submit', formId, form);
            window.StarmusSubmissionsHandler.handleSubmit(formId, form)
                .finally(() => { isSubmitting = false; });
        });
    }

    function init() {
        if (window.starmusUiInitialized) return;
        window.starmusUiInitialized = true;
        log('info', 'UI Controller init called');
        debugInitBanner();
        
        const forms = document.querySelectorAll('form.starmus-audio-form');
        forms.forEach(initializeForm);
        
        doAction('starmus_ui_controller_ready');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.StarmusUIController = {
        init,
        showUserMessage,
        buildRecorderUI,
        handleContinueClick
    };

})(window, document);
