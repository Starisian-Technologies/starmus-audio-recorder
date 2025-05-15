/**
 * starmus-audio-recorder.js
 * 
 * Handles the front-end user interface logic for recording audio using the microphone.
 * Utilizes the MediaRecorder API to capture audio in Web/Opus or MP4/AAC formats.
 * 
 * Features:
 * - Manages recording, pausing, resuming, and stopping audio capture.
 * - Updates UI elements: record, pause, play buttons, timer, and status messages.
 * - Handles microphone permissions and user consent.
 * - Processes the recorded audio into a Blob and attaches it to a form field.
 * - Provides visual feedback for recording duration and remaining time.
 * - Handles browser compatibility and error scenarios.
 * 
 * UI Elements Required:
 * - Record button (ID: 'recordButton')
 * - Pause button (ID: 'pauseButton')
 * - Play button (ID: 'playButton')
 * - Timer display (ID: 'timer')
 * - Audio player (ID: 'audioPlayer')
 * - Consent checkbox (ID: 'field_consent')
 * - File input for audio attachment (ID: 'field_audio_attachment')
 * - Audio level meter wrapper (ID: 'sparxstar_audioLevelWrap')
 * - Audio level meter bar (ID: 'sparxstar_audioLevelBar')
 * 
 * Constants:
 * - MAX_RECORDING_TIME: Maximum allowed recording time in milliseconds (20 minutes).
 * 
 * Functions:
 * - updateTimerColor(remainingTime): Updates timer color based on remaining time.
 * - updateTimerDisplay(): Updates timer display and handles auto-stop.
 * - startTimer(): Starts the recording timer.
 * - stopTimer(): Stops the recording timer.
 * - handleRecordingReady(): Updates UI state when recording is ready.
 * - handleDataAvailable(event): Collects audio data chunks.
 * - handleStop(): Processes and finalizes the recording.
 * - attachAudioToForm(audioBlob, fileExtension): Attaches audio file to form input.
 * - startRecording(): Initiates audio recording with permission and consent checks.
 * - stopRecording(): Stops or finalizes the recording.
 * - setupRecorder(): Initializes UI and permission checks on page load.
 * - animateBar(): Animates the audio level meter based on microphone input.
 * 
 * Event Listeners:
 * - Record button: Starts or stops recording.
 * - Pause button: Pauses or resumes recording.
 * - Play button: Plays back the recorded audio.
 * 
 * Error Handling:
 * - Alerts and logs errors for unsupported browsers, denied permissions, missing UI elements, and recording failures.
 */

document.addEventListener('DOMContentLoaded', function () {
  const recordButton = document.getElementById('sparxstar_recordButton') || document.getElementById('recordButton');
  const pauseButton = document.getElementById('sparxstar_pauseButton') || document.getElementById('pauseButton');
  const playButton = document.getElementById('sparxstar_playButton') || document.getElementById('playButton');
  const timerDisplay = document.getElementById('sparxstar_timer') || document.getElementById('timer');
  const audioPlayer = document.getElementById('sparxstar_audioPlayer') || document.getElementById('audioPlayer');
  const audioAttachmentFieldId = 'field_audio_attachment';
  const consentCheckboxId = 'field_consent';
  const MAX_RECORDING_TIME = 1200000;

  if (
    !recordButton ||
    !pauseButton ||
    !playButton ||
    !timerDisplay ||
    !audioPlayer
  ) {
    console.error(
      'One or more essential UI elements are missing from the DOM. Recorder cannot initialize.'
    );
    return;
  }

  let mediaRecorder;
  let audioChunks = [];
  let startTime;
  let timerInterval;
  let isRecording = false;
  let isPaused = false;
  let currentStream = null; // Track the current audio stream

  // Accessibility: ensure timer is announced by screen readers
  timerDisplay.setAttribute('aria-live', 'polite');

  // Audio level meter elements
  const levelWrap = document.getElementById('sparxstar_audioLevelWrap');
  const levelBar = document.getElementById('sparxstar_audioLevelBar');
  let audioContext, analyser, dataArray, sourceNode;

  function animateBar() {
    if (analyser && dataArray && levelBar) {
      analyser.getByteFrequencyData(dataArray);
      const volume = dataArray.reduce((a, b) => a + b, 0) / dataArray.length;
      const percent = Math.min((volume / 255) * 100, 100);
      levelBar.style.height = `${percent}%`;
    }
    requestAnimationFrame(animateBar);
  }

  animateBar();

  function updateTimerColor(remainingTime) {
    const twoMinutes = 120000;
    const sevenMinutes = 420000;

    if (remainingTime <= twoMinutes) {
      timerDisplay.style.color = 'red';
    } else if (remainingTime <= sevenMinutes) {
      timerDisplay.style.color = 'orange';
    } else {
      timerDisplay.style.color = '';
    }
  }

  function updateTimerDisplay() {
    const elapsedTime = Date.now() - startTime;
    const remainingTime = Math.max(0, MAX_RECORDING_TIME - elapsedTime);
    const minutes = Math.floor(remainingTime / 60000);
    const seconds = Math.floor((remainingTime % 60000) / 1000);
    const formattedTime = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;

    timerDisplay.textContent = formattedTime;
    updateTimerColor(remainingTime);

    if (remainingTime <= 0) {
      stopRecording();
    }
  }

  function startTimer() {
    startTime = Date.now();
    timerInterval = setInterval(updateTimerDisplay, 1000);
  }

  function stopTimer() {
    clearInterval(timerInterval);
  }

  function handleRecordingReady() {
    recordButton.disabled = false;
    pauseButton.disabled = true;
    playButton.disabled =
      !audioPlayer.src || audioPlayer.src === window.location.href;
  }

  function handleDataAvailable(event) {
    if (event.data.size > 0) {
      audioChunks.push(event.data);
    }
  }

  function handleStop() {
    if (!mediaRecorder || audioChunks.length === 0) {
      console.warn(
        'No audio data recorded or mediaRecorder not available to stop. Resetting UI.'
      );
      audioChunks = [];
      stopTimer();
      timerDisplay.textContent = '00:00';
      timerDisplay.style.color = '';
      isRecording = false;
      isPaused = false;
      recordButton.textContent = 'Record';
      handleRecordingReady();
      // Release the microphone if a stream exists
      if (currentStream) {
        currentStream.getTracks().forEach(track => track.stop());
        currentStream = null;
      }
      return;
    }

    let audioBlob;
    let fileType;
    const mimeType = mediaRecorder.mimeType;

    if (mimeType.includes('opus')) {
      audioBlob = new Blob(audioChunks, { type: mimeType });
      fileType = 'webm';
    } else if (mimeType.includes('aac')) {
      audioBlob = new Blob(audioChunks, { type: mimeType });
      fileType = 'm4a';
    } else if (mimeType.includes('webm')) {
      audioBlob = new Blob(audioChunks, { type: mimeType });
      fileType = 'webm';
    } else {
      console.error(
        'Could not determine the audio format from recorded MIME type:',
        mimeType
      );
      alert(
        'Recording failed: The recorded audio format is not supported for processing. Please try a different browser.'
      );
      return;
    }

    const audioUrl = URL.createObjectURL(audioBlob);
    audioPlayer.src = audioUrl;
    attachAudioToForm(audioBlob, fileType);
    audioChunks = [];
    stopTimer();
    timerDisplay.textContent = '00:00';
    timerDisplay.style.color = '';
    isRecording = false;
    isPaused = false;
    recordButton.textContent = 'Record';
    handleRecordingReady();
    // Release the microphone if a stream exists
    if (currentStream) {
      currentStream.getTracks().forEach(track => track.stop());
      currentStream = null;
    }
  }

  function attachAudioToForm(audioBlob, fileExtension) {
    const fileName = `oral_history_recording.${fileExtension}`;
    const file = new File([audioBlob], fileName, { type: audioBlob.type });
    const dataTransfer = new DataTransfer();
    dataTransfer.items.add(file);
    const fileInput = document.getElementById(audioAttachmentFieldId);
    if (fileInput) {
      fileInput.files = dataTransfer.files;
    } else {
      console.error(
        `Form field with ID '${audioAttachmentFieldId}' not found.`
      );
      alert(
        `Developer error: Form field with ID '${audioAttachmentFieldId}' not found. Please contact the site administrator.`
      );
    }
  }

  async function startRecording() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      alert(
        'Audio recording is not supported in your browser. Please use a modern browser that supports audio recording.'
      );
      console.error('navigator.mediaDevices.getUserMedia is not available.');
      return;
    }

    if (!window.MediaRecorder) {
      alert(
        'MediaRecorder is not supported in your browser. Please use a modern browser that supports MediaRecorder.'
      );
      console.error('window.MediaRecorder is not available.');
      return;
    }

    const consentCheckbox = document.getElementById(consentCheckboxId);
    if (!consentCheckbox) {
      alert('Consent checkbox is missing. Please contact the administrator.');
      return;
    }
    if (!consentCheckbox.checked) {
      alert('Please provide your consent before recording.');
      return;
    }

    const supportedMimeTypes = [
      'audio/webm;codecs=opus',
      'audio/mp4;codecs=aac',
      'audio/webm',
    ];

    let selectedMimeType = null;
    for (const type of supportedMimeTypes) {
      if (MediaRecorder.isTypeSupported(type)) {
        selectedMimeType = type;
        break;
      }
    }

    if (!selectedMimeType) {
      alert(
        'Your browser does not support any suitable audio recording format (e.g., WebM Opus, MP4 AAC). Please try a different browser.'
      );
      console.error('No supported MIME type found for MediaRecorder.');
      return;
    }

    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      currentStream = stream; // Save reference to release later
      audioChunks = [];
      mediaRecorder = new MediaRecorder(stream, { mimeType: selectedMimeType });
      mediaRecorder.ondataavailable = handleDataAvailable;
      mediaRecorder.onstop = handleStop;
      mediaRecorder.start();
      isRecording = true;
      isPaused = false;
      recordButton.textContent = 'Stop';
      pauseButton.disabled = false;
      playButton.disabled = true;
      audioPlayer.src = '';
      startTimer();
      updateTimerDisplay();
      // Audio level meter setup
      if (levelBar && window.AudioContext) {
        if (audioContext) {
          audioContext.close();
        }
        audioContext = new (window.AudioContext || window.webkitAudioContext)();
        analyser = audioContext.createAnalyser();
        analyser.fftSize = 256;
        dataArray = new Uint8Array(analyser.frequencyBinCount);
        sourceNode = audioContext.createMediaStreamSource(stream);
        sourceNode.connect(analyser);
      }
    } catch (error) {
      let userMessage =
        'Failed to access the microphone or start recording. Please ensure it is enabled and not in use by another application.';
      if (
        error.name === 'NotAllowedError' ||
        error.name === 'PermissionDeniedError'
      ) {
        userMessage =
          'Microphone access was denied. Please allow microphone access in your browser settings to record audio.';
      } else if (
        error.name === 'NotFoundError' ||
        error.name === 'DevicesNotFoundError'
      ) {
        userMessage =
          'No microphone found. Please ensure a microphone is connected and enabled.';
      } else if (
        error.name === 'NotReadableError' ||
        error.name === 'TrackStartError'
      ) {
        userMessage =
          'The microphone is currently in use or could not be accessed. Please ensure it is not being used by another application and is working correctly.';
      }
      alert(userMessage);
      console.error('getUserMedia error:', error);
    }
  }

  function stopRecording() {
    if (isRecording && mediaRecorder && mediaRecorder.state !== 'inactive') {
      mediaRecorder.stop();
    } else if (isRecording) {
      console.warn(
        'stopRecording called while isRecording is true, but mediaRecorder is not active. Attempting to finalize.'
      );
      handleStop();
    }
  }

  recordButton.addEventListener('click', () => {
    if (!isRecording) {
      startRecording();
    } else {
      stopRecording();
    }
  });

  pauseButton.addEventListener('click', () => {
    if (isRecording && !isPaused) {
      mediaRecorder.pause();
      isPaused = true;
      stopTimer();
      recordButton.textContent = 'Resume';
    } else if (isRecording && isPaused) {
      mediaRecorder.resume();
      isPaused = false;
      startTimer();
      recordButton.textContent = 'Stop';
    }
  });

  playButton.addEventListener('click', () => {
    audioPlayer.play();
  });

  async function setupRecorder() {
    pauseButton.disabled = true;
    playButton.disabled = true;
    recordButton.disabled = true;

    if (navigator.permissions && navigator.permissions.query) {
      try {
        const permissionStatus = await navigator.permissions.query({
          name: 'microphone',
        });

        const updateButtonBasedOnPermission = () => {
          if (permissionStatus.state === 'granted') {
            recordButton.disabled = false;
            console.log('Microphone permission granted.');
          } else if (permissionStatus.state === 'prompt') {
            recordButton.disabled = false;
            console.log('Microphone permission will be prompted.');
          } else {
            recordButton.disabled = true;
            alert(
              'Microphone access is denied. Please enable it in your browser settings to record audio.'
            );
            console.warn('Microphone access denied.');
          }
        };

        updateButtonBasedOnPermission();
        permissionStatus.onchange = updateButtonBasedOnPermission;
      } catch (error) {
        console.error('Error querying microphone permissions:', error);
        recordButton.disabled = false;
      }
    } else {
      console.warn(
        'Permissions API is not supported in this browser. Microphone access will be requested on first record attempt.'
      );
      recordButton.disabled = false;
    }
  }

  setupRecorder();
});
