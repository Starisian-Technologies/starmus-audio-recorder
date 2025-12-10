/**
 * @file starmus-ui.js
 * @version 5.2.1-FINAL
 * @description UI Layer. Handles DOM events, Step Switching, and visual state rendering.
 */

'use strict';

function formatTime(seconds) {
  if (!Number.isFinite(seconds)) return '00m 00s';
  const m = Math.floor(seconds / 60);
  const s = Math.floor(seconds % 60);
  return `${m.toString().padStart(2,'0')}m ${s.toString().padStart(2,'0')}s`;
}

/**
 * Force Bind Helper: Replaces element to ensure clean event listeners.
 */
function forceBind(element, eventName, handler) {
  if (!element) return null;
  const newEl = element.cloneNode(true);
  newEl.style.pointerEvents = 'auto'; 
  
  if(element.parentNode) {
      element.parentNode.replaceChild(newEl, element);
  }
  
  newEl.addEventListener(eventName, (e) => {
    e.preventDefault();
    e.stopPropagation();
    console.log('[StarmusUI] Click detected on', newEl.dataset.starmusAction || newEl.id);
    handler(e);
  });
  
  return newEl;
}

/* ---------------------------- RENDER VIEW ---------------------------- */

export function render(state, elements) {
  if (!elements) return;

  const { status, step, recorder = {}, calibration = {}, submission = {} } = state;

  // 1. STEP VISIBILITY
  if (elements.step1 && elements.step2) {
    const showStep2 = step === 2 || (status !== 'idle' && status !== 'uninitialized' && status !== 'ready');
    elements.step1.style.display = showStep2 ? 'none' : 'block';
    elements.step2.style.display = showStep2 ? 'block' : 'none';
  }

  // 2. FALLBACK
  if (state.tier === 'C' || state.fallbackActive) {
    if(elements.fallbackContainer) elements.fallbackContainer.style.display = 'block';
    if(elements.recorderContainer) elements.recorderContainer.style.display = 'none';
    return;
  }

  // 3. CALIBRATION & SETUP
  const isCalibrating = status === 'calibrating';
  const isCalibrated = calibration.complete === true;
  
  if (elements.setupContainer) {
      // Show setup if we are in step 2 AND (not calibrated OR currently calibrating)
      const showSetup = (!isCalibrated || isCalibrating);
      elements.setupContainer.style.display = showSetup ? 'block' : 'none';
      
      // Update setup button text
      if (elements.setupMicBtn) {
          if (isCalibrating) {
              elements.setupMicBtn.textContent = calibration.message || 'Adjusting...';
              elements.setupMicBtn.disabled = true;
          } else {
              elements.setupMicBtn.innerHTML = '<span class="dashicons dashicons-microphone"></span> Setup Microphone';
              elements.setupMicBtn.disabled = false;
          }
      }
  }

  // 4. RECORDER CONTAINER VISIBILITY
  if (elements.recorderContainer) {
      // Show recorder ONLY if calibrated
      elements.recorderContainer.style.display = isCalibrated ? 'block' : 'none';
  }

  // 5. TIMER
  const formatted = formatTime(recorder.duration || 0);
  if (elements.timerElapsed) elements.timerElapsed.textContent = formatted;
  else if (elements.timer) elements.timer.textContent = formatted;

  if (elements.timer) {
    if (status === 'recording') elements.timer.classList.add('starmus-timer--recording');
    else elements.timer.classList.remove('starmus-timer--recording');
  }

  // 6. BUTTONS VISIBILITY
  const isRec = status === 'recording';
  const isPaused = status === 'paused';
  const isReady = status === 'ready' || status === 'ready_to_record' || status === 'idle'; 
  
  if (elements.recordBtn)
    elements.recordBtn.style.display = (isReady && isCalibrated && !isRec && !isPaused) ? 'inline-flex' : 'none';

  if (elements.pauseBtn)
    elements.pauseBtn.style.display = isRec ? 'inline-flex' : 'none';

  if (elements.resumeBtn)
    elements.resumeBtn.style.display = isPaused ? 'inline-flex' : 'none';

  if (elements.stopBtn) {
    elements.stopBtn.style.display = (isRec || isPaused) ? 'inline-flex' : 'none';
  }
  
  // 7. SUBMIT BUTTON
  if (elements.submitBtn) {
      if (status === 'submitting') {
          elements.submitBtn.textContent = `Uploading... ${Math.round((submission.progress||0)*100)}%`;
          elements.submitBtn.disabled = true;
      } else {
          elements.submitBtn.textContent = 'Submit Recording';
          elements.submitBtn.disabled = status !== 'ready_to_submit';
      }
  }
}

/* ---------------------------- INITIALIZE ---------------------------- */

export function initInstance(store, incomingElements = {}, forcedInstanceId = null) {
  
  // 1. GET ID
  let instId = forcedInstanceId || store?.getState()?.instanceId;
  let root = document;

  // 2. FIND ROOT ELEMENT
  if (instId) {
      root = document.querySelector(`[data-starmus-instance="${instId}"]`) || document;
  } else {
      const form = document.querySelector('form[data-starmus-instance]');
      if (form) {
          instId = form.getAttribute('data-starmus-instance');
          root = form;
      }
  }

  console.log('[StarmusUI] Initializing for ID:', instId);
  const BUS = window.CommandBus || window.StarmusHooks;

  // 3. MAP ELEMENTS
  const el = {
    step1: root.querySelector('[data-starmus-step="1"]'),
    step2: root.querySelector('[data-starmus-step="2"]'),
    setupContainer: root.querySelector('[data-starmus-setup-container]'),
    timer: root.querySelector('[data-starmus-timer]'),
    timerElapsed: root.querySelector('.starmus-timer-elapsed'),
    fallbackContainer: root.querySelector('[data-starmus-fallback-container]'),
    recorderContainer: root.querySelector('[data-starmus-recorder-container]'),
    
    // Buttons to bind
    continueBtn: root.querySelector('[data-starmus-action="next"]'),
    setupMicBtn: root.querySelector('[data-starmus-action="setup-mic"]'),
    recordBtn: root.querySelector('[data-starmus-action="record"]'),
    pauseBtn: root.querySelector('[data-starmus-action="pause"]'),
    resumeBtn: root.querySelector('[data-starmus-action="resume"]'),
    stopBtn: root.querySelector('[data-starmus-action="stop"]'),
    submitBtn: root.querySelector('[data-starmus-action="submit"]')
  };

  // 4. BIND BUTTONS (Using Force Bind)

  if (el.continueBtn) {
      el.continueBtn = forceBind(el.continueBtn, 'click', () => {
          const inputs = el.step1 ? el.step1.querySelectorAll('[required]') : [];
          let valid = true;
          inputs.forEach(i => {
              if(!i.value.trim() && !i.checked) { valid=false; i.style.borderColor='red'; }
              else { i.style.borderColor=''; }
          });

          if (valid) {
              store.dispatch({ type: 'starmus/ui/step-continue' });
          } else {
              alert('Please fill in all required fields.');
          }
      });
  }

  if(el.setupMicBtn) el.setupMicBtn = forceBind(el.setupMicBtn, 'click', () => BUS?.dispatch('setup-mic', {}, { instanceId: instId }));
  if(el.recordBtn) el.recordBtn = forceBind(el.recordBtn, 'click', () => BUS?.dispatch('start-recording', {}, { instanceId: instId }));
  if(el.pauseBtn) el.pauseBtn = forceBind(el.pauseBtn, 'click', () => BUS?.dispatch('pause-mic', {}, { instanceId: instId }));
  if(el.resumeBtn) el.resumeBtn = forceBind(el.resumeBtn, 'click', () => BUS?.dispatch('resume-mic', {}, { instanceId: instId }));
  if(el.stopBtn) el.stopBtn = forceBind(el.stopBtn, 'click', () => BUS?.dispatch('stop-mic', {}, { instanceId: instId }));

  if (el.submitBtn) {
      el.submitBtn = forceBind(el.submitBtn, 'click', (e) => {
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