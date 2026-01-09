/**
 * @file starmus-ui.js
 * @version 6.4.0-UI-FIXES
 * @description UI rendering and interaction management for Starmus audio recorder.
 * Handles step visibility, calibration feedback, recording controls, audio playback,
 * and responsive state management. Fixes calibration text visibility and meter gradient support.
 */

'use strict';

/**
 * Currently playing audio instance for playback controls.
 * Used to manage audio playback state and prevent multiple simultaneous playback.
 * @type {Audio|null}
 */
let currentAudio = null;

/**
 * Formats seconds into MM'm SS's format for timer display.
 * Handles invalid numbers gracefully by returning default format.
 *
 * @function
 * @param {number} seconds - Time in seconds to format
 * @returns {string} Formatted time string (e.g., "02m 30s")
 *
 * @example
 * formatTime(150) // Returns "02m 30s"
 * formatTime(65)  // Returns "01m 05s"
 * formatTime(NaN) // Returns "00m 00s"
 */
function formatTime(seconds) {
  if (!Number.isFinite(seconds)) {
    return '00m 00s';
  }
  const m = Math.floor(seconds / 60);
  const s = Math.floor(seconds % 60);
  return (m < 10 ? '0' + m : m) + 'm ' + (s < 10 ? '0' + s : s) + 's';
}

/**
 * Safely binds event handlers to DOM elements with duplicate prevention.
 * Prevents default behavior, stops propagation, and respects disabled state.
 * Uses internal flag to prevent multiple bindings on the same element.
 *
 * @function
 * @param {HTMLElement|null} element - DOM element to bind event to
 * @param {string} eventName - Event type (e.g., 'click', 'change')
 * @param {function} handler - Event handler function to execute
 * @returns {void}
 *
 * @description Safety features:
 * - Checks for null elements
 * - Prevents duplicate event bindings
 * - Calls preventDefault() on cancelable events
 * - Stops event propagation
 * - Respects element disabled state
 */
function safeBind(element, eventName, handler) {
  if (!element) {
    return;
  }
  if (element._starmusBound) {
    return;
  }
  element.addEventListener(eventName, function (e) {
    if (e.cancelable) {
      e.preventDefault();
    }
    e.stopPropagation();
    if (!element.disabled) {
      handler(e);
    }
  });
  element._starmusBound = true;
}

/**
 * Renders UI state changes based on current application state.
 * Updates visibility, controls, meters, and button states across all UI sections.
 * Handles tier-based fallbacks and responsive state transitions.
 *
 * @function
 * @param {Object} state - Current application state from store
 * @param {string} state.status - Current status (idle/recording/calibrating/etc.)
 * @param {number} state.step - Current UI step (1 or 2)
 * @param {string} state.tier - Browser capability tier (A/B/C)
 * @param {Object} state.recorder - Recording state with duration and amplitude
 * @param {number} state.recorder.duration - Current recording duration in seconds
 * @param {number} state.recorder.amplitude - Current audio amplitude (0-100)
 * @param {Object} state.calibration - Microphone calibration state
 * @param {boolean} state.calibration.complete - Whether calibration is finished
 * @param {number} state.calibration.volumePercent - Volume level during calibration
 * @param {string} state.calibration.message - Current calibration message
 * @param {Object} state.submission - Upload progress state
 * @param {number} state.submission.progress - Upload progress (0.0 to 1.0)
 * @param {Object} elements - DOM element references object
 * @param {HTMLElement} elements.step1 - Step 1 container element
 * @param {HTMLElement} elements.step2 - Step 2 container element
 * @param {HTMLElement} elements.setupContainer - Microphone setup container
 * @param {HTMLElement} elements.recorderContainer - Recording controls container
 * @param {HTMLElement} elements.volumeMeter - Volume level meter element
 * @param {HTMLElement} elements.timerElapsed - Timer display element
 * @param {HTMLElement} elements.durationProgress - Recording progress indicator
 * @param {HTMLElement} elements.setupMicBtn - Setup microphone button
 * @param {HTMLElement} elements.recordBtn - Start recording button
 * @param {HTMLElement} elements.pauseBtn - Pause recording button
 * @param {HTMLElement} elements.resumeBtn - Resume recording button
 * @param {HTMLElement} elements.stopBtn - Stop recording button
 * @param {HTMLElement} elements.playBtn - Audio playback button
 * @param {HTMLElement} elements.resetBtn - Reset/discard button
 * @param {HTMLElement} elements.submitBtn - Submit recording button
 * @param {HTMLElement} elements.reviewControls - Review controls container
 * @returns {void}
 *
 * @description Rendering sections:
 * 1. Tier C fallback - Shows file upload for unsupported browsers
 * 2. Audio meters - Updates volume and duration visual indicators
 * 3. Step visibility - Controls step 1/2 container display
 * 4. Calibration UI - Manages setup button state and messages
 * 5. Recording controls - Shows/hides appropriate action buttons
 * 6. Submit button - Updates upload progress and success states
 */
function render(state, elements) {
  if (!elements) {
    return;
  }

  const status = state.status;
  const step = state.step;
  const recorder = state.recorder || {};
  const calibration = state.calibration || {};
  const submission = state.submission || {};
  const tier = state.tier;

  // --- TIER C FALLBACK ---
  if (tier === 'C') {
    if (elements.recorderContainer) {
      elements.recorderContainer.style.display = 'none';
    }
    if (elements.setupContainer) {
      elements.setupContainer.style.display = 'none';
    }
    const fallback = document.querySelector('[data-starmus-fallback-container]');
    if (fallback) {
      fallback.style.display = 'block';
    }
    return;
  }

  // --- 1. METERS (Gradient Restored via CSS Var) ---
  if (status === 'calibrating' || status === 'recording') {
    const vol = status === 'calibrating' ? calibration.volumePercent || 0 : recorder.amplitude || 0;
    if (elements.volumeMeter) {
      elements.volumeMeter.style.setProperty('--starmus-audio-level', vol + '%');
    }
  } else {
    if (elements.volumeMeter) {
      elements.volumeMeter.style.setProperty('--starmus-audio-level', '0%');
    }
  }

  if (elements.timerElapsed) {
    elements.timerElapsed.textContent = formatTime(recorder.duration || 0);
  }
  if (elements.durationProgress) {
    const maxDuration = 120; // 2 minutes max recording
    const pct = Math.min(100, ((recorder.duration || 0) / maxDuration) * 100);
    elements.durationProgress.style.setProperty('--starmus-recording-progress', pct + '%');
  }

  // --- 2. VISIBILITY ---
  if (elements.step1 && elements.step2) {
    const activeStates = [
      'recording',
      'paused',
      'processing',
      'ready_to_submit',
      'submitting',
      'calibrating',
      'ready',
      'complete',
    ];
    const showStep2 = step === 2 || activeStates.indexOf(status) !== -1;
    if (showStep2) {
      elements.step1.style.display = 'none';
      elements.step2.style.display = 'block';
    } else {
      elements.step1.style.display = 'block';
      elements.step2.style.display = 'none';
    }
  }

  // --- 3. CALIBRATION UI (CRITICAL FIX) ---
  const isCalibrated = calibration.complete === true;
  if (elements.setupContainer) {
    const showSetup = !isCalibrated || status === 'calibrating';
    elements.setupContainer.style.display = showSetup ? 'block' : 'none';

    if (elements.setupMicBtn) {
      if (status === 'calibrating') {
        // REMOVED 'is-busy' so text is visible!
        // Added icon to show activity
        elements.setupMicBtn.innerHTML =
          '<span class="dashicons dashicons-microphone" style="animation:pulse 1s infinite"></span> ' +
          (calibration.message || 'Adjusting...');
        elements.setupMicBtn.disabled = true;
        elements.setupMicBtn.classList.remove('is-busy');
      } else {
        elements.setupMicBtn.innerHTML =
          '<span class="dashicons dashicons-microphone"></span> Setup Microphone';
        elements.setupMicBtn.disabled = false;
        elements.setupMicBtn.classList.remove('is-busy');
      }
    }
  }

  if (elements.recorderContainer) {
    elements.recorderContainer.style.display = isCalibrated ? 'block' : 'none';
  }

  // --- 4. BUTTONS ---
  const isRec = status === 'recording';
  const isPaused = status === 'paused';
  const isDone = status === 'ready_to_submit';
  const isReady =
    (status === 'ready' || status === 'ready_to_record' || status === 'idle') && isCalibrated;

  if (elements.recordBtn) {
    elements.recordBtn.style.display =
      isReady && !isRec && !isPaused && !isDone ? 'inline-flex' : 'none';
  }
  if (elements.pauseBtn) {
    elements.pauseBtn.style.display = isRec ? 'inline-flex' : 'none';
  }
  if (elements.resumeBtn) {
    elements.resumeBtn.style.display = isPaused ? 'inline-flex' : 'none';
  }
  if (elements.stopBtn) {
    elements.stopBtn.style.display = isRec || isPaused ? 'inline-flex' : 'none';
  }

  if (elements.reviewControls) {
    elements.reviewControls.style.display = isDone ? 'flex' : 'none';
  } else {
    if (elements.playBtn) {
      elements.playBtn.style.display = isDone ? 'inline-flex' : 'none';
    }
    if (elements.resetBtn) {
      elements.resetBtn.style.display = isDone ? 'inline-flex' : 'none';
    }
  }

  // Submit Button (Still uses is-busy for uploading)
  if (elements.submitBtn) {
    if (status === 'submitting') {
      elements.submitBtn.textContent =
        'Uploading... ' + Math.round((submission.progress || 0) * 100) + '%';
      elements.submitBtn.disabled = true;
      elements.submitBtn.classList.add('is-busy'); // Keep spinner here
    } else if (status === 'complete') {
      elements.submitBtn.innerHTML =
        '<span class="dashicons dashicons-yes"></span> Success! Redirecting...';
      elements.submitBtn.disabled = true;
      elements.submitBtn.classList.remove('is-busy');
      elements.submitBtn.classList.add('starmus-btn--success');
    } else {
      elements.submitBtn.textContent = 'Submit Recording';
      elements.submitBtn.disabled = status !== 'ready_to_submit';
      elements.submitBtn.classList.remove('is-busy');
    }
  }
}

// ... (initInstance and Exports remain exactly the same as 6.1.0) ...
// REPEAT initInstance code here if rebuilding the file entirely
/**
 * Initializes UI instance for a specific recorder instance.
 * Sets up DOM element references, event handlers, and state subscription.
 * Handles form validation, audio playback, file uploads, and command dispatching.
 *
 * @function
 * @exports initInstance
 * @param {Object} store - Redux-style store for state management
 * @param {function} store.getState - Function to get current state
 * @param {function} store.dispatch - Function to dispatch actions
 * @param {function} store.subscribe - Function to subscribe to state changes
 * @param {Object} [incomingElements] - Optional pre-selected DOM elements (unused)
 * @param {string} [forcedInstanceId] - Optional forced instance ID override
 * @returns {function} Unsubscribe function for state change listener
 *
 * @description Setup process:
 * 1. Determines instance ID from parameter or store state
 * 2. Finds form container or uses document as root
 * 3. Queries for all required DOM elements using data attributes
 * 4. Binds event handlers with safeBind for all interactive elements
 * 5. Sets up form validation for step 1 continue button
 * 6. Configures audio playback controls with URL.createObjectURL
 * 7. Handles file input for Tier C browser fallback
 * 8. Subscribes to offline queue updates
 * 9. Dispatches init action and returns state subscription
 *
 * @example
 * const unsubscribe = initInstance(store, null, 'rec-123');
 * // Later: unsubscribe() to clean up
 */
function initInstance(store, incomingElements, forcedInstanceId) {
  const instId = forcedInstanceId || store.getState().instanceId;
  let root = document;
  if (instId) {
    const found = document.querySelector('form[data-starmus-instance="' + instId + '"]');
    if (found) {
      root = found;
    }
  }
  const BUS = window.CommandBus;

  /**
   * DOM element references object.
   * Contains all interactive elements found within the instance root.
   * @type {Object}
   */
  const el = {
    step1: root.querySelector('[data-starmus-step="1"]'),
    step2: root.querySelector('[data-starmus-step="2"]'),
    setupContainer: root.querySelector('[data-starmus-setup-container]'),
    timer: root.querySelector('[data-starmus-timer]'),
    timerElapsed: root.querySelector('.starmus-timer-elapsed'),
    volumeMeter: root.querySelector('[data-starmus-volume-meter]'),
    durationProgress: root.querySelector('[data-starmus-duration-progress]'),
    recorderContainer: root.querySelector('[data-starmus-recorder-container]'),
    transcript: root.querySelector('[data-starmus-transcript]'),
    reviewControls: root.querySelector('.starmus-review-controls'),
    continueBtn: root.querySelector('[data-starmus-action="next"]'),
    setupMicBtn: root.querySelector('[data-starmus-action="setup-mic"]'),
    recordBtn: root.querySelector('[data-starmus-action="record"]'),
    pauseBtn: root.querySelector('[data-starmus-action="pause"]'),
    resumeBtn: root.querySelector('[data-starmus-action="resume"]'),
    stopBtn: root.querySelector('[data-starmus-action="stop"]'),
    playBtn: root.querySelector('[data-starmus-action="play"]'),
    resetBtn: root.querySelector('[data-starmus-action="reset"]'),
    submitBtn: root.querySelector('[data-starmus-action="submit"]'),
  };

  /**
   * Continue button handler - validates required fields and advances to step 2.
   * Performs client-side validation and visual error indication.
   */
  safeBind(el.continueBtn, 'click', function () {
    const inputs = el.step1 ? el.step1.querySelectorAll('[required]') : [];
    let valid = true;
    for (let i = 0; i < inputs.length; i++) {
      if (!inputs[i].value.trim() && !inputs[i].checked) {
        valid = false;
        inputs[i].style.borderColor = 'red';
      } else {
        inputs[i].style.borderColor = '';
      }
    }
    if (valid) {
      store.dispatch({ type: 'starmus/ui/step-continue' });
    }
  });

  /**
   * Microphone and recording control handlers.
   * Dispatch commands through CommandBus with instance ID metadata.
   */
  safeBind(el.setupMicBtn, 'click', function () {
    BUS.dispatch('setup-mic', {}, { instanceId: instId });
  });
  safeBind(el.recordBtn, 'click', function () {
    BUS.dispatch('start-recording', {}, { instanceId: instId });
  });
  safeBind(el.pauseBtn, 'click', function () {
    BUS.dispatch('pause-mic', {}, { instanceId: instId });
  });
  safeBind(el.resumeBtn, 'click', function () {
    BUS.dispatch('resume-mic', {}, { instanceId: instId });
  });
  safeBind(el.stopBtn, 'click', function () {
    BUS.dispatch('stop-mic', {}, { instanceId: instId });
  });

  /**
   * Audio playback handler - toggles between play and pause states.
   * Creates Audio object from blob URL and manages playback state.
   */
  safeBind(el.playBtn, 'click', function () {
    if (currentAudio) {
      currentAudio.pause();
      currentAudio = null;
      el.playBtn.innerHTML = '<span class="dashicons dashicons-controls-play"></span> Play / Pause';
      return;
    }
    const state = store.getState();
    if (state.source.blob) {
      try {
        const url = URL.createObjectURL(state.source.blob);
        currentAudio = new Audio(url);
        currentAudio.onended = function () {
          currentAudio = null;
          el.playBtn.innerHTML =
            '<span class="dashicons dashicons-controls-play"></span> Play / Pause';
        };
        currentAudio.onerror = function () {
          alert('Playback error.');
          currentAudio = null;
          el.playBtn.innerHTML =
            '<span class="dashicons dashicons-controls-play"></span> Play / Pause';
        };
        currentAudio.play();
        el.playBtn.innerHTML = '<span class="dashicons dashicons-controls-pause"></span> Stop';
      } catch (e) {
        console.error(e);
      }
    }
  });

  /**
   * Reset handler - confirms and discards current recording.
   * Stops any playing audio and dispatches reset command.
   */
  safeBind(el.resetBtn, 'click', function () {
    if (confirm('Discard recording?')) {
      if (currentAudio) {
        currentAudio.pause();
        currentAudio = null;
      }
      BUS.dispatch('reset', {}, { instanceId: instId });
    }
  });

  /**
   * Submit handler - collects form data and dispatches submission.
   * Serializes form fields and stops any audio playback.
   */
  safeBind(el.submitBtn, 'click', function (e) {
    if (currentAudio) {
      currentAudio.pause();
      currentAudio = null;
    }
    const form = e.target.closest('form');
    const data = {};
    if (form) {
      const formData = new FormData(form);
      for (const pair of formData.entries()) {
        data[pair[0]] = pair[1];
      }
    }
    BUS.dispatch('submit', { formFields: data }, { instanceId: instId });
  });

  /**
   * File input handler for Tier C browser fallback.
   * Handles audio file uploads when MediaRecorder is not supported.
   */
  // Tier C File Listener
  const fileInput = root.querySelector('input[type="file"][name="audio_file"]');
  if (fileInput) {
    safeBind(fileInput, 'change', function (e) {
      if (e.target.files && e.target.files[0]) {
        store.dispatch({ type: 'starmus/file-attached', file: e.target.files[0] });
      }
    });
  }

  /**
   * Offline queue event subscription for debugging.
   */
  if (BUS) {
    BUS.subscribe('starmus/offline/queue_updated', function (payload) {
      console.log('[UI] Offline Queue:', payload);
    });
  }

  /**
   * Initialize instance and set up state subscription.
   * Returns unsubscribe function for cleanup.
   */
  store.dispatch({ type: 'starmus/init', payload: { instanceId: instId } });
  return store.subscribe(function (nextState) {
    render(nextState, el);
  });
}

/**
 * ES6 module exports for build system.
 * Exports render and initInstance functions.
 */
export { render, initInstance };

/**
 * Global export for browser environments.
 * Makes initInstance available as window.initUI for direct script loading.
 */
if (typeof window !== 'undefined') {
  window.initUI = initInstance;
}
