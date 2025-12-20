/**
 * @file starmus-sparxstar-integration.js
 * @version 1.0.0
 * @description Enhanced SPARXSTAR integration for environment detection, error reporting,
 * and tier-based optimization for African markets and emerging economies.
 */

'use strict';

/**
 * SPARXSTAR Integration Manager
 * Handles communication with SPARXSTAR plugin for environment detection and error reporting
 */
class SparxstarIntegration {
    constructor() {
        this.isAvailable = false;
        this.environmentData = null;
        this.fingerprintId = null;
        this.tier = 'C'; // Default to lowest tier
        this.initTimeout = null;
        this.callbacks = new Set();
    }

    /**
     * Initialize SPARXSTAR integration with adaptive timeout
     */
    async init() {
        return new Promise((resolve) => {
            // Detect network quality first
            const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
            let networkType = 'unknown';
            
            if (connection) {
                const effectiveType = connection.effectiveType;
                if (effectiveType === 'slow-2g' || effectiveType === '2g') {networkType = 'very_low';}
                else if (effectiveType === '3g') {networkType = 'low';}
                else {networkType = 'high';}
            }
            
            // Adaptive timeout based on network
            const timeouts = {
                'very_low': 15000,  // 15s for 2G
                'low': 8000,        // 8s for 3G  
                'high': 3000,       // 3s for 4G+
                'unknown': 10000    // 10s fallback
            };
            
            const timeout = timeouts[networkType];
            console.log(`[SPARXSTAR] Using ${timeout}ms timeout for ${networkType} network`);
            
            // Set adaptive timeout
            this.initTimeout = setTimeout(() => {
                console.warn(`[SPARXSTAR] Timeout after ${timeout}ms - using fallback detection`);
                this.initFallback();
                resolve(this.getEnvironmentData());
            }, timeout);

            // Listen for SPARXSTAR ready event
            document.addEventListener('sparxstar-state-ready', () => {
                clearTimeout(this.initTimeout);
                this.initSparxstar();
                resolve(this.getEnvironmentData());
            });

            // Also try immediate initialization if already loaded
            if (window.SPARXSTAR?.Utils) {
                clearTimeout(this.initTimeout);
                this.initSparxstar();
                resolve(this.getEnvironmentData());
            }
        });
    }

    /**
     * Initialize with SPARXSTAR plugin
     */
    initSparxstar() {
        try {
            if (!window.SPARXSTAR?.Utils) {
                throw new Error('SPARXSTAR.Utils not available');
            }

            this.isAvailable = true;
            
            // Get environment data
            const networkBandwidth = window.SPARXSTAR.Utils.getNetworkBandwidth?.() || 'unknown';
            const deviceType = window.SPARXSTAR.Utils.getDeviceType?.() || 'unknown';
            const deviceSpecs = window.SPARXSTAR.Utils.getDeviceSpecs?.() || {};
            const networkQuality = window.SPARXSTAR.Utils.getNetworkQuality?.() || {};
            
            // Get fingerprint ID for error reporting
            this.fingerprintId = window.SPARXSTAR.Utils.getFingerprint?.() || null;

            this.environmentData = {
                source: 'sparxstar',
                network: {
                    bandwidth: networkBandwidth,
                    quality: networkQuality,
                    type: this.getNetworkType(networkBandwidth)
                },
                device: {
                    type: deviceType,
                    specs: deviceSpecs,
                    capabilities: this.getDeviceCapabilities(deviceSpecs)
                },
                fingerprint: this.fingerprintId,
                timestamp: Date.now()
            };

            // Determine tier based on environment
            this.tier = this.calculateTier(this.environmentData);

            console.log('[SPARXSTAR] Environment detected:', {
                tier: this.tier,
                network: networkBandwidth,
                device: deviceType
            });

            // Setup error reporting
            this.setupErrorReporting();

        } catch (error) {
            console.warn('[SPARXSTAR] Failed to initialize:', error.message);
            this.initFallback();
        }
    }

    /**
     * Fallback initialization without SPARXSTAR
     */
    initFallback() {
        this.isAvailable = false;
        
        // Basic browser-based detection
        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        const networkType = connection?.effectiveType || 'unknown';
        const deviceMemory = navigator.deviceMemory || 'unknown';
        const hardwareConcurrency = navigator.hardwareConcurrency || 'unknown';

        this.environmentData = {
            source: 'fallback',
            network: {
                bandwidth: networkType,
                quality: { latency: 'unknown', stability: 'unknown' },
                type: this.getNetworkType(networkType)
            },
            device: {
                type: this.detectDeviceType(),
                specs: {
                    memory: deviceMemory,
                    cores: hardwareConcurrency
                },
                capabilities: this.getBasicCapabilities()
            },
            fingerprint: this.generateBasicFingerprint(),
            timestamp: Date.now()
        };

        this.tier = this.calculateTier(this.environmentData);

        console.log('[SPARXSTAR] Fallback detection:', {
            tier: this.tier,
            network: networkType,
            device: this.environmentData.device.type
        });
    }

    /**
     * Calculate optimal tier based on environment data
     */
    calculateTier(envData) {
        const network = envData.network;
        const device = envData.device;

        // Tier C: Very limited (2G, slow-2G, or very old devices)
        if (network.type === 'very_low' || 
            (device.specs.memory && device.specs.memory < 2) ||
            (device.specs.cores && device.specs.cores < 2)) {
            return 'C';
        }

        // Tier B: Limited (3G or mobile devices with constraints)
        if (network.type === 'low' || 
            device.type === 'mobile' ||
            (device.specs.memory && device.specs.memory < 4)) {
            return 'B';
        }

        // Tier A: Full capabilities (4G+ and desktop/modern mobile)
        return 'A';
    }

    /**
     * Get network type classification
     */
    getNetworkType(bandwidth) {
        if (typeof bandwidth === 'string') {
            switch (bandwidth.toLowerCase()) {
                case 'slow-2g':
                case '2g': return 'very_low';
                case '3g': return 'low';
                case '4g':
                case 'lte':
                case '5g': return 'high';
                default: return 'unknown';
            }
        }
        
        // Numeric bandwidth (Mbps)
        if (typeof bandwidth === 'number') {
            if (bandwidth < 1) {return 'very_low';}
            if (bandwidth < 5) {return 'low';}
            return 'high';
        }

        return 'unknown';
    }

    /**
     * Detect device type from user agent
     */
    detectDeviceType() {
        const ua = navigator.userAgent.toLowerCase();
        if (/mobile|android|iphone|ipad|tablet/.test(ua)) {return 'mobile';}
        return 'desktop';
    }

    /**
     * Get device capabilities based on specs
     */
    getDeviceCapabilities(specs = {}) {
        return {
            mediaRecorder: typeof MediaRecorder !== 'undefined',
            audioContext: !!(window.AudioContext || window.webkitAudioContext),
            speechRecognition: !!(window.SpeechRecognition || window.webkitSpeechRecognition),
            getUserMedia: !!(navigator.mediaDevices?.getUserMedia),
            webGL: !!document.createElement('canvas').getContext('webgl'),
            serviceWorker: 'serviceWorker' in navigator,
            indexedDB: 'indexedDB' in window
        };
    }

    /**
     * Get basic capabilities for fallback
     */
    getBasicCapabilities() {
        return this.getDeviceCapabilities();
    }

    /**
     * Generate basic fingerprint for fallback
     */
    generateBasicFingerprint() {
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        ctx.textBaseline = 'top';
        ctx.font = '14px Arial';
        ctx.fillText('Starmus fingerprint', 2, 2);
        
        const fingerprint = [
            navigator.userAgent,
            navigator.language,
            screen.width + 'x' + screen.height,
            new Date().getTimezoneOffset(),
            canvas.toDataURL()
        ].join('|');

        return btoa(fingerprint).substring(0, 16);
    }

    /**
     * Setup error reporting to SPARXSTAR
     */
    setupErrorReporting() {
        if (!this.isAvailable || !window.SPARXSTAR?.ErrorReporter) {return;}

        // Global error handler
        window.addEventListener('error', (event) => {
            this.reportError('javascript_error', {
                message: event.message,
                filename: event.filename,
                lineno: event.lineno,
                colno: event.colno,
                stack: event.error?.stack
            });
        });

        // Unhandled promise rejections
        window.addEventListener('unhandledrejection', (event) => {
            this.reportError('unhandled_rejection', {
                reason: event.reason?.toString(),
                stack: event.reason?.stack
            });
        });

        console.log('[SPARXSTAR] Error reporting enabled');
    }

    /**
     * Report error to SPARXSTAR
     */
    reportError(type, details) {
        if (!this.isAvailable || !window.SPARXSTAR?.ErrorReporter) {
            console.warn('[SPARXSTAR] Error reporting not available:', type, details);
            return;
        }

        try {
            window.SPARXSTAR.ErrorReporter.report({
                type,
                details,
                fingerprint: this.fingerprintId,
                tier: this.tier,
                environment: this.environmentData,
                timestamp: Date.now(),
                userAgent: navigator.userAgent,
                url: window.location.href
            });
        } catch (error) {
            console.error('[SPARXSTAR] Failed to report error:', error);
        }
    }

    /**
     * Get optimized recording settings based on tier
     */
    getRecordingSettings() {
        const settings = {
            A: {
                sampleRate: 44100,
                bitrate: 128000,
                channels: 2,
                enableNoiseSupression: true,
                enableEchoCancellation: true,
                enableAutoGainControl: true,
                chunkSize: 1000,
                uploadChunkSize: 1024 * 1024 // 1MB
            },
            B: {
                sampleRate: 22050,
                bitrate: 64000,
                channels: 1,
                enableNoiseSupression: true,
                enableEchoCancellation: true,
                enableAutoGainControl: false,
                chunkSize: 2000,
                uploadChunkSize: 512 * 1024 // 512KB
            },
            C: {
                sampleRate: 16000,
                bitrate: 32000,
                channels: 1,
                enableNoiseSupression: false,
                enableEchoCancellation: false,
                enableAutoGainControl: false,
                chunkSize: 5000,
                uploadChunkSize: 256 * 1024 // 256KB
            }
        };

        return settings[this.tier] || settings.C;
    }

    /**
     * Get environment data
     */
    getEnvironmentData() {
        return {
            ...this.environmentData,
            tier: this.tier,
            recordingSettings: this.getRecordingSettings()
        };
    }

    /**
     * Subscribe to environment updates
     */
    onReady(callback) {
        if (this.environmentData) {
            callback(this.getEnvironmentData());
        } else {
            this.callbacks.add(callback);
        }
    }

    /**
     * Notify callbacks
     */
    notifyCallbacks() {
        const data = this.getEnvironmentData();
        this.callbacks.forEach(callback => {
            try {
                callback(data);
            } catch (error) {
                console.error('[SPARXSTAR] Callback error:', error);
            }
        });
        this.callbacks.clear();
    }
}

// Create global instance
const sparxstarIntegration = new SparxstarIntegration();

// Export for module use
export default sparxstarIntegration;

// Global access
window.SparxstarIntegration = sparxstarIntegration;