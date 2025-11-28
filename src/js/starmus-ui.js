/**
 * @file starmus-ui.js
 * @version 4.2.1 (ES Module)
 * @description Pure view layer. Maps store state to DOM elements.
 * Supports Timer, Volume Meter, Live Transcript, and Review Controls.
 */

'use strict';

/**
 * Clipping coaching state (one warning per session)
 */
let starmusClipWarned = false;

/**
 * Helper: Maybe show coaching message for clipping levels
 * @param {number} normalizedLevel - Volume level 0-1
 * @param {Object} elements - DOM elements object
 */
function starmusMaybeCoachUser(normalizedLevel, elements) {
    if (normalizedLevel >= 0.85 && !starmusClipWarned) {
        starmusClipWarned = true;

        const msg = elements.messageBox || document.querySelector('[data-starmus-message-box]');
        if (msg) {
            msg.innerHTML =
                '⚠️ Your microphone is too loud. Move back 6–12 inches or speak softer for a cleaner recording.';
            msg.style.display = 'block';
            msg.setAttribute('role', 'alert');
            msg.setAttribute('aria-live', 'assertive');

            // Auto-clear after 6 seconds
            setTimeout(() => {
                msg.style.display = 'none';
                msg.removeAttribute('role');
                msg.removeAttribute('aria-live');
            }, 6000);
        }
    }
}

/**
 * Helper: Format seconds into MM:SS with units
 */
function formatTime(seconds) {
    if (seconds === undefined || seconds === null || isNaN(seconds)) {
        return '00m 00s';
    }
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60);
    return `${m.toString().padStart(2, '0')}m ${s.toString().padStart(2, '0')}s`;
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

    // --- Runtime Tier C Guard ---
    // If tier downgrades to C at runtime (audio graph failure, telemetry-triggered), hide all recording controls
    if (state.tier === 'C' || state.fallbackActive === true) {
        if (elements.recordBtn) elements.recordBtn.style.display = 'none';
        if (elements.pauseBtn) elements.pauseBtn.style.display = 'none';
        if (elements.resumeBtn) elements.resumeBtn.style.display = 'none';
        if (elements.stopBtn) elements.stopBtn.style.display = 'none';

        if (elements.recorderContainer) elements.recorderContainer.style.display = 'none';
        if (elements.fallbackContainer) elements.fallbackContainer.style.display = 'block';
        return;
    }

    // --- 1. Step Visibility ---
    if (elements.step1 && elements.step2) {
        // Handle uninitialized state (first load before init action)
        if (status === 'uninitialized') {
            elements.step1.style.display = 'block';
            elements.step2.style.display = 'none';
            return;
        }
        
        // Show step 2 when user continues from step 1 (ready_to_record or any active status)
        // Only keep Step 1 visible for 'idle' (initial state before Continue button clicked)
        const showStep2 = status !== 'idle' && status !== 'uninitialized';
        elements.step1.style.display = showStep2 ? 'none' : 'block';
        elements.step2.style.display = showStep2 ? 'block' : 'none';
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
            
            // Update ::after width via CSS custom property (matches CSS: --starmus-recording-progress)
            elements.durationProgress.style.setProperty('--starmus-recording-progress', `${progressPercent}%`);
            elements.durationProgress.setAttribute('aria-valuenow', Math.floor(time));
            
            // Color thresholds via data attributes: green -> orange (15min) -> red (17min)
            if (time >= RED_THRESHOLD) {
                elements.durationProgress.setAttribute('data-level', 'danger');
            } else if (time >= ORANGE_THRESHOLD) {
                elements.durationProgress.setAttribute('data-level', 'warning');
            } else {
                elements.durationProgress.setAttribute('data-level', 'safe');
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
            // Do NOT reset width here; let processing keep the last known width
        }
    }

    // --- 3. Volume Meter (Enhanced with color states and coaching) ---
    if (elements.volumeMeter) {
        const showMeter = status === 'calibrating' || status === 'recording' || status === 'paused';

        if (elements.volumeMeter.parentElement) {
            elements.volumeMeter.parentElement.style.display = showMeter ? 'block' : 'none';
        }

        if (showMeter) {
            // FIX: Explicitly choose source based on current status
            let vol = 0;
            if (status === 'calibrating') {
                vol = calibration.volumePercent || 0;
            } else {
                // During recording/pause, strictly use recorder amplitude
                vol = recorder.amplitude || 0;
            }
            
            // Clamp to 0-100 range
            vol = Math.max(0, Math.min(100, vol));
            
            // Normalize to 0-1 range for classification
            const normalizedLevel = vol / 100;
            
            // Update ::after width via CSS custom property (matches CSS: --starmus-audio-level)
            elements.volumeMeter.style.setProperty('--starmus-audio-level', `${vol}%`);
            
            // State classification for color feedback (overrides gradient)
            if (normalizedLevel < 0.6) {
                elements.volumeMeter.setAttribute('data-level', 'safe');
            } else if (normalizedLevel < 0.85) {
                elements.volumeMeter.setAttribute('data-level', 'hot');
            } else {
                elements.volumeMeter.setAttribute('data-level', 'clip');
            }
            
            // Coaching layer (only during recording, not calibration)
            if (status === 'recording') {
                starmusMaybeCoachUser(normalizedLevel, elements);
            }
        } else {
            // Reset meter when hidden
            elements.volumeMeter.style.setProperty('--starmus-audio-level', '0%');
            elements.volumeMeter.removeAttribute('data-level');
        }
    }

    // --- 4. Control Visibility Logic ---
    const isRecorded = status === 'ready_to_submit';
    const isRecording = status === 'recording';
    const isCalibrating = status === 'calibrating';
    const isReady = status === 'ready';
    const isCalibrated = calibration && calibration.complete === true;
    const isPaused = status === 'paused';
    const showStopBtn = isRecording || isCalibrating;

    // --- 4a. Setup Button (appears first, before calibration) ---
    if (elements.setupMicBtn && elements.setupContainer) {
        const showSetup = status === 'ready_to_record' && !isCalibrated;
        elements.setupContainer.style.display = showSetup ? 'block' : 'none';
    }

    // --- 4b. Record Button (appears after calibration) ---
    if (elements.recordBtn) {
        // Show Record button ONLY when calibrated and ready (not before calibration)
        const showRecordBtn = isReady && isCalibrated && !isRecorded && !showStopBtn && !isPaused && status !== 'submitting' && status !== 'processing';
        elements.recordBtn.style.display = showRecordBtn ? 'inline-flex' : 'none';
        
        if (isReady && isCalibrated) {
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

            if (hasFinal && status !== 'calibrating') {
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
                        message = 'Upload successful! Redirecting...';
                        msgClass += ' starmus-status--success';
                        
                        // Redirect to my-recordings page after 2 seconds
                        setTimeout(() => {
                            const redirectUrl = window.starmusConfig?.myRecordingsUrl || '/my-submissions/';
                            window.location.href = redirectUrl;
                        }, 2000);
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
