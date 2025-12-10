/**
 * @file starmus-ui.js
 * @version 6.0.0-TIER-UI
 */

'use strict';

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
  var tier = state.tier;

  // --- TIER C HANDLING (Legacy/Fallback) ---
  if (tier === 'C') {
      if (elements.recorderContainer) elements.recorderContainer.style.display = 'none';
      if (elements.setupContainer) elements.setupContainer.style.display = 'none';
      
      // Look for fallback container (ensure it exists in HTML)
      var fallback = document.querySelector('[data-starmus-fallback-container]');
      if (fallback) {
          fallback.style.display = 'block';
          // Ensure file input listener is bound (done in init)
      }
      // If we are Tier C, we skip step 2 logic and just show the fallback
      return;
  }

  // ... (Paste logic from previous Step 1/2/3/4 here - Meters, Visibility, Transcript, Buttons) ...
  // ... To save space, I assume you keep the logic from the 5.9.1 version I just gave you ...
  // ... INSERT 5.9.1 RENDER LOGIC HERE ...
  
  // Quick Recap of 5.9.1 logic for context:
  var step = state.step;
  var recorder = state.recorder || {};
  var calibration = state.calibration || {};
  var submission = state.submission || {};
  
  // 1. Meters
  if (status === 'calibrating' || status === 'recording') {
      var vol = (status === 'calibrating') ? (calibration.volumePercent || 0) : (recorder.amplitude || 0);
      if (elements.volumeMeter) elements.volumeMeter.style.setProperty('--starmus-audio-level', vol + '%');
  } else {
      if (elements.volumeMeter) elements.volumeMeter.style.setProperty('--starmus-audio-level', '0%');
  }
  
  if (elements.timerElapsed) elements.timerElapsed.textContent = formatTime(recorder.duration || 0);
  if (elements.durationProgress) {
      var pct = Math.min(100, ((recorder.duration || 0) / 300) * 100);
      elements.durationProgress.style.setProperty('--starmus-recording-progress', pct + '%');
  }

  // 2. Transcript
  // ... (Keep 5.9.1 transcript logic) ...

  // 3. Visibility
  // ... (Keep 5.9.1 visibility logic) ...
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
  
  // 4. Calibration
  var isCalibrated = calibration.complete === true;
  if (elements.setupContainer) {
      var showSetup = (!isCalibrated || status === 'calibrating');
      elements.setupContainer.style.display = showSetup ? 'block' : 'none';
      if (elements.setupMicBtn) {
           if (status === 'calibrating') {
               elements.setupMicBtn.innerHTML = calibration.message || 'Adjusting...';
               elements.setupMicBtn.disabled = true;
           } else {
               elements.setupMicBtn.innerHTML = '<span class="dashicons dashicons-microphone"></span> Setup Mic';
               elements.setupMicBtn.disabled = false;
           }
      }
  }
  if (elements.recorderContainer) elements.recorderContainer.style.display = isCalibrated ? 'block' : 'none';

  // 5. Buttons
  // ... (Keep 5.9.1 button logic) ...
  var isRec = status === 'recording';
  var isPaused = status === 'paused';
  var isDone = status === 'ready_to_submit';
  var isReady = (status === 'ready' || status === 'ready_to_record' || status === 'idle') && isCalibrated; 
  
  if (elements.recordBtn) elements.recordBtn.style.display = (isReady && !isRec && !isPaused && !isDone) ? 'inline-flex' : 'none';
  if (elements.pauseBtn) elements.pauseBtn.style.display = isRec ? 'inline-flex' : 'none';
  if (elements.resumeBtn) elements.resumeBtn.style.display = isPaused ? 'inline-flex' : 'none';
  if (elements.stopBtn) elements.stopBtn.style.display = (isRec || isPaused) ? 'inline-flex' : 'none';
  if (elements.reviewControls) elements.reviewControls.style.display = isDone ? 'flex' : 'none';

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
  
  // ... (Keep existing Element map) ...
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

  // ... (Keep existing SafeBinds) ...
  safeBind(el.continueBtn, 'click', function() {
      // Basic validation
      store.dispatch({ type: 'starmus/ui/step-continue' });
  });
  safeBind(el.setupMicBtn, 'click', function(){ BUS.dispatch('setup-mic', {}, { instanceId: instId }); });
  safeBind(el.recordBtn, 'click', function(){ BUS.dispatch('start-recording', {}, { instanceId: instId }); });
  safeBind(el.pauseBtn, 'click', function(){ BUS.dispatch('pause-mic', {}, { instanceId: instId }); });
  safeBind(el.resumeBtn, 'click', function(){ BUS.dispatch('resume-mic', {}, { instanceId: instId }); });
  safeBind(el.stopBtn, 'click', function(){ BUS.dispatch('stop-mic', {}, { instanceId: instId }); });
  safeBind(el.playBtn, 'click', function() { /* ... */ });
  safeBind(el.resetBtn, 'click', function() { 
      if(confirm('Discard?')) BUS.dispatch('reset', {}, { instanceId: instId }); 
  });
  safeBind(el.submitBtn, 'click', function(e) {
    var form = e.target.closest('form');
    var data = {};
    if (form) {
        var formData = new FormData(form);
        for (var pair of formData.entries()) data[pair[0]] = pair[1];
    }
    BUS.dispatch('submit', { formFields: data }, { instanceId: instId });
  });

  // TIER C: FILE INPUT LISTENER
  var fileInput = root.querySelector('input[type="file"][name="audio_file"]');
  if (fileInput) {
      safeBind(fileInput, 'change', function(e) {
          if (e.target.files && e.target.files[0]) {
              // Dispatch file attached event to store
              store.dispatch({ 
                  type: 'starmus/file-attached', 
                  file: e.target.files[0] 
              });
          }
      });
  }
  
  // FIX: OFFLINE QUEUE LISTENER (Fixes log warning)
  if (BUS) {
      BUS.subscribe('starmus/offline/queue_updated', (payload) => {
           console.log('[UI] 📶 Offline Queue Updated:', payload);
           // Update a badge in the UI if you have one, e.g.:
           // var badge = root.querySelector('.starmus-queue-badge');
           // if(badge) badge.textContent = payload.count || 0;
      });
  }

  store.dispatch({ type: 'starmus/init', payload: { instanceId: instId } });
  return store.subscribe(function(nextState) { render(nextState, el); });
}

export { render, initInstance };
if (typeof window !== 'undefined') window.initUI = initInstance;