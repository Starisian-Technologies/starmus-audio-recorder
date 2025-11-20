/**
 * @file starmus-ui.js
 * @version 4.0.0 (ES Module)
 * @description Pure view layer. Subscribes to a store and maps state changes to DOM changes.
 * This module is a pure renderer and holds no state or logic.
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
        const isInStep1 = status === 'idle';
        elements.step1.style.display = isInStep1 ? 'block' : 'none';
        elements.step2.style.display = isInStep1 ? 'none' : 'block';
    }

    // --- Button States ---
    if (elements.recordBtn) {
        elements.recordBtn.disabled = status !== 'ready_to_record';
    }
    if (elements.stopBtn) {
        elements.stopBtn.disabled = status !== 'recording';
    }
    if (elements.submitBtn) {
        elements.submitBtn.disabled = status !== 'ready_to_submit';
    }

    // --- Progress Bar ---
    if (elements.progressEl) {
        elements.progressEl.style.width = `${(submission.progress || 0) * 100}%`;
        elements.progressEl.style.display = status === 'submitting' ? 'block' : 'none';
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

    // --- Status & Error Messages ---
    const messageEl = elements.statusEl || elements.messageBox;
    if (messageEl) {
        let message = '';
        if (error) {
            messageEl.className = 'starmus-status starmus-status--error';
            message = `Error: ${error.message || 'An unknown error occurred.'}`;
            if (error.retryable) {
                message += ' Please try again.';
            }
        } else {
            messageEl.className = 'starmus-status';
            switch (status) {
                case 'idle':
                    message = 'Please fill out the details below to continue.';
                    break;
                case 'calibrating':
                    message = calibration.message || 'Calibrating microphone...';
                    break;
                case 'ready':
                case 'ready_to_record':
                    message = calibration.complete 
                        ? `Calibration complete (Gain: ${(calibration.gain || 1.0).toFixed(1)}x). Ready to record.`
                        : 'Ready to record or attach an audio file.';
                    break;
                case 'recording':
                    message = 'Recording...';
                    break;
                case 'processing':
                    message = 'Finalizing recording...';
                    break;
                case 'ready_to_submit':
                    message = submission.isQueued
                        ? 'Saved offline. Will submit automatically when online.'
                        : `Ready to submit: ${source.fileName || 'audio file'}.`;
                    break;
                case 'submitting':
                    message = `Uploading... ${Math.round((submission.progress || 0) * 100)}%`;
                    break;
                case 'complete':
                    message = submission.isQueued
                        ? 'Saved offline. Will submit automatically when online.'
                        : 'Submission successful!';
                    break;
                default:
                    message = 'Initializing...';
            }
        }
        messageEl.textContent = message;
        messageEl.style.display = 'block';
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
