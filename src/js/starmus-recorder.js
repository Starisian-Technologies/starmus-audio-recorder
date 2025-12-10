/**
 * @file starmus-recorder.js
 * @version 5.2.0-SAFE
 * @description Recorder logic. Simplified initialization to prevent crashes.
 */

'use strict';

import { CommandBus } from './starmus-hooks.js';

let sharedAudioContext = null;
const activeRecorders = {}; // Simple object map instead of Map for safety

// AUDIO CONTEXT HELPER
function getContext() {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) throw new Error('Audio API not supported');
    
    if (!sharedAudioContext || sharedAudioContext.state === 'closed') {
        sharedAudioContext = new Ctx();
        window.StarmusAudioContext = sharedAudioContext;
    }
    return sharedAudioContext;
}

async function wakeContext() {
    const ctx = getContext();
    if (ctx.state === 'suspended') {
        console.log('[Recorder] Waking AudioContext...');
        await ctx.resume();
    }
    return ctx;
}

// CALIBRATION
async function runCalibration(onUpdate) {
    const ctx = await wakeContext();
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    const source = ctx.createMediaStreamSource(stream);
    const analyser = ctx.createAnalyser();
    source.connect(analyser);

    const data = new Uint8Array(analyser.frequencyBinCount);
    const start = Date.now();
    
    return new Promise(resolve => {
        function loop() {
            analyser.getByteFrequencyData(data);
            let sum = 0;
            for(let i=0; i<data.length; i++) sum += data[i];
            const avg = sum / data.length;
            const pct = Math.min(100, avg * 2); // Simple gain math
            
            if (onUpdate) onUpdate('Calibrating...', pct);

            if (Date.now() - start < 4000) { // 4 seconds
                requestAnimationFrame(loop);
            } else {
                stream.getTracks().forEach(t => t.stop());
                resolve({ complete: true, gain: 1.0 });
            }
        }
        loop();
    });
}

// MAIN INIT
export function initRecorder(store, instanceId) {
    console.log('[Recorder] Initializing for ID:', instanceId);

    // 1. SETUP MIC
    CommandBus.subscribe('setup-mic', async () => {
        console.log('[Recorder] Setup Mic Triggered');
        try {
            store.dispatch({ type: 'starmus/calibration-start' });
            
            const calibration = await runCalibration((msg, vol) => {
                store.dispatch({ type: 'starmus/calibration-update', message: msg, volumePercent: vol });
            });

            store.dispatch({ type: 'starmus/calibration-complete', payload: { calibration } });
        } catch (e) {
            console.error('[Recorder] Calibration failed:', e);
            store.dispatch({ type: 'starmus/error', payload: { message: 'Microphone failed.' } });
        }
    }, instanceId);

    // 2. START RECORDING
    CommandBus.subscribe('start-recording', async () => {
        console.log('[Recorder] Start Recording Triggered');
        try {
            const ctx = await wakeContext();
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            
            // Connect stream -> destination
            const source = ctx.createMediaStreamSource(stream);
            const dest = ctx.createMediaStreamDestination();
            source.connect(dest);

            const mediaRecorder = new MediaRecorder(dest.stream);
            const chunks = [];

            mediaRecorder.ondataavailable = e => { if (e.data.size > 0) chunks.push(e.data); };
            
            mediaRecorder.onstop = () => {
                const blob = new Blob(chunks, { type: 'audio/webm' });
                const fileName = `rec-${Date.now()}.webm`;
                store.dispatch({ type: 'starmus/recording-available', payload: { blob, fileName } });
                stream.getTracks().forEach(t => t.stop());
            };

            activeRecorders[instanceId] = mediaRecorder;
            mediaRecorder.start(1000);
            
            store.dispatch({ type: 'starmus/mic-start' });

            // Visualizer Loop
            const analyser = ctx.createAnalyser();
            source.connect(analyser);
            const data = new Uint8Array(analyser.frequencyBinCount);
            
            function visLoop() {
                if (mediaRecorder.state !== 'recording') return;
                analyser.getByteFrequencyData(data);
                let sum = 0; for(let i=0; i<data.length; i++) sum += data[i];
                const vol = Math.min(100, (sum / data.length) * 2);
                
                store.dispatch({ 
                    type: 'starmus/recorder-tick', 
                    duration: (Date.now() - startTime)/1000, 
                    amplitude: vol 
                });
                requestAnimationFrame(visLoop);
            }
            const startTime = Date.now();
            visLoop();

        } catch (e) {
            console.error('[Recorder] Start failed:', e);
            store.dispatch({ type: 'starmus/error', payload: { message: 'Could not start recording.' } });
        }
    }, instanceId);

    // 3. STOP / PAUSE / CONTROLS
    CommandBus.subscribe('stop-mic', () => {
        const mr = activeRecorders[instanceId];
        if (mr && mr.state === 'recording') {
            mr.stop();
            store.dispatch({ type: 'starmus/mic-stop' });
        }
    }, instanceId);
    
    // Resume/Pause handlers can be added here similarly
}