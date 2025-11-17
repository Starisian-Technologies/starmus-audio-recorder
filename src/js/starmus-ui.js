/**
 * @file starmus-ui.js
 * @version 3.1.0
 * @description Pure view layer for Starmus. Subscribes to a store and maps
 * state changes to DOM changes.
 */
/* global window */
(function (window) {
    'use strict';

    if (!window.STARMUS) {
        window.STARMUS = {};
    }

    var STARMUS = window.STARMUS;

    function initInstance(store, elements) {
        if (!store || !elements) {
            return;
        }

        function render(state) {
            var statusEl = elements.statusEl;
            var progressEl = elements.progressEl;
            var recordBtn = elements.recordBtn;
            var stopBtn = elements.stopBtn;
            var submitBtn = elements.submitBtn;

            var status = state.status;
            var error = state.error;
            var source = state.source || {};
            var submission = state.submission || {};

            // Button states
            if (recordBtn) {
                recordBtn.disabled = (status === 'recording' || status === 'submitting');
            }
            if (stopBtn) {
                stopBtn.disabled = (status !== 'recording');
            }
            if (submitBtn) {
                submitBtn.disabled = (status !== 'ready_to_submit');
            }

            // Progress bar
            if (progressEl) {
                if (status === 'submitting') {
                    progressEl.style.display = 'block';
                    progressEl.style.width = String((submission.progress || 0) * 100) + '%';
                } else {
                    progressEl.style.display = 'none';
                    progressEl.style.width = '0%';
                }
            }

            // Status text
            if (!statusEl) {
                return;
            }

            var message = '';
            if (error) {
                statusEl.className = 'starmus-status starmus-status--error';
                message = 'Error: ' + (error.message || 'Unknown error.');
                if (error.retryable) {
                    message += ' Please try again.';
                }
            } else {
                statusEl.className = 'starmus-status';
                switch (status) {
                    case 'idle':
                    case 'uninitialized':
                        message = 'Ready to record or attach an audio file.';
                        break;
                    case 'recording':
                        message = 'Recording in progress...';
                        break;
                    case 'processing':
                        message = 'Finalizing your recording...';
                        break;
                    case 'ready_to_submit':
                        if (submission.isQueued) {
                            message = 'Saved offline. Will submit automatically when your connection is stable.';
                        } else {
                            message = source.fileName ? ('Ready to submit: ' + source.fileName) : 'Ready to submit.';
                        }
                        break;
                    case 'submitting':
                        message = 'Uploading... ' + Math.round((submission.progress || 0) * 100) + '%';
                        break;
                    case 'complete':
                        message = 'Submission successful. Thank you.';
                        break;
                    default:
                        message = 'Initializing...';
                        break;
                }
            }

            statusEl.textContent = message;
        }

        store.subscribe(render);
        render(store.getState());
    }

    STARMUS.UI = {
        initInstance: initInstance
    };

}(window));
