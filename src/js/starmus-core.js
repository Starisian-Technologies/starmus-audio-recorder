/**
 * @file starmus-core.js
 * @version 4.0.0
 * @description Handles submission (upload) and reset business logic.
 */

'use strict';

import { CommandBus, debugLog } from './starmus-hooks.js';

/**
 * Wire submission logic for a specific instance.
 *
 * @param {object} store
 * @param {string} instanceId
 * @param {object} env - Environment payload from sparxstar-user-environment-check.
 */
export function initCore(store, instanceId, env) {
    async function handleSubmit(formFields) {
        const state = store.getState();
        const { source } = state;

        if (!source || (!source.blob && !source.file)) {
            store.dispatch({
                type: 'starmus/error',
                error: { message: 'Please record or attach audio before submitting.', retryable: false },
                status: state.status,
            });
            return;
        }

        const config = (window && window.starmusConfig) ? window.starmusConfig : {};
        const endpoints = config.endpoints || {};
        const nonce = config.nonce || '';

        const directUpload = endpoints.directUpload || '';
        if (!directUpload) {
            store.dispatch({
                type: 'starmus/error',
                error: { message: 'Upload endpoint is not configured.', retryable: true },
                status: state.status,
            });
            return;
        }

        const fd = new FormData();
        Object.keys(formFields).forEach((key) => {
            fd.append(key, formFields[key]);
        });

        if (source.blob) {
            fd.append('audio_file', source.blob, source.fileName || 'recording.webm');
        } else if (source.file) {
            fd.append('audio_file', source.file, source.file.name || 'upload.webm');
        }

        // You can attach environment diagnostics as needed.
        fd.append('_starmus_env', JSON.stringify(env || {}));

        store.dispatch({ type: 'starmus/submit-start' });

        try {
            debugLog('Submitting audio for instance', instanceId, directUpload);

            const response = await fetch(directUpload, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': nonce,
                },
                body: fd,
            });

            if (!response.ok) {
                throw new Error(`Upload failed with status ${response.status}`);
            }

            store.dispatch({ type: 'starmus/submit-complete' });
        } catch (error) {
            // eslint-disable-next-line no-console
            console.error(error);
            store.dispatch({
                type: 'starmus/error',
                error: { message: error.message || 'Upload failed.', retryable: true },
                status: 'ready_to_submit',
            });
        }
    }

    // CommandBus hook
    CommandBus.subscribe('submit', (payload, meta) => {
        if (meta.instanceId !== instanceId) {
            return;
        }
        handleSubmit(payload.formFields || {});
    });

    CommandBus.subscribe('reset', (_payload, meta) => {
        if (meta.instanceId !== instanceId) {
            return;
        }
        store.dispatch({ type: 'starmus/reset' });
    });

    return {
        handleSubmit,
    };
}
