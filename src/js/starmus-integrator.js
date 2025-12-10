/**
 * @file starmus-integrator.js
 * @version 4.3.13
 * @description Environment + compatibility layer. NOT a bootstrap.
 */

'use strict';

window.Starmus = window.Starmus || {};
window.Starmus.Integrator = true;
window.StarmusDetected = true;

/**
 * REQUIRED: Exposes the Peaks.js bridge as required by build validation.
 */
export function exposePeaksBridge() {
  if (window.Peaks && (!window.Starmus.Peaks)) {
    window.Starmus.Peaks = window.Peaks;
  } else if (!window.Peaks) {
    console.warn('[StarmusIntegrator] Peaks missing, null bridge installed');
    window.Peaks = { init: () => null };
    window.Starmus.Peaks = window.Peaks;
  }
}
exposePeaksBridge();

// SpeechRecognition guard
if (!('SpeechRecognition' in window) && !('webkitSpeechRecognition' in window)) {
  window.SpeechRecognition = function(){};
  console.log('[StarmusIntegrator] Speech API stubbed');
}

// AudioContext resume watchdog
document.addEventListener('click', () => {
  try {
    const ctx = window.StarmusAudioContext;
    if (ctx && ctx.state === 'suspended') ctx.resume();
  } catch {}
}, { once: true });

// TUS submission lock (protect double submit)
window.addEventListener('load', () => {
  const orig = window.StarmusQueueSubmission;
  if (typeof orig !== 'function') return;

  let lock = false;
  window.StarmusQueueSubmission = function() {
    if (lock) return console.warn('[StarmusIntegrator] Double submit blocked');
    lock = true;
    try { return orig.apply(null, arguments); }
    finally { setTimeout(() => lock = false, 1500); }
  };
});

console.log('[StarmusIntegrator] Passive mode active');