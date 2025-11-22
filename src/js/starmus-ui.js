/**
 * @file starmus-ui.js
 * @version 4.1.0 (ES Module)
 * @description Pure view layer. Maps store state to DOM elements.
 * Updated to support Transcripts, Reset logic, and Offline Queue feedback.
 */

'use strict';

/**
 * Renders the current state of a Starmus instance to the DOM.
 * @param {object} state - The current state from the store.
 * @param {object} elements - A map of DOM elements for the instance.
 */
function render(state, elements) {
    const { status, error, source = {}, submission = {}, calibration = {} } = state;

    // --- Step Visibility ---
    if (elements.step1 && elements.step2) {
        // If we have a recording/file, or we are recording, force Step 2
        const isActive = status !== 'idle' && status !== 'ready'; 
        elements.step1.style.display = isActive ? 'none' : 'block';
        elements.step2.style.display = isActive ? 'block' : 'none';
    }

    // --- Button States ---
    if (elements.recordBtn) {
        // Disable record button if we already have content or are submitting
        elements.recordBtn.disabled = status === 'recording' || status === 'submitting' || status === 'ready_to_submit';
        
        // Visual cue: Add 'recording' class for CSS pulsing effects
        if (status === 'recording') {
            elements.recordBtn.classList.add('starmus-btn--recording');
            elements.recordBtn.textContent = 'Recording...';
        } else {
            elements.recordBtn.classList.remove('starmus-btn--recording');
            elements.recordBtn.textContent = elements.recordBtn.getAttribute('data-original-text') || 'Record';
        }
    }

    if (elements.stopBtn) {
        elements.stopBtn.disabled = status !== 'recording';
    }

    if (elements.submitBtn) {
        // Only enable submit if we have content and aren't currently uploading
        elements.submitBtn.disabled = status !== 'ready_to_submit';
        
        if (status === 'submitting') {
            elements.submitBtn.classList.add('starmus-btn--loading');
        } else {
            elements.submitBtn.classList.remove('starmus-btn--loading');
        }
    }

    // --- Reset Button Logic (New) ---
    if (elements.resetBtn) {
        // Allow reset if we have a file/recording, or if an error occurred
        const canReset = (status === 'ready_to_submit' || status === 'complete' || !!error);
        elements.resetBtn.style.display = canReset ? 'inline-block' : 'none';
        elements.resetBtn.disabled = status === 'submitting';
    }

    // --- Live Transcript (New) ---
    // Shows speech-to-text results in real-time with focus animation
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

    // --- Progress Bar ---
    if (elements.progressEl) {
        const pct = (submission.progress || 0) * 100;
        elements.progressEl.style.width = `${pct}%`;
        // Show progress bar during upload OR calibration (reusing the bar if volumeMeter is missing)
        elements.progressEl.style.display = (status === 'submitting' || status === 'calibrating') ? 'block' : 'none';
    }

    // --- Calibration Volume Meter ---
    if (elements.volumeMeter) {
        if (status === 'calibrating') {
            elements.volumeMeter.style.display = 'block';
            elements.volumeMeter.style.width = `${calibration.volumePercent || 0}%`;
        } else {
            elements.volumeMeter.style.display = 'none';
        }
    }

    // --- File Input Feedback (Tier C Support) ---
    if (elements.fileInput && (source.file || source.fileName)) {
        // Visual feedback that file is selected
        const fileName = source.fileName || source.file.name;
        const label = elements.form?.querySelector(`label[for="${elements.fileInput.id}"]`);
        if (label) {
            label.textContent = `Selected: ${fileName}`;
        }
    }

    // --- Status & Error Messages ---
    const messageEl = elements.statusEl || elements.messageBox;
    if (messageEl) {
        let message = '';
        let msgClass = 'starmus-status';

        if (error) {
            msgClass += ' starmus-status--error';
            message = `Error: ${error.message || 'An unknown error occurred.'}`;
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
                    // Show calibration results if available
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
                        message = `Recorded: ${source.fileName || 'Audio File'}. Ready to submit.`;
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
                        message = 'Submission successful! Thank you.';
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
    // Store original button text for restoring state
    if (elements.recordBtn && !elements.recordBtn.getAttribute('data-original-text')) {
        elements.recordBtn.setAttribute('data-original-text', elements.recordBtn.textContent);
    }

    const unsubscribe = store.subscribe((nextState) => render(nextState, elements));
    render(store.getState(), elements); // Initial render
    return unsubscribe;
}
