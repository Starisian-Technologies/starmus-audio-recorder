/**
 * @file starmus-recorder.js
 * @version 4.0.0
 * @description Handles microphone recording and file attachment logic.
 */

'use strict';

import { CommandBus, debugLog } from './starmus-hooks.js';

const recorderRegistry = new Map(); // instanceId -> { mediaRecorder, chunks, stream }

/**
 * Wires microphone + file logic for a specific instance.
 *
 * @param {object} store
 * @param {string} instanceId
 */
export function initRecorder(store, instanceId) {
    // Start mic
    CommandBus.subscribe('start-mic', async (_payload, meta) => {
        if (meta.instanceId !== instanceId) {
            return;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            store.dispatch({
                type: 'starmus/error',
                error: { message: 'Microphone is not supported in this browser.', retryable: false },
                status: 'idle',
            });
            return;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            const mediaRecorder = new MediaRecorder(stream);
            const chunks = [];

            recorderRegistry.set(instanceId, { mediaRecorder, chunks, stream });

            mediaRecorder.addEventListener('dataavailable', (event) => {
                if (event.data && event.data.size > 0) {
                    chunks.push(event.data);
                }
            });

            mediaRecorder.addEventListener('stop', () => {
                const rec = recorderRegistry.get(instanceId);
                if (!rec) {
                    return;
                }
                const blob = new Blob(rec.chunks, { type: 'audio/webm' });
                const fileName = `starmus-recording-${Date.now()}.webm`;

                debugLog('Mic recording complete', instanceId, fileName);

                store.dispatch({
                    type: 'starmus/mic-complete',
                    blob,
                    fileName,
                });

                rec.stream.getTracks().forEach((track) => track.stop());
                recorderRegistry.delete(instanceId);
            });

            store.dispatch({ type: 'starmus/mic-start' });
            mediaRecorder.start();
        } catch (error) {
             
            console.error(error);
            store.dispatch({
                type: 'starmus/error',
                error: { message: 'Could not access microphone.', retryable: true },
                status: 'idle',
            });
        }
    });

    // Stop mic
    CommandBus.subscribe('stop-mic', (_payload, meta) => {
        if (meta.instanceId !== instanceId) {
            return;
        }

        const rec = recorderRegistry.get(instanceId);
        if (!rec || !rec.mediaRecorder) {
            return;
        }

        if (rec.mediaRecorder.state === 'recording') {
            store.dispatch({ type: 'starmus/mic-stop' });
            rec.mediaRecorder.stop();
        }
    });

    // Attach file from input
    CommandBus.subscribe('attach-file', (payload, meta) => {
        if (meta.instanceId !== instanceId) {
            return;
        }
        const file = payload.file;
        if (!file) {
            return;
        }

        debugLog('File attached', instanceId, file.name);

        store.dispatch({
            type: 'starmus/file-attached',
            file,
        });
    });

    // Reset
    CommandBus.subscribe('reset', (_payload, meta) => {
        if (meta.instanceId !== instanceId) {
            return;
        }
        const rec = recorderRegistry.get(instanceId);
        if (rec) {
            if (rec.mediaRecorder && rec.mediaRecorder.state === 'recording') {
                rec.mediaRecorder.stop();
            }
            if (rec.stream) {
                rec.stream.getTracks().forEach((track) => track.stop());
            }
            recorderRegistry.delete(instanceId);
        }
    });
}
