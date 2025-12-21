/**
 * @file peaks-es2015-example.js
 * @description Example implementation of Peaks.js using ES2015 module import
 * This demonstrates the exact code pattern from the Peaks.js documentation
 * integrated with the Starmus Audio Recorder plugin architecture.
 */

import Peaks from 'peaks.js';

/**
 * Initialize Peaks.js with ES2015 module import
 * This matches the example from the Peaks.js documentation
 */
function initPeaksExample() {
  const options = {
    zoomview: {
      container: document.getElementById('zoomview-container')
    },
    overview: {
      container: document.getElementById('overview-container')
    },
    mediaElement: document.getElementById('audio'),
    webAudio: {
      audioContext: new AudioContext()
    }
  };

  Peaks.init(options, function(err, peaks) {
    if (err) {
      console.error('Failed to initialize Peaks instance: ' + err.message);
      return;
    }

    // Do something when the waveform is displayed and ready
    console.log('Peaks.js initialized successfully');
    
    // Example: Add a segment
    peaks.segments.add({
      startTime: 5,
      endTime: 15,
      label: 'Example Segment',
      color: '#ff0000'
    });
  });
}

// Export for use in other modules
export { initPeaksExample };

// Auto-initialize if DOM elements are present
document.addEventListener('DOMContentLoaded', () => {
  const zoomview = document.getElementById('zoomview-container');
  const overview = document.getElementById('overview-container');
  const audio = document.getElementById('audio');
  
  if (zoomview && overview && audio) {
    initPeaksExample();
  }
});