/**
 * @file starmus-integrator.js
 * @version 6.0.0-METADATA-BRIDGE
 * @description Bridges SparxstarUEC data into Starmus Store and handles legacy compatibility.
 */

'use strict';

// 1. ANNOUNCE PRESENCE (Fixes "Starmus not detected")
window.Starmus = window.Starmus || {};
window.Starmus.version = '6.0.0';
window.Starmus.ready = false; 

// 2. PEAKS BRIDGE
export function exposePeaksBridge() {
  if (window.Peaks && (!window.Starmus.Peaks)) {
    window.Starmus.Peaks = window.Peaks;
  } else if (!window.Peaks) {
    // Non-fatal warning
    window.Peaks = { init: () => null };
    window.Starmus.Peaks = window.Peaks;
  }
}
exposePeaksBridge();

// 3. SPEECH API POLYFILL (Tier A check)
if (!('SpeechRecognition' in window) && !('webkitSpeechRecognition' in window)) {
  console.log('[StarmusIntegrator] Speech API not supported (Tier B/C implied)');
} else {
  // Normalize
  window.SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
}

// 4. METADATA LISTENER (CRITICAL FIX)
// Listens for SparxstarUEC to finish its analysis
window.addEventListener('sparxstar:environment-ready', (e) => {
    console.log('[StarmusIntegrator] 📡 Received UEC Data', e.detail);
    
    // Inject into Starmus Store if available
    if (window.StarmusStore) {
        window.StarmusStore.dispatch({ 
            type: 'starmus/env-update', 
            payload: e.detail 
        });
        
        // Also extract specific fields for the UI/Metadata mapper
        const snapshot = e.detail;
        if (snapshot.identifiers) {
             // Map session ID and Visitor ID explicitly
             window.StarmusStore.dispatch({
                 type: 'starmus/env-update',
                 payload: { 
                     sessionId: snapshot.identifiers.sessionId,
                     visitorId: snapshot.identifiers.visitorId
                 }
             });
        }
    }
});

// 5. AUDIO CONTEXT WATCHDOG (Mobile Safari/Old Chrome)
document.addEventListener('click', () => {
  try {
    const ctx = window.StarmusAudioContext;
    if (ctx && ctx.state === 'suspended') ctx.resume();
  } catch {}
}, { once: true });

console.log('[StarmusIntegrator] Bridge Active');