// ==== starmus-audio-recorder-module.js ====

// Utility: Button State Enforcer (already somewhat modular, keep it that way)
function createButtonStateEnforcer(initialButtonElement, sharedStateObject, permissionKey, logFn = console.log) {
  // ... your existing createButtonStateEnforcer code ...
  // Ensure it's robust and handles cases where the button might be temporarily removed/re-added.
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
    timerDisplayId: 'sparxstar_timer',
    audioPlayerId: 'sparxstar_audioPlayer',
    statusDisplayId: 'sparxstar_status',
    levelBarId: 'sparxstar_audioLevelBar',
    uuidFieldId: 'audio_uuid', // Matches your form HTML
    fileInputId: 'audio_file', // Matches your form HTML
    recorderContainerSelector: '[data-enabled-recorder]',
    maxRecordingTime: 1200000, // 20 minutes
    buildHash: 'YOUR_BUILD_HASH_HERE', // Update this
    logPrefix: 'RECORDER:'
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

  function _resumeTimer() { /* ... */ }
  function _pauseTimer() { /* ... */ }
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
    if (dom.statusDisplay) dom.statusDisplay.textContent = msg;
    // Make status visible if it was visually-hidden
    if (dom.statusDisplay && dom.statusDisplay.classList.contains('visually-hidden')) {
        dom.statusDisplay.classList.remove('visually-hidden');
    }
  }

  function _handleRecordingReady() {
    _log("handleRecordingReady called");
    if (dom.recordButton) dom.recordButton.disabled = (window.sparxstarRecorderState.micPermission === 'denied');
    if (dom.pauseButton) {
      dom.pauseButton.disabled = true;
      dom.pauseButton.textContent = 'Pause';
    }
    if (dom.playButton && dom.audioPlayer) {
      dom.playButton.disabled = !dom.audioPlayer.src || dom.audioPlayer.src === window.location.href || dom.audioPlayer.readyState === 0;
    }
  }

  function _handleDataAvailable(event) { /* ... */ }

  function _handleStop() {
    _log("handleStop called. isRecording:", isRecording, "isPaused:", isPaused, "audioChunks length:", audioChunks.length);
    _stopAnimationBarLoop();

    if (isRecording && !isPaused) {
        accumulatedElapsedTime += (Date.now() - segmentStartTime);
    }
     _log(`Total recorded duration (accumulated): ${_formatTime(accumulatedElapsedTime)}`);

    if (!mediaRecorder || audioChunks.length === 0) {
      // ... (reset UI as before) ...
      _updateStatus('Recording stopped, no audio captured.');
      _handleRecordingReady();
      // ...
      return;
    }

    const mimeType = mediaRecorder.mimeType;
    let fileType;
    // ... (determine fileType) ...

    const audioBlob = new Blob(audioChunks, { type: mimeType });
    const audioUrl = URL.createObjectURL(audioBlob);
    if (dom.audioPlayer) dom.audioPlayer.src = audioUrl;
    _log("Audio blob created, URL:", audioUrl);

    _attachAudioToForm(audioBlob, fileType); // This needs access to uuidField and fileInput

    audioChunks = [];
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

  function _generateUUID() {
    _log("generateUUID called");
    try {
        if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID();
        }
        // ... rest of your fallback UUID logic ...
    } catch (error) {
        _error("Crypto API failed during UUID generation, falling back.", error);
    }
    _warn("Generating UUID using Math.random(). Not cryptographically secure.");
    // ... Math.random() fallback ...
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
    const generatedUuid = _generateUUID(); // Generate UUID here when audio is ready
    const fileName = `audio_${generatedUuid}.${fileType}`;
    _log("Generated filename:", fileName);

    if (dom.uuidField) {
        dom.uuidField.value = generatedUuid; // Populate the hidden UUID field
        _log("UUID field value set to:", generatedUuid);
    } else {
        _warn("UUID field not found in DOM. Skipping setting its value.");
    }

    const file = new File([audioBlob], fileName, { type: audioBlob.type });
    if (!dom.fileInput) {
      _warn('No audio file input (dom.fileInput) found. Skipping attachment to form.');
      _updateStatus('Recording saved locally. File input not found in form.');
      return;
    }
    try {
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(file);
        dom.fileInput.files = dataTransfer.files; // Populate the hidden file input
        _log('Audio attached to form input:', dom.fileInput.name || dom.fileInput.id);
        _updateStatus('Recording saved and attached to form.');

        // Optionally, dispatch a custom event to notify the submission script
        // that the audio is ready and fields are populated.
        const event = new CustomEvent('starmusAudioReady', { detail: { uuid: generatedUuid, fileName: fileName } });
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

      // Get main container first
      dom.container = document.querySelector(config.recorderContainerSelector);
      if (!dom.container) {
        _warn(`Container "${config.recorderContainerSelector}" not found. Recorder will not run.`);
        return false;
      }

      // Find DOM elements (scoped to the container if they are inside it, or globally if IDs are unique)
      // The IDs in your HTML are sparxstar_ prefixed or generic. Using the generic ones for simplicity here.
      dom.recordButton = document.getElementById(config.recordButtonId); // e.g., 'recordButton'
      dom.pauseButton = document.getElementById(config.pauseButtonId);
      dom.playButton = document.getElementById(config.playButtonId);
      dom.timerDisplay = document.getElementById(config.timerDisplayId);
      dom.audioPlayer = document.getElementById(config.audioPlayerId);
      dom.statusDisplay = document.getElementById(config.statusDisplayId);
      dom.levelBar = document.getElementById(config.levelBarId); // Assuming levelBar is uniquely ID'd

      // These are critical for the form submission part
      dom.uuidField = document.getElementById(config.uuidFieldId);
      dom.fileInput = document.getElementById(config.fileInputId);


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
        // ... (pause/resume logic calling this.pause() and this.resume()) ...
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
        // ... user messages ...
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
        _log("Revoked audio player blob URL.");
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

    getGeneratedUUID: function() { // If the submission script needs to get it after recording
        return dom.uuidField ? dom.uuidField.value : null;
    }

  };

  return publicMethods;
})();

// ==== How to use it in your modal context (e.g., in a script loaded with your modal form) ====

/*
// This part would go into the script that manages the modal itself.
// It assumes starmus-audio-recorder-module.js has been loaded.

document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('yourRecorderModalId'); // The modal container
    const openModalButton = document.getElementById('openRecorderModalButton');
    const closeModalButton = document.getElementById('closeRecorderModalButton'); // Your modal's close button
    const audioForm = document.getElementById('sparxstarAudioForm'); // Your form ID
    const formStatusDiv = document.getElementById('sparxstar_status'); // Your form's status div
    const formUuidField = document.getElementById('audio_uuid'); // Your form's UUID field

    let recorderInstance = null; // To hold the initialized recorder

    function initializeAndShowModal() {
        if (!recorderInstance) {
            // Pass the correct IDs if they differ from defaults in the module
            recorderInstance = StarmusAudioRecorder; // The module itself
            const initSuccess = await recorderInstance.init({
                // Example: override default IDs if your modal HTML uses different ones
                // recordButtonId: 'modalRecordBtn',
                // buildHash: 'CURRENT_BUILD_HASH_FROM_YOUR_BUILD_PROCESS'
            });
            if (!initSuccess) {
                console.error("Failed to initialize StarmusAudioRecorder in modal.");
                // Show error in modal or disable modal opening
                return;
            }
        } else {
            // If already initialized, ensure it's reset for a new session
            recorderInstance.cleanup(); // Clean previous state
            // Re-setup permissions as they might have changed or if UI was torn down
            await recorderInstance.setupPermissionsAndUI();
        }
        modal.style.display = 'block'; // Or use modal.showModal() for <dialog>
    }

    function hideAndCleanupModal() {
        modal.style.display = 'none'; // Or modal.close() for <dialog>
        if (recorderInstance) {
            recorderInstance.cleanup();
        }
    }

    if (openModalButton) {
        openModalButton.addEventListener('click', initializeAndShowModal);
    }
    if (closeModalButton) {
        closeModalButton.addEventListener('click', hideAndCleanupModal);
    }

    // --- Form Submission Logic (from your other script, adapted) ---
    if (audioForm) {
        // Listen for the custom event from the recorder module
        const recorderContainerForEvent = document.querySelector('[data-enabled-recorder]'); // Or pass this element ref to the recorder
        if (recorderContainerForEvent) {
            recorderContainerForEvent.addEventListener('starmusAudioReady', (event) => {
                console.log('FORM: starmusAudioReady event received!', event.detail);
                // The UUID is now in event.detail.uuid and also in formUuidField.value
                // The cookie can be set here before submission if not already handled
                document.cookie = `audio_uuid=${event.detail.uuid}; path=/; max-age=86400; SameSite=Lax; Secure`;
                console.log("FORM: Cookie set with UUID:", event.detail.uuid);
            });
        }


        audioForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!formUuidField.value) { // Ensure UUID is set (by recorder)
                formStatusDiv.textContent = '❌ Error: Audio not recorded or UUID missing.';
                return;
            }
            // Cookie should have been set by the 'starmusAudioReady' event listener
            // or ensure it's set here if that event isn't used.

            formStatusDiv.textContent = 'Uploading…';
            formStatusDiv.classList.remove('visually-hidden');

            const formData = new FormData(audioForm);
            try {
                const response = await fetch(audioForm.action, {
                    method: 'POST',
                    body: formData
                });
                const text = await response.text(); // Or .json()
                if (response.ok) {
                    formStatusDiv.textContent = '✅ Successfully submitted!';
                    audioForm.reset();
                    if (recorderInstance) recorderInstance.cleanup(); // Also cleanup recorder UI
                    // setTimeout(hideAndCleanupModal, 2000); // Optionally close modal after success
                } else {
                    formStatusDiv.textContent = '❌ Error: ' + text;
                }
            } catch (error) {
                formStatusDiv.textContent = '❌ Network error. Please try again.';
                console.error("FORM Submit Error:", error);
            }
        });
    }
});
*/
