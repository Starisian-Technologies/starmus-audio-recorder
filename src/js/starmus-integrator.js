/**
 * @file starmus-integrator.js
 * @version 6.5.0-SCHEMA-NORMALIZER
 * @description Bridges and NORMALIZES SparxstarUEC data to match Starmus Backend Schema.
 */

'use strict';

window.Starmus = window.Starmus || {};
window.Starmus.version = '6.5.0';

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

// 2. SPEECH API CHECK
if (!('SpeechRecognition' in window) && !('webkitSpeechRecognition' in window)) {
  console.log('[StarmusIntegrator] Speech API missing (Tier B/C)');
} else {
  window.SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
}

// 3. UEC DATA INGESTION (CRITICAL FIX)
window.addEventListener('sparxstar:environment-ready', (e) => {
    console.log('[StarmusIntegrator] 📡 Parsing UEC Payload...');
    
    if (!window.StarmusStore) return;

    const raw = e.detail || {};
    const tech = raw.technical || {};
    const rawTech = tech.raw || {};
    const profile = tech.profile || {};
    const idents = raw.identifiers || {}; // Sometimes at root
    // Handle case where identifiers might be inside technical or separate (based on logs)
    
    // --- NORMALIZE TO STRICT SCHEMA ---
    // The server expects keys: 'device', 'browser', 'network', 'errors' at ROOT of _starmus_env
    
    const normalizedEnv = {
        // 1. Device Info (Merge Detector + Profile)
        device: {
            ...(rawTech.device || {}),
            class: profile.deviceClass || 'unknown',
            os: (raw.identifiers?.deviceDetails?.os) || {}, 
            userAgent: navigator.userAgent
        },

        // 2. Browser Info
        browser: {
            ...(rawTech.browser || {}),
            ... (raw.identifiers?.deviceDetails?.client || {})
        },

        // 3. Network Info
        network: {
            ...(rawTech.network || {}),
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
            battery: rawTech.battery || {},
            performance: rawTech.performance || {}
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

// 4. AUDIO CONTEXT WATCHDOG
document.addEventListener('click', () => {
  try {
    const ctx = window.StarmusAudioContext;
    if (ctx && ctx.state === 'suspended') ctx.resume();
  } catch {}
}, { once: true });