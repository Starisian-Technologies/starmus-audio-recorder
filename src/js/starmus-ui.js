/**
 * @file starmus-ui.js
 * @version 4.0.0
 * @description Pure view layer. Subscribes to a store and maps state changes to DOM changes.
 */
(function(window) {
    'use strict';

    if (!window.STARMUS) window.STARMUS = {};
    const STARMUS = window.STARMUS;

    function render(state, elements) {
        const { status, error, source, submission } = state;

        if (elements.step1 && elements.step2) {
            const isInStep1 = status === 'idle';
            elements.step1.style.display = isInStep1 ? 'block' : 'none';
            elements.step2.style.display = isInStep1 ? 'none' : 'block';
        }

        if (elements.recordBtn) {
            elements.recordBtn.disabled = status !== 'ready_to_record';
        }
        if (elements.stopBtn) {
            elements.stopBtn.disabled = status !== 'recording';
        }
        if (elements.submitBtn) {
            elements.submitBtn.disabled = status !== 'ready_to_submit';
        }

        if (elements.progressEl) {
            elements.progressEl.style.width =
                `${(submission.progress || 0) * 100}%`;
            elements.progressEl.style.display =
                status === 'submitting' ? 'block' : 'none';
        }

        const messageEl = elements.statusEl || elements.messageBox;
        if (messageEl) {
            let message = '';
            if (error) {
                messageEl.className = 'starmus-status starmus-status--error';
                message = `Error: ${error.message || 'Unknown error.'}`;
                if (error.retryable) message += ' Please try again.';
            } else {
                messageEl.className = 'starmus-status';
                switch (status) {
                    case 'idle': message = 'Please fill out the details below.'; break;
                    case 'ready_to_record': message = 'Ready to record or attach a file.'; break;
                    case 'recording': message = 'Recording…'; break;
                    case 'processing': message = 'Finalizing recording…'; break;
                    case 'ready_to_submit':
                        message = submission.isQueued
                            ? 'Saved offline. Will submit automatically.'
                            : `Ready to submit: ${source.fileName || 'audio file'}.`;
                        break;
                    case 'submitting': message = `Uploading… ${Math.round((submission.progress || 0) * 100)}%`; break;
                    case 'complete': message = 'Submission successful!'; break;
                    default: message = 'Initializing…';
                }
            }
            messageEl.textContent = message;
            messageEl.style.display = 'block';
        }
    }

    STARMUS.initUIInstance = function(store, elements) {
        const unsub = store.subscribe(() => render(store.getState(), elements));
        render(store.getState(), elements);
        return unsub;
    };

})(window);
