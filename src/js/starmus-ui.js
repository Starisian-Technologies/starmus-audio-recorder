/**
 * @file starmus-ui.js
 * @version 4.2.1 (ES Module)
 * @description Pure view layer. Maps store state to DOM elements.
 * Supports Timer, Volume Meter, Live Transcript, and Review Controls.
 */

'use strict';

/**
 * Helper: Format seconds into MM:SS
 */
function formatTime(seconds) {
    if (seconds === undefined || seconds === null || isNaN(seconds)) {
        return '00:00';
    }
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60);
    return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
}

/**
 * Helper: Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Renders the current state of a Starmus instance to the DOM.
 * @param {object} state - The current state from the store.
 * @param {object} elements - A map of DOM elements for the instance.
 */
function render(state, elements) {
    const {
        status,
        error,
        source = {},
        submission = {},
        calibration = {},
        recorder = {}
    } = state;

    // --- 1. Step Visibility ---
    if (elements.step1 && elements.step2) {
        // Show step 2 when user continues from step 1, including 'ready' status after calibration
        // Keep Step 1 visible for 'idle' and 'ready_to_record' (before user presses Continue)
        const isRecorderActive = status !== 'idle' && status !== 'ready_to_record';
        elements.step1.style.display = isRecorderActive ? 'none' : 'block';
        elements.step2.style.display = isRecorderActive ? 'block' : 'none';
    }

    // --- 2. Timer & Duration Progress Update ---
    const MAX_DURATION = 1200; // 20 minutes in seconds
    const ORANGE_THRESHOLD = 900; // 15 minutes (5 min remaining)
    const RED_THRESHOLD = 1020; // 17 minutes (3 min remaining)
    
    if (elements.timer || elements.timerElapsed) {
        const time = recorder.duration || 0;
        const formattedTime = formatTime(time);
        
        // Update timer display
        if (elements.timerElapsed) {
            elements.timerElapsed.textContent = formattedTime;
        } else if (elements.timer) {
            elements.timer.textContent = formattedTime;
        }

        // Add recording class for visual feedback
        if (elements.timer) {
            if (status === 'recording') {
                elements.timer.classList.add('starmus-timer--recording');
            } else {
                elements.timer.classList.remove('starmus-timer--recording');
            }
        }
    }
    
    // --- 2b. Duration Progress Bar ---
    if (elements.durationProgress) {
        const time = recorder.duration || 0;
        const showProgress = status === 'recording' || status === 'paused' || status === 'calibrating';
        
        if (showProgress) {
            if (elements.durationProgress.parentElement) {
                elements.durationProgress.parentElement.style.display = 'block';
            }
            
            // Calculate progress percentage (0-100)
            const progressPercent = Math.min(100, (time / MAX_DURATION) * 100);
            elements.durationProgress.style.width = `${progressPercent}%`;
            elements.durationProgress.setAttribute('aria-valuenow', Math.floor(time));
            
            // Color thresholds: green -> orange (15min) -> red (17min)
            if (time >= RED_THRESHOLD) {
                elements.durationProgress.style.backgroundColor = '#e74c3c'; // Red
                elements.durationProgress.classList.add('starmus-duration-progress--danger');
                elements.durationProgress.classList.remove('starmus-duration-progress--warning');
            } else if (time >= ORANGE_THRESHOLD) {
                elements.durationProgress.style.backgroundColor = '#f39c12'; // Orange
                elements.durationProgress.classList.add('starmus-duration-progress--warning');
                elements.durationProgress.classList.remove('starmus-duration-progress--danger');
            } else {
                elements.durationProgress.style.backgroundColor = '#27ae60'; // Green
                elements.durationProgress.classList.remove('starmus-duration-progress--warning', 'starmus-duration-progress--danger');
            }
            
            // Auto-stop at 20 minutes
            if (time >= MAX_DURATION && status === 'recording') {
                // Dispatch stop command
                if (window.CommandBus) {
                    window.CommandBus.dispatch('stop-mic', {}, { instanceId: state.instanceId });
                }
            }
        } else {
            if (elements.durationProgress.parentElement) {
                elements.durationProgress.parentElement.style.display = 'none';
            }
            elements.durationProgress.style.width = '0%';
        }
    }

    // --- 3. Volume Meter ---
    if (elements.volumeMeter) {
        const showMeter = status === 'calibrating' || status === 'recording' || status === 'paused';

        if (elements.volumeMeter.parentElement) {
            elements.volumeMeter.parentElement.style.display = showMeter ? 'block' : 'none';
        }

        if (showMeter) {
            const vol = calibration.volumePercent || recorder.amplitude || 0;
            elements.volumeMeter.style.width = `${Math.max(0, Math.min(100, vol))}%`;
            elements.volumeMeter.style.backgroundColor = vol > 90 ? '#ff4444' : '#4caf50';
        } else {
            // Reset meter when hidden
            elements.volumeMeter.style.width = '0%';
        }
    }

    // --- 4. Control Visibility Logic ---
    const isRecorded = status === 'ready_to_submit';
    const isRecording = status === 'recording';
    const isCalibrating = status === 'calibrating';
    const isReady = status === 'ready';
    const isPaused = status === 'paused';
    const showStopBtn = isRecording || isCalibrating;

    if (elements.recordBtn) {
        // Show Record button when: ready after calibration, ready_to_record, or idle (not during recording/submit/processing)
        const showRecordBtn = (isReady || status === 'ready_to_record') && !isRecorded && !showStopBtn && !isPaused && status !== 'submitting' && status !== 'processing';
        elements.recordBtn.style.display = showRecordBtn ? 'inline-flex' : 'none';
        
        // Update button text based on calibration state
        if (isReady && calibration.complete) {
            elements.recordBtn.innerHTML = '<span class="dashicons dashicons-microphone"></span> Start Recording';
        }
    }

    // Pause button - show during recording only
    if (elements.pauseBtn) {
        elements.pauseBtn.style.display = isRecording ? 'inline-flex' : 'none';
    }

    // Resume button - show when paused
    if (elements.resumeBtn) {
        elements.resumeBtn.style.display = isPaused ? 'inline-flex' : 'none';
    }

    if (elements.stopBtn) {
        // Show stop button during recording, calibrating, or paused states
        const showStop = isRecording || isCalibrating || isPaused;
        elements.stopBtn.style.display = showStop ? 'inline-flex' : 'none';
        
        if (isCalibrating) {
            elements.stopBtn.innerHTML = '<span class="dashicons dashicons-update"></span> Calibrating...';
            elements.stopBtn.disabled = true;
        } else {
            elements.stopBtn.innerHTML = '<span class="dashicons dashicons-media-default"></span> Stop';
            elements.stopBtn.disabled = false;
        }
    }

    // Review controls - show when paused or ready to submit
    if (elements.reviewControls) {
        elements.reviewControls.style.display = (isRecorded || isPaused) ? 'flex' : 'none';
    }

    if (elements.playBtn) {
        const isPlaying = !!recorder.isPlaying;
        elements.playBtn.textContent = isPlaying ? 'Pause' : 'Play Preview';
        elements.playBtn.setAttribute(
            'aria-label',
            isPlaying ? 'Pause audio' : 'Play recorded audio'
        );
    }

    // --- 5. Submit Button ---
    if (elements.submitBtn) {
        elements.submitBtn.disabled = status !== 'ready_to_submit';

        if (status === 'submitting') {
            elements.submitBtn.innerHTML = '<span class="starmus-spinner"></span> Uploading...';
            elements.submitBtn.classList.add('starmus-btn--loading');
        } else {
            elements.submitBtn.textContent = 'Submit Recording';
            elements.submitBtn.classList.remove('starmus-btn--loading');
        }
    }

    // --- 6. Progress Bar (Upload) ---
    if (elements.progressEl && elements.progressWrap) {
        if (status === 'submitting') {
            elements.progressWrap.style.display = 'block';
            const pct = (submission.progress || 0) * 100;
            elements.progressEl.style.width = `${Math.max(0, Math.min(100, pct))}%`;
        } else {
            elements.progressWrap.style.display = 'none';
        }
    }

    // --- 7. Live Transcript ---
    if (elements.transcriptBox) {
        const hasFinal = source.transcript && source.transcript.length > 0;
        const hasInterim = source.interimTranscript && source.interimTranscript.length > 0;
        
        if (hasFinal || hasInterim) {
            elements.transcriptBox.style.display = 'block';
            
            // Show final transcript with interim in dimmed style
            elements.transcriptBox.innerHTML = hasFinal
                ? `<span class="starmus-transcript--final">${escapeHtml(source.transcript)}</span>${hasInterim ? ' <span class="starmus-transcript--interim">' + escapeHtml(source.interimTranscript) + '</span>' : ''}`
                : `<span class="starmus-transcript--interim">${escapeHtml(source.interimTranscript)}</span>`;

            if (hasFinal) {
                elements.transcriptBox.classList.remove('starmus-transcript--pulse');
                void elements.transcriptBox.offsetWidth;
                elements.transcriptBox.classList.add('starmus-transcript--pulse');
            }
        } else {
            elements.transcriptBox.style.display = 'none';
        }
    }

    // --- 8. Status Messages ---
    const messageEl = elements.statusEl;
    if (messageEl) {
        let message = '';
        let msgClass = 'starmus-status';

        if (error) {
            msgClass += ' starmus-status--error';
            message = error.message || 'An error occurred.';
            if (error.retryable) {
                message += ' Please try again.';
            }
        } else {
            switch (status) {
                case 'idle':
                    message = 'Please fill out the details to continue.';
                    break;
                case 'calibrating':
                    message = calibration.message || 'Adjusting microphone...';
                    break;
                case 'ready':
                    message = calibration.complete
                        ? `Mic calibrated (Gain: ${(calibration.gain || 1.0).toFixed(1)}x). Click "Start Recording" to begin.`
                        : 'Ready to record.';
                    msgClass += ' starmus-status--success';
                    break;
                case 'ready_to_record':
                    message = 'Ready to record.';
                    break;
                case 'recording':
                    message = 'Recording in progress...';
                    msgClass += ' starmus-status--recording';
                    break;
                case 'paused':
                    message = 'Recording paused. Click Resume to continue or Stop to finish.';
                    msgClass += ' starmus-status--info';
                    break;
                case 'processing':
                    message = 'Processing audio... Please wait.';
                    msgClass += ' starmus-status--info';
                    break;
                case 'ready_to_submit':
                    if (submission.isQueued) {
                        message = 'Network offline. Recording saved to queue.';
                        msgClass += ' starmus-status--warning';
                    } else {
                        message = 'Recording complete. Review or Submit.';
                        msgClass += ' starmus-status--success';
                    }
                    break;
                case 'submitting':
                    message = `Uploading... ${Math.round((submission.progress || 0) * 100)}%`;
                    break;
                case 'complete':
                    if (submission.isQueued) {
                        message = 'Saved to offline queue. Will upload automatically when online.';
                        msgClass += ' starmus-status--warning';
                    } else {
                        message = 'Upload successful!';
                        msgClass += ' starmus-status--success';
                    }
                    break;
                default:
                    message = '';
            }
        }

        messageEl.className = msgClass;
        messageEl.textContent = message;
        messageEl.style.display = message ? 'block' : 'none';
    }
}

/**
 * Initializes a UI instance and binds it to a state store.
 * @param {object} store - The Starmus state store for the instance.
 * @param {object} elements - A map of DOM elements for this instance.
 * @returns {function} An unsubscribe function.
 */
export function initInstance(store, elements) {
    const unsubscribe = store.subscribe((nextState) => render(nextState, elements));
    render(store.getState(), elements);
    return unsubscribe;
}
