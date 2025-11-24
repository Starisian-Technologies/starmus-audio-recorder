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
        const isActive = status !== 'idle' && status !== 'ready';
        elements.step1.style.display = isActive ? 'none' : 'block';
        elements.step2.style.display = isActive ? 'block' : 'none';
    }

    // --- 2. Timer Update ---
    if (elements.timer) {
        const time = recorder.duration || 0;
        elements.timer.textContent = formatTime(time);

        if (status === 'recording') {
            elements.timer.classList.add('starmus-timer--recording');
        } else {
            elements.timer.classList.remove('starmus-timer--recording');
        }
    }

    // --- 3. Volume Meter ---
    if (elements.volumeMeter) {
        const showMeter = status === 'calibrating' || status === 'recording';

        if (elements.volumeMeter.parentElement) {
            elements.volumeMeter.parentElement.style.display = showMeter ? 'block' : 'none';
        }

        if (showMeter) {
            const vol = calibration.volumePercent || recorder.amplitude || 0;
            elements.volumeMeter.style.width = `${Math.max(0, Math.min(100, vol))}%`;
            elements.volumeMeter.style.backgroundColor = vol > 90 ? '#ff4444' : '#4caf50';
        }
    }

    // --- 4. Control Visibility Logic ---
    const isRecorded = status === 'ready_to_submit';
    const isRecording = status === 'recording';
    const isCalibrating = status === 'calibrating';
    const isReady = status === 'ready';
    const showStopBtn = isRecording || isCalibrating;

    if (elements.recordBtn) {
        // Show Record button when: ready after calibration, ready_to_record, or idle (not during recording/submit/processing)
        const showRecordBtn = (isReady || status === 'ready_to_record') && !isRecorded && !showStopBtn && status !== 'submitting' && status !== 'processing';
        elements.recordBtn.style.display = showRecordBtn ? 'inline-flex' : 'none';
        
        // Update button text based on calibration state
        if (isReady && calibration.complete) {
            elements.recordBtn.innerHTML = '<span class="dashicons dashicons-microphone"></span> Start Recording';
        }
    }

    if (elements.stopBtn) {
        elements.stopBtn.style.display = showStopBtn ? 'inline-flex' : 'none';
        if (isCalibrating) {
            elements.stopBtn.innerHTML = '<span class="dashicons dashicons-update"></span> Calibrating...';
            elements.stopBtn.disabled = true;
        } else {
            elements.stopBtn.innerHTML = '<span class="dashicons dashicons-media-default"></span> Stop';
            elements.stopBtn.disabled = false;
        }
    }

    if (elements.reviewControls) {
        elements.reviewControls.style.display = isRecorded ? 'flex' : 'none';
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
        if (source.transcript) {
            elements.transcriptBox.style.display = 'block';
            elements.transcriptBox.textContent = source.transcript;

            elements.transcriptBox.classList.remove('starmus-transcript--pulse');
            void elements.transcriptBox.offsetWidth;
            elements.transcriptBox.classList.add('starmus-transcript--pulse');
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
