/**
 * @file starmus-integrator.js
 * @version 6.5.0-SCHEMA-NORMALIZER
 * @description Bridges and NORMALIZES SparxstarUEC data to match Starmus Backend Schema.
 */

'use strict';

/**
 * Global Starmus namespace object.
 * @global
 * @namespace
 */
window.Starmus = window.Starmus || { /* intentionally empty */ };

/**
 * Current version of the Starmus integration layer.
 * @global
 * @type {string}
 */
window.Starmus.version = '6.5.0';

/**
 * Exposes Peaks.js waveform library through the Starmus namespace.
 * Creates a bridge between the global Peaks library and Starmus.Peaks.
 * Provides a fallback implementation if Peaks.js is not available.
 * 
 * @function
 * @exports exposePeaksBridge
 * @returns {void}
 */
// 1. PEAKS BRIDGE
export function exposePeaksBridge() {
	if (window.Peaks && (!window.Starmus.Peaks)) {
		window.Starmus.Peaks = window.Peaks;
	} else if (!window.Peaks) {
		window.Peaks = { init: () => null };
		window.Starmus.Peaks = window.Peaks;
	}
}
exposePeaksBridge();

/**
 * Speech Recognition API compatibility check and polyfill setup.
 * Detects browser support for speech recognition and logs availability.
 * Sets up webkit prefixed fallback for cross-browser compatibility.
 */
// 2. SPEECH API CHECK
if (!('SpeechRecognition' in window) && !('webkitSpeechRecognition' in window)) {
	console.log('[StarmusIntegrator] Speech API missing (Tier B/C)');
} else {
	window.SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
}

/**
 * Handles SparxstarUEC environment data and normalizes it for Starmus backend.
 * Listens for 'sparxstar:environment-ready' events and transforms the payload
 * to match the strict schema expected by the Starmus backend.
 * 
 * @listens window~sparxstar:environment-ready
 * @param {CustomEvent} e - The environment ready event
 * @param {Object} e.detail - Raw UEC environment data
 * @param {Object} e.detail.technical - Technical device information
 * @param {Object} e.detail.identifiers - Session and visitor identifiers
 */
// 3. UEC DATA INGESTION (CRITICAL FIX)
window.addEventListener('sparxstar:environment-ready', (e) => {
	console.log('[StarmusIntegrator] 📡 Parsing UEC Payload...');
    
	if (!window.StarmusStore) {return;}

	const raw = e.detail || { /* intentionally empty */ };
	const tech = raw.technical || { /* intentionally empty */ };
	const rawTech = tech.raw || { /* intentionally empty */ };
	const profile = tech.profile || { /* intentionally empty */ };
	const idents = raw.identifiers || { /* intentionally empty */ }; // Sometimes at root
	// Handle case where identifiers might be inside technical or separate (based on logs)
    
	/**
     * Normalized environment data object matching Starmus backend schema.
     * @type {Object}
     * @property {Object} device - Device information including class, OS, and user agent
     * @property {Object} browser - Browser capabilities and client details  
     * @property {Object} network - Network information and connection profile
     * @property {Object} identifiers - Session, visitor, and IP identifiers
     * @property {Object} features - Battery and performance feature detection
     * @property {Array} errors - Array of initialization errors
     */
	// --- NORMALIZE TO STRICT SCHEMA ---
	// The server expects keys: 'device', 'browser', 'network', 'errors' at ROOT of _starmus_env
    
	const normalizedEnv = {
		// 1. Device Info (Merge Detector + Profile)
		device: {
			...(rawTech.device || { /* intentionally empty */ }),
			class: profile.deviceClass || 'unknown',
			os: (raw.identifiers?.deviceDetails?.os) || { /* intentionally empty */ }, 
			userAgent: navigator.userAgent
		},

		// 2. Browser Info
		browser: {
			...(rawTech.browser || { /* intentionally empty */ }),
			... (raw.identifiers?.deviceDetails?.client || { /* intentionally empty */ })
		},

		// 3. Network Info
		network: {
			...(rawTech.network || { /* intentionally empty */ }),
			profile: profile.networkProfile || 'unknown'
		},

		// 4. Identifiers (Session/Visitor)
		identifiers: {
			sessionId: idents.sessionId || raw.sessionId || 'unknown',
			visitorId: idents.visitorId || raw.visitorId || 'unknown',
			ip: idents.ipAddress || '0.0.0.0'
		},

		// 5. Features / Battery / Perf
		features: {
			battery: rawTech.battery || { /* intentionally empty */ },
			performance: rawTech.performance || { /* intentionally empty */ }
		},
        
		// 6. Init Error Array (Required by Schema)
		errors: [] 
	};

	console.log('[StarmusIntegrator] ✅ Normalized Env:', normalizedEnv);

	// Dispatch merged environment
	window.StarmusStore.dispatch({ 
		type: 'starmus/env-update', 
		payload: normalizedEnv 
	});
});

/**
 * Audio Context watchdog for user activation compliance.
 * Resumes suspended AudioContext on first user interaction to comply
 * with browser autoplay policies. Uses {once: true} to run only once.
 * 
 * @listens document~click
 */
// 4. AUDIO CONTEXT WATCHDOG
document.addEventListener('click', () => {
	try {
		const ctx = window.StarmusAudioContext;
		if (ctx && ctx.state === 'suspended') {ctx.resume();}
	} catch { /* intentionally empty */ }
}, { once: true });