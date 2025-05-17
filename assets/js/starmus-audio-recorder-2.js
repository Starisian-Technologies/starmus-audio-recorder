// Add this at the very top of your starmus-audio-recorder.js file
console.log('RECORDER SCRIPT FILE: PARSING STARTED - TOP OF FILE');

document.addEventListener('DOMContentLoaded', function () {
  console.log('RECORDER: DOMContentLoaded event fired. Script starting.');

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
    if(recordButton) recordButton.disabled = false;
    if(pauseButton) {
        pauseButton.disabled = true;
        pauseButton.textContent = 'Pause';
    }
    if(playButton && audioPlayer) {
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
    console.log('RECORDER: setupRecorder() CALLED.');
    if(recordButton) recordButton.disabled = true;
    if(pauseButton) pauseButton.disabled = true;
    if(playButton) playButton.disabled = true;

    if (navigator.permissions && navigator.permissions.query) {
      try {
        console.log('RECORDER: Querying microphone permissions via Permissions API.');
        const permissionStatus = await navigator.permissions.query({ name: 'microphone' });
        console.log('RECORDER: Microphone permission state from API:', permissionStatus.state);
        const updateButtonOnPermission = () => {
          console.log('RECORDER: updateButtonOnPermission called. Current state:', permissionStatus.state);
          if (!recordButton) {
              console.error("RECORDER ERROR: Record button not found in updateButtonOnPermission.");
              return;
          }
          if (permissionStatus.state === 'granted' || permissionStatus.state === 'prompt') {
            recordButton.disabled = false;
            console.log(`RECORDER: Mic permission: ${permissionStatus.state}. Record button ENABLED.`);
          } else {
            recordButton.disabled = true;
            // alert('Microphone access denied. Enable in browser settings to record.'); // Keep commented for now
            console.warn('RECORDER WARNING: Mic permission: DENIED. Record button DISABLED.');
          }
        };
        updateButtonOnPermission(); // Call it once to set initial state
        permissionStatus.onchange = () => { // Re-query or use event data if available
            console.log('RECORDER: Microphone permission status CHANGED to:', permissionStatus.state);
            updateButtonOnPermission();
        };
      } catch (error) {
        console.error('RECORDER ERROR: Error querying microphone permissions:', error);
        if(recordButton) recordButton.disabled = false; // Fallback
        console.log('RECORDER: Fallback - Record button ENABLED after permission query error.');
      }
    } else {
      console.warn('RECORDER WARNING: Permissions API not supported. Mic access will be requested on record attempt. Enabling record button.');
      if(recordButton) recordButton.disabled = false; // Allow attempt
    }

    if (timerDisplay) {
        timerDisplay.textContent = formatTime(MAX_RECORDING_TIME);
        updateTimerColor(MAX_RECORDING_TIME);
    } else {
        console.warn("RECORDER WARNING: timerDisplay not found in setupRecorder.");
    }
    handleRecordingReady(); // Ensure UI is in a consistent state initially
    console.log('RECORDER: setupRecorder() finished.');
  }

  console.log('RECORDER: Calling setupRecorder().');
  setupRecorder();
  console.log('RECORDER: Script initialization finished.');

 console.log('RECORDER: Script initialization finished.'); // This log should already be there

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
    }
  }, 2000); // Check after 2 seconds
}); // End of DOMContentLoaded

// Add this at the very bottom of your starmus-audio-recorder.js file
console.log('RECORDER SCRIPT FILE: PARSING FINISHED - BOTTOM OF FILE');
