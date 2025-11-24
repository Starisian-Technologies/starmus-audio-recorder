/**
 * @file starmus-integrator.js
 * @version 4.6.0
 */

'use strict';

import * as tus from 'tus-js-client';
window.tus = tus;
import Peaks from 'peaks.js';
window.Peaks = Peaks;

import { CommandBus } from './starmus-hooks.js';
import { createStore } from './starmus-state-store.js';
import { initInstance as initUI } from './starmus-ui.js';
import { initRecorder } from './starmus-recorder.js';
import { initCore } from './starmus-core.js';
import './starmus-tus.js'; 
import { getOfflineQueue } from './starmus-offline.js';

getOfflineQueue().catch(console.error);
const instances = new Map();

function detectTier(env) {
    if (/\bCrOS\b|Chrome OS/i.test(navigator.userAgent)) return 'A';
    const caps = env.capabilities || {};
    if (!caps.mediaRecorder) return 'C';
    return 'A';
}

async function wireInstance(env, formEl) {
    let instanceId = formEl.getAttribute('data-starmus-id');
    if (!instanceId) {
        instanceId = 'starmus_' + Date.now() + '_' + Math.random().toString(16).slice(2);
        formEl.setAttribute('data-starmus-id', instanceId);
    }
    if (instances.has(instanceId)) return instanceId;

    let tier = detectTier(env);
    const store = createStore({ instanceId, env, tier });

    const elements = {
        step1: formEl.querySelector('.starmus-step-1'),
        step2: formEl.querySelector('.starmus-step-2'),
        continueBtn: formEl.querySelector('[data-starmus-action="continue"]'),
        recordBtn: formEl.querySelector('[data-starmus-action="record"]'),
        stopBtn: formEl.querySelector('[data-starmus-action="stop"]'),
        submitBtn: formEl.querySelector('[data-starmus-action="submit"]'),
        resetBtn: formEl.querySelector('[data-starmus-action="reset"]'),
        playBtn: formEl.querySelector('[data-starmus-action="play"]'),
        reviewControls: formEl.querySelector('.starmus-review-controls'),
        timer: formEl.querySelector('[data-starmus-timer]'),
        
        // Enhanced Selector
        volumeMeter: formEl.querySelector('[data-starmus-volume-meter]'),
        waveformBox: formEl.querySelector('[data-starmus-waveform], [data-starmus-waveform-box]'),
        
        fileInput: formEl.querySelector('input[type="file"]'),
        statusEl: formEl.querySelector('[data-starmus-status]'),
        progressEl: formEl.querySelector('[data-starmus-progress]'),
        progressWrap: formEl.querySelector('.starmus-progress-wrap'),
        recorderContainer: formEl.querySelector('[data-starmus-recorder-container]'),
        fallbackContainer: formEl.querySelector('[data-starmus-fallback-container]'),
    };

    if (tier === 'C') {
        if (elements.recorderContainer) elements.recorderContainer.style.display = 'none';
        if (elements.fallbackContainer) elements.fallbackContainer.style.display = 'block';
    }

    // INIT ORDER
    initUI(store, elements);
    if (tier !== 'C') initRecorder(store, instanceId);
    initCore(store, instanceId, env);

    instances.set(instanceId, { store, form: formEl, elements, tier });
    store.dispatch({ type: 'starmus/init', payload: { instanceId, env, tier, speechSupported: true } });

    // Listeners
    if (elements.continueBtn) elements.continueBtn.addEventListener('click', (e) => { e.preventDefault(); store.dispatch({ type: 'starmus/ui/step-continue' }); });
    if (elements.recordBtn) elements.recordBtn.addEventListener('click', (e) => { e.preventDefault(); CommandBus.dispatch('start-mic', {}, { instanceId }); });
    if (elements.stopBtn) elements.stopBtn.addEventListener('click', (e) => { e.preventDefault(); CommandBus.dispatch('stop-mic', {}, { instanceId }); });
    if (elements.resetBtn) elements.resetBtn.addEventListener('click', (e) => { e.preventDefault(); CommandBus.dispatch('reset', {}, { instanceId }); });
    if (elements.fileInput) elements.fileInput.addEventListener('change', () => { if(elements.fileInput.files[0]) CommandBus.dispatch('attach-file', { file: elements.fileInput.files[0] }, { instanceId }); });

    // Playback
    let audioEl = null;
    if (elements.playBtn) {
        elements.playBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const state = store.getState();
            const blob = state.source.blob || state.source.file;
            if (!blob) return;

            if (!audioEl) {
                audioEl = new Audio(URL.createObjectURL(blob));
                audioEl.onended = () => store.dispatch({ type: 'starmus/recorder-playback-state', isPlaying: false });
            }
            if (state.recorder.isPlaying) {
                audioEl.pause();
                store.dispatch({ type: 'starmus/recorder-playback-state', isPlaying: false });
            } else {
                audioEl.play();
                store.dispatch({ type: 'starmus/recorder-playback-state', isPlaying: true });
            }
        });
    }
    CommandBus.subscribe('reset', (_, meta) => { if(meta.instanceId === instanceId && audioEl) { audioEl.pause(); audioEl = null; } });

    formEl.addEventListener('submit', (e) => {
        e.preventDefault();
        const formData = new FormData(formEl);
        const formFields = {};
        formData.forEach((v, k) => formFields[k] = v);
        CommandBus.dispatch('submit', { formFields }, { instanceId });
    });

    return instanceId;
}

async function onEnvironmentReady(event) {
    document.querySelectorAll('form[data-starmus="recorder"]').forEach(f => wireInstance(event.detail || {}, f));
}

document.addEventListener('sparxstar:environment-ready', onEnvironmentReady);
setTimeout(() => { if(instances.size === 0) onEnvironmentReady({ detail: { capabilities: { mediaRecorder: true } } }); }, 2000);

if (typeof window !== 'undefined') {
    window.STARMUS = window.STARMUS || {};
    window.STARMUS.instances = instances;
}
