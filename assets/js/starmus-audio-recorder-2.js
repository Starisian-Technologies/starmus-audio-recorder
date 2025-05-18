// Add this at the very top of your starmus-audio-recorder.js file
console.log('RECORDER SCRIPT FILE: PARSING STARTED - TOP OF FILE');
console.log('Starmus Recorder Build Hash: 1d51ca08edb9');
function createButtonStateEnforcer(initialButtonElement, sharedStateObject, permissionKey, logFn = console.log) {
  // KEY CHANGE: Re-select the button using its ID every time the enforcer's logic runs,
  // or at least at critical points.
  // For the observer itself, it needs to be attached to a specific instance.
  // But for checks and corrections, always get the latest.

  // Get the button that the observer will be attached to.
  // This initialButtonElement is passed when the observer is first created.
  let observedButtonElement = document.getElementById(initialButtonElement?.id || 'recordButton'); // Or pass the ID string directly

  if (!observedButtonElement) {
    console.error('StateEnforcer Init: Could not find button to observe in DOM using ID:', initialButtonElement?.id || 'recordButton');
    return null;
  }

  const getLiveButton = () => document.getElementById(observedButtonElement.id); // Function to always get the current live button by its ID

  const shouldBeEnabled = () => {
    const state = sharedStateObject?.[permissionKey];
    // logFn(`StateEnforcer [shouldBeEnabled]: For button id "${observedButtonElement.id}", sharedState["${permissionKey}"] = "${state}"`);
    return state === 'granted' || state === 'prompt';
  };

  // --- Immediate correction on the button we are about to observe ---
  let liveButtonForImmediateCheck = getLiveButton();
  if (liveButtonForImmediateCheck) { // Check if it still exists
      if (liveButtonForImmediateCheck.disabled && shouldBeEnabled()) {
        logFn(`StateEnforcer (Immediate Init for ID ${liveButtonForImmediateCheck.id}): Button is disabled but should be enabled. Re-enabling.`);
        liveButtonForImmediateCheck.disabled = false;
      } else if (!liveButtonForImmediateCheck.disabled && !shouldBeEnabled()) {
        logFn(`StateEnforcer (Immediate Init for ID ${liveButtonForImmediateCheck.id}): Button is enabled but should be disabled. Disabling.`);
        liveButtonForImmediateCheck.disabled = true;
      } else {
        // logFn(`StateEnforcer (Immediate Init for ID ${liveButtonForImmediateCheck.id}): No immediate correction needed. isDisabled=${liveButtonForImmediateCheck.disabled}, shouldEnable=${shouldBeEnabled()}`);
      }
  } else {
      logFn.warn(`StateEnforcer (Immediate Init): Button with ID ${observedButtonElement.id} not found for immediate check.`);
  }
  // --- End Immediate correction ---

  const observer = new MutationObserver((mutations) => {
    let currentLiveButton = getLiveButton(); // Re-fetch inside observer in case it was replaced between mutations
    if (!currentLiveButton) {
        // logFn('StateEnforcer (Mutation): Observed button no longer in DOM. Original ID was ' + observedButtonElement.id);
        // The observer is still attached to the old, detached node.
        // The setInterval approach from previous discussion is better to handle re-attaching the observer itself.
        // For now, this observer will stop being effective if the element it's attached to is removed.
        return;
    }

    for (const mutation of mutations) {
      if (mutation.type === 'attributes' && mutation.attributeName === 'disabled') {
        // logFn(`StateEnforcer (Mutation for ID ${currentLiveButton.id}): "disabled" attribute mutated.`);
        const allowed = shouldBeEnabled();
        // Check the live button's state
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

  // Observe the specific instance found at init.
  // If THIS `observedButtonElement` is removed, this observer becomes ineffective for the new button.
  observer.observe(observedButtonElement, {
    attributes: true,
    attributeFilter: ['disabled']
  });
  logFn(`StateEnforcer: MutationObserver active on initial button instance with ID "${observedButtonElement.id}".`);


  // --- Delayed Timeouts ---
  // These timeouts will always try to get the LATEST button by ID.
  [1500, 3000, 5000].forEach((delay) => {
    setTimeout(() => {
      const freshButton = getLiveButton(); // Always get the current live button
      if (!freshButton) { // Check if any button with that ID exists
        // logFn(`StateEnforcer (Timeout ${delay}ms): Button with ID ${observedButtonElement.id} no longer in DOM. Aborting correction.`);
        return;
      }
      // Check if the *original observed element* is still in the DOM for context, though we operate on freshButton
      if (!document.body.contains(observedButtonElement)) {
          // logFn(`StateEnforcer (Timeout ${delay}ms): Original observed button instance no longer in DOM. Operating on fresh instance if found.`);
      }

      if (freshButton.disabled && shouldBeEnabled()) {
        logFn(`StateEnforcer (Timeout ${delay}ms for ID ${freshButton.id}): Correction — re-enabling button.`);
        freshButton.disabled = false;
      } else if (!freshButton.disabled && !shouldBeEnabled()) {
        logFn(`StateEnforcer (Timeout ${delay}ms for ID ${freshButton.id}): Button enabled, should be disabled. Disabling.`);
        freshButton.disabled = true;
      } else {
        // logFn(`StateEnforcer (Timeout ${delay}ms for ID ${freshButton.id}): No correction needed.`);
      }
    }, delay);
  });

  return observer; // Return the observer attached to the *initial* button
}


document.addEventListener('DOMContentLoaded', function () {
  console.log('RECORDER: DOMContentLoaded event fired. Script starting.');
  window.StarmusRecorderBuild = '1d51ca08edb9';

  window.SparxstarUtils = window.SparxstarUtils || {};
  window.SparxstarUtils.createButtonStateEnforcer = createButtonStateEnforcer;


  const container = document.querySelector('[data-enabled-recorder]');
  console.log('RECORDER: Container element:', container);
  if (!container) {
    console.warn('RECORDER: Container [data-enabled-recorder] not found. Script will not run.');
    return;
  }

  const recordButton = document.getElementById('sparxstar_recordButton') || document.getElementById('recordButton');
  const pauseButton = document.getElementById('sparxstar_pauseButton') || document.getElementById('pauseButton');
  const playButton = document.getElementById('sparxstar_playButton') || document.getElementById('playButton');
  const timerDisplay = document.getElementById('sparxstar_timer') || document.getElementById('timer');
  const audioPlayer = document.getElementById('sparxstar_audioPlayer') || document.getElementById('audioPlayer');
  const fileInput = container.querySelector('input[type="file"][accept*="audio"]'); // Keep as is
  const uuidField = container.querySelector('input[name="audio_uuid"]'); // Keep as is
  const levelBar = container.querySelector('#sparxstar_audioLevelBar'); // Keep as is

  console.log('RECORDER: Record button:', recordButton);
  console.log('RECORDER: Pause button:', pauseButton);
  console.log('RECORDER: Play button:', playButton);
  console.log('RECORDER: Timer display:', timerDisplay);
  console.log('RECORDER: Audio player:', audioPlayer);
  console.log('RECORDER: Level bar:', levelBar);
  console.log('RECORDER: File input:', fileInput); // Will be null if not found, that's ok
  console.log('RECORDER: UUID field:', uuidField); // Will be null if not found, that's ok

  const MAX_RECORDING_TIME = 1200000; // 20 minutes in milliseconds

  if (!recordButton || !pauseButton || !playButton || !timerDisplay || !audioPlayer) {
    console.error('RECORDER ERROR: One or more essential UI elements are missing. Recorder cannot initialize.');
    return;
  }
  console.log('RECORDER: All essential UI elements found.');

  let mediaRecorder;
  let audioChunks = [];
  let currentStream = null;

  let isRecording = false;
  let isPaused = false;

  let timerInterval;
  let segmentStartTime;
  let accumulatedElapsedTime = 0;

  if (timerDisplay) timerDisplay.setAttribute('aria-live', 'polite');
  else console.warn("RECORDER: timerDisplay is null, cannot set aria-live.");


  let audioContext, analyser, dataArray, sourceNode, animationFrameId;

   window.sparxstarRecorderState = window.sparxstarRecorderState || {
    micPermission: 'prompt',
  };

  // --- Your existing functions (animateBar, stopAnimationBarLoop, formatTime, etc.) go here ---
  // Make sure they are all present. I'll just show where the event listeners and setupRecorder go.

  function animateBar() {
    if (analyser && dataArray && levelBar && isRecording && !isPaused) {
      analyser.getByteFrequencyData(dataArray);
      const volume = dataArray.reduce((a, b) => a + b, 0) / dataArray.length;
      const percent = Math.min((volume / 255) * 100, 100);
      levelBar.style.width = `${percent}%`;
      levelBar.setAttribute('aria-valuenow', Math.round(percent));
      animationFrameId = requestAnimationFrame(animateBar);
    } else {
      stopAnimationBarLoop();
    }
  }

  function stopAnimationBarLoop() {
    if (animationFrameId) {
      cancelAnimationFrame(animationFrameId);
      animationFrameId = null;
    }
    if (levelBar) levelBar.style.width = '0%';
    // else console.log("RECORDER DEBUG: levelBar not found in stopAnimationBarLoop");
  }

  function formatTime(ms) {
    const totalSeconds = Math.floor(ms / 1000);
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
  }

  function updateTimerColor(remainingTimeMs) {
    if (!timerDisplay) {
        // console.log("RECORDER DEBUG: timerDisplay not found in updateTimerColor");
        return;
    }
    const twoMinutes = 120000;
    const sevenMinutes = 420000;
    timerDisplay.classList.remove('red', 'orange');
    timerDisplay.style.color = '';
    if (remainingTimeMs <= twoMinutes) {
      timerDisplay.classList.add('red');
    } else if (remainingTimeMs <= sevenMinutes) {
      timerDisplay.classList.add('orange');
    }
  }

  function updateTimerDisplay() {
    if (!timerDisplay) {
        // console.log("RECORDER DEBUG: timerDisplay not found in updateTimerDisplay");
        return;
    }
    let currentSegmentElapsedTime = 0;
    if (isRecording && !isPaused) {
      currentSegmentElapsedTime = Date.now() - segmentStartTime;
    }
    const totalRecordedTime = accumulatedElapsedTime + currentSegmentElapsedTime;
    const remainingTime = Math.max(0, MAX_RECORDING_TIME - totalRecordedTime);
    timerDisplay.textContent = formatTime(remainingTime);
    updateTimerColor(remainingTime);
    if (remainingTime <= 0) {
      console.log("RECORDER: Time limit reached, stopping recording.");
      stopRecording();
    }
  }

  function startTimerForNewRecording() {
    console.log("RECORDER: startTimerForNewRecording called");
    accumulatedElapsedTime = 0;
    segmentStartTime = Date.now();
    if (timerInterval) clearInterval(timerInterval);
    timerInterval = setInterval(updateTimerDisplay, 1000);
    updateTimerDisplay();
  }

  function resumeTimer() {
    console.log("RECORDER: resumeTimer called");
    segmentStartTime = Date.now();
    if (timerInterval) clearInterval(timerInterval);
    timerInterval = setInterval(updateTimerDisplay, 1000);
    updateTimerDisplay();
  }

  function pauseTimer() {
    console.log("RECORDER: pauseTimer called");
    clearInterval(timerInterval);
    timerInterval = null;
    if (isRecording) {
      accumulatedElapsedTime += (Date.now() - segmentStartTime);
    }
    updateTimerDisplay();
  }

  function stopTimerAndResetDisplay() {
    console.log("RECORDER: stopTimerAndResetDisplay called");
    clearInterval(timerInterval);
    timerInterval = null;
    accumulatedElapsedTime = 0;
    if (timerDisplay) {
        timerDisplay.textContent = formatTime(MAX_RECORDING_TIME);
        updateTimerColor(MAX_RECORDING_TIME);
    }
    // else console.log("RECORDER DEBUG: timerDisplay not found in stopTimerAndResetDisplay");
  }

  function updateStatus(msg) {
    console.log("RECORDER STATUS UPDATE:", msg);
    const status = document.getElementById('sparxstar_status');
    if (status) status.textContent = msg;
    // else console.log("RECORDER DEBUG: sparxstar_status element not found in updateStatus");
  }

  function handleRecordingReady() {
  console.log("RECORDER: handleRecordingReady called");
  if (pauseButton) {
    pauseButton.disabled = true;
    pauseButton.textContent = 'Pause';
  }
  if (playButton && audioPlayer) {
    playButton.disabled = !audioPlayer.src || audioPlayer.src === window.location.href || audioPlayer.readyState === 0;
  }
}

  function handleDataAvailable(event) {
    console.log("RECORDER: handleDataAvailable called, data size:", event.data.size);
    if (event.data.size > 0) audioChunks.push(event.data);
  }

  function handleStop() {
    console.log("RECORDER: handleStop called. isRecording:", isRecording, "isPaused:", isPaused, "audioChunks length:", audioChunks.length);
    stopAnimationBarLoop();
    // clearInterval(timerInterval); // Redundant, called in stopTimerAndResetDisplay

    if (isRecording && !isPaused) {
        accumulatedElapsedTime += (Date.now() - segmentStartTime);
    }
    console.log(`RECORDER: Total recorded duration (accumulated): ${formatTime(accumulatedElapsedTime)}`);

    if (!mediaRecorder || audioChunks.length === 0) {
      console.warn('RECORDER: No audio data recorded or mediaRecorder not available. Resetting UI.');
      audioChunks = [];
      stopTimerAndResetDisplay();
      isRecording = false;
      isPaused = false;
      if(recordButton) {
        recordButton.textContent = 'Record';
        recordButton.setAttribute('aria-pressed', 'false');
      }
      updateStatus('Recording stopped, no audio captured.');
      handleRecordingReady();
      if (currentStream) {
        console.log("RECORDER: Stopping current stream tracks in handleStop (no data).");
        currentStream.getTracks().forEach(track => track.stop());
        currentStream = null;
      }
      return;
    }

    const mimeType = mediaRecorder.mimeType;
    console.log("RECORDER: MediaRecorder mimeType:", mimeType);
    let fileType;
    if (mimeType.includes('opus') || mimeType.includes('webm')) fileType = 'webm';
    else if (mimeType.includes('aac')) fileType = 'm4a'; // Common for mp4 audio
    else {
      console.error('RECORDER ERROR: Unsupported recorded MIME type:', mimeType);
      alert('Recording failed: unsupported audio format. Try a different browser.');
      audioChunks = [];
      stopTimerAndResetDisplay();
      isRecording = false;
      isPaused = false;
      if(recordButton) recordButton.textContent = 'Record';
      handleRecordingReady();
      if (currentStream) {
        console.log("RECORDER: Stopping current stream tracks in handleStop (unsupported mime).");
        currentStream.getTracks().forEach(track => track.stop());
        currentStream = null;
      }
      return;
    }
    console.log("RECORDER: Determined fileType:", fileType);

    const audioBlob = new Blob(audioChunks, { type: mimeType });
    const audioUrl = URL.createObjectURL(audioBlob);
    if(audioPlayer) audioPlayer.src = audioUrl;
    // else console.log("RECORDER DEBUG: audioPlayer not found in handleStop");
    console.log("RECORDER: Audio blob created, URL:", audioUrl);

    attachAudioToForm(audioBlob, fileType);

    audioChunks = [];
    stopTimerAndResetDisplay();
    isRecording = false;
    isPaused = false;
    if(recordButton) {
        recordButton.textContent = 'Record';
        recordButton.setAttribute('aria-pressed', 'false');
    }
    handleRecordingReady();
    if (currentStream) {
      console.log("RECORDER: Stopping current stream tracks in handleStop (successful end).");
      currentStream.getTracks().forEach(track => track.stop());
      currentStream = null;
    }
    console.log("RECORDER: handleStop finished.");
  }

  function generateUUID() {
    // ... (UUID function as before)
    // You can add a console.log here if you want to see when it's called
    // console.log("RECORDER: generateUUID called");
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
      console.error("RECORDER: Crypto API failed during UUID generation, falling back.", error);
    }
    console.warn("RECORDER WARNING: Generating UUID using Math.random(). Not cryptographically secure.");
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

  function attachAudioToForm(audioBlob, fileType) {
    console.log("RECORDER: attachAudioToForm called. File type:", fileType);
    const uuid = generateUUID();
    const fileName = `audio_${uuid}.${fileType}`;
    console.log("RECORDER: Generated filename:", fileName);

    if (uuidField) {
        uuidField.value = fileName;
        console.log("RECORDER: UUID field value set to:", fileName);
    } else {
        console.warn("RECORDER: UUID field not found. Skipping setting its value.");
    }

    const file = new File([audioBlob], fileName, { type: audioBlob.type });
    if (!fileInput) {
      console.warn('RECORDER: No audio file input (fileInput) found in form. Skipping attachment.');
      updateStatus('Recording saved locally. File input not found.');
      return;
    }
    try {
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(file);
        fileInput.files = dataTransfer.files;
        console.log('RECORDER: Audio attached to form input:', fileInput.name || fileInput.id);
        updateStatus('Recording saved and attached to form.');
    } catch (e) {
        console.error("RECORDER ERROR: Could not attach file to fileInput. DataTransfer may not be supported or fileInput is problematic.", e);
        updateStatus('Recording saved locally. Error attaching to form.');
    }
  }

  async function startRecording() {
    console.log('RECORDER: startRecording() function CALLED.');
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
      alert('Audio recording is not fully supported in your browser.');
      console.error('RECORDER ERROR: getUserMedia or MediaRecorder not available.');
      return;
    }
    console.log('RECORDER: MediaDevices API and MediaRecorder seem available.');

    const supportedMimeTypes = ['audio/webm;codecs=opus', 'audio/mp4;codecs=aac', 'audio/webm', 'audio/ogg;codecs=opus']; // Added audio/ogg
    let selectedMimeType = supportedMimeTypes.find(type => MediaRecorder.isTypeSupported(type));
    
    if (!selectedMimeType) {
        // Fallback for some browsers that might report supported types differently
        if (MediaRecorder.isTypeSupported('audio/webm')) selectedMimeType = 'audio/webm';
        else if (MediaRecorder.isTypeSupported('audio/mp4')) selectedMimeType = 'audio/mp4'; // Broader check
    }

    if (!selectedMimeType) {
      alert('No suitable audio recording format supported. Please try a different browser.');
      console.error('RECORDER ERROR: No supported MIME type found for MediaRecorder. Checked:', supportedMimeTypes);
      return;
    }
    console.log("RECORDER: Selected MIME type for recording:", selectedMimeType);

    try {
      console.log('RECORDER: Attempting navigator.mediaDevices.getUserMedia().');
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      console.log('RECORDER: getUserMedia() SUCCESSFUL. Stream obtained:', stream);
      currentStream = stream;
      audioChunks = [];
      mediaRecorder = new MediaRecorder(stream, { mimeType: selectedMimeType });
      mediaRecorder.ondataavailable = handleDataAvailable;
      mediaRecorder.onstop = handleStop;
      mediaRecorder.onerror = (event) => { // Added onerror handler
          console.error('RECORDER MediaRecorder Error:', event.error);
          alert(`MediaRecorder error: ${event.error.name}. Please try again or use a different browser.`);
          // Potentially stop and reset UI here too
          stopRecording(); // Or a more direct UI reset
      };
      mediaRecorder.start();
      console.log("RECORDER: mediaRecorder.start() called.");

      isRecording = true;
      isPaused = false;
      if(recordButton) {
        recordButton.textContent = 'Stop';
        recordButton.setAttribute('aria-pressed', 'true'); // Use string for ARIA
      }
      if(pauseButton) pauseButton.disabled = false;
      if(pauseButton) pauseButton.textContent = 'Pause';
      if(playButton) playButton.disabled = true;
      updateStatus('Recording started…');
      if(audioPlayer) audioPlayer.src = '';

      startTimerForNewRecording();
      if (levelBar && (window.AudioContext || window.webkitAudioContext)) {
        console.log("RECORDER: Setting up audio level bar.");
        if (audioContext && audioContext.state !== 'closed') audioContext.close(); // Close existing context if not already closed
        audioContext = new (window.AudioContext || window.webkitAudioContext)();
        analyser = audioContext.createAnalyser();
        analyser.fftSize = 256; // Or 512 for more detail if needed
        dataArray = new Uint8Array(analyser.frequencyBinCount);
        sourceNode = audioContext.createMediaStreamSource(stream);
        sourceNode.connect(analyser);
        animateBar();
      } else if (!levelBar) {
          console.log("RECORDER: Level bar element not found, skipping audio level visualization.");
      } else {
          console.log("RECORDER: AudioContext not supported, skipping audio level visualization.");
      }
    } catch (error) {
      console.error('RECORDER: getUserMedia error:', error.name, error.message, error);
      let userMessage = 'Failed to access microphone or start recording.';
      if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') userMessage = 'Microphone access denied. Please allow in browser settings.';
      else if (error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError') userMessage = 'No microphone found. Please connect one.';
      else if (error.name === 'NotReadableError' || error.name === 'TrackStartError') userMessage = 'Microphone in use or unreadable. Check other apps or browser settings.';
      else if (error.name === 'AbortError') userMessage = 'Request to use microphone was dismissed.';
      else if (error.name === 'SecurityError') userMessage = 'Microphone access denied due to security settings (e.g. page not HTTPS, or sandboxed iframe).';
      else if (error.name === 'TypeError' && error.message.includes("MediaRecorder")) userMessage = `Problem initializing MediaRecorder. Your browser might not fully support it or the chosen audio format. ${selectedMimeType}`;


      alert(userMessage);
      isRecording = false;
      isPaused = false;
      if(recordButton) recordButton.textContent = 'Record';
      handleRecordingReady();
      stopTimerAndResetDisplay();
      if (currentStream) { // Ensure stream tracks are stopped on failure
        console.log("RECORDER: Stopping stream tracks after getUserMedia error.");
        currentStream.getTracks().forEach(track => track.stop());
        currentStream = null;
      }
    }
  }

  function stopRecording() {
    console.log("RECORDER: stopRecording called. MediaRecorder state:", mediaRecorder ? mediaRecorder.state : "N/A");
    if (isRecording && mediaRecorder && mediaRecorder.state !== 'inactive') {
      console.log("RECORDER: Calling mediaRecorder.stop()");
      mediaRecorder.stop(); // This will trigger onstop -> handleStop()
    } else if (isRecording) {
      console.warn('RECORDER WARNING: stopRecording called, but mediaRecorder not active or not defined. Finalizing manually.');
      handleStop();
    } else {
        console.log("RECORDER: stopRecording called but not in a recording state.");
    }
  }


  // --- EVENT LISTENERS ---
  if(recordButton) {
      recordButton.addEventListener('click', () => {
        console.log('RECORDER: Record button CLICKED. isRecording:', isRecording);
        if (!isRecording) {
            console.log('RECORDER: Calling startRecording() from click.');
            startRecording();
        } else {
            console.log('RECORDER: Calling stopRecording() from click.');
            stopRecording();
        }
      });
  } else {
      console.error("RECORDER ERROR: Record button not found, cannot add click listener!");
  }

  if(pauseButton) {
      pauseButton.addEventListener('click', () => {
        console.log('RECORDER: Pause button CLICKED. isRecording:', isRecording, 'isPaused:', isPaused);
        if (!isRecording) {
            console.log("RECORDER: Pause clicked but not recording.");
            return;
        }
        if (!isPaused) {
          console.log("RECORDER: PAUSING recording.");
          if (mediaRecorder && mediaRecorder.state === 'recording') {
              try {
                mediaRecorder.pause();
                console.log("RECORDER: mediaRecorder.pause() called.");
              } catch (e) {
                console.error("RECORDER ERROR: mediaRecorder.pause() failed", e);
              }
          } else {
              console.warn("RECORDER: Tried to pause, but mediaRecorder not in 'recording' state. State:", mediaRecorder ? mediaRecorder.state : "N/A");
          }
          pauseTimer();
          isPaused = true;
          stopAnimationBarLoop();
          pauseButton.textContent = 'Resume';
          pauseButton.setAttribute('aria-pressed', 'true'); // Use string
          updateStatus('Recording paused');
        } else {
          console.log("RECORDER: RESUMING recording.");
          if (mediaRecorder && mediaRecorder.state === 'paused') {
              try {
                mediaRecorder.resume();
                console.log("RECORDER: mediaRecorder.resume() called.");
              } catch (e) {
                console.error("RECORDER ERROR: mediaRecorder.resume() failed", e);
              }
          }  else {
              console.warn("RECORDER: Tried to resume, but mediaRecorder not in 'paused' state. State:", mediaRecorder ? mediaRecorder.state : "N/A");
          }
          isPaused = false;
          resumeTimer();
          if (analyser) animateBar();
          pauseButton.textContent = 'Pause';
          pauseButton.setAttribute('aria-pressed', 'false'); // Use string
          updateStatus('Recording started…'); // Or 'Recording resumed...'
        }
      });
  } else {
      console.warn("RECORDER WARNING: Pause button not found, cannot add click listener.");
  }

  if(playButton && audioPlayer) {
      playButton.addEventListener('click', () => {
        console.log('RECORDER: Play button CLICKED. audioPlayer.src:', audioPlayer.src);
        if (audioPlayer.src && audioPlayer.src !== window.location.href && audioPlayer.readyState > 0) { // Check readyState
            audioPlayer.play().catch(e => console.error("RECORDER: Error playing audio:", e));
        } else {
            console.log("RECORDER: Play button clicked, but no valid audio source or player not ready.");
        }
      });
  } else {
      console.warn("RECORDER WARNING: Play button or audioPlayer not found, cannot add click listener for play.");
  }


 async function setupRecorder() {
  console.log('RECORDER: setupRecorder() called.');

  // Disable all controls initially
  if (recordButton) recordButton.disabled = true;
  if (pauseButton) pauseButton.disabled = true;
  if (playButton) playButton.disabled = true;

  // Initialize mic permission state
  let permissionStatus = null;

  if (navigator.permissions && navigator.permissions.query) {
    try {
      console.log('RECORDER: Querying microphone permission via Permissions API...');
      permissionStatus = await navigator.permissions.query({ name: 'microphone' });

      const updateButtonState = () => {
        console.log(`RECORDER: Permission state is "${permissionStatus.state}". Updating button state.`);
        if (!recordButton) {
          console.error('RECORDER ERROR: Record button not found during permission update.');
          return;
        }
        const isAllowed = ['granted', 'prompt'].includes(permissionStatus.state);
        recordButton.disabled = !isAllowed;
        console.log(`RECORDER: Record button ${isAllowed ? 'ENABLED' : 'DISABLED'}.`);
      };

      // Set global state for observers
      window.sparxstarRecorderState.micPermission = permissionStatus.state;

      // Initial and onchange state sync
      updateButtonState();
      permissionStatus.onchange = () => {
        console.log(`RECORDER: Permission state CHANGED to "${permissionStatus.state}".`);
        window.sparxstarRecorderState.micPermission = permissionStatus.state;
        updateButtonState();
      };

    } catch (err) {
      console.error('RECORDER ERROR: Permissions API query failed:', err);
      if (recordButton) recordButton.disabled = false;
      console.log('RECORDER: Fallback — record button ENABLED.');
    }
  } else {
    console.warn('RECORDER WARNING: Permissions API not supported. Enabling record button by default.');
    if (recordButton) recordButton.disabled = false;
    window.sparxstarRecorderState.micPermission = 'prompt'; // Default fallback
  }

  // Initialize timer display
  if (timerDisplay) {
    timerDisplay.textContent = formatTime(MAX_RECORDING_TIME);
    updateTimerColor(MAX_RECORDING_TIME);
  } else {
    console.warn('RECORDER WARNING: timerDisplay not found.');
  }

  handleRecordingReady(); // Reset UI to a usable state
  console.log('RECORDER: setupRecorder() finished.');
}

  
  console.log('RECORDER: Calling setupRecorder().');
setupRecorder().then(() => {
    // Get the initial button (instance A)
    let currentRecordButton = document.getElementById('recordButton') || document.getElementById('sparxstar_recordButton');
    let observerInstance = null;
    // Use the ID of the button we expect to find.
    // This assumes the ID 'recordButton' (or 'sparxstar_recordButton') remains consistent
    // even if the element instance changes.
    let observerButtonId = currentRecordButton?.id || (document.getElementById('recordButton') ? 'recordButton' : 'sparxstar_recordButton');


    if (currentRecordButton) {
      // Attach observer to initial button (instance A)
      observerInstance = createButtonStateEnforcer(currentRecordButton, window.sparxstarRecorderState, 'micPermission');
    }

    setInterval(() => {
      // Periodically get the LATEST button in the DOM with the expected ID (could be instance B, C, etc.)
      const latestRecordButton = document.getElementById(observerButtonId);

      if (!latestRecordButton) {
        console.warn('RECORDER (Interval Check): recordButton not found in DOM using ID:', observerButtonId);
        // If button is gone, disconnect old observer if it exists
        if (observerInstance) {
            console.log('RECORDER (Interval Check): Disconnecting observer from potentially detached old button.');
            observerInstance.disconnect();
            observerInstance = null;
        }
        currentRecordButton = null; // Reset
        return;
      }

      // If the live button is a NEW instance OR if no observer is currently active on it
      // (The second condition !currentRecordButton.dataset.observerAttached might be more robust
      // if createButtonStateEnforcer marks the button it attaches to)
      if (latestRecordButton !== currentRecordButton) { // Check if the actual DOM element reference has changed
        console.warn('RECORDER (Interval Check): Detected new recordButton instance. Reinitializing observer.');
        currentRecordButton = latestRecordButton; // Update our reference to the new live button
        if (observerInstance) {
          console.log('RECORDER (Interval Check): Disconnecting observer from old button instance.');
          observerInstance.disconnect(); // Disconnect from the old, possibly detached, button
        }
        // Attach a NEW observer to the NEW button instance
        observerInstance = createButtonStateEnforcer(currentRecordButton, window.sparxstarRecorderState, 'micPermission');

        // Immediate correction for the newly found (and now observed) button
        // This uses the shared state, which should be up-to-date via setupRecorder's onchange.
        const shouldEnable = ['granted', 'prompt'].includes(window.sparxstarRecorderState?.micPermission);
        if (currentRecordButton.disabled && shouldEnable) {
          console.log('RECORDER (Interval Check - Correcting New Instance): Enabling newly found recordButton.');
          currentRecordButton.disabled = false;
        } else if (!currentRecordButton.disabled && !shouldEnable) {
          console.log('RECORDER (Interval Check - Correcting New Instance): Disabling newly found recordButton as per permissions.');
          currentRecordButton.disabled = true;
        }
      } else {
        // Optional: Safety net if the same button instance is still there but somehow its state is wrong
        // and the observer didn't catch it (unlikely for 'disabled' with attributeFilter, but for completeness)
        const shouldEnable = ['granted', 'prompt'].includes(window.sparxstarRecorderState?.micPermission);
        if (currentRecordButton.disabled && shouldEnable) {
            // console.warn('RECORDER (Interval Check - Safety Net on same instance): Forcing enable.');
            // currentRecordButton.disabled = false;
        } else if (!currentRecordButton.disabled && !shouldEnable) {
            // console.warn('RECORDER (Interval Check - Safety Net on same instance): Forcing disable.');
            // currentRecordButton.disabled = true;
        }
      }
    }, 2000); // Check every 2 seconds (Adjust as needed)

}).catch(err => {
  console.error('RECORDER ERROR: setupRecorder failed:', err);
});
}); // End of setupRecorder().then(...)
 console.log('RECORDER: All scripts parsed and initialized. [Starmus Audio Recorder]'); 


  // ADD THIS BLOCK:
  setTimeout(() => {
    const finalRecordButton = document.getElementById('recordButton') || document.getElementById('sparxstar_recordButton');
    if (finalRecordButton) {
      console.log('RECORDER DELAYED CHECK (2 seconds): Record button "disabled" attribute status:', finalRecordButton.hasAttribute('disabled'), 'Actual disabled property:', finalRecordButton.disabled);
      if (finalRecordButton.hasAttribute('disabled')) {
        console.warn('RECORDER DELAYED CHECK: The "disabled" attribute IS PRESENT on the record button after 2 seconds!');
      } else {
        console.log('RECORDER DELAYED CHECK: The "disabled" attribute IS NOT PRESENT on the record button after 2 seconds.');
      }
    } else {
      console.error('RECORDER DELAYED CHECK: Record button not found after 2 seconds!');
  }, 2000); // Check after 2 seconds
}); // End of DOMContentLoaded

