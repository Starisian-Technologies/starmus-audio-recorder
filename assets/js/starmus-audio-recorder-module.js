// ==== starmus-audio-recorder-module.js ====
// Build Hash (SHA-1):   c8aaf461c00bc0b613a759027af26b2faeaad730
// Build Hash (SHA-256): 2ade7463624fafe901e8968080483099e9ea2cf5d6c8e8b7db666a295b0e8093

// Utility: Button State Enforcer (already somewhat modular, keep it that way)
function createButtonStateEnforcer(initialButtonElement, sharedStateObject, permissionKey, logFn = console.log) {
  let observedButtonElement = document.getElementById(initialButtonElement?.id || 'recordButton');

  if (!observedButtonElement) {
    console.error('StateEnforcer Init: Could not find button to observe in DOM using ID:', initialButtonElement?.id || 'recordButton');
    return null;
  }

  const getLiveButton = () => document.getElementById(observedButtonElement.id);

  const shouldBeEnabled = () => {
    const state = sharedStateObject?.[permissionKey];
    return state === 'granted' || state === 'prompt';
  };

  let liveButtonForImmediateCheck = getLiveButton();
  if (liveButtonForImmediateCheck) {
    if (liveButtonForImmediateCheck.disabled && shouldBeEnabled()) {
      logFn(`StateEnforcer (Immediate Init for ID ${liveButtonForImmediateCheck.id}): Button is disabled but should be enabled. Re-enabling.`);
      liveButtonForImmediateCheck.disabled = false;
    } else if (!liveButtonForImmediateCheck.disabled && !shouldBeEnabled()) {
      logFn(`StateEnforcer (Immediate Init for ID ${liveButtonForImmediateCheck.id}): Button is enabled but should be disabled. Disabling.`);
      liveButtonForImmediateCheck.disabled = true;
    }
  } else {
    logFn.warn(`StateEnforcer (Immediate Init): Button with ID ${observedButtonElement.id} not found for immediate check.`);
  }

  const observer = new MutationObserver((mutations) => {
    let currentLiveButton = getLiveButton();
    if (!currentLiveButton) return;

    for (const mutation of mutations) {
      if (mutation.type === 'attributes' && mutation.attributeName === 'disabled') {
        const allowed = shouldBeEnabled();
        if (currentLiveButton.disabled && allowed) {
          logFn(`StateEnforcer (Mutation for ID ${currentLiveButton.id}): Button was disabled externally — re-enabling.`);
          currentLiveButton.disabled = false;
        } else if (!currentLiveButton.disabled && !allowed) {
          logFn(`StateEnforcer (Mutation for ID ${currentLiveButton.id}): Button enabled while permission is denied — disabling.`);
          currentLiveButton.disabled = true;
        }
      }
    }
  });

  observer.observe(observedButtonElement, {
    attributes: true,
    attributeFilter: ['disabled']
  });
  logFn(`StateEnforcer: MutationObserver active on initial button instance with ID "${observedButtonElement.id}".`);

  [1500, 3000, 5000].forEach((delay) => {
    setTimeout(() => {
      const freshButton = getLiveButton();
      if (!freshButton) return;
      if (!document.body.contains(observedButtonElement)) {}

      if (freshButton.disabled && shouldBeEnabled()) {
        logFn(`StateEnforcer (Timeout ${delay}ms for ID ${freshButton.id}): Correction — re-enabling button.`);
        freshButton.disabled = false;
      } else if (!freshButton.disabled && !shouldBeEnabled()) {
        logFn(`StateEnforcer (Timeout ${delay}ms for ID ${freshButton.id}): Button enabled, should be disabled. Disabling.`);
        freshButton.disabled = true;
      }
    }, delay);
  });

  return observer;
}

// Main Recorder Module
const StarmusAudioRecorder = (function () {
  'use strict';

  const strings = window.starmusRecorderStrings || {};

  console.log('RECORDER MODULE: Loaded.');

  // --- Private State Variables ---
  let config = { // To be set during init
    recordButtonId: 'recordButton',
    pauseButtonId: 'pauseButton',
    deleteButtonId: 'deleteButton',
    timerDisplayId: 'sparxstar_timer',
    audioPlayerId: 'sparxstar_audioPlayer',
    statusDisplayId: 'sparxstar_status',
    levelBarId: 'sparxstar_audioLevelBar',         // This is now the FILL element
    audioLevelTextId: 'sparxstar_audioLevelText', // New ID for the text percentage
    levelBarWrapId: 'sparxstar_audioLevelWrap',   // ID for the visual track/background container
    uuidFieldId: 'audio_uuid', // Matches your form HTM
    fileInputId: 'audio_file', // Matches your form HTML
    recorderContainerSelector: '[data-enabled-recorder]',
    maxRecordingTime: 1200000, // 20 minutes
    buildHash: 'c8aaf461c00bc0b613a759027af26b2faeaad730', // SHA-1 hash
    logPrefix: 'STARMUS:'
  };

  let dom = {}; // To store DOM element references

  let mediaRecorder;
  let audioChunks = [];
  let currentStream = null;
  let isRecording = false;
  let isPaused = false;
  let timerInterval;
  let segmentStartTime;
  let accumulatedElapsedTime = 0;
  let audioContext, analyser, dataArray, sourceNode, animationFrameId;

  let recordButtonIntervalId = null; // Track interval for record button
  let cleanupInProgress = false; // Prevent double cleanup

  // Global state for permissions (can be shared with ButtonStateEnforcer)
  // This could also be managed within the module if preferred.
  window.sparxstarRecorderState = window.sparxstarRecorderState || {
    micPermission: 'prompt',
  };
  let recordButtonEnforcer = null; // To hold the observer instance

  // --- Private Helper Functions (Your existing functions, slightly adapted) ---
  function _log(...args) {
    console.log(config.logPrefix, ...args);
  }
  function _warn(...args) {
    console.warn(config.logPrefix, ...args);
  }
  function _error(...args) {
    console.error(config.logPrefix, ...args);
  }

  // In your StarmusAudioRecorder module or the form submission script
  window.addEventListener('offline', () => {
      if (isRecording || isPaused || audioChunks.length > 0) {
          _updateStatus(strings.network_lost || "Network connection lost. Current recording is paused. Do NOT close page if you wish to save. Try to Stop and Submit when online.");
          if (isRecording && !isPaused && mediaRecorder && mediaRecorder.state === 'recording') {
              publicMethods.pause(); // Auto-pause if they were actively recording
          }
      }
  });

  window.addEventListener('online', () => {
      // Only show this if they were previously offline and had a recording in progress/paused
      if (audioChunks.length > 0) { // Check if there's something to recover
          _updateStatus(strings.network_restored_recording || "Network connection restored. You can now Stop your recording and Submit, or Delete and start over.");
      } else {
          _updateStatus(strings.network_restored || "Network connection restored.");
      }
      if (dom.downloadLink) {
          dom.downloadLink.classList.add('sparxstar_visually_hidden');
          dom.downloadLink.setAttribute('aria-disabled', 'true');
          dom.downloadLink.href = '#';
          dom.downloadLink.removeAttribute('download');
      }
  });

  function _animateBar() {
      // Add dom.audioLevelText to the condition
      if (analyser && dataArray && dom.levelBar && dom.audioLevelText && isRecording && !isPaused) {
          analyser.getByteFrequencyData(dataArray); // Populates dataArray (0-255)

          const averageVolume = dataArray.reduce((a, b) => a + b, 0) / dataArray.length;

          // Calculate raw percentage
          let rawPercent = (averageVolume / 255) * 100;

          // Apply an amplification factor to make the bar more responsive
          // Adjust this factor (e.g., 2.0, 2.5, 3.0, 3.5) based on testing to get the desired feel.
          const amplificationFactor = 2.5; // START WITH THIS AND TUNE
          let amplifiedPercent = rawPercent * amplificationFactor;

          // Ensure the final percentage doesn't exceed 100 or go below 0
          const finalPercent = Math.max(0, Math.min(amplifiedPercent, 100));
          const roundedFinalPercent = Math.round(finalPercent);

          // Update the fill bar's width (use unrounded for smoother visual if desired)
          dom.levelBar.style.width = `${finalPercent}%`;
          dom.levelBar.setAttribute('aria-valuenow', roundedFinalPercent);

          // Update the text percentage
          dom.audioLevelText.textContent = `${roundedFinalPercent}%`;

          // Ensure the whole bar unit (wrap) is visible when animating
          // This check also implicitly handles making the fill bar (dom.levelBar) visible
          // because if the wrap is hidden, the fill won't be seen.
          if (dom.levelBarWrap && dom.levelBarWrap.classList.contains('sparxstar_visually_hidden')) {
              dom.levelBarWrap.classList.remove('sparxstar_visually_hidden');
          }

          animationFrameId = requestAnimationFrame(_animateBar);
      } else {
          _stopAnimationBarLoop(); // Call if conditions aren't met (e.g., paused, stopped)
      }
  }
  function _stopAnimationBarLoop() {
      if (animationFrameId) {
          cancelAnimationFrame(animationFrameId);
          animationFrameId = null;
      }
      // Reset the fill bar
      if (dom.levelBar) {
          dom.levelBar.style.width = '0%';
          dom.levelBar.setAttribute('aria-valuenow', 0);
      }
      // Reset the text percentage
      if (dom.audioLevelText) {
          dom.audioLevelText.textContent = '0%';
      }
      // Hide the entire visual bar unit (the wrap)
      if (dom.levelBarWrap) {
          dom.levelBarWrap.classList.add('sparxstar_visually_hidden');
      }
  }

  function _formatTime(ms) {
    const totalSeconds = Math.floor(ms / 1000);
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
  }

  function _updateTimerColor(remainingTimeMs) {
    if (!dom.timerDisplay) return;
    const twoMinutes = 120000;
    const sevenMinutes = 420000;
    dom.timerDisplay.classList.remove('red', 'orange');
    dom.timerDisplay.style.color = '';
    if (remainingTimeMs <= twoMinutes) {
      dom.timerDisplay.classList.add('red');
    } else if (remainingTimeMs <= sevenMinutes) {
      dom.timerDisplay.classList.add('orange');
    }
  }

  function _updateTimerDisplay() {
    if (!dom.timerDisplay) return;
    let currentSegmentElapsedTime = 0;
    if (isRecording && !isPaused) {
      currentSegmentElapsedTime = Date.now() - segmentStartTime;
    }
    const totalRecordedTime = accumulatedElapsedTime + currentSegmentElapsedTime;
    const remainingTime = Math.max(0, config.maxRecordingTime - totalRecordedTime);
    dom.timerDisplay.textContent = _formatTime(remainingTime);
    _updateTimerColor(remainingTime);
    if (remainingTime <= 0) {
      _log("Time limit reached, stopping recording.");
      publicMethods.stop(); // Use public method
    }
  }

  function _startTimerForNewRecording() {
    _log("startTimerForNewRecording called");
    accumulatedElapsedTime = 0;
    segmentStartTime = Date.now();
    if (timerInterval) clearInterval(timerInterval);
    timerInterval = setInterval(_updateTimerDisplay, 1000);
    _updateTimerDisplay();
  }

  function _resumeTimer() {
    segmentStartTime = Date.now();
    if (!timerInterval) timerInterval = setInterval(_updateTimerDisplay, 1000);
  }

  function _pauseTimer() {
    clearInterval(timerInterval);
    timerInterval = null;
    accumulatedElapsedTime += (Date.now() - segmentStartTime);
  }
  function _stopTimerAndResetDisplay() {
     clearInterval(timerInterval);
     timerInterval = null;
     accumulatedElapsedTime = 0;
     if (dom.timerDisplay) {
         dom.timerDisplay.textContent = _formatTime(config.maxRecordingTime);
         _updateTimerColor(config.maxRecordingTime);
     }
  }

  function _updateStatus(msg) {
    _log("STATUS UPDATE:", msg);
    if (dom.statusDisplay) {
      const span = dom.statusDisplay.querySelector('.sparxstar_status__text');
      if (span) {
        span.textContent = msg;
      } else {
        dom.statusDisplay.textContent = msg;
      }
      dom.statusDisplay.classList.remove('sparxstar_visually_hidden');
    }
  }

  function _handleRecordingReady() {
     _log("handleRecordingReady called - resetting controls for a new recording session or after cleanup");
    if (dom.recordButton) {
        dom.recordButton.disabled = !['granted', 'prompt'].includes(window.sparxstarRecorderState.micPermission);
        dom.recordButton.textContent = 'Record';
        dom.recordButton.setAttribute('aria-pressed', 'false');
    }
    if (dom.pauseButton) {
        dom.pauseButton.disabled = true;
        dom.pauseButton.textContent = 'Pause';
        dom.pauseButton.setAttribute('aria-pressed', 'false');
    }
    if (dom.deleteButton) {
        dom.deleteButton.disabled = true;
        dom.deleteButton.classList.add('sparxstar_visually_hidden');
    }
    if (dom.audioPlayer) { // Ensure native player is reset/hidden
        if (dom.audioPlayer.src && dom.audioPlayer.src.startsWith('blob:')) {
            URL.revokeObjectURL(dom.audioPlayer.src);
        }
        dom.audioPlayer.src = '';
        dom.audioPlayer.removeAttribute('controls');
        dom.audioPlayer.classList.add('sparxstar_visually_hidden');
    }
    const submitButton = document.getElementById(`submit_button_${config.formInstanceId}`);
    if (submitButton) {
        submitButton.disabled = true;
    }
    if (dom.downloadLink) {
        dom.downloadLink.classList.add('sparxstar_visually_hidden');
        dom.downloadLink.setAttribute('aria-disabled', 'true');
        dom.downloadLink.href = '#';
        dom.downloadLink.removeAttribute('download');
    }
    _updateStatus(strings.ready_to_record || "Ready to record.");
  }

  function _handleDataAvailable(event) {
    if (event.data.size > 0) {
      audioChunks.push(event.data);
      _log("Data chunk received:", event.data.size);
    }
  }

  function _handleStop() {
    _log("handleStop called. isRecording:", isRecording, "isPaused:", isPaused, "audioChunks length:", audioChunks.length);
    _stopAnimationBarLoop();

    if (!mediaRecorder || audioChunks.length === 0) {
        _updateStatus(strings.recording_stopped || 'Recording stopped, no audio captured.');
        publicMethods.cleanup(); // Full UI reset
        return;
    }

    // --- Process successful recording ---
    const mimeType = mediaRecorder.mimeType;
    let fileType;
    if (mimeType.includes('opus') || mimeType.includes('webm')) fileType = 'webm';
    else if (mimeType.includes('aac') || mimeType.includes('mp4')) fileType = 'm4a';
    else {
        _error('Unsupported recorded MIME type:', mimeType);
        alert(strings.unsupported_format || 'Unsupported recording format. Try a different browser.');
      publicMethods.cleanup();
      return;
    }
    _log("Recording MIME type:", mimeType, "File type:", fileType);
    _stopTimerAndResetDisplay();
    const audioBlob = new Blob(audioChunks, { type: mimeType });
    const audioUrl = URL.createObjectURL(audioBlob);
    const fileName = `audio_${dom.uuidField ? dom.uuidField.value || _generateUniqueAudioId() : _generateUniqueAudioId()}.${fileType}`;

    if (dom.audioPlayer) {
        dom.audioPlayer.src = audioUrl;
        dom.audioPlayer.setAttribute('controls', '');
        dom.audioPlayer.classList.remove('sparxstar_visually_hidden');
    }
    _attachAudioToForm(audioBlob, fileType); // This will enable submit if successful

    // Show download link only if offline and after recording is stopped
    if (dom.downloadLink) {
        if (!navigator.onLine) {
            dom.downloadLink.href = audioUrl;
            dom.downloadLink.download = fileName;
            dom.downloadLink.removeAttribute('aria-disabled');
            dom.downloadLink.classList.remove('sparxstar_visually_hidden');
        } else {
            dom.downloadLink.classList.add('sparxstar_visually_hidden');
            dom.downloadLink.setAttribute('aria-disabled', 'true');
            dom.downloadLink.href = '#';
            dom.downloadLink.removeAttribute('download');
        }
    }

    _updateStatus(strings.recording_complete || "Recording complete. Play, Download, Delete, or Submit.");
    _log("Audio blob created, URL:", audioUrl);
    
    isRecording = false;
    isPaused = false;

    if (dom.recordButton) {
        dom.recordButton.textContent = 'Record';
        dom.recordButton.setAttribute('aria-pressed', 'false');
        dom.recordButton.disabled = !['granted', 'prompt'].includes(window.sparxstarRecorderState.micPermission);
    }
    if (dom.pauseButton) {
        dom.pauseButton.disabled = true;
        dom.pauseButton.textContent = 'Pause';
        dom.pauseButton.setAttribute('aria-pressed', 'false');
    }
    if (dom.deleteButton) {
        dom.deleteButton.disabled = false;
        dom.deleteButton.classList.remove('sparxstar_visually_hidden');
    }

    if (currentStream) {
        currentStream.getTracks().forEach(track => track.stop());
        currentStream = null;
    }
    _log("handleStop finished. Recording is ready for playback or submission.");
  }

  function _generateUniqueAudioId() {
    _log("generateUniqueAudioId called");
    try {
        if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID();
        }
        if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
          const buffer = new Uint8Array(16);
          crypto.getRandomValues(buffer);
          buffer[6] = (buffer[6] & 0x0f) | 0x40;
          buffer[8] = (buffer[8] & 0x3f) | 0x80;
          const U = (i) => buffer[i].toString(16).padStart(2, '0');
          return `${U(0)}${U(1)}${U(2)}${U(3)}-${U(4)}${U(5)}-${U(6)}${U(7)}-${U(8)}${U(9)}-${U(10)}${U(11)}${U(12)}${U(13)}${U(14)}${U(15)}`;
        }
    } catch (error) {
        _error("Crypto API failed during AudioID generation, falling back.", error);
    }
    // codeql[js/insecure-randomness]: This use of Math.random is for filename uniqueness only, not for security. See project README and code comments.
    // Fallback: Math.random() is used for filename uniqueness only, not for security. This is intentional for compatibility with old browsers in low-bandwidth regions.
    _warn("Generating AudioID using Math.random(). Not cryptographically secure, but safe for filename uniqueness.");
    let d = new Date().getTime();
    if (typeof performance !== 'undefined' && typeof performance.now === 'function') {
      d += performance.now();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      const r = (d + Math.random() * 16) % 16 | 0;
      d = Math.floor(d / 16);
      return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
  }

  function _attachAudioToForm(audioBlob, fileType) {
    _log("attachAudioToForm called. File type:", fileType);
    const generatedAudioID = _generateUniqueAudioId();
    const fileName = `audio_${generatedAudioID}.${fileType}`;
    _log("Generated filename:", fileName);

    if (dom.uuidField) {
      dom.uuidField.value = generatedAudioID;
      _log("UUID field value set to:", generatedAudioID);
    } else {
      _warn("UUID field not found in DOM. Skipping setting its value.");
    }

    const file = new File([audioBlob], fileName, { type: audioBlob.type });
    if (!dom.fileInput) {
      _warn('No audio file input (dom.fileInput) found. Skipping attachment to form.');
      _updateStatus(strings.recording_saved_no_input || 'Recording saved locally. File input not found in form.');
      return;
    }

    try {
      const dataTransfer = new DataTransfer();
      dataTransfer.items.add(file);
      dom.fileInput.files = dataTransfer.files;
      _log('Audio attached to form input:', dom.fileInput.name || dom.fileInput.id);
      _updateStatus(strings.recording_saved_attached || 'Recording saved and attached to form.');
      if (!dom.fileInput.files || dom.fileInput.files.length === 0) {
        _warn('File could not be attached to the file input. This browser may not support programmatic file assignment.');
        _updateStatus(strings.recording_saved_no_support || 'Recording saved, but your browser does not support automatic file attachment. Please try a different browser.');
      } else {
        const submitButton = document.getElementById(`submit_button_${config.formInstanceId}`);
        if (submitButton && dom.uuidField && dom.uuidField.value && dom.fileInput.files.length > 0) {
          submitButton.disabled = false;
          _log('Submit button enabled: recording and UUID present.');
        }
      }
    } catch (e) {
      _error("Could not attach file to fileInput. DataTransfer may not be supported or fileInput is problematic.", e);
      _updateStatus(strings.recording_saved_error || 'Recording saved locally. Error attaching to form.');
    }
    // ...
    const finalDurationMs = accumulatedElapsedTime; // Capture it before it's reset by _stopTimerAndResetDisplay
    // ...
    const event = new CustomEvent('starmusAudioReady', {
        detail: {
            audioId: generatedAudioID, // Assuming you renamed from uuid
            fileName: fileName,
            durationMs: finalDurationMs // ADDED DURATION
        }
    });
    dom.container.dispatchEvent(event);
    _log("starmusAudioReady event dispatched with details:", event.detail);
  }

  // --- Public Methods ---
  const publicMethods = {
    init: function (userConfig = {}) {
      _log('Initializing recorder module...');
      config = { ...config, ...userConfig }; // Merge user config
      _log('Using Build Hash:', config.buildHash);

      // Explicit formInstanceId check for developer clarity
      if (!config.formInstanceId) {
        _error('formInstanceId is missing in config. Cannot initialize recorder.');
        return Promise.resolve(false);
      }

      // Get main container first
      dom.container = document.querySelector(config.recorderContainerSelector);
      if (!dom.container) {
        _warn(`Container "${config.recorderContainerSelector}" not found. Recorder will not run.`);
        return Promise.resolve(false);
      }

      // Find DOM elements (scoped to the container if they are inside it, or globally if IDs are unique)
      const id = (baseId) => `${baseId}_${config.formInstanceId}`;
      dom.recordButton = document.getElementById(id(config.recordButtonId));
      dom.pauseButton = document.getElementById(id(config.pauseButtonId));
      dom.deleteButton = document.getElementById(id(config.deleteButtonId));
      dom.timerDisplay = document.getElementById(id(config.timerDisplayId));
      dom.audioPlayer = document.getElementById(id(config.audioPlayerId));
      dom.statusDisplay = document.getElementById(id(config.statusDisplayId));
      dom.levelBar = document.getElementById(id(config.levelBarId)); // The fill div
      dom.audioLevelText = document.getElementById(id(config.audioLevelTextId)); // The text span
      dom.levelBarWrap = document.getElementById(id(config.levelBarWrapId));   // The visual track div
      // These are critical for the form submission part
      dom.uuidField = document.getElementById(id(config.uuidFieldId));
      dom.fileInput = document.getElementById(id(config.fileInputId));
      dom.container = document.getElementById(`starmus_audioWrapper_${config.formInstanceId}`);
      dom.downloadLink = document.getElementById(`downloadLink_${config.formInstanceId}`); // Add this to your HTML template

      // Remove old event listeners before adding new ones
      if (dom.recordButton) dom.recordButton.replaceWith(dom.recordButton.cloneNode(true));
      if (dom.pauseButton) dom.pauseButton.replaceWith(dom.pauseButton.cloneNode(true));
      if (dom.deleteButton) dom.deleteButton.replaceWith(dom.deleteButton.cloneNode(true));
      // Re-query after replace
      dom.recordButton = document.getElementById(id(config.recordButtonId));
      dom.pauseButton = document.getElementById(id(config.pauseButtonId));
      dom.deleteButton = document.getElementById(id(config.deleteButtonId));

      if (!dom.recordButton || !dom.pauseButton || !dom.timerDisplay || !dom.audioPlayer || !dom.statusDisplay) {
        _error('One or more essential UI elements are missing. Recorder cannot initialize.');
        return Promise.resolve(false);
      }
      if (!dom.uuidField || !dom.fileInput) {
        _warn('UUID field or File input field for form submission not found. Attachment will fail.');
        // Decide if this is critical enough to stop init. For now, it will proceed but warn.
      }
      _log('All essential UI elements found.');

      if (dom.timerDisplay) dom.timerDisplay.setAttribute('aria-live', 'polite');

      if (recordButtonIntervalId) clearInterval(recordButtonIntervalId);

      this.setupEventListeners(); // Call internal method
      // Always return a Promise
      return Promise.resolve(this.setupPermissionsAndUI()); // setupPermissionsAndUI already returns a Promise
    },

    setupEventListeners: function () {
      _log('Setting up event listeners...');
      if (!dom.recordButton || !dom.pauseButton) {
          _error("Cannot setup event listeners: one or more buttons not found.");
          return;
      }

      dom.recordButton.addEventListener('click', () => {
        _log('Record button CLICKED. isRecording:', isRecording);
        if (!isRecording) {
          this.start();
        } else {
          this.stop();
        }
      });

      dom.pauseButton.addEventListener('click', () => {
        _log('Pause button CLICKED. isRecording:', isRecording, 'isPaused:', isPaused);
        if (!isRecording) return;
        if (!isPaused) {
            this.pause();
        } else {
            this.resume();
        }
      });

      if (dom.deleteButton) {
        dom.deleteButton.addEventListener('click', () => {
          _log('Delete button clicked.');
          publicMethods.cleanup(); // Also resets form state
          dom.deleteButton.classList.add('sparxstar_visually_hidden'); // Consistent hiding
        });   
      }

    },

    setupPermissionsAndUI: async function () {
      _log('setupPermissionsAndUI called.');
      if (dom.recordButton) dom.recordButton.disabled = true; // Start disabled
      _handleRecordingReady(); // Set initial button states for pause

      // Initialize mic permission state
      let permissionQuerySupported = (navigator.permissions && navigator.permissions.query);

      if (permissionQuerySupported) {
        try {
          _log('Querying microphone permission via Permissions API...');
          const permissionStatus = await navigator.permissions.query({ name: 'microphone' });
          window.sparxstarRecorderState.micPermission = permissionStatus.state; // Update global state
          _log(`Mic permission: ${permissionStatus.state}.`);

          const updateBasedOnPermission = () => {
            const currentPermState = window.sparxstarRecorderState.micPermission;
            _log(`Permission state is "${currentPermState}". Updating button state.`);
            if (!dom.recordButton) {
              _error('Record button not found during permission update.');
              return;
            }
            const isAllowed = ['granted', 'prompt'].includes(currentPermState);
            dom.recordButton.disabled = !isAllowed;
            _log(`Record button ${isAllowed ? 'ENABLED' : 'DISABLED'}.`);
            if (!isAllowed) {
              _updateStatus(strings.mic_denied || 'Microphone permission denied. Please allow mic access.');
              _stopAnimationBarLoop();
            }
          };

          updateBasedOnPermission(); // Initial update
          permissionStatus.onchange = () => {
            window.sparxstarRecorderState.micPermission = permissionStatus.state; // Update global on change
            _log(`Permission state CHANGED to "${permissionStatus.state}".`);
            updateBasedOnPermission();
            if(recordButtonEnforcer) {
                // The enforcer's internal `shouldBeEnabled` will pick up the new global state.
                // We might need a way to manually trigger its check if its observer didn't fire.
            }
          };

          if (recordButtonIntervalId) clearInterval(recordButtonIntervalId);

          // Setup Button State Enforcer only if recordButton exists
          if (dom.recordButton) {
             // Disconnect old enforcer if it exists and we are re-initializing
            if (recordButtonEnforcer && typeof recordButtonEnforcer.disconnect === 'function') {
                recordButtonEnforcer.disconnect();
            }
            recordButtonEnforcer = createButtonStateEnforcer(dom.recordButton, window.sparxstarRecorderState, 'micPermission', _log);

            // Add periodic check for the button instance (if it gets replaced by other JS)
            recordButtonIntervalId = setInterval(() => {
                const latestRecordButton = document.getElementById(dom.recordButton.id);
                if (latestRecordButton && latestRecordButton !== dom.recordButton) {
                    _warn('Record button instance changed. Re-attaching state enforcer.');
                    if (recordButtonEnforcer) recordButtonEnforcer.disconnect();
                    dom.recordButton = latestRecordButton; // Update internal reference
                    recordButtonEnforcer = createButtonStateEnforcer(dom.recordButton, window.sparxstarRecorderState, 'micPermission', _log);
                } else if (!latestRecordButton && recordButtonEnforcer) {
                    _warn('Record button disappeared. Disconnecting state enforcer.');
                    recordButtonEnforcer.disconnect();
                    recordButtonEnforcer = null;
                    dom.recordButton = null; // Clear reference
                }
            }, 3000); // Check every 3 seconds
          }


        } catch (err) {
          _error('Permissions API query failed:', err);
          window.sparxstarRecorderState.micPermission = 'prompt'; // Fallback assumption
          if (dom.recordButton) dom.recordButton.disabled = false; // Fallback enable
          _updateStatus(strings.mic_failed || 'Microphone permission check failed. Please check your browser settings.');
          _stopAnimationBarLoop();
        }
      } else {
        _warn('Permissions API not supported. Enabling record button by default and requesting on use.');
        window.sparxstarRecorderState.micPermission = 'prompt'; // Fallback assumption
        if (dom.recordButton) dom.recordButton.disabled = false;
      }

      if (dom.timerDisplay) {
        dom.timerDisplay.textContent = _formatTime(config.maxRecordingTime);
        _updateTimerColor(config.maxRecordingTime);
      }
      _handleRecordingReady(); // Ensure UI is in a consistent state initially
      _log('setupPermissionsAndUI finished.');
      return true; // Indicate success
    },

    start: async function () {
      _log('startRecording() method CALLED.');
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
        alert('Audio recording is not fully supported in your browser.');
        _error('getUserMedia or MediaRecorder not available.');
        return;
      }
      _log('MediaDevices API and MediaRecorder seem available.');

      const supportedMimeTypes = ['audio/webm;codecs=opus', 'audio/mp4;codecs=aac', 'audio/webm', 'audio/ogg;codecs=opus'];
      let selectedMimeType = supportedMimeTypes.find(type => MediaRecorder.isTypeSupported(type));
      if (!selectedMimeType && MediaRecorder.isTypeSupported('audio/webm')) selectedMimeType = 'audio/webm';
      else if (!selectedMimeType && MediaRecorder.isTypeSupported('audio/mp4')) selectedMimeType = 'audio/mp4';

      if (!selectedMimeType) { 
        alert('No supported audio format found.'); 
        _error('No supported audio format.'); 
        return; 
      }
      _log("Selected MIME type for recording:", selectedMimeType);

      try {
        _log('Attempting navigator.mediaDevices.getUserMedia().');
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        _log('getUserMedia() SUCCESSFUL. Stream obtained.');
        currentStream = stream;
        audioChunks = [];
        mediaRecorder = new MediaRecorder(stream, { mimeType: selectedMimeType });
        mediaRecorder.ondataavailable = _handleDataAvailable; // Use private method
        mediaRecorder.onstop = _handleStop;                 // Use private method
        mediaRecorder.onerror = (event) => {
            _error('MediaRecorder Error:', event.error);
            alert(`MediaRecorder error: ${event.error.name}.`);
            this.stop(); // Call public method to ensure full cleanup
        };

        // Await AudioContext close before creating a new one
        if (audioContext && audioContext.state !== 'closed') {
          try {
            await audioContext.close();
            _log("AudioContext closed before new creation.");
          } catch (e) {
            _warn("AudioContext close failed:", e);
          }
        }
        audioContext = null;

        mediaRecorder.start();
        _log("mediaRecorder.start() called.");

        isRecording = true;
        isPaused = false;
         if (dom.recordButton) {
            dom.recordButton.textContent = 'Stop';
            dom.recordButton.setAttribute('aria-pressed', 'true');
        }
        if (dom.pauseButton) {
            dom.pauseButton.disabled = false; // Enable Pause button
            dom.pauseButton.textContent = 'Pause';
            dom.pauseButton.setAttribute('aria-pressed', 'false');
        }
        // Ensure delete button is hidden/disabled at start of new recording
        if (dom.deleteButton) {
            dom.deleteButton.disabled = true;
            dom.deleteButton.classList.add('sparxstar_visually_hidden');
        }
        // Ensure audio player is hidden
        if (dom.audioPlayer) {
            dom.audioPlayer.classList.add('sparxstar_visually_hidden');
            dom.audioPlayer.removeAttribute('controls');
            if (dom.audioPlayer.src.startsWith('blob:')) URL.revokeObjectURL(dom.audioPlayer.src);
            dom.audioPlayer.src = '';
        }
        // Disable submit button when a new recording starts
        const submitButton = document.getElementById(`submit_button_${config.formInstanceId}`);
        if (submitButton) {
            submitButton.disabled = true;
        }
        _startTimerForNewRecording();
        if (dom.levelBar) {
          dom.levelBar.classList.remove('sparxstar_visually_hidden');
        }
        if (dom.levelBar && (window.AudioContext || window.webkitAudioContext)) {
            _log("Setting up audio level bar.");
            try {
              audioContext = new (window.AudioContext || window.webkitAudioContext)();
              analyser = audioContext.createAnalyser();
              analyser.fftSize = 256;
              dataArray = new Uint8Array(analyser.frequencyBinCount);
              sourceNode = audioContext.createMediaStreamSource(stream);
              sourceNode.connect(analyser);
              _animateBar();
            } catch (e) {
              _error('AudioContext creation failed:', e);
              _updateStatus(strings.level_unavailable || 'Audio level visualization not available.');
            }
        }
      } catch (error) {
        _error('getUserMedia error:', error.name, error.message);
        let userMessage = 'Microphone access error. Please check permissions.';
        switch (error.name) {
          case 'NotAllowedError':
            userMessage = 'Microphone permission denied. Please allow mic access.';
            break;
          case 'NotFoundError':
            userMessage = 'No microphone found. Please connect one.';
            break;
        }
        alert(userMessage); // (Define userMessage based on error.name)
        isRecording = false; isPaused = false;
        if(dom.recordButton) dom.recordButton.textContent = 'Record';
        _handleRecordingReady();
        _stopTimerAndResetDisplay();
        _stopAnimationBarLoop();
        if (currentStream) { currentStream.getTracks().forEach(track => track.stop()); currentStream = null; }
      }
    },

    stop: function () {
      _log("stopRecording method called. MediaRecorder state:", mediaRecorder ? mediaRecorder.state : "N/A");
      if (isRecording && mediaRecorder && mediaRecorder.state !== 'inactive') {
        _log("Calling mediaRecorder.stop()");
        mediaRecorder.stop(); // Triggers onstop -> _handleStop()
      } else if (isRecording) {
        _warn('stopRecording called, but mediaRecorder not active. Finalizing manually.');
        _handleStop(); // Manually finalize
      }
    },

    pause: function() {
        if (!isRecording || isPaused || !mediaRecorder || mediaRecorder.state !== 'recording') return;
        _log("PAUSING recording.");
        try {
            mediaRecorder.pause();
            _log("mediaRecorder.pause() called.");
            isPaused = true;
            _pauseTimer();
            _stopAnimationBarLoop();
            // ... (mediaRecorder.pause() logic) ...
            if (dom.pauseButton) {
                dom.pauseButton.textContent = 'Resume';
                dom.pauseButton.setAttribute('aria-pressed', 'true');
            }
            _updateStatus(strings.recording_paused || 'Recording paused');
        } catch (e) { _error("mediaRecorder.pause() failed", e); }
    },

    resume: function() {
        if (!isRecording || !isPaused || !mediaRecorder || mediaRecorder.state !== 'paused') return;
        _log("RESUMING recording.");
        try {
            mediaRecorder.resume();
            _log("mediaRecorder.resume() called.");
            isPaused = false;
            _resumeTimer();
            if (analyser) _animateBar();
            if (dom.pauseButton) {
                dom.pauseButton.textContent = 'Pause';
                dom.pauseButton.setAttribute('aria-pressed', 'false');
            }
            _updateStatus(strings.recording_resumed || 'Recording resumed...');
        } catch (e) { _error("mediaRecorder.resume() failed", e); }
    },

    cleanup: function(suppressFinalStatusUpdate) {
      if (cleanupInProgress) return;
      cleanupInProgress = true;
      _log("Cleanup called.");
      if (isRecording) {
        this.stop();
      }
      if (currentStream) {
        currentStream.getTracks().forEach(track => track.stop());
        currentStream = null;
        _log("Media stream stopped.");
      }
      if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.onstop = null;
        mediaRecorder.stop();
      }
      if (audioContext && audioContext.state !== 'closed') {
        audioContext.close().then(() => _log("AudioContext closed."));
        audioContext = null;
      }
      _stopAnimationBarLoop();
      if (dom.audioPlayer) {
        if (dom.audioPlayer.src && dom.audioPlayer.src.startsWith('blob:')) {
          URL.revokeObjectURL(dom.audioPlayer.src);
        }
        dom.audioPlayer.src = '';
        dom.audioPlayer.classList.add('sparxstar_visually_hidden');
        dom.audioPlayer.controls = false;
      }
      if (dom.deleteButton) {
        dom.deleteButton.classList.add('sparxstar_visually_hidden');
        dom.deleteButton.disabled = true;
      }
      if (dom.recordButton) {
        dom.recordButton.textContent = 'Record';
        dom.recordButton.setAttribute('aria-pressed', 'false');
        dom.recordButton.disabled = !['granted', 'prompt'].includes(window.sparxstarRecorderState.micPermission);
      }
      if (dom.pauseButton) {
        dom.pauseButton.disabled = true;
        dom.pauseButton.textContent = 'Pause';
      }
      const submitButton = document.getElementById(`submit_button_${config.formInstanceId}`);
      if (submitButton) {
        submitButton.disabled = true;
        _log('Submit button disabled after cleanup.');
      }
      if (dom.downloadLink) {
        dom.downloadLink.classList.add('sparxstar_visually_hidden');
        dom.downloadLink.setAttribute('aria-disabled', 'true');
        dom.downloadLink.href = '#';
        dom.downloadLink.removeAttribute('download');
      }
      audioChunks = [];
      isRecording = false;
      isPaused = false;
      _stopTimerAndResetDisplay();
      _handleRecordingReady();
      if (dom.uuidField) dom.uuidField.value = '';
      if (dom.fileInput) dom.fileInput.value = '';
      if (!suppressFinalStatusUpdate) {
        _updateStatus(strings.recorder_reset || "Recorder reset.");
      }
      _log("Recorder state and UI fully reset.");
      cleanupInProgress = false;
    },

    destroy: function () {
      this.cleanup();
      recordButtonEnforcer?.disconnect();
      recordButtonEnforcer = null;
      if (recordButtonIntervalId) clearInterval(recordButtonIntervalId);
      recordButtonIntervalId = null;
      dom = {}; // Drop all DOM refs
      config = {}; // Reset config if needed
    },

    getRecordedAudioId: function() { // Renamed for clarity
        return dom.uuidField ? dom.uuidField.value : null;
    }

  };

  return publicMethods;
})();
