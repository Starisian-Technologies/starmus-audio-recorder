/**
 * @file starmus-ui.js
 * @version 6.1.0-PLAY-FIX
 * @description UI Layer. Robust Play Button + Hidden Transcript.
 */

'use strict';

// Track audio instance globally to prevent overlapping sounds
let currentAudio = null;

function formatTime(seconds) {
  if (!Number.isFinite(seconds)) return '00m 00s';
  var m = Math.floor(seconds / 60);
  var s = Math.floor(seconds % 60);
  return (m < 10 ? '0' + m : m) + 'm ' + (s < 10 ? '0' + s : s) + 's';
}

function safeBind(element, eventName, handler) {
  if (!element) return;
  if (element._starmusBound) return;
  element.addEventListener(eventName, function(e) {
    if (e.cancelable) e.preventDefault();
    e.stopPropagation();
    if (!element.disabled) handler(e);
  });
  element._starmusBound = true;
}

function render(state, elements) {
  if (!elements) return;

  var status = state.status;
  var step = state.step;
  var recorder = state.recorder || {};
  var calibration = state.calibration || {};
  var submission = state.submission || {};
  var tier = state.tier;

  // --- TIER C FALLBACK ---
  if (tier === 'C') {
      if (elements.recorderContainer) elements.recorderContainer.style.display = 'none';
      if (elements.setupContainer) elements.setupContainer.style.display = 'none';
      var fallback = document.querySelector('[data-starmus-fallback-container]');
      if (fallback) fallback.style.display = 'block';
      return;
  }

  // --- 1. METERS ---
  if (status === 'calibrating' || status === 'recording') {
      var vol = (status === 'calibrating') ? (calibration.volumePercent || 0) : (recorder.amplitude || 0);
      if (elements.volumeMeter) elements.volumeMeter.style.setProperty('--starmus-audio-level', vol + '%');
  } else {
      if (elements.volumeMeter) elements.volumeMeter.style.setProperty('--starmus-audio-level', '0%');
  }

  if (elements.timerElapsed) elements.timerElapsed.textContent = formatTime(recorder.duration || 0);
  if (elements.durationProgress) {
      var pct = Math.min(100, ((recorder.duration || 0) / 300) * 100); // 5 min scale
      elements.durationProgress.style.setProperty('--starmus-recording-progress', pct + '%');
  }

  // --- 2. TRANSCRIPT (HIDDEN FOR NOW) ---
  if (elements.transcript) {
      elements.transcript.style.display = 'none'; 
  }

  // --- 3. VISIBILITY ---
  if (elements.step1 && elements.step2) {
    var activeStates = ['recording', 'paused', 'processing', 'ready_to_submit', 'submitting', 'calibrating', 'ready', 'complete'];
    var showStep2 = step === 2 || activeStates.indexOf(status) !== -1;
    if (showStep2) {
        elements.step1.style.display = 'none';
        elements.step2.style.display = 'block';
    } else {
        elements.step1.style.display = 'block';
        elements.step2.style.display = 'none';
    }
  }

  var isCalibrated = calibration.complete === true;
  if (elements.setupContainer) {
      var showSetup = (!isCalibrated || status === 'calibrating');
      elements.setupContainer.style.display = showSetup ? 'block' : 'none';
      if (elements.setupMicBtn) {
          if (status === 'calibrating') {
              elements.setupMicBtn.innerHTML = calibration.message || 'Adjusting...';
              elements.setupMicBtn.disabled = true;
              elements.setupMicBtn.classList.add('is-busy');
          } else {
              elements.setupMicBtn.innerHTML = '<span class="dashicons dashicons-microphone"></span> Setup Microphone';
              elements.setupMicBtn.disabled = false;
              elements.setupMicBtn.classList.remove('is-busy');
          }
      }
  }

  if (elements.recorderContainer) elements.recorderContainer.style.display = isCalibrated ? 'block' : 'none';

  // --- 4. BUTTONS ---
  var isRec = status === 'recording';
  var isPaused = status === 'paused';
  var isDone = status === 'ready_to_submit';
  var isReady = (status === 'ready' || status === 'ready_to_record' || status === 'idle') && isCalibrated; 
  
  if (elements.recordBtn) elements.recordBtn.style.display = (isReady && !isRec && !isPaused && !isDone) ? 'inline-flex' : 'none';
  if (elements.pauseBtn) elements.pauseBtn.style.display = isRec ? 'inline-flex' : 'none';
  if (elements.resumeBtn) elements.resumeBtn.style.display = isPaused ? 'inline-flex' : 'none';
  if (elements.stopBtn) elements.stopBtn.style.display = (isRec || isPaused) ? 'inline-flex' : 'none';
  
  // Review Controls
  if (elements.reviewControls) {
      elements.reviewControls.style.display = isDone ? 'flex' : 'none';
  } else {
      // Individual fallback
      if (elements.playBtn) elements.playBtn.style.display = isDone ? 'inline-flex' : 'none';
      if (elements.resetBtn) elements.resetBtn.style.display = isDone ? 'inline-flex' : 'none';
  }

  if (elements.submitBtn) {
      if (status === 'submitting') {
          elements.submitBtn.textContent = 'Uploading... ' + Math.round((submission.progress||0)*100) + '%';
          elements.submitBtn.disabled = true;
      } else if (status === 'complete') {
          elements.submitBtn.textContent = 'Submitted!';
          elements.submitBtn.disabled = true;
          elements.submitBtn.classList.add('starmus-btn--success');
      } else {
          elements.submitBtn.textContent = 'Submit Recording';
          elements.submitBtn.disabled = status !== 'ready_to_submit';
      }
  }
}

function initInstance(store, incomingElements, forcedInstanceId) {
  var instId = forcedInstanceId || store.getState().instanceId;
  var root = document;
  if (instId) {
      var found = document.querySelector('form[data-starmus-instance="' + instId + '"]');
      if (found) root = found;
  }

  var BUS = window.CommandBus;
  
  var el = {
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
    submitBtn: root.querySelector('[data-starmus-action="submit"]')
  };

  safeBind(el.continueBtn, 'click', function() {
      var inputs = el.step1 ? el.step1.querySelectorAll('[required]') : [];
      var valid = true;
      for (var i = 0; i < inputs.length; i++) {
          if (!inputs[i].value.trim() && !inputs[i].checked) {
               valid = false;
               inputs[i].style.borderColor = 'red';
          } else {
               inputs[i].style.borderColor = '';
          }
      }
      if (valid) store.dispatch({ type: 'starmus/ui/step-continue' });
  });

  safeBind(el.setupMicBtn, 'click', function(){ BUS.dispatch('setup-mic', {}, { instanceId: instId }); });
  safeBind(el.recordBtn, 'click', function(){ BUS.dispatch('start-recording', {}, { instanceId: instId }); });
  safeBind(el.pauseBtn, 'click', function(){ BUS.dispatch('pause-mic', {}, { instanceId: instId }); });
  safeBind(el.resumeBtn, 'click', function(){ BUS.dispatch('resume-mic', {}, { instanceId: instId }); });
  safeBind(el.stopBtn, 'click', function(){ BUS.dispatch('stop-mic', {}, { instanceId: instId }); });
  
  // FIX: ROBUST PLAY BUTTON
  safeBind(el.playBtn, 'click', function() {
     // 1. If playing, Stop it
     if (currentAudio) {
         currentAudio.pause();
         currentAudio = null;
         el.playBtn.innerHTML = '<span class="dashicons dashicons-controls-play"></span> Play / Pause';
         return;
     }

     var state = store.getState();
     
     // 2. Play New
     if (state.source.blob) {
         try {
             var url = URL.createObjectURL(state.source.blob);
             currentAudio = new Audio(url);
             
             // Update UI when done
             currentAudio.onended = function() {
                 currentAudio = null;
                 el.playBtn.innerHTML = '<span class="dashicons dashicons-controls-play"></span> Play / Pause';
             };
             
             currentAudio.onerror = function() {
                 alert('Error playing audio on this device.');
                 currentAudio = null;
                 el.playBtn.innerHTML = '<span class="dashicons dashicons-controls-play"></span> Play / Pause';
             };

             var promise = currentAudio.play();
             if (promise !== undefined) {
                 promise.then(function() {
                     el.playBtn.innerHTML = '<span class="dashicons dashicons-controls-pause"></span> Stop';
                 }).catch(function(error) {
                     console.error('Play prevented:', error);
                     alert('Click again to play.');
                 });
             }
         } catch(e) {
             console.error(e);
         }
     }
  });
  
  safeBind(el.resetBtn, 'click', function() {
      if(confirm('Discard recording and start over?')) {
          if(currentAudio) { currentAudio.pause(); currentAudio = null; }
          BUS.dispatch('reset', {}, { instanceId: instId });
      }
  });

  safeBind(el.submitBtn, 'click', function(e) {
    if(currentAudio) { currentAudio.pause(); currentAudio = null; }
    var form = e.target.closest('form');
    var data = {};
    if (form) {
        var formData = new FormData(form);
        for (var pair of formData.entries()) data[pair[0]] = pair[1];
    }
    BUS.dispatch('submit', { formFields: data }, { instanceId: instId });
  });

  // Tier C File Listener
  var fileInput = root.querySelector('input[type="file"][name="audio_file"]');
  if (fileInput) {
      safeBind(fileInput, 'change', function(e) {
          if (e.target.files && e.target.files[0]) {
              store.dispatch({ type: 'starmus/file-attached', file: e.target.files[0] });
          }
      });
  }
  
  if (BUS) {
      BUS.subscribe('starmus/offline/queue_updated', function(payload) {
           console.log('[UI] Offline Queue:', payload);
      });
  }

  store.dispatch({ type: 'starmus/init', payload: { instanceId: instId } });
  return store.subscribe(function(nextState) { render(nextState, el); });
}

export { render, initInstance };
if (typeof window !== 'undefined') window.initUI = initInstance;