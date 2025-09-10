// FILE: starmus-audio-recorder-ui-controller.js (HOOKS-INTEGRATED)
/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * @module  StarmusUIController
 * @version 1.2.0
 * @file    The UI Manager - Hooks-integrated interface controller
 */
(function(window, document) {
    'use strict';

    const CONFIG = { LOG_PREFIX: '[Starmus UI Controller]' };
    function log(level, msg, data) { 
        if (console && console[level]) { 
            console[level](CONFIG.LOG_PREFIX, msg, data || ''); 
        } 
    }
    function el(id) { return document.getElementById(id); }
    function safeId(id) { 
        return typeof id === 'string' && /^[A-Za-z0-9_-]{1,100}$/.test(id); 
    }

    function doAction(hook) {
        if (window.StarmusHooks) {
            const args = Array.prototype.slice.call(arguments, 1);
            window.StarmusHooks.doAction.apply(null, [hook].concat(args));
        }
    }

    function applyFilters(hook, value) {
        if (window.StarmusHooks) {
            const args = Array.prototype.slice.call(arguments, 2);
            return window.StarmusHooks.applyFilters.apply(null, [hook, value].concat(args));
        }
        return value;
    }

    function showUserMessage(instanceId, message, type) {
        type = type || 'info';
        const area = el('starmus_recorder_status_' + instanceId) || 
                    el('starmus_calibration_status_' + instanceId) || 
                    el('starmus_step1_usermsg_' + instanceId);
        if (area) {
            area.textContent = message;
            area.setAttribute('data-status', type);
            area.style.display = message ? 'block' : 'none';
        }
        doAction('starmus_user_message_shown', instanceId, message, type);
    }

    function updateRecorderUI(instanceId, state) {
        const elements = {
            recordBtn: el('starmus_record_btn_' + instanceId),
            stopBtn: el('starmus_stop_btn_' + instanceId),
            playBtn: el('starmus_play_btn_' + instanceId),
            pauseBtn: el('starmus_pause_btn_' + instanceId),
            audioPreview: el('starmus_audio_preview_' + instanceId),
            volumeMeter: el('starmus_volume_meter_' + instanceId)
        };

        if (!elements.recordBtn || !elements.stopBtn) return;

        // Default state
        elements.recordBtn.disabled = false;
        elements.stopBtn.disabled = true;
        elements.playBtn.disabled = true;
        elements.pauseBtn.style.display = 'none';
        if (elements.audioPreview) elements.audioPreview.style.display = 'none';
        if (elements.volumeMeter) elements.volumeMeter.style.display = 'none';

        const stateConfig = applyFilters('starmus_ui_state_config', {
            recording: {
                recordBtn: { disabled: true },
                stopBtn: { disabled: false },
                pauseBtn: { display: 'inline-block', textContent: 'Pause' },
                volumeMeter: { display: 'block' },
                message: { text: 'Recording...', type: 'info' }
            },
            paused: {
                pauseBtn: { textContent: 'Resume' },
                message: { text: 'Paused', type: 'info' }
            },
            stopped: {
                playBtn: { disabled: false },
                audioPreview: { display: 'block' },
                message: { text: 'Recording complete. Ready to submit.', type: 'success' }
            }
        }, instanceId);

        const config = stateConfig[state];
        if (config) {
            var key;
            for (key in config) {
                if (elements[key] && config[key]) {
                    var prop;
                    for (prop in config[key]) {
                        if (prop === 'display') {
                            elements[key].style.display = config[key][prop];
                        } else {
                            elements[key][prop] = config[key][prop];
                        }
                    }
                }
            }

            if (config.message) {
                showUserMessage(instanceId, config.message.text, config.message.type);
            }

            if (state === 'stopped' && elements.audioPreview) {
                const submissionData = window.StarmusAudioRecorder && window.StarmusAudioRecorder.getSubmissionData ? window.StarmusAudioRecorder.getSubmissionData(instanceId) : null;
                if (submissionData && submissionData.blob) {
                    const currentUrl = elements.audioPreview.src;
                    if (currentUrl && currentUrl.indexOf('blob:') === 0) {
                        URL.revokeObjectURL(currentUrl);
                    }
                    elements.audioPreview.src = URL.createObjectURL(submissionData.blob);
                }
            }
        }

        doAction('starmus_ui_state_changed', instanceId, state);
    }

    function buildRecorderUI(instanceId) {
        const container = el('starmus_recorder_container_' + instanceId);
        if (!container) return;
        
        const uiTemplate = applyFilters('starmus_recorder_ui_template', 
            '<div class="starmus-calibration-status" id="starmus_calibration_status_' + instanceId + '">Click "Test Mic" for best results.</div>' +
            '<div class="starmus-volume-meter" id="starmus_volume_meter_' + instanceId + '" style="display:none;">' +
                '<div id="starmus_volume_bar_' + instanceId + '" class="starmus-volume-bar"></div>' +
            '</div>' +
            '<div class="starmus-recorder-timer" id="starmus_timer_' + instanceId + '">00:00</div>' +
            '<div class="starmus-recorder-controls">' +
                '<button type="button" id="starmus_calibrate_btn_' + instanceId + '" class="starmus-btn">Test Mic</button>' +
                '<button type="button" id="starmus_record_btn_' + instanceId + '" class="starmus-btn starmus-btn--primary" disabled>Record</button>' +
                '<button type="button" id="starmus_pause_btn_' + instanceId + '" class="starmus-btn" style="display:none;">Pause</button>' +
                '<button type="button" id="starmus_stop_btn_' + instanceId + '" class="starmus-btn" disabled>Stop</button>' +
                '<button type="button" id="starmus_play_btn_' + instanceId + '" class="starmus-btn" disabled>Play</button>' +
            '</div>' +
            '<audio id="starmus_audio_preview_' + instanceId + '" controls style="display:none; width:100%; margin-top:1rem;"></audio>',
            instanceId);

        container.innerHTML = uiTemplate;
        bindRecorderEvents(instanceId);
        doAction('starmus_recorder_ui_built', instanceId);
    }

    function bindRecorderEvents(instanceId) {
        const elements = {
            calibrateBtn: el('starmus_calibrate_btn_' + instanceId),
            recordBtn: el('starmus_record_btn_' + instanceId),
            stopBtn: el('starmus_stop_btn_' + instanceId),
            pauseBtn: el('starmus_pause_btn_' + instanceId),
            playBtn: el('starmus_play_btn_' + instanceId),
            timerEl: el('starmus_timer_' + instanceId),
            volumeMeter: el('starmus_volume_meter_' + instanceId),
            volumeBar: el('starmus_volume_bar_' + instanceId)
        };

        if (elements.calibrateBtn) {
            elements.calibrateBtn.addEventListener('click', function() {
                elements.recordBtn.disabled = true;
                elements.calibrateBtn.disabled = true;
                elements.volumeMeter.style.display = 'block';
                
                window.StarmusAudioRecorder.calibrate(instanceId, function(message, volume, isDone) {
                    if (message) showUserMessage(instanceId, message, 'info');
                    if (volume != null) elements.volumeBar.style.width = volume + '%';
                    if (isDone) {
                        elements.recordBtn.disabled = false;
                        elements.calibrateBtn.disabled = false;
                        elements.volumeMeter.style.display = 'none';
                    }
                });
            });
        }

        if (elements.recordBtn) {
            elements.recordBtn.addEventListener('click', function() {
                const instance = window.StarmusAudioRecorder.instances && window.StarmusAudioRecorder.instances[instanceId];
                if (!instance || !instance.isCalibrated) {
                    showUserMessage(instanceId, 'Please test your microphone before recording.', 'warn');
                    return;
                }
                elements.volumeMeter.style.display = 'block';
                window.StarmusAudioRecorder.startRecording(instanceId);
                window.StarmusAudioRecorder.startVolumeMonitoring(instanceId, function(volume) {
                    elements.volumeBar.style.width = volume + '%';
                });
            });
        }
        
        if (elements.stopBtn) {
            elements.stopBtn.addEventListener('click', function() {
                window.StarmusAudioRecorder.stopRecording(instanceId);
                elements.volumeMeter.style.display = 'none';
            });
        }

        if (elements.pauseBtn) {
            elements.pauseBtn.addEventListener('click', function() {
                window.StarmusAudioRecorder.togglePause(instanceId);
            });
        }

        if (elements.playBtn) {
            elements.playBtn.addEventListener('click', function() {
                const audio = el('starmus_audio_preview_' + instanceId);
                if (audio) audio.play();
            });
        }

        // Timer update
        setInterval(function() {
            const instance = window.StarmusAudioRecorder.instances && window.StarmusAudioRecorder.instances[instanceId];
            if (instance && instance.isRecording && !instance.isPaused && elements.timerEl) {
                const elapsed = Date.now() - instance.startTime;
                const minutes = Math.floor(elapsed / 60000);
                const seconds = Math.floor((elapsed % 60000) / 1000);
                elements.timerEl.textContent = String(minutes).replace(/^(\d)$/, '0$1') + ':' + String(seconds).replace(/^(\d)$/, '0$1');
            }
        }, 1000);
    }

    function handleContinueClick(formId) {
        const step1 = el('starmus_step1_' + formId);
        const step2 = el('starmus_step2_' + formId);

        if (!step1 || !step2) return;

        var allValid = true;
        var requiredInputs = step1.querySelectorAll('[required]');
        
        for (var i = 0; i < requiredInputs.length; i++) {
            var input = requiredInputs[i];
            if (!input.checkValidity()) {
                showUserMessage(formId, 'Please complete all required fields.', 'error');
                if (typeof input.reportValidity === 'function') input.reportValidity();
                allValid = false;
                break;
            }
        }
        
        if (!allValid) return;

        doAction('starmus_before_step_transition', formId);
        
        step1.style.display = 'none';
        step2.style.display = 'block';
        showUserMessage(formId, '', 'info');

        window.StarmusSubmissionsHandler.initRecorder(formId)
            .then(function() {
                buildRecorderUI(formId);
                doAction('starmus_after_step_transition', formId);
            })
            .catch(function(err) {
                log('error', 'Recorder init failed', err && err.message);
                doAction('starmus_recorder_init_failed', formId, err);
            });
    }

    function initializeForm(form) {
        const formId = form.id;
        if (!safeId(formId) || form.getAttribute('data-starmus-ui-bound') === '1') return;
        
        form.setAttribute('data-starmus-ui-bound', '1');

        const continueBtn = el('starmus_continue_btn_' + formId);
        if (continueBtn) {
            continueBtn.addEventListener('click', function() {
                handleContinueClick(formId);
            });
        }

        form.addEventListener('submit', function(event) {
            event.preventDefault();
            if (window.StarmusSubmissionsHandler && window.StarmusSubmissionsHandler.handleSubmit) {
                window.StarmusSubmissionsHandler.handleSubmit(formId, form);
            }
        });

        doAction('starmus_form_initialized', formId);
    }

    function init() {
        // Hook into recording events
        if (window.StarmusHooks) {
            window.StarmusHooks.addAction('starmus_recording_started', updateRecorderUI);
            window.StarmusHooks.addAction('starmus_recording_stopped', updateRecorderUI);
            window.StarmusHooks.addAction('starmus_recording_paused', updateRecorderUI);
            window.StarmusHooks.addAction('starmus_recording_resumed', updateRecorderUI);
        }

        const forms = document.querySelectorAll('form.starmus-audio-form');
        for (var i = 0; i < forms.length; i++) {
            initializeForm(forms[i]);
        }
        
        doAction('starmus_ui_controller_ready');
    }

    // Initialize when hooks are ready
    if (window.StarmusHooks) {
        window.StarmusHooks.addAction('starmus_hooks_ready', init, 10);
    } else {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
    }

    // Global interface
    window.StarmusUIController = {
        showUserMessage: showUserMessage,
        updateRecorderUI: updateRecorderUI,
        buildRecorderUI: buildRecorderUI
    };

})(window, document);