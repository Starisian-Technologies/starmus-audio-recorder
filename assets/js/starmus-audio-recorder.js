/**
 * starmus-audio-recorder.js
 *
 * Handles the front-end user interface logic for recording audio using the microphone.
 * Utilizes the MediaRecorder API to capture audio in Web/Opus or MP4/AAC formats.
 *
 * FORM FIELD INTEGRATION NOTICE
 * -----------------------------
 * This script expects the presence of two optional fields in the form:
 * 
 * 1. A file input field to attach the recorded audio:
 *    <input type="file" accept="audio/*" name="...">
 * 
 * 2. A hidden text input to store the generated UUID-based filename:
 *    <input type="hidden" name="audio_uuid">
 * 
 * These fields are NOT part of the recorder UI HTML on purpose.
 * They MUST be added via the form plugin (e.g. Formidable Forms, WPForms, etc.)
 * by the site admin or template system.
 * 
 * If these fields are missing, the script logs a warning and continues without breaking.
 * This keeps the recorder modular and compatible with dynamic form systems.
 */
document.addEventListener('DOMContentLoaded', function () {
  const container = document.querySelector('[data-enabled-recorder]');
  if (!container) return; // Skip if recorder isn't enabled on this page


  const recordButton = document.getElementById('sparxstar_recordButton') || document.getElementById('recordButton');
  const pauseButton = document.getElementById('sparxstar_pauseButton') || document.getElementById('pauseButton');
  const playButton = document.getElementById('sparxstar_playButton') || document.getElementById('playButton');
  const timerDisplay = document.getElementById('sparxstar_timer') || document.getElementById('timer');
  const audioPlayer = document.getElementById('sparxstar_audioPlayer') || document.getElementById('audioPlayer');
  const fileInput = container.querySelector('input[type="file"][accept*="audio"]');
  const uuidField = container.querySelector('input[name="audio_uuid"]');
  const levelBar = container.querySelector('#sparxstar_audioLevelBar');

  const MAX_RECORDING_TIME = 1200000; // 20 minutes in milliseconds

  if (!recordButton || !pauseButton || !playButton || !timerDisplay || !audioPlayer) {
    console.error('One or more essential UI elements are missing. Recorder cannot initialize.');
    return;
  }

  let mediaRecorder;
  let audioChunks = [];
  let currentStream = null;

  let isRecording = false;
  let isPaused = false;

  let timerInterval;
  let segmentStartTime; // Start time of the current recording segment
  let accumulatedElapsedTime = 0; // Total time recorded across pauses

  // Accessibility
  timerDisplay.setAttribute('aria-live', 'polite');

  // Audio level meter
  let audioContext, analyser, dataArray, sourceNode, animationFrameId;

  function animateBar() {
    if (analyser && dataArray && levelBar && isRecording && !isPaused) {
      analyser.getByteFrequencyData(dataArray);
      const volume = dataArray.reduce((a, b) => a + b, 0) / dataArray.length;
      const percent = Math.min((volume / 255) * 100, 100);
      levelBar.style.width = `${percent}%`; 
      levelBar.setAttribute('aria-valuenow', Math.round(percent));
      animationFrameId = requestAnimationFrame(animateBar);
    } else {
      stopAnimationBarLoop(); // Stop if not actively recording
    }
  }

  function stopAnimationBarLoop() {
    if (animationFrameId) {
      cancelAnimationFrame(animationFrameId);
      animationFrameId = null;
    }
    if (levelBar) levelBar.style.width = '0%'; // Reset bar
  }

  // --- TIMER FUNCTIONS ---
  function formatTime(ms) {
    const totalSeconds = Math.floor(ms / 1000);
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
  }

  function updateTimerColor(remainingTimeMs) {
    const twoMinutes = 120000;
    const sevenMinutes = 420000;

    // Always remove all state classes first to ensure a clean slate
    timerDisplay.classList.remove('red', 'orange');
    // Also clear any inline style that might have been set by older code or directly
    timerDisplay.style.color = ''; // Clear inline style just in case

    if (remainingTimeMs <= twoMinutes) {
      timerDisplay.classList.add('red');
    } else if (remainingTimeMs <= sevenMinutes) {
      timerDisplay.classList.add('orange');
    } else {
      // Default state: No specific color class needed if your base .sparxstar_timer CSS
      // already defines the default color (e.g., color: #333;).
      // If you want a specific class for the default state (e.g., 'normal-time'), you can add it here.
      // For now, assuming the base style for .sparxstar_timer handles the default.
    }
  }

  function updateTimerDisplay() {
    let currentSegmentElapsedTime = 0;
    if (isRecording && !isPaused) {
      currentSegmentElapsedTime = Date.now() - segmentStartTime;
    }
    const totalRecordedTime = accumulatedElapsedTime + currentSegmentElapsedTime;
    const remainingTime = Math.max(0, MAX_RECORDING_TIME - totalRecordedTime);

    timerDisplay.textContent = formatTime(remainingTime);
    updateTimerColor(remainingTime);

    if (remainingTime <= 0) {
      stopRecording(); // Auto-stop
    }
  }

  function startTimerForNewRecording() {
    accumulatedElapsedTime = 0;
    segmentStartTime = Date.now();
    if (timerInterval) clearInterval(timerInterval);
    timerInterval = setInterval(updateTimerDisplay, 1000);
    updateTimerDisplay(); // Update display immediately
  }

  function resumeTimer() {
    segmentStartTime = Date.now(); // Reset start time for the new segment
    if (timerInterval) clearInterval(timerInterval);
    timerInterval = setInterval(updateTimerDisplay, 1000);
    updateTimerDisplay(); // Update display immediately
  }

  function pauseTimer() {
    clearInterval(timerInterval);
    timerInterval = null;
    if (isRecording) { // Should always be true if we are pausing
      accumulatedElapsedTime += (Date.now() - segmentStartTime);
    }
    updateTimerDisplay(); // Update display to show frozen time
  }

  function stopTimerAndResetDisplay() {
    clearInterval(timerInterval);
    timerInterval = null;
    accumulatedElapsedTime = 0;
    timerDisplay.textContent = formatTime(MAX_RECORDING_TIME);
    updateTimerColor(MAX_RECORDING_TIME);
  }

  function updateStatus(msg) {
    const status = document.getElementById('sparxstar_status');
    if (status) status.textContent = msg;
  }

  // --- UI AND STATE MANAGEMENT ---
  function handleRecordingReady() { // Call when ready for new recording or after stop
    recordButton.disabled = false;
    pauseButton.disabled = true;
    pauseButton.textContent = 'Pause';
    playButton.disabled = !audioPlayer.src || audioPlayer.src === window.location.href;
  }

  // --- MEDIA RECORDER EVENT HANDLERS ---
  function handleDataAvailable(event) {
    if (event.data.size > 0) audioChunks.push(event.data);
  }

  function handleStop() {
    stopAnimationBarLoop();
    clearInterval(timerInterval); // Ensure interval is cleared

    if (isRecording && !isPaused) { // If it was actively recording when stopped
        accumulatedElapsedTime += (Date.now() - segmentStartTime);
    }
    // Now accumulatedElapsedTime holds the total duration. Can be logged.
    // console.log(`Total recorded duration: ${formatTime(accumulatedElapsedTime)}`);

    if (!mediaRecorder || audioChunks.length === 0) {
      console.warn('No audio data recorded or mediaRecorder not available. Resetting UI.');
      audioChunks = []; // Clear chunks
      stopTimerAndResetDisplay(); // Reset timer for next session
      isRecording = false;
      isPaused = false;
      recordButton.textContent = 'Record';
      recordButton.setAttribute('aria-pressed', 'false');
      updateStatus('Recording stopped, no audio captured.'); 
      handleRecordingReady();
      if (currentStream) {
        currentStream.getTracks().forEach(track => track.stop());
        currentStream = null;
      }
      return;
    }

    const mimeType = mediaRecorder.mimeType;
    let fileType;
    if (mimeType.includes('opus') || mimeType.includes('webm')) fileType = 'webm';
    else if (mimeType.includes('aac')) fileType = 'm4a';
    else {
      console.error('Unsupported recorded MIME type:', mimeType);
      alert('Recording failed: unsupported audio format. Try a different browser.');
      // Reset full UI as if no recording happened
      audioChunks = [];
      stopTimerAndResetDisplay();
      isRecording = false;
      isPaused = false;
      recordButton.textContent = 'Record';
      handleRecordingReady();
      if (currentStream) {
        currentStream.getTracks().forEach(track => track.stop());
        currentStream = null;
      }
      return;
    }

    const audioBlob = new Blob(audioChunks, { type: mimeType });
    const audioUrl = URL.createObjectURL(audioBlob);
    audioPlayer.src = audioUrl;
    attachAudioToForm(audioBlob, fileType);

    audioChunks = [];
    stopTimerAndResetDisplay(); // Reset timer for next session
    isRecording = false;
    isPaused = false;
    recordButton.textContent = 'Record';
    handleRecordingReady(); // Updates playButton
    if (currentStream) {
      currentStream.getTracks().forEach(track => track.stop());
      currentStream = null;
    }
  }

  /**
   * Generates a Version 4 UUID (Universally Unique Identifier).
   */
  function generateUUID() {
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
      console.error("Crypto API failed during UUID generation, falling back.", error);
    }
    console.warn("WARNING: Generating UUID using Math.random(). Not cryptographically secure.");
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
    const uuid = generateUUID();
    const fileName = `audio_${uuid}.${fileType}`;
    if (uuidField) uuidField.value = fileName;

    const file = new File([audioBlob], fileName, { type: audioBlob.type });
    if (!fileInput) {
      console.warn('No audio file input found in form. Skipping attachment.');
      return;
    }
    const dataTransfer = new DataTransfer();
    dataTransfer.items.add(file);
    fileInput.files = dataTransfer.files;
    console.log('Audio attached to:', fileInput.name || fileInput.id);
    updateStatus('Recording saved.');
  }

  // --- RECORDING ACTIONS ---
  async function startRecording() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
      alert('Audio recording is not fully supported in your browser.');
      console.error('getUserMedia or MediaRecorder not available.');
      return;
    }
    const supportedMimeTypes = ['audio/webm;codecs=opus', 'audio/mp4;codecs=aac', 'audio/webm'];
    let selectedMimeType = supportedMimeTypes.find(type => MediaRecorder.isTypeSupported(type));
    if (!selectedMimeType) {
      alert('No suitable audio recording format supported. Please try a different browser.');
      console.error('No supported MIME type found for MediaRecorder.');
      return;
    }

    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      currentStream = stream;
      audioChunks = [];
      mediaRecorder = new MediaRecorder(stream, { mimeType: selectedMimeType });
      mediaRecorder.ondataavailable = handleDataAvailable;
      mediaRecorder.onstop = handleStop;
      mediaRecorder.start();

      isRecording = true;
      isPaused = false;
      recordButton.textContent = 'Stop';
      recordButton.setAttribute('aria-pressed', isRecording);
      pauseButton.disabled = false;
      pauseButton.textContent = 'Pause';
      playButton.disabled = true;
      updateStatus('Recording started…');
      audioPlayer.src = ''; // Clear previous playback

      startTimerForNewRecording();
      if (levelBar && (window.AudioContext || window.webkitAudioContext)) {
        if (audioContext) audioContext.close();
        audioContext = new (window.AudioContext || window.webkitAudioContext)();
        analyser = audioContext.createAnalyser();
        analyser.fftSize = 256;
        dataArray = new Uint8Array(analyser.frequencyBinCount);
        sourceNode = audioContext.createMediaStreamSource(stream);
        sourceNode.connect(analyser);
        animateBar();
      }
    } catch (error) {
      let userMessage = 'Failed to access microphone or start recording.';
      if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') userMessage = 'Microphone access denied. Please allow in browser settings.';
      else if (error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError') userMessage = 'No microphone found. Please connect one.';
      else if (error.name === 'NotReadableError' || error.name === 'TrackStartError') userMessage = 'Microphone in use or unreadable. Check other apps.';
      alert(userMessage);
      console.error('getUserMedia error:', error);
      // Reset UI if start fails critically
      isRecording = false;
      isPaused = false;
      recordButton.textContent = 'Record';
      handleRecordingReady();
      stopTimerAndResetDisplay();
    }
  }

  function stopRecording() {
    if (isRecording && mediaRecorder && mediaRecorder.state !== 'inactive') {
      mediaRecorder.stop(); // Triggers 'onstop' -> handleStop()
    } else if (isRecording) { // If recording state is true but recorder is somehow inactive
      console.warn('stopRecording called, but mediaRecorder not active. Finalizing.');
      handleStop(); // Manually finalize to reset UI and state
    }
  }

  // --- EVENT LISTENERS ---
  recordButton.addEventListener('click', () => {
    if (!isRecording) startRecording();
    else stopRecording();
  });

  pauseButton.addEventListener('click', () => {
    if (!isRecording) return;
    if (!isPaused) { // Action: PAUSE
      if (mediaRecorder && mediaRecorder.state === 'recording') mediaRecorder.pause();
      pauseTimer(); // Updates accumulated time and stops interval
      isPaused = true;
      stopAnimationBarLoop(); // Stop level meter animation
      pauseButton.textContent = 'Resume';
      pauseButton.setAttribute('aria-pressed', isPaused);
      updateStatus('Recording paused');
    } else { // Action: RESUME
      if (mediaRecorder && mediaRecorder.state === 'paused') mediaRecorder.resume();
      isPaused = false;
      resumeTimer(); // Sets new segmentStartTime and starts interval
      if (analyser) animateBar(); // Restart level meter if analyser exists
      pauseButton.textContent = 'Pause';
      pauseButton.setAttribute('aria-pressed', isPaused);
      updateStatus('Recording started…');
    }
  });

  playButton.addEventListener('click', () => {
    if (audioPlayer.src && audioPlayer.src !== window.location.href) {
        audioPlayer.play();
    }
  });

  async function setupRecorder() {
    recordButton.disabled = true; // Initially disable till permission check
    pauseButton.disabled = true;
    playButton.disabled = true;

    if (navigator.permissions && navigator.permissions.query) {
      try {
        const permissionStatus = await navigator.permissions.query({ name: 'microphone' });
        const updateButtonOnPermission = () => {
          if (permissionStatus.state === 'granted' || permissionStatus.state === 'prompt') {
            recordButton.disabled = false;
            console.log(`Microphone permission: ${permissionStatus.state}.`);
          } else {
            recordButton.disabled = true;
            alert('Microphone access denied. Enable in browser settings to record.');
            console.warn('Microphone access denied.');
          }
        };
        updateButtonOnPermission();
        permissionStatus.onchange = updateButtonOnPermission;
      } catch (error) {
        console.error('Error querying microphone permissions:', error);
        recordButton.disabled = false; // Fallback to allow attempt
      }
    } else {
      console.warn('Permissions API not supported. Mic access requested on record attempt.');
      recordButton.disabled = false; // Allow attempt
    }
    // Initial timer display
    timerDisplay.textContent = formatTime(MAX_RECORDING_TIME);
    updateTimerColor(MAX_RECORDING_TIME);
  }

  setupRecorder();
});