/**
 * SmartAudioPlayer (2025 Edition)
 * Optimized for Low-End Devices & Unstable Networks (Africa/Emerging Markets)
 *
 * Features:
 * - Network Awareness: Auto-switches between Opus/MP3 and Low/High bitrates.
 * - Hardware Awareness: Disables Web Audio API on devices with < 4GB RAM to save battery.
 * - Lazy Initialization: Prevents Autoplay Policy errors and "Zombie" AudioContexts.
 * - Broadcast Leveling: Normalizes volume without destroying dynamic range.
 * - Memory Safety: Full cleanup via destroy() to prevent leaks in SPAs.
 */

class _SmartAudioPlayer {
    /**
     * @param {Object} config
     * @param {number} config.lowMemoryLimit - GB of RAM to treat as low-end (Default: 4)
     * @param {number} config.lowCoreLimit - CPU cores to treat as low-end (Default: 4)
     * @param {boolean} config.debug - Enable console logs for debugging
     */
    constructor(config = {}) {
        this.config = {
            lowMemoryLimit: 4,
            lowCoreLimit: 4,
            debug: false,
            ...config,
        };

        // State trackers
        this.audioContext = null;
        this.sourceNode = null;
        this.compressor = null;
        this.isDestroyed = false;

        // Instantiate Audio Element
        this.audioElement = new Audio();
        this.audioElement.crossOrigin = "anonymous"; // Essential for CDN usage

        // Enhancement B: Explicitly disable looping to prevent run-away buffering on retry
        this.audioElement.loop = false;

        // Feature Detection
        this.isLowEndDevice = this.evaluateDeviceTier();

        // Detect Opus support (critical for 2G/3G efficiency)
        this.canPlayOpus =
            this.audioElement.canPlayType('audio/webm; codecs="opus"').replace(/^no$/, "") !== "";

        // Optimization: Don't buffer on low-end/data-saver devices until clicked.
        // 'metadata' allows UI to show duration, 'none' saves maximum data.
        this.audioElement.preload = this.isLowEndDevice ? "none" : "metadata";
    }

    /**
     * Safe logger that respects debug config
     */
    log(...args) {
        if (this.config.debug) {
            console.log("[SmartAudio]", ...args);
        }
    }

    /**
     * Determines if device is "Low Tier" based on RAM, Cores, and Data Saver.
     * Conservative default: < 4GB RAM or < 4 Cores.
     * Why? Mid-range MediaTek chips overheat with WebAudio, draining battery.
     */
    evaluateDeviceTier() {
        // 1. Check Hardware
        if (navigator.deviceMemory && navigator.deviceMemory < this.config.lowMemoryLimit) {
            return true;
        }
        if (
            navigator.hardwareConcurrency &&
            navigator.hardwareConcurrency < this.config.lowCoreLimit
        ) {
            return true;
        }

        // 2. Check "Save Data" header
        const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (conn && conn.saveData) {
            return true;
        }

        return false;
    }

    /**
     * Returns the best URL based on Network Conditions & Codec Support
     */
    getOptimalSource(sources) {
        const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        let isSlow = false;

        // Don't trust effectiveType blindly (it freezes), but check it if available.
        if (conn) {
            isSlow = conn.saveData || (conn.effectiveType && /2g/.test(conn.effectiveType));
        }

        // Priority 1: Opus on Slow Networks (24-32kbps Opus > 32kbps MP3)
        if (isSlow && this.canPlayOpus && sources.opus) {
            return sources.opus;
        }

        // Priority 2: Fallback Low Quality
        if (isSlow) {
            return sources.low;
        }

        // Priority 3: High Quality (WiFi/4G)
        return sources.high || sources.low;
    }

    /**
     * Lazy Initialization of Web Audio API
     * Only called AFTER playback starts successfully to respect Autoplay Policies.
     */
    initEnhancedAudio() {
        if (this.audioContext || this.isDestroyed) {
            return;
        }

        try {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            this.audioContext = new AudioContext();

            // Singleton Source Node Pattern
            if (!this.sourceNode) {
                this.sourceNode = this.audioContext.createMediaElementSource(this.audioElement);
            }

            // Enhancement C: Broadcast-safe Gentle Leveling
            // Unlike radio compression (harsh), this preserves dynamic range while boosting clarity.
            this.compressor = this.audioContext.createDynamicsCompressor();
            this.compressor.threshold.setValueAtTime(-24, this.audioContext.currentTime); // Engage at -24dB
            this.compressor.knee.setValueAtTime(30, this.audioContext.currentTime); // Soft knee
            this.compressor.ratio.setValueAtTime(4, this.audioContext.currentTime); // 4:1 compression
            this.compressor.attack.setValueAtTime(0.003, this.audioContext.currentTime); // Fast attack
            this.compressor.release.setValueAtTime(0.25, this.audioContext.currentTime); // Natural release

            // Connect the Graph: Source -> Compressor -> Speakers
            this.sourceNode.connect(this.compressor);
            this.compressor.connect(this.audioContext.destination);

            this.log("Audio pipeline upgraded: Enhanced Broadcast Leveling active.");
        } catch (e) {
            this.log("Web Audio API not supported. Continuing with standard playback.");
        }
    }

    /**
     * Main Play Method
     */
    async play(sources) {
        if (this.isDestroyed) {
            console.warn("[SmartAudio] Cannot play: Player has been destroyed.");
            return;
        }

        const url = this.getOptimalSource(sources);

        // Only reset src if it changed (prevents skipping/re-buffering)
        if (this.audioElement.src !== url) {
            this.audioElement.src = url;
        }

        try {
            // 1. Attempt Standard Playback first
            await this.audioElement.play();

            // 2. Progressive Enhancement (Lazy Load)
            // Only enhance if device is capable and battery/data isn't critical
            if (!this.isLowEndDevice) {
                this.initEnhancedAudio();

                // Fix: Explicitly resume if Chrome suspended the context (Autoplay Policy)
                if (this.audioContext && this.audioContext.state === "suspended") {
                    await this.audioContext.resume();
                }
            }
        } catch (error) {
            this.log("Playback failed. Attempting auto-fallback...", error);

            // Silent Failover: High quality failed? Try Low quality immediately.
            if (url !== sources.low && sources.low) {
                this.log("Downgrading to low quality source.");
                this.audioElement.src = sources.low;
                this.audioElement.load(); // Force reset buffer

                try {
                    await this.audioElement.play();
                } catch (fallbackError) {
                    console.error("[SmartAudio] Critical Failure:", fallbackError);
                }
            }
        }
    }

    /**
     * Pause playback and suspend context to save battery
     */
    pause() {
        if (this.isDestroyed) {
            return;
        }

        this.audioElement.pause();

        if (this.audioContext && this.audioContext.state === "running") {
            this.audioContext.suspend();
        }
    }

    /**
     * Enhancement A: Cleanup Method
     * Essential for SPAs (React/Vue) to prevent memory leaks.
     */
    destroy() {
        this.isDestroyed = true;
        this.pause();

        // 1. Close AudioContext to release hardware
        if (this.audioContext && this.audioContext.state !== "closed") {
            this.audioContext.close();
        }

        // 2. Detach Audio Element Source to stop buffering
        this.audioElement.src = "";
        this.audioElement.load();

        // 3. Nullify references for Garbage Collection
        if (this.sourceNode) {
            try {
                this.sourceNode.disconnect();
            } catch (e) {
                /* Ignore disconnect errors */
            }
        }

        this.audioContext = null;
        this.sourceNode = null;
        this.compressor = null;
        this.audioElement = null;

        this.log("Player destroyed and memory released.");
    }
}

// --- USAGE EXAMPLE ---

/*
const player = new SmartAudioPlayer({ debug: true });

const tracks = {
	opus: 'https://cdn.example.com/song_24k.webm', // Efficient
	low:	'https://cdn.example.com/song_32k.mp3',	// Reliable Fallback
	high: 'https://cdn.example.com/song_128k.mp3'	// Quality
};

// Play
document.getElementById('playBtn').addEventListener('click', () => {
	player.play(tracks);
});

// Cleanup (e.g., in React useEffect return or Vue unmounted)
// player.destroy();
*/
