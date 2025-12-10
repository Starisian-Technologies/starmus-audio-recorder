/**
 * @file starmus-ui.js
 * @version 5.4.1-SAFE
 * @description UI Layer. Optimized for reliability.
 */

'use strict';

function formatTime(seconds) {
  if (!Number.isFinite(seconds)) return '00m 00s';
  var m = Math.floor(seconds / 60);
  var s = Math.floor(seconds % 60);
  return (m < 10 ? '0' + m : m) + 'm ' + (s < 10 ? '0' + s : s) + 's';
}

function forceBind(element, eventName, handler) {
  if (!element) return null;
  var newEl = element.cloneNode(true);
  newEl.style.pointerEvents = 'auto'; 
  if(element.parentNode) element.parentNode.replaceChild(newEl, element);
  newEl.addEventListener(eventName, function(e) {
    e.preventDefault();
    e.stopPropagation();
    handler(e);
  });
  return newEl;
}

export function render(state, elements) {
  if (!elements) return;

  var status = state.status;
  var step = state.step;
  var recorder = state.recorder || {};
  var calibration = state.calibration || {};
  var submission = state.submission || {};

  // --- METERS (High Frequency) ---
  if (status === 'calibrating' || status === 'recording') {
      var vol = (status === 'calibrating') ? (calibration.volumePercent || 0) : (recorder.amplitude || 0);
      if (elements.volumeMeter) {
          elements.volumeMeter.style.width = vol + '%';
          elements.volumeMeter.style.setProperty('--starmus-audio-level', vol + '%');
      }
  }

  var timeStr = formatTime(recorder.duration || 0);
  if (elements.timerElapsed) elements.timerElapsed.textContent = timeStr;
  if (elements.timer) elements.timer.textContent = timeStr;

  if (elements.durationProgress) {
      var pct = Math.min(100, ((recorder.duration || 0) / 1200) * 100);
      elements.durationProgress.style.width = pct + '%';
  }

  // --- VISIBILITY (Low Frequency) ---

  // Step 1 vs Step 2
  if (elements.step1 && elements.step2) {
    var showStep2 = step === 2 || (status !== 'idle' && status !== 'uninitialized' && status !== 'ready');
    elements.step1.style.display = showStep2 ? 'none' : 'block';
    elements.step2.style.display = showStep2 ? 'block' : 'none';
  }

  // Fallback
  if (state.tier === 'C' || state.fallbackActive) {
    if(elements.fallbackContainer) elements.fallbackContainer.style.display = 'block';
    if(elements.recorderContainer) elements.recorderContainer.style.display = 'none';
    return;
  }

  // Setup / Calibration Message
  var isCalibrated = calibration.complete === true;
  
  if (elements.setupContainer) {
      var showSetup = (!isCalibrated || status === 'calibrating');
      elements.setupContainer.style.display = showSetup ? 'block' : 'none';
      
      if (elements.setupMicBtn) {
          if (status === 'calibrating') {
              elements.setupMicBtn.textContent = calibration.message || 'Adjusting...';
              elements.setupMicBtn.disabled = true;
          } else {
              elements.setupMicBtn.textContent = 'Setup Microphone';
              elements.setupMicBtn.disabled = false;
          }
      }
  }

  if (elements.recorderContainer) {
      elements.recorderContainer.style.display = isCalibrated ? 'block' : 'none';
  }

  // Buttons
  var isRec = status === 'recording';
  var isPaused = status === 'paused';
  var isReady = status === 'ready' || status === 'ready_to_record' || status === 'idle'; 
  
  if (elements.recordBtn) elements.recordBtn.style.display = (isReady && isCalibrated && !isRec && !isPaused) ? 'inline-flex' : 'none';
  if (elements.pauseBtn) elements.pauseBtn.style.display = isRec ? 'inline-flex' : 'none';
  if (elements.resumeBtn) elements.resumeBtn.style.display = isPaused ? 'inline-flex' : 'none';
  if (elements.stopBtn) elements.stopBtn.style.display = (isRec || isPaused) ? 'inline-flex' : 'none';
  
  if (elements.submitBtn) {
      if (status === 'submitting') {
          elements.submitBtn.textContent = 'Uploading... ' + Math.round((submission.progress||0)*100) + '%';
          elements.submitBtn.disabled = true;
      } else {
          elements.submitBtn.textContent = 'Submit Recording';
          elements.submitBtn.disabled = status !== 'ready_to_submit';
      }
  }
}

export function initInstance(store, incomingElements, forcedInstanceId) {
  var instId = forcedInstanceId || store.getState().instanceId;
  var root = document;

  if (instId) {
      root = document.querySelector('[data-starmus-instance="' + instId + '"]') || document;
  }

  var BUS = window.CommandBus;
  
  // Map Elements
  var el = {
    step1: root.querySelector('[data-starmus-step="1"]'),
    step2: root.querySelector('[data-starmus-step="2"]'),
    setupContainer: root.querySelector('[data-starmus-setup-container]'),
    timer: root.querySelector('[data-starmus-timer]'),
    timerElapsed: root.querySelector('.starmus-timer-elapsed'),
    volumeMeter: root.querySelector('[data-starmus-volume-meter]'),
    durationProgress: root.querySelector('[data-starmus-duration-progress]'),
    fallbackContainer: root.querySelector('[data-starmus-fallback-container]'),
    recorderContainer: root.querySelector('[data-starmus-recorder-container]'),
    
    continueBtn: root.querySelector('[data-starmus-action="next"]'),
    setupMicBtn: root.querySelector('[data-starmus-action="setup-mic"]'),
    recordBtn: root.querySelector('[data-starmus-action="record"]'),
    pauseBtn: root.querySelector('[data-starmus-action="pause"]'),
    resumeBtn: root.querySelector('[data-starmus-action="resume"]'),
    stopBtn: root.querySelector('[data-starmus-action="stop"]'),
    submitBtn: root.querySelector('[data-starmus-action="submit"]')
  };

  // Bind Buttons
  if (el.continueBtn) {
      forceBind(el.continueBtn, 'click', function() {
          var inputs = el.step1 ? el.step1.querySelectorAll('[required]') : [];
          var valid = true;
          inputs.forEach(function(i) {
              if(!i.value.trim() && !i.checked) { valid=false; i.style.borderColor='red'; }
              else { i.style.borderColor=''; }
          });
          if (valid) store.dispatch({ type: 'starmus/ui/step-continue' });
          else alert('Please fill in all required fields.');
      });
  }

  if(el.setupMicBtn) forceBind(el.setupMicBtn, 'click', function(){ BUS.dispatch('setup-mic', {}, { instanceId: instId }); });
  if(el.recordBtn) forceBind(el.recordBtn, 'click', function(){ BUS.dispatch('start-recording', {}, { instanceId: instId }); });
  if(el.pauseBtn) forceBind(el.pauseBtn, 'click', function(){ BUS.dispatch('pause-mic', {}, { instanceId: instId }); });
  if(el.resumeBtn) forceBind(el.resumeBtn, 'click', function(){ BUS.dispatch('resume-mic', {}, { instanceId: instId }); });
  if(el.stopBtn) forceBind(el.stopBtn, 'click', function(){ BUS.dispatch('stop-mic', {}, { instanceId: instId }); });

  if (el.submitBtn) {
      forceBind(el.submitBtn, 'click', function(e) {
        var form = e.target.closest('form');
        var data = form ? Object.fromEntries(new FormData(form).entries()) : {};
        BUS.dispatch('submit', { formFields: data }, { instanceId: instId });
      });
  }

  store.dispatch({ type: 'starmus/init', payload: { instanceId: instId } });
  return store.subscribe(function(next) { render(next, el); });
}

if (typeof window !== 'undefined') window.initUI = initInstance;