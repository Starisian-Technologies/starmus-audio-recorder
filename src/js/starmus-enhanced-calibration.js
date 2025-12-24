/**
 * @file starmus-enhanced-calibration.js
 * @version 1.0.1
 * @description Enhanced mic calibration with phase correction and Tier mapping.
 */

'use strict';

import sparxstarIntegration from './starmus-sparxstar-integration.js';

class EnhancedCalibration {
    constructor() {
        this.audioContext = null;
        this.analyser = null;
        this.source = null;
        this.tier = 'C';
        this.environmentData = null;
    }

    /**
     * Initialize with mapped tier data
     */
    async init() {
        this.environmentData = sparxstarIntegration.getEnvironmentData();
        this.tier = this.environmentData?.tier || 'C';
        console.log('[Enhanced Calibration] Initializing with Tier:', this.tier);
        return this;
    }

    /**
     * Define settings based on Tier
     */
    getTierSettings() {
        const settings = {
            A: { duration: 15000, phases: 3, noiseThreshold: 5, speechThreshold: 20, sampleRate: 44100, fftSize: 2048, smoothing: 0.8, gainRange: [0.5, 2.0] },
            B: { duration: 10000, phases: 2, noiseThreshold: 8, speechThreshold: 15, sampleRate: 22050, fftSize: 1024, smoothing: 0.6, gainRange: [0.7, 1.5] },
            C: { duration: 5000,  phases: 1, noiseThreshold: 12, speechThreshold: 10, sampleRate: 16000, fftSize: 512,  smoothing: 0.4, gainRange: [0.8, 1.2] }
        };
        return settings[this.tier] || settings.C;
    }

    /**
     * Main entry point for calibration
     */
    async performCalibration(stream, onUpdate) {
        const settings = this.getTierSettings();
        
        try {
            this.audioContext = new (window.AudioContext || window.webkitAudioContext)({
                sampleRate: settings.sampleRate
            });

            if (this.audioContext.state === 'suspended') await this.audioContext.resume();

            this.source = this.audioContext.createMediaStreamSource(stream);
            this.analyser = this.audioContext.createAnalyser();
            this.analyser.fftSize = settings.fftSize;
            this.analyser.smoothingTimeConstant = settings.smoothing;
            this.source.connect(this.analyser);

            const result = await this.runTierBasedCalibration(settings, onUpdate);
            
            sparxstarIntegration.reportError('calibration_completed', {
                tier: this.tier,
                result: result
            });

            return result;
        } catch (error) {
            sparxstarIntegration.reportError('calibration_failed', { error: error.message, tier: this.tier });
            throw error;
        } finally {
            this.cleanup();
        }
    }

    /**
     * Calibration loop logic
     */
    async runTierBasedCalibration(settings, onUpdate) {
        const data = new Uint8Array(this.analyser.frequencyBinCount);
        const startTime = Date.now();
        
        let maxVolume = 0;
        let sampleCount = 0;
        let noiseFloor = 0;
        const speechPeaks = [];
        const phaseDuration = settings.duration / settings.phases;
        let currentPhase = 0;

        return new Promise((resolve) => {
            const calibrationLoop = () => {
                const elapsed = Date.now() - startTime;

                // 1. COMPLETION CHECK (Must be first to prevent phase overflow)
                if (elapsed >= settings.duration) {
                    const avgSpeech = speechPeaks.length > 0 ? speechPeaks.reduce((a,b)=>a+b,0)/speechPeaks.length : maxVolume;
                    const result = {
                        complete: true,
                        tier: this.tier,
                        noiseFloor: noiseFloor,
                        speechLevel: avgSpeech,
                        quality: avgSpeech > 20 ? 'excellent' : 'fair'
                    };
                    if (onUpdate) onUpdate('Complete', 0, true, result);
                    resolve(result);
                    return;
                }

                // 2. PHASE UPDATE LOGIC
                const newPhase = Math.floor(elapsed / phaseDuration);
                if (newPhase !== currentPhase && newPhase < settings.phases) {
                    currentPhase = newPhase;
                    console.log(`[Enhanced Calibration] Transition to Phase ${currentPhase + 1}`);
                }

                // 3. AUDIO ANALYSIS
                this.analyser.getByteFrequencyData(data);
                let sum = 0;
                for (let i = 0; i < data.length; i++) sum += data[i];
                const volume = (sum / data.length);
                sampleCount++;

                if (volume > maxVolume) maxVolume = volume;
                if (currentPhase === 0 && volume < settings.noiseThreshold) noiseFloor = Math.max(noiseFloor, volume);
                if (currentPhase > 0 && volume > settings.speechThreshold) speechPeaks.push(volume);

                if (onUpdate) {
                    onUpdate(`Phase ${currentPhase + 1}: Calibrating...`, volume, false, {
                        phase: currentPhase + 1,
                        totalPhases: settings.phases,
                        progress: (elapsed / settings.duration) * 100,
                        tier: this.tier
                    });
                }

                requestAnimationFrame(calibrationLoop);
            };

            calibrationLoop();
        });
    }

    cleanup() {
        try {
            if (this.source) this.source.disconnect();
            if (this.analyser) this.analyser.disconnect();
            if (this.audioContext) this.audioContext.close();
        } catch (e) { console.warn('Cleanup error', e); }
    }
}

export default EnhancedCalibration;
