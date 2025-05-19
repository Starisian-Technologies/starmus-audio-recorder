// ==== starmus-audio-recorder-module.js ====
// Build Hash (SHA-1):   c8aaf461c00bc0b613a759027af26b2faeaad730
// Build Hash (SHA-256): 2ade7463624fafe901e8968080483099e9ea2cf5d6c8e8b7db666a295b0e8093

// Utility: Button State Enforcer (already somewhat modular, keep it that way)
function createButtonStateEnforcer(initialButtonElement, sharedStateObject, permissionKey, logFn = console.log) {
  // KEY CHANGE: Re-select the button using its ID every time the enforcer's logic runs,
  // or at least at critical points.
  // For the observer itself, it needs to be attached to a specific instance.
  // But for checks and corrections, always get the latest.

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

  console.log('RECORDER MODULE: Loaded.');

  // --- Private State Variables ---
  let config = { // To be set during init
    recordButtonId: 'recordButton',
    pauseButtonId: 'pauseButton',
    playButtonId: 'playButton',
    deleteButtonId: 'deleteButton',
    timerDisplayId: 'sparxstar_timer',
    audioPlayerId: 'sparxstar_audioPlayer',
    statusDisplayId: 'sparxstar_status',
    levelBarId: 'sparxstar_audioLevelBar',
    uuidFieldId: 'audio_uuid', // Matches your form HTML
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

  function _animateBar() {
    if (analyser && dataArray && dom.levelBar && isRecording && !isPaused) {
      analyser.getByteFrequencyData(dataArray);
      const volume = dataArray.reduce((a, b) => a + b, 0) / dataArray.length;
      const percent = Math.min((volume / 255) * 100, 100);
      dom.levelBar.style.width = `${percent}%`;
      dom.levelBar.setAttribute('aria-valuenow', Math.round(percent));
      animationFrameId = requestAnimationFrame(_animateBar);
    } else {
      _stopAnimationBarLoop();
    }
  }

  function _stopAnimationBarLoop() {
    if (animationFrameId) {
      cancelAnimationFrame(animationFrameId);
      animationFrameId = null;
    }
    if (dom.levelBar) dom.levelBar.style.width = '0%';
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

  function _handleDataAvailable(event) {
    if (event.data.size > 0) {
      audioChunks.push(event.data);
      _log("Data chunk received:", event.data.size);
    }
  }

  function _handleStop() {
    _log("handleStop called. isRecording:", isRecording, "isPaused:", isPaused, "audioChunks length:", audioChunks.length);
    _stopAnimationBarLoop();

    // Show delete button IF there are audio chunks that might be deleted
    if (dom.deleteButton) {
      if (audioChunks.length > 0) {
        dom.deleteButton.classList.remove('sparxstar_visually_hidden');
        dom.deleteButton.disabled = false;
      } else {
        dom.deleteButton.classList.add('sparxstar_visually_hidden');
        dom.deleteButton.disabled = true;
      }
    }

    if (isRecording && !isPaused) {
        accumulatedElapsedTime += (Date.now() - segmentStartTime);
    }
    _log(`Total recorded duration (accumulated): ${_formatTime(accumulatedElapsedTime)}`);

    if (!mediaRecorder || audioChunks.length === 0) {
        _updateStatus('Recording stopped, no audio captured.');
        // Ensure UI reflects no recording (e.g., audio player remains hidden, delete button hidden)
        if (dom.audioPlayer) dom.audioPlayer.classList.add('sparxstar_visually_hidden');
        if (dom.deleteButton) {
            dom.deleteButton.classList.add('sparxstar_visually_hidden');
            dom.deleteButton.disabled = true;
        }
        _handleRecordingReady(); // Resets record/pause/play
        // Clear any partially set fields if necessary
        if (dom.uuidField) dom.uuidField.value = '';
        if (dom.fileInput) dom.fileInput.value = '';
        return;
    }

    // Moved mimeType definition and fileType determination up
    const mimeType = mediaRecorder.mimeType;
    let fileType;
    if (mimeType.includes('opus') || mimeType.includes('webm')) fileType = 'webm';
    else if (mimeType.includes('aac') || mimeType.includes('mp4')) fileType = 'm4a';
    else {
        _error('Unsupported recorded MIME type:', mimeType);
        alert('Unsupported recording format. Try a different browser.');
        publicMethods.cleanup();
        return;
    }

    const audioBlob = new Blob(audioChunks, { type: mimeType });
    const audioUrl = URL.createObjectURL(audioBlob);

    if (dom.audioPlayer) {
        dom.audioPlayer.src = audioUrl;
        dom.audioPlayer.classList.remove('sparxstar_visually_hidden');
    }
    _log("Audio blob created, URL:", audioUrl);

    _attachAudioToForm(audioBlob, fileType);

    _stopTimerAndResetDisplay();
    isRecording = false;
    isPaused = false;
    if (dom.recordButton) {
        dom.recordButton.textContent = 'Record';
        dom.recordButton.setAttribute('aria-pressed', 'false');
    }
    _handleRecordingReady();
    if (currentStream) {
        currentStream.getTracks().forEach(track => track.stop());
        currentStream = null;
    }
    _log("handleStop finished.");
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
    _warn("Generating AudioID using Math.random(). Not cryptographically secure.");
    // Math.random() fallback for old browsers
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
    const generatedAudioID = _generateUniqueAudioId(); // Generate UUID here when audio is ready
    const fileName = `audio_${generatedAudioID}.${fileType}`;
    _log("Generated filename:", fileName);

    if (dom.uuidField) {
        dom.uuidField.value = generatedAudioID; // Populate the hidden UUID field
        _log("UUID field value set to:", generatedAudioID);
    } else {
        _warn("UUID field not found in DOM. Skipping setting its value.");
    }

    const file = new File([audioBlob], fileName, { type: audioBlob.type });
    if (!dom.fileInput) {
      _warn('No audio file input (dom.fileInput) found. Skipping attachment to form.');
      _updateStatus('Recording saved locally. File input not found in form.');
      return;
    }
    // activate submit button
    const submitButton = document.getElementById(`submit_button_${config.formInstanceId}`);
    if (submitButton) {
      submitButton.disabled = false;
      _log('Submit button enabled after recording completed.');
    }
    try {
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(file);
        dom.fileInput.files = dataTransfer.files; // Populate the hidden file input
        _log('Audio attached to form input:', dom.fileInput.name || dom.fileInput.id);
        _updateStatus('Recording saved and attached to form.');

        // Optionally, dispatch a custom event to notify the submission script
        // that the audio is ready and fields are populated.
        const event = new CustomEvent('starmusAudioReady', { detail: { uuid: generatedAudioID, fileName: fileName } });
        dom.container.dispatchEvent(event); // Dispatch on the main recorder container

    } catch (e) {
        _error("Could not attach file to fileInput. DataTransfer may not be supported or fileInput is problematic.", e);
        _updateStatus('Recording saved locally. Error attaching to form.');
    }
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
        return false;
      }

      // Get main container first
      dom.container = document.querySelector(config.recorderContainerSelector);
      if (!dom.container) {
        _warn(`Container "${config.recorderContainerSelector}" not found. Recorder will not run.`);
        return false;
      }

      // Find DOM elements (scoped to the container if they are inside it, or globally if IDs are unique)
      const id = (baseId) => `${baseId}_${config.formInstanceId}`;
      dom.recordButton = document.getElementById(id(config.recordButtonId));
      dom.pauseButton = document.getElementById(id(config.pauseButtonId));
      dom.playButton = document.getElementById(id(config.playButtonId));
      dom.deleteButton = document.getElementById(id(config.deleteButtonId));
      dom.timerDisplay = document.getElementById(id(config.timerDisplayId));
      dom.audioPlayer = document.getElementById(id(config.audioPlayerId));
      dom.statusDisplay = document.getElementById(id(config.statusDisplayId));
      dom.levelBar = document.getElementById(id(config.levelBarId));
      // These are critical for the form submission part
      dom.uuidField = document.getElementById(id(config.uuidFieldId));
      dom.fileInput = document.getElementById(id(config.fileInputId));
      dom.container = document.getElementById(`starmus_audioWrapper_${config.formInstanceId}`);

      if (!dom.recordButton || !dom.pauseButton || !dom.playButton || !dom.timerDisplay || !dom.audioPlayer || !dom.statusDisplay) {
        _error('One or more essential UI elements are missing. Recorder cannot initialize.');
        return false;
      }
      if (!dom.uuidField || !dom.fileInput) {
        _warn('UUID field or File input field for form submission not found. Attachment will fail.');
        // Decide if this is critical enough to stop init. For now, it will proceed but warn.
      }
      _log('All essential UI elements found.');

      if (dom.timerDisplay) dom.timerDisplay.setAttribute('aria-live', 'polite');

      this.setupEventListeners(); // Call internal method
      return this.setupPermissionsAndUI(); // Call internal method, returns a Promise
    },

    setupEventListeners: function () {
      _log('Setting up event listeners...');
      if (!dom.recordButton || !dom.pauseButton || !dom.playButton) {
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

      dom.playButton.addEventListener('click', () => {
        _log('Play button CLICKED. audioPlayer.src:', dom.audioPlayer.src);
        if (dom.audioPlayer.src && dom.audioPlayer.src !== window.location.href && dom.audioPlayer.readyState > 0) {
            dom.audioPlayer.play().catch(e => _error("Error playing audio:", e));
        } else {
            _log("Play button clicked, but no valid audio source or player not ready.");
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
      _handleRecordingReady(); // Set initial button states for pause/play

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
            // The enforcer should ideally handle this, but direct set is fallback/initial
            dom.recordButton.disabled = !isAllowed;
            _log(`Record button ${isAllowed ? 'ENABLED' : 'DISABLED'}.`);
          };

          updateBasedOnPermission(); // Initial update
          permissionStatus.onchange = () => {
            window.sparxstarRecorderState.micPermission = permissionStatus.state; // Update global on change
            _log(`Permission state CHANGED to "${permissionStatus.state}".`);
            updateBasedOnPermission();
            // If enforcer exists, it should also react or be re-evaluated
            if(recordButtonEnforcer) {
                // The enforcer's internal `shouldBeEnabled` will pick up the new global state.
                // We might need a way to manually trigger its check if its observer didn't fire.
            }
          };

          // Setup Button State Enforcer only if recordButton exists
          if (dom.recordButton) {
             // Disconnect old enforcer if it exists and we are re-initializing
            if (recordButtonEnforcer && typeof recordButtonEnforcer.disconnect === 'function') {
                recordButtonEnforcer.disconnect();
            }
            recordButtonEnforcer = createButtonStateEnforcer(dom.recordButton, window.sparxstarRecorderState, 'micPermission', _log);

            // Add periodic check for the button instance (if it gets replaced by other JS)
            // This is simplified; a more robust solution might involve a different strategy
            // if the button is frequently replaced.
            setInterval(() => {
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

      if (!selectedMimeType) { /* ... alert and error ... */ return; }
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
        mediaRecorder.start();
        _log("mediaRecorder.start() called.");

        isRecording = true;
        isPaused = false;
        if (dom.recordButton) {
            dom.recordButton.textContent = 'Stop';
            dom.recordButton.setAttribute('aria-pressed', 'true');
        }
        if (dom.pauseButton) dom.pauseButton.disabled = false;
        if (dom.pauseButton) dom.pauseButton.textContent = 'Pause';
        if (dom.playButton) dom.playButton.disabled = true;
        _updateStatus('Recording started…');
        if (dom.audioPlayer) dom.audioPlayer.src = '';

        _startTimerForNewRecording();
        if (dom.levelBar && (window.AudioContext || window.webkitAudioContext)) {
            _log("Setting up audio level bar.");
            if (audioContext && audioContext.state !== 'closed') audioContext.close();
            audioContext = new (window.AudioContext || window.webkitAudioContext)();
            analyser = audioContext.createAnalyser();
            analyser.fftSize = 256;
            dataArray = new Uint8Array(analyser.frequencyBinCount);
            sourceNode = audioContext.createMediaStreamSource(stream);
            sourceNode.connect(analyser);
            _animateBar();
        }
      } catch (error) {
        // ... (error handling as before, update status, reset UI via _handleRecordingReady, etc.) ...
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
            if (dom.pauseButton) {
                dom.pauseButton.textContent = 'Resume';
                dom.pauseButton.setAttribute('aria-pressed', 'true');
            }
            _updateStatus('Recording paused');
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
            _updateStatus('Recording resumed...');
        } catch (e) { _error("mediaRecorder.resume() failed", e); }
    },

    cleanup: function() {
      _log("Cleanup called.");
      if (isRecording) {
        this.stop(); // Ensure recording is stopped
      }
      if (currentStream) {
        currentStream.getTracks().forEach(track => track.stop());
        currentStream = null;
        _log("Media stream stopped.");
      }
      if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        // This case should be handled by this.stop(), but as a safeguard
        mediaRecorder.onstop = null; // Prevent _handleStop from running again if already cleaned up
        mediaRecorder.stop();
      }
      if (audioContext && audioContext.state !== 'closed') {
        audioContext.close().then(() => _log("AudioContext closed."));
        audioContext = null;
      }
      if (dom.audioPlayer && dom.audioPlayer.src.startsWith('blob:')) {
        URL.revokeObjectURL(dom.audioPlayer.src);
        dom.audioPlayer.src = '';
        dom.audioPlayer.classList.add('sparxstar_visually_hidden'); // Hide it
        _log("Revoked audio player blob URL.");
      }
      const submitButton = document.getElementById(`submit_button_${config.formInstanceId}`);
        if (submitButton) {
          submitButton.disabled = true;
          _log('Submit button disabled after cleanup.');
        }
      audioChunks = [];
      isRecording = false;
      isPaused = false;
      _stopTimerAndResetDisplay();
      _handleRecordingReady(); // Reset button states
       // Reset UUID and File input fields (important for modal re-use)
      if (dom.uuidField) dom.uuidField.value = '';
      if (dom.fileInput) dom.fileInput.value = ''; // For type=file, setting value to "" or null clears it

      _updateStatus("Recorder reset.");
      _log("Recorder state and UI fully reset.");
    },

    destroy: function () {
      this.cleanup();
      recordButtonEnforcer?.disconnect();
      recordButtonEnforcer = null;
      submitButton.disabled = true;
      dom = {}; // Drop all DOM refs
      config = {}; // Reset config if needed
    },

    generateUniqueAudioId: function() { // If the submission script needs to get it after recording
        return dom.uuidField ? dom.uuidField.value : null;
    }

  };

  return publicMethods;
})();

// ==== How to use it in your modal context (e.g., in a script loaded with your modal form) ====

// Modal usage example - place in your modal controller script
/*
document.addEventListener('DOMContentLoaded', () => {
  const modal = document.getElementById('yourRecorderModalId');
  const openModalButton = document.getElementById('openRecorderModalButton');
  const closeModalButton = document.getElementById('closeRecorderModalButton');
  const audioForm = document.getElementById('sparxstarAudioForm');
  const formStatusDiv = document.getElementById('sparxstar_status');
  const formUuidField = document.getElementById('audio_uuid');

  let recorderInstance = null;

  recorderInstance.init({
    formInstanceId: 'sparxstarAudioForm_xyz', // must match PHP-rendered ID suffix
    buildHash: '1d51ca08edb9'
  });

  function initializeAndShowModal() {
    if (!recorderInstance) {
      recorderInstance = StarmusAudioRecorder;
      recorderInstance.init({ formInstanceId: 'modalForm1', buildHash: 'abc123' });
    } else {
      recorderInstance.cleanup();
      recorderInstance.setupPermissionsAndUI();
    }
    modal.style.display = 'block';
  }

  function hideAndCleanupModal() {
    modal.style.display = 'none';
    if (recorderInstance) recorderInstance.cleanup();
  }

  openModalButton?.addEventListener('click', initializeAndShowModal);
  closeModalButton?.addEventListener('click', hideAndCleanupModal);

  if (audioForm) {
    const recorderContainer = document.querySelector('[data-enabled-recorder]');
    recorderContainer?.addEventListener('starmusAudioReady', (event) => {
      document.cookie = `audio_uuid=${event.detail.uuid}; path=/; max-age=86400; SameSite=Lax; Secure`;
    });

    audioForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!formUuidField.value) {
        formStatusDiv.textContent = ' Error: Audio not recorded or UUID missing.';
        return;
      }

      formStatusDiv.textContent = 'Uploading…';
      formStatusDiv.classList.remove('visually-hidden');

      const formData = new FormData(audioForm);
      try {
        const response = await fetch(audioForm.action, { method: 'POST', body: formData });
        const text = await response.text();
        formStatusDiv.textContent = response.ok ? 'Successfully submitted!' : 'Error: ' + text;
        if (response.ok) audioForm.reset();
        recorderInstance?.cleanup();
      } catch (error) {
        formStatusDiv.textContent = 'Network error. Please try again.';
        console.error("FORM Submit Error:", error);
      }
    });
  }
});
*/

/*
// In your modal controller script that finds ALL recorder triggers
// NOTE: Call init() inside the modal open handler, not on DOMContentLoaded!
document.querySelectorAll('.open-starmus-recorder-modal-button').forEach(button => {
    const formInstanceId = button.dataset.formInstanceId; // e.g., <button data-form-instance-id="form1_abc">
    const modalId = button.dataset.modalId; // e.g., <button data-modal-id="recorderModal_form1_abc">

    button.addEventListener('click', async () => {
        const modal = document.getElementById(modalId);
        // This is where you'd pass the specific formInstanceId
        await StarmusAudioRecorder.init({
            formInstanceId: formInstanceId, // CRITICAL
            buildHash: 'YOUR_BUILD_HASH',
        });
        modal.style.display = 'block';
    });

    const closeModalButton = document.getElementById(`closeButton_${formInstanceId}`);
    closeModalButton?.addEventListener('click', () => {
        const modal = document.getElementById(modalId);
        modal.style.display = 'none';
        StarmusAudioRecorder.cleanup();
    });

    const audioForm = document.getElementById(formInstanceId);
    const formStatusDiv = document.getElementById(`sparxstar_status_${formInstanceId}`);
    const formUuidField = document.getElementById(`audio_uuid_${formInstanceId}`);
    const recorderContainer = document.getElementById(`starmus_audioWrapper_${formInstanceId}`);
    recorderContainer?.addEventListener('starmusAudioReady', (event) => {
        // ... set cookie ...
    });
    audioForm?.addEventListener('submit', async (e) => {
        // ... submit logic for THIS formInstanceId ...
    });
});
*/
