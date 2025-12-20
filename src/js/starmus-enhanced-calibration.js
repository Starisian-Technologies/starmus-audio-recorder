/**
 * @file starmus-enhanced-calibration.js
 * @version 1.0.0
 * @description Enhanced microphone calibration with SPARXSTAR integration.
 * Provides tier-based calibration optimization for African markets and emerging economies.
 */

'use strict';

import sparxstarIntegration from './starmus-sparxstar-integration.js';

/**
 * Enhanced Microphone Calibration System
 * Integrates with SPARXSTAR for optimal calibration based on device and network conditions
 */
class EnhancedCalibration {
    constructor() {
        this.audioContext = null;
        this.analyser = null;
        this.source = null;
        this.calibrationData = null;
        this.tier = 'C';
        this.environmentData = null;
    }

    /**
     * Initialize calibration with SPARXSTAR environment data
     */
    async init() {
        this.environmentData = sparxstarIntegration.getEnvironmentData();
        this.tier = this.environmentData?.tier || 'C';
        
        console.log('[Enhanced Calibration] Initialized for tier:', this.tier);
        return this;
    }

    /**
     * Get tier-specific calibration settings
     */
    getTierSettings() {
        const settings = {
            A: {
                duration: 15000,        // 15 seconds
                phases: 3,              // 3 calibration phases
                noiseThreshold: 5,      // Lower noise threshold
                speechThreshold: 20,    // Higher speech detection
                sampleRate: 44100,      // High quality sampling
                fftSize: 2048,          // High resolution analysis
                smoothing: 0.8,         // High smoothing
                gainRange: [0.5, 2.0],  // Wide gain range
                autoGainControl: true   // Enable AGC
            },
            B: {
                duration: 10000,        // 10 seconds
                phases: 2,              // 2 calibration phases
                noiseThreshold: 8,      // Medium noise threshold
                speechThreshold: 15,    // Medium speech detection
                sampleRate: 22050,      // Medium quality sampling
                fftSize: 1024,          // Medium resolution analysis
                smoothing: 0.6,         // Medium smoothing
                gainRange: [0.7, 1.5],  // Moderate gain range
                autoGainControl: true   // Enable AGC
            },
            C: {
                duration: 5000,         // 5 seconds
                phases: 1,              // 1 calibration phase
                noiseThreshold: 12,     // Higher noise threshold
                speechThreshold: 10,    // Lower speech detection
                sampleRate: 16000,      // Basic quality sampling
                fftSize: 512,           // Low resolution analysis
                smoothing: 0.4,         // Low smoothing
                gainRange: [0.8, 1.2],  // Narrow gain range
                autoGainControl: false  // Disable AGC for performance
            }
        };

        return settings[this.tier] || settings.C;
    }

    /**
     * Perform enhanced calibration with tier-based optimization
     */
    async performCalibration(stream, onUpdate) {
        const settings = this.getTierSettings();
        
        try {
            // Create audio context with tier-appropriate settings
            this.audioContext = new (window.AudioContext || window.webkitAudioContext)({
                sampleRate: settings.sampleRate,
                latencyHint: 'interactive'
            });

            if (this.audioContext.state === 'suspended') {
                await this.audioContext.resume();
            }

            this.source = this.audioContext.createMediaStreamSource(stream);
            this.analyser = this.audioContext.createAnalyser();
            
            this.analyser.fftSize = settings.fftSize;
            this.analyser.smoothingTimeConstant = settings.smoothing;
            
            this.source.connect(this.analyser);

            const calibrationResult = await this.runTierBasedCalibration(settings, onUpdate);
            
            // Report calibration completion to SPARXSTAR
            if (sparxstarIntegration.isAvailable) {
                sparxstarIntegration.reportError('calibration_completed', {
                    tier: this.tier,
                    duration: settings.duration,
                    phases: settings.phases,
                    result: calibrationResult,
                    network: this.environmentData?.network?.type,
                    device: this.environmentData?.device?.type
                });
            }

            return calibrationResult;

        } catch (error) {
            console.error('[Enhanced Calibration] Error:', error);
            
            // Report calibration error to SPARXSTAR
            if (sparxstarIntegration.isAvailable) {
                sparxstarIntegration.reportError('calibration_failed', {
                    error: error.message,
                    tier: this.tier,
                    network: this.environmentData?.network?.type,
                    device: this.environmentData?.device?.type
                });
            }
            
            throw error;
        } finally {
            this.cleanup();
        }
    }

    /**
     * Run tier-based calibration process
     */
    async runTierBasedCalibration(settings, onUpdate) {
        const data = new Uint8Array(this.analyser.frequencyBinCount);
        const startTime = Date.now();
        
        let maxVolume = 0;
        let minVolume = 100;
        let avgVolume = 0;
        let sampleCount = 0;
        let noiseFloor = 0;
        const speechPeaks = [];
        
        const phaseDuration = settings.duration / settings.phases;
        let currentPhase = 0;

        return new Promise((resolve) => {
            const calibrationLoop = () => {
                const elapsed = Date.now() - startTime;
                const phaseElapsed = elapsed % phaseDuration;
                const newPhase = Math.floor(elapsed / phaseDuration);
                
                if (newPhase !== currentPhase) {
                    currentPhase = newPhase;
                    console.log('[Enhanced Calibration] Phase', currentPhase + 1, 'of', settings.phases);
                }

                // Get audio data
                this.analyser.getByteFrequencyData(data);
                
                // Calculate volume metrics
                let sum = 0;
                for (let i = 0; i < data.length; i++) {
                    sum += data[i];
                }
                
                const volume = (sum / data.length) * (100 / 255);
                sampleCount++;
                avgVolume = ((avgVolume * (sampleCount - 1)) + volume) / sampleCount;
                
                if (volume > maxVolume) {maxVolume = volume;}
                if (volume < minVolume) {minVolume = volume;}

                // Phase-specific processing
                let message = '';
                const progress = (elapsed / settings.duration) * 100;

                switch (currentPhase) {
                    case 0:
                        // Noise floor measurement
                        if (volume < settings.noiseThreshold) {
                            noiseFloor = Math.max(noiseFloor, volume);
                        }
                        message = this.tier === 'C' ? 
                            'Quick setup...' : 
                            `Phase 1: Measuring background noise (${Math.ceil((phaseDuration - phaseElapsed) / 1000)}s)`;
                        break;
                        
                    case 1:
                        // Speech detection (Tier A & B only)
                        if (volume > settings.speechThreshold) {
                            speechPeaks.push(volume);
                        }
                        message = 'Phase 2: Speak your name clearly...';
                        break;
                        
                    case 2:
                        // Optimization (Tier A only)
                        message = 'Phase 3: Optimizing settings...';
                        break;
                        
                    default:
                        message = 'Calibration complete';
                }

                if (onUpdate) {
                    onUpdate(message, Math.min(volume, 100), false, {
                        phase: currentPhase + 1,
                        totalPhases: settings.phases,
                        progress: Math.min(progress, 100),
                        tier: this.tier
                    });
                }

                if (elapsed >= settings.duration) {
                    // Calculate final calibration values
                    const avgSpeechLevel = speechPeaks.length > 0 ? 
                        speechPeaks.reduce((a, b) => a + b, 0) / speechPeaks.length : 
                        maxVolume;

                    const dynamicRange = maxVolume - noiseFloor;
                    const signalToNoise = avgSpeechLevel / Math.max(noiseFloor, 1);
                    
                    // Calculate optimal gain based on tier and measurements
                    const optimalGain = this.calculateOptimalGain(
                        avgSpeechLevel, 
                        noiseFloor, 
                        dynamicRange, 
                        settings
                    );

                    const result = {
                        complete: true,
                        tier: this.tier,
                        gain: optimalGain,
                        speechLevel: avgSpeechLevel,
                        noiseFloor: noiseFloor,
                        dynamicRange: dynamicRange,
                        signalToNoise: signalToNoise,
                        sampleCount: sampleCount,
                        duration: elapsed,
                        phases: settings.phases,
                        quality: this.assessCalibrationQuality(dynamicRange, signalToNoise, settings),
                        recommendations: this.generateRecommendations(dynamicRange, signalToNoise, settings)
                    };

                    if (onUpdate) {
                        onUpdate('Calibration Complete!', 0, true, result);
                    }

                    resolve(result);
                    return;
                }

                requestAnimationFrame(calibrationLoop);
            };

            calibrationLoop();
        });
    }

    /**
     * Calculate optimal gain based on measurements and tier
     */
    calculateOptimalGain(speechLevel, noiseFloor, dynamicRange, _settings) {
        const targetLevel = 60; // Target speech level
        const baseGain = targetLevel / Math.max(speechLevel, 1);
        
        // Constrain to tier-appropriate range
        const [minGain, maxGain] = settings.gainRange;
        let optimalGain = Math.max(minGain, Math.min(maxGain, baseGain));
        
        // Adjust based on noise conditions
        if (noiseFloor > 15) {
            // High noise environment - reduce gain slightly
            optimalGain *= 0.9;
        } else if (noiseFloor < 5) {
            // Low noise environment - can increase gain
            optimalGain *= 1.1;
        }
        
        // Network-based adjustments for upload optimization
        if (this.environmentData?.network?.type === 'very_low') {
            // Very poor network - prioritize smaller file sizes
            optimalGain *= 0.8;
        }
        
        return Math.round(optimalGain * 100) / 100; // Round to 2 decimal places
    }

    /**
     * Assess calibration quality
     */
    assessCalibrationQuality(dynamicRange, signalToNoise, _settings) {
        let score = 0;
        
        // Dynamic range scoring
        if (dynamicRange > 40) {score += 3;}
        else if (dynamicRange > 20) {score += 2;}
        else if (dynamicRange > 10) {score += 1;}
        
        // Signal-to-noise ratio scoring
        if (signalToNoise > 5) {score += 3;}
        else if (signalToNoise > 3) {score += 2;}
        else if (signalToNoise > 2) {score += 1;}
        
        // Tier-based quality expectations
        const maxScore = this.tier === 'A' ? 6 : this.tier === 'B' ? 5 : 4;
        const qualityPercent = (score / maxScore) * 100;
        
        if (qualityPercent >= 80) {return 'excellent';}
        if (qualityPercent >= 60) {return 'good';}
        if (qualityPercent >= 40) {return 'fair';}
        return 'poor';
    }

    /**
     * Generate recommendations based on calibration results
     */
    generateRecommendations(dynamicRange, signalToNoise, _settings) {
        const recommendations = [];
        
        if (dynamicRange < 15) {
            recommendations.push('Consider moving to a quieter location');
        }
        
        if (signalToNoise < 2) {
            recommendations.push('Speak closer to the microphone');
        }
        
        if (this.tier === 'C' && this.environmentData?.network?.type === 'very_low') {
            recommendations.push('Recording optimized for your network conditions');
        }
        
        if (settings.autoGainControl && dynamicRange > 50) {
            recommendations.push('Automatic gain control will help maintain consistent levels');
        }
        
        return recommendations;
    }

    /**
     * Cleanup audio resources
     */
    cleanup() {
        try {
            if (this.source) {
                this.source.disconnect();
                this.source = null;
            }
            
            if (this.analyser) {
                this.analyser.disconnect();
                this.analyser = null;
            }
            
            if (this.audioContext && this.audioContext.state !== 'closed') {
                this.audioContext.close();
                this.audioContext = null;
            }
        } catch (error) {
            console.warn('[Enhanced Calibration] Cleanup error:', error);
        }
    }
}

// Export enhanced calibration
export default EnhancedCalibration;