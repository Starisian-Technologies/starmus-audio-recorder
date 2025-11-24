/**
 * @file starmus-ui.js
 * @version 4.2.0 (ES Module)
 * @description Pure view layer. Maps store state to DOM elements.
 * Updated to support Timer, Volume Meter, Waveform, and Review Controls.
 */

'use strict';

/**
 * Helper: Format seconds into MM:SS
 */
function formatTime(seconds) {
    if (!seconds || isNaN(seconds)) {
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
    const { status, error, source = {}, submission = {}, calibration = {}, recorder = {} } = state;

    // --- 1. Step Visibility ---
    if (elements.step1 && elements.step2) {
        // Step 1 is hidden if we are recording, reviewing, or submitting
        const isActive = status !== 'idle' && status !== 'ready'; 
        elements.step1.style.display = isActive ? 'none' : 'block';
        elements.step2.style.display = isActive ? 'block' : 'none';
    }

    // --- 2. Timer Update ---
    if (elements.timer) {
        // If recording, use current duration. If playing back, use current time.
        const time = recorder.duration || 0;
        elements.timer.textContent = formatTime(time);
        
        // Visual cue: Red text while recording
        if (status === 'recording') {
            elements.timer.classList.add('starmus-timer--recording');
        } else {
            elements.timer.classList.remove('starmus-timer--recording');
        }
    }

    // --- 3. Volume Meter ---
    if (elements.volumeMeter) {
        // Show meter during calibration OR recording
        const showMeter = status === 'calibrating' || status === 'recording';
        // Hide the whole container so borders don't show when empty
        if (elements.volumeMeter.parentElement) {
            elements.volumeMeter.parentElement.style.display = showMeter ? 'block' : 'none';
        }
        
        if (showMeter) {
            // Use calibration volume OR recording amplitude
            const vol = calibration.volumePercent || recorder.amplitude || 0;
            elements.volumeMeter.style.width = `${vol}%`;
            
            // Color feedback for clipping
            if (vol > 90) {
                elements.volumeMeter.style.backgroundColor = '#ff4444'; // Red
            } else {
                elements.volumeMeter.style.backgroundColor = '#4caf50'; // Green
            }
        }
    }

    // --- 4. Control Visibility Logic ---
    const isRecorded = status === 'ready_to_submit';
    const isRecording = status === 'recording';

    // Record Button
    if (elements.recordBtn) {
        // Only show Record if we haven't recorded yet and aren't currently recording
        elements.recordBtn.style.display = (!isRecorded && !isRecording && status !== 'submitting') ? 'inline-flex' : 'none';
    }

    // Stop Button
    if (elements.stopBtn) {
        elements.stopBtn.style.display = isRecording ? 'inline-flex' : 'none';
    }

    // Review Controls (Play/Pause/Retake)
    if (elements.reviewControls) {
        elements.reviewControls.style.display = isRecorded ? 'flex' : 'none';
    }

    // Play/Pause Button Text
    if (elements.playBtn) {
        const isPlaying = recorder.isPlaying; // Assuming state tracks playback
        elements.playBtn.textContent = isPlaying ? 'Pause' : 'Play Preview';
        elements.playBtn.setAttribute('aria-label', isPlaying ? 'Pause audio' : 'Play recorded audio');
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
            elements.progressEl.style.width = `${pct}%`;
        } else {
            elements.progressWrap.style.display = 'none';
        }
    }

    // --- 7. Live Transcript (New) ---
    if (elements.transcriptBox) {
        if (source.transcript) {
            elements.transcriptBox.style.display = 'block';
            elements.transcriptBox.textContent = source.transcript;
            
            // Pulse animation on update to show recognition
            elements.transcriptBox.classList.remove('starmus-transcript--pulse');
            void elements.transcriptBox.offsetWidth; // Trigger reflow
            elements.transcriptBox.classList.add('starmus-transcript--pulse');
        } else {
            // Hide if empty to save space on small screens
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
                case 'ready_to_record':
                    message = calibration.complete 
                        ? `Mic ready (Gain: ${(calibration.gain || 1.0).toFixed(1)}x).`
                        : 'Ready to record.';
                    break;
                case 'recording':
                    message = 'Recording in progress...';
                    msgClass += ' starmus-status--recording';
                    break;
                case 'processing':
                    message = 'Processing audio...';
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
    render(store.getState(), elements); // Initial render
    return unsubscribe;
}
