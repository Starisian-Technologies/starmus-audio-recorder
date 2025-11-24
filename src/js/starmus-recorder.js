/**
 * @file starmus-recorder.js
 * @version 4.6.0
 * @description Production Recorder.
 * FIXED: ChromeOS Permission logic, Track validation, and Autoplay unlocking.
 */

'use strict';

import { CommandBus, debugLog } from './starmus-hooks.js';

const recorderRegistry = new Map();
let sharedAudioContext = null;

function getSharedContext() {
    if (!sharedAudioContext || sharedAudioContext.state === 'closed') {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        sharedAudioContext = new Ctx({ latencyHint: 'playback' });
    }
    return sharedAudioContext;
}

// --- HELPER: Unlocks AudioContext on first interaction ---
function ensureContextResumed(ctx) {
    if (ctx.state === 'suspended') {
        ctx.resume().catch(() => {
            debugLog('[Audio] Context suspended. Waiting for gesture...');
            const unlock = () => {
                ctx.resume();
                document.removeEventListener('click', unlock);
                document.removeEventListener('touchstart', unlock);
            };
            document.addEventListener('click', unlock, { once: true });
            document.addEventListener('touchstart', unlock, { once: true });
        });
    }
}

async function calibrateAudioLevels(stream, onUpdate) {
    return new Promise((resolve) => {
        const audioContext = getSharedContext();
        const analyser = audioContext.createAnalyser();
        analyser.fftSize = 2048;
        
        const microphone = audioContext.createMediaStreamSource(stream);
        microphone.connect(analyser);
        
        const buffer = new Float32Array(analyser.fftSize);
        const samples = [];
        const startTime = performance.now();
        const DURATION = 8000; // Reduced to 8s for better UX
        const VOLUME_SCALE_FACTOR = 2000;
        
        function tick() {
            const elapsed = performance.now() - startTime;
            const remaining = Math.ceil((DURATION - elapsed) / 1000);
            
            let message = '';
            if (elapsed < 3000) message = \`Background noise check (\${remaining}s)\`;
            else if (elapsed < 6000) message = \`Speak normally (\${remaining}s)\`;
            else message = \`Finalizing...\`;
            
            analyser.getFloatTimeDomainData(buffer);
            let sum = 0;
            for (let i = 0; i < buffer.length; i++) sum += buffer[i] * buffer[i];
            const rms = Math.sqrt(sum / buffer.length);

            if (Number.isFinite(rms)) {
                samples.push(rms);
                if (onUpdate) onUpdate(message, Math.min(100, rms * VOLUME_SCALE_FACTOR), false);
            }
            
            if (elapsed < DURATION) {
                requestAnimationFrame(tick);
            } else {
                // Finish
                try { microphone.disconnect(); analyser.disconnect(); } catch {}
                const avg = samples.reduce((a,b) => a + b, 0) / (samples.length || 1);
                const gain = Math.max(1.0, Math.min(4.0, 0.1 / (avg || 0.01)));
                
                const calibration = {
                    gain: parseFloat(gain.toFixed(3)),
                    complete: true,
                    timestamp: new Date().toISOString()
                };
                
                if (onUpdate) onUpdate(\`Ready. (Gain ×\${gain.toFixed(1)})\`, 0, true);
                resolve(calibration);
            }
        }
        tick();
    });
}

function setupAudioGraph(rawStream) {
    const audioContext = getSharedContext();
    const source = audioContext.createMediaStreamSource(rawStream);
    
    const highPass = audioContext.createBiquadFilter();
    highPass.type = 'highpass';
    highPass.frequency.value = 85; 
    
    const compressor = audioContext.createDynamicsCompressor();
    compressor.threshold.value = -20;
    compressor.knee.value = 40;
    compressor.ratio.value = 12;
    compressor.attack.value = 0;
    compressor.release.value = 0.25;
    
    const analyser = audioContext.createAnalyser();
    analyser.fftSize = 2048;

    const destination = audioContext.createMediaStreamAudioDestination();
    
    source.connect(highPass);
    highPass.connect(compressor);
    compressor.connect(analyser);
    analyser.connect(destination);
    
    return {
        audioContext,
        destinationStream: destination.stream,
        analyser,
        nodes: [source, highPass, compressor, analyser, destination] 
    };
}

export function initRecorder(store, instanceId) {
    CommandBus.subscribe('start-mic', async (_payload, meta) => {
        if (meta.instanceId !== instanceId) return;

        // --- FIX 1: Resume Context IMMEDIATELY on user click ---
        const ctx = getSharedContext();
        ensureContextResumed(ctx);

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            store.dispatch({ type: 'starmus/error', error: { message: 'Microphone not supported.' } });
            return;
        }

        try {
            const config = window.starmusConfig || {};
            
            // --- FIX 2: Force Permission Request (ChromeOS) ---
            // Request simple access first to trigger the popup immediately
            try {
                const permStream = await navigator.mediaDevices.getUserMedia({ audio: true });
                permStream.getTracks().forEach(t => t.stop()); // Release immediately
            } catch (permErr) {
                console.warn('[Recorder] Permission check failed', permErr);
                throw new Error('Microphone permission denied. Please allow access.');
            }

            // Now get the real stream with constraints
            const rawStream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true
                }
            });

            // --- FIX 3: Verify Tracks ---
            if (rawStream.getAudioTracks().length === 0) {
                throw new Error('Microphone stream is empty (System Muted?)');
            }
            
            store.dispatch({ type: 'starmus/calibration-start' });
            
            const calibration = await calibrateAudioLevels(rawStream, (message, volumePercent, isDone) => {
                if (isDone) store.dispatch({ type: 'starmus/calibration-complete', calibration });
                else store.dispatch({ type: 'starmus/calibration-update', message, volumePercent });
            });
            
            const { audioContext, destinationStream, analyser, nodes } = setupAudioGraph(rawStream);
            
            const mediaRecorder = new MediaRecorder(destinationStream, { mimeType: 'audio/webm;codecs=opus' });
            const chunks = [];
            let transcript = '';

            // Speech Rec Setup
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            let recognition = null;
            if (SpeechRecognition) {
                try {
                    recognition = new SpeechRecognition();
                    recognition.continuous = true;
                    recognition.interimResults = true;
                    recognition.lang = 'en-US';
                    recognition.onresult = (e) => {
                        let txt = '';
                        for (let i = 0; i < e.results.length; i++) if (e.results[i].isFinal) txt += e.results[i][0].transcript + ' ';
                        transcript += txt;
                        store.dispatch({ type: 'starmus/transcript-update', transcript: transcript.trim() });
                    };
                    recognition.start();
                } catch (e) { debugLog('Speech rec error (ignored)', e); }
            }

            // --- VISUALIZER LOOP ---
            const startTime = performance.now();
            const meterBuffer = new Float32Array(analyser.fftSize);
            let rafId = null;

            function meterLoop() {
                if (mediaRecorder.state !== 'recording') return;

                analyser.getFloatTimeDomainData(meterBuffer);
                let sum = 0;
                for (let i = 0; i < meterBuffer.length; i++) sum += meterBuffer[i] * meterBuffer[i];
                const rms = Math.sqrt(sum / meterBuffer.length);
                
                store.dispatch({
                    type: 'starmus/recorder-tick',
                    duration: (performance.now() - startTime) / 1000,
                    amplitude: Math.min(100, Math.max(0, rms * 4000))
                });

                rafId = requestAnimationFrame(meterLoop);
            }

            // Register instance
            const recRef = { 
                mediaRecorder, chunks, rawStream, processedStream: destinationStream, 
                audioContext, audioNodes: nodes, recognition, transcript, calibration, rafId: null 
            };
            recorderRegistry.set(instanceId, recRef);

            // Handlers
            mediaRecorder.ondataavailable = e => { if (e.data.size > 0) chunks.push(e.data); };
            
            mediaRecorder.onstop = () => {
                const active = recorderRegistry.get(instanceId);
                if (!active) return;

                if (active.rafId) cancelAnimationFrame(active.rafId);
                if (active.recognition) try { active.recognition.stop(); } catch {}

                const blob = new Blob(chunks, { type: 'audio/webm' });
                
                store.dispatch({
                    type: 'starmus/recording-available',
                    payload: { blob, fileName: \`rec-\${Date.now()}.webm\` }
                });

                // Cleanup
                [active.rawStream, active.processedStream].forEach(s => s?.getTracks().forEach(t => t.stop()));
                active.audioNodes.forEach(n => { try { n.disconnect(); } catch {} });
                
                recorderRegistry.delete(instanceId);
            };

            store.dispatch({ type: 'starmus/mic-start' });
            mediaRecorder.start(1000);
            recRef.rafId = requestAnimationFrame(meterLoop);

        } catch (error) {
            console.error('[Recorder] Init failed:', error);
            store.dispatch({
                type: 'starmus/error',
                error: { message: error.message || 'Could not access microphone.' },
                status: 'idle' // Reset to idle so they can try again
            });
        }
    });

    CommandBus.subscribe('stop-mic', (_, meta) => {
        if (meta.instanceId !== instanceId) return;
        const rec = recorderRegistry.get(instanceId);
        if (rec?.mediaRecorder?.state === 'recording') {
            store.dispatch({ type: 'starmus/mic-stop' });
            rec.mediaRecorder.stop();
        }
    });

    CommandBus.subscribe('reset', (_, meta) => {
        if (meta.instanceId !== instanceId) return;
        const rec = recorderRegistry.get(instanceId);
        if (rec) {
            if (rec.mediaRecorder.state !== 'inactive') rec.mediaRecorder.stop();
            if (rec.rafId) cancelAnimationFrame(rec.rafId);
            [rec.rawStream].forEach(s => s?.getTracks().forEach(t => t.stop()));
            recorderRegistry.delete(instanceId);
        }
    });
}
