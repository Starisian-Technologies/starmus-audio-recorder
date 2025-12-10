/**
 * @file starmus-ui.js
 * @version 5.4.0-VISUALS
 * @description UI Layer. Optimized for high-frequency visual updates (Meters/Timers).
 */

'use strict';

function formatTime(seconds) {
  if (!Number.isFinite(seconds)) return '00m 00s';
  const m = Math.floor(seconds / 60);
  const s = Math.floor(seconds % 60);
  return `${m.toString().padStart(2,'0')}m ${s.toString().padStart(2,'0')}s`;
}

function forceBind(element, eventName, handler) {
  if (!element) return null;
  const newEl = element.cloneNode(true);
  newEl.style.pointerEvents = 'auto'; 
  if(element.parentNode) element.parentNode.replaceChild(newEl, element);
  newEl.addEventListener(eventName, (e) => {
    e.preventDefault();
    e.stopPropagation();
    console.log('[StarmusUI] Click detected on', newEl.dataset.starmusAction || newEl.id);
    handler(e);
  });
  return newEl;
}

export function render(state, elements) {
  if (!elements) return;

  const { status, step, recorder = {}, calibration = {}, submission = {} } = state;

  // --- 1. ALWAYS UPDATE METERS (Critical Fix) ---
  
  // A. Volume Meter (Calibration OR Recording)
  // Check if we are in a state where audio is live
  if (status === 'calibrating' || status === 'recording') {
      let vol = 0;
      if (status === 'calibrating') {
          vol = calibration.volumePercent || 0;
      } else {
          vol = recorder.amplitude || 0;
      }
      
      // Ensure element exists before setting style
      if (elements.volumeMeter) {
          elements.volumeMeter.style.width = `${vol}%`;
          // Also set CSS variable for theme compatibility
          elements.volumeMeter.style.setProperty('--starmus-audio-level', `${vol}%`);
      }
  }

  // B. Timer
  const timeStr = formatTime(recorder.duration || 0);
  if (elements.timerElapsed) elements.timerElapsed.textContent = timeStr;
  if (elements.timer) elements.timer.textContent = timeStr;

  // C. Duration Bar
  if (elements.durationProgress) {
      const pct = Math.min(100, ((recorder.duration || 0) / 1200) * 100);
      elements.durationProgress.style.width = `${pct}%`;
  }

  // --- 2. STATE-BASED UPDATES (Visibility & Text) ---

  // Calibration Text
  if (elements.setupMicBtn) {
      if (status === 'calibrating') {
          elements.setupMicBtn.textContent = calibration.message || 'Adjusting...';
          elements.setupMicBtn.disabled = true;
      } else if (status === 'ready') {
          elements.setupMicBtn.innerHTML = '<span class="dashicons dashicons-microphone"></span> Mic Ready';
          elements.setupMicBtn.disabled = false;
      } else if (status === 'ready_to_record' && !calibration.complete) {
           elements.setupMicBtn.innerHTML = '<span class="dashicons dashicons-microphone"></span> Setup Microphone';
           elements.setupMicBtn.disabled = false;
      }
  }

  // Step Visibility
  if (elements.step1 && elements.step2) {
    const showStep2 = step === 2 || (status !== 'idle' && status !== 'uninitialized' && status !== 'ready');
    elements.step1.style.display = showStep2 ? 'none' : 'block';
    elements.step2.style.display = showStep2 ? 'block' : 'none';
  }

  // Container Visibility
  const isCalibrated = calibration.complete === true;
  
  if (elements.setupContainer) {
      // Show setup if NOT calibrated OR if currently calibrating
      const showSetup = (!isCalibrated || status === 'calibrating');
      elements.setupContainer.style.display = showSetup ? 'block' : 'none';
  }

  if (elements.recorderContainer) {
      // Show recorder ONLY if calibrated
      elements.recorderContainer.style.display = isCalibrated ? 'block' : 'none';
  }

  // Button Visibility
  const isRec = status === 'recording';
  const isPaused = status === 'paused';
  const isReady = status === 'ready' || status === 'ready_to_record' || status === 'idle'; 
  const showReview = status === 'ready_to_submit';

  if (elements.recordBtn) elements.recordBtn.style.display = (isReady && isCalibrated && !isRec && !isPaused && !showReview) ? 'inline-flex' : 'none';
  if (elements.pauseBtn) elements.pauseBtn.style.display = isRec ? 'inline-flex' : 'none';
  if (elements.resumeBtn) elements.resumeBtn.style.display = isPaused ? 'inline-flex' : 'none';
  if (elements.stopBtn) elements.stopBtn.style.display = (isRec || isPaused) ? 'inline-flex' : 'none';
  
  // Submit Button
  if (elements.submitBtn) {
      if (status === 'submitting') {
          elements.submitBtn.textContent = `Uploading... ${Math.round((submission.progress||0)*100)}%`;
          elements.submitBtn.disabled = true;
      } else {
          elements.submitBtn.textContent = 'Submit Recording';
          elements.submitBtn.disabled = status !== 'ready_to_submit';
      }
  }
  
  // Play/Reset (Review Controls)
  if (elements.playBtn) {
      const isPlaying = !!recorder.isPlaying;
      elements.playBtn.textContent = isPlaying ? 'Pause' : 'Play Preview';
  }
  // Show review controls ONLY when ready to submit
  if (elements.reviewControls) {
      elements.reviewControls.style.display = showReview ? 'flex' : 'none';
  }
}

/* ---------------------------- INITIALIZE ---------------------------- */

export function initInstance(store, incomingElements = {}, forcedInstanceId = null) {
  
  let instId = forcedInstanceId || store?.getState()?.instanceId;
  let root = document;

  if (instId) {
      root = document.querySelector(`[data-starmus-instance="${instId}"]`) || document;
  } else {
      const form = document.querySelector('form[data-starmus-instance]');
      if (form) {
          instId = form.getAttribute('data-starmus-instance');
          root = form;
      }
  }

  const BUS = window.CommandBus;

  // Map Elements
  const el = {
    step1: root.querySelector('[data-starmus-step="1"]'),
    step2: root.querySelector('[data-starmus-step="2"]'),
    setupContainer: root.querySelector('[data-starmus-setup-container]'),
    timer: root.querySelector('[data-starmus-timer]'),
    timerElapsed: root.querySelector('.starmus-timer-elapsed'),
    volumeMeter: root.querySelector('[data-starmus-volume-meter]'), 
    durationProgress: root.querySelector('[data-starmus-duration-progress]'),
    fallbackContainer: root.querySelector('[data-starmus-fallback-container]'),
    recorderContainer: root.querySelector('[data-starmus-recorder-container]'),
    reviewControls: root.querySelector('.starmus-review-controls'),
    
    continueBtn: root.querySelector('[data-starmus-action="next"]'),
    setupMicBtn: root.querySelector('[data-starmus-action="setup-mic"]'),
    recordBtn: root.querySelector('[data-starmus-action="record"]'),
    pauseBtn: root.querySelector('[data-starmus-action="pause"]'),
    resumeBtn: root.querySelector('[data-starmus-action="resume"]'),
    stopBtn: root.querySelector('[data-starmus-action="stop"]'),
    submitBtn: root.querySelector('[data-starmus-action="submit"]'),
    playBtn: root.querySelector('[data-starmus-action="play"]'),
    resetBtn: root.querySelector('[data-starmus-action="reset"]')
  };

  // Bind Buttons
  if (el.continueBtn) {
      forceBind(el.continueBtn, 'click', () => {
          const inputs = el.step1 ? el.step1.querySelectorAll('[required]') : [];
          let valid = true;
          inputs.forEach(i => {
              if(!i.value.trim() && !i.checked) { valid=false; i.style.borderColor='red'; }
              else { i.style.borderColor=''; }
          });
          if (valid) store.dispatch({ type: 'starmus/ui/step-continue' });
          else alert('Please fill in all required fields.');
      });
  }

  if(el.setupMicBtn) forceBind(el.setupMicBtn, 'click', () => BUS.dispatch('setup-mic', {}, { instanceId: instId }));
  if(el.recordBtn) forceBind(el.recordBtn, 'click', () => BUS.dispatch('start-recording', {}, { instanceId: instId }));
  if(el.pauseBtn) forceBind(el.pauseBtn, 'click', () => BUS.dispatch('pause-mic', {}, { instanceId: instId }));
  if(el.resumeBtn) forceBind(el.resumeBtn, 'click', () => BUS.dispatch('resume-mic', {}, { instanceId: instId }));
  if(el.stopBtn) forceBind(el.stopBtn, 'click', () => BUS.dispatch('stop-mic', {}, { instanceId: instId }));

  // Playback wiring
  if (el.playBtn) {
      let audio = null;
      forceBind(el.playBtn, 'click', () => {
          const state = store.getState();
          if (state.recorder.isPlaying) {
              if(audio) audio.pause();
              store.dispatch({ type: 'starmus/recorder-playback-state', isPlaying: false });
          } else {
              const blob = state.source.blob;
              if (blob) {
                  if(!audio) audio = new Audio(URL.createObjectURL(blob));
                  audio.play();
                  store.dispatch({ type: 'starmus/recorder-playback-state', isPlaying: true });
                  audio.onended = () => store.dispatch({ type: 'starmus/recorder-playback-state', isPlaying: false });
              }
          }
      });
  }

  if (el.resetBtn) {
      forceBind(el.resetBtn, 'click', () => {
          BUS.dispatch('reset', {}, { instanceId: instId });
          // Force reload to clean state
          window.location.reload(); 
      });
  }

  if (el.submitBtn) {
      forceBind(el.submitBtn, 'click', (e) => {
        const form = e.target.closest('form');
        const data = form ? Object.fromEntries(new FormData(form).entries()) : {};
        BUS?.dispatch('submit', { formFields: data }, { instanceId: instId });
      });
  }

  // 5. START RENDER LOOP
  store.dispatch({ type: 'starmus/init', payload: { instanceId: instId } });
  const unsubscribe = store.subscribe(next => render(next, el));
  render(store.getState(), el);

  return unsubscribe;
}

if (typeof window !== 'undefined') window.initUI = initInstance;