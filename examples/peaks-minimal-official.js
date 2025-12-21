/**
 * Minimal Peaks.js Implementation
 * Based on official BBC Peaks.js documentation
 */

import Peaks from 'peaks.js';

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
  },
  emitCueEvents: true,
  keyboard: true,
  showPlayheadTime: true
};

Peaks.init(options, function(err, peaks) {
  if (err) {
    console.error('Failed to initialize Peaks instance: ' + err.message);
    return;
  }

  // Cue events
  peaks.on('segments.enter', (event) => {
    console.log('Entered segment:', event.segment.labelText);
  });

  peaks.on('segments.exit', (event) => {
    console.log('Exited segment:', event.segment.labelText);
  });

  peaks.on('points.enter', (event) => {
    console.log('Entered point:', event.point.labelText);
  });
});