/**
 * @file starmus-ui.js
 * @version 4.5.0-FIXED
 * @description UI Layer. Handles Button Clicks & Step Switching.
 */

'use strict';

function formatTime(seconds) {
  if (!Number.isFinite(seconds)) return '00m 00s';
  const m = Math.floor(seconds / 60);
  const s = Math.floor(seconds % 60);
  return `${m.toString().padStart(2,'0')}m ${s.toString().padStart(2,'0')}s`;
}

/**
 * FORCE BIND:
 * Replaces the button element to wipe external event listeners (like from themes)
 * and attaches our own listener. Guarantees the click works.
 */
function forceBind(element, eventName, handler) {
  if (!element) return null;
  const newEl = element.cloneNode(true);
  newEl.style.pointerEvents = 'auto'; // Ensure clickable
  
  if(element.parentNode) {
      element.parentNode.replaceChild(newEl, element);
  }
  
  newEl.addEventListener(eventName, (e) => {
    e.preventDefault();
    e.stopPropagation();
    console.log('[StarmusUI] Click detected on', newEl.dataset.starmusAction || 'button');
    handler(e);
  });
  
  return newEl;
}

/* ---------------------------- RENDER VIEW ---------------------------- */

export function render(state, elements) {
  if (!elements) return;

  const { status, step, recorder = {}, calibration = {} } = state;

  // 1. HANDLE STEPS (Details vs Recorder)
  if (elements.step1 && elements.step2) {
      // If status is 'idle' or 'uninitialized', we are on Step 1.
      // Otherwise (recording, calibrated, ready), we are on Step 2.
      const showStep2 = step === 2 || (status !== 'idle' && status !== 'uninitialized' && status !== 'ready');
      
      elements.step1.style.display = showStep2 ? 'none' : 'block';
      elements.step2.style.display = showStep2 ? 'block' : 'none';
  }

  // 2. FALLBACK MODE
  if (state.tier === 'C' || state.fallbackActive) {
    if(elements.fallbackContainer) elements.fallbackContainer.style.display = 'block';
    if(elements.recorderContainer) elements.recorderContainer.style.display = 'none';
    return;
  }

  // 3. TIMER
  const fmt = formatTime(recorder.duration || 0);
  if (elements.timerElapsed) elements.timerElapsed.textContent = fmt;
  if (elements.timer) {
      elements.timer.textContent = fmt;
      if (status === 'recording') elements.timer.classList.add('starmus-timer--recording');
      else elements.timer.classList.remove('starmus-timer--recording');
  }

  // 4. BUTTON STATES
  const isRec = status === 'recording';
  const isPaused = status === 'paused';
  const isReady = status === 'ready' || status === 'ready_to_record' || status === 'idle'; 
  const isCalib = calibration && calibration.complete === true;
  
  if (elements.recordBtn)
    elements.recordBtn.style.display = (isReady && isCalib && !isRec && !isPaused) ? 'inline-flex' : 'none';

  if (elements.pauseBtn) elements.pauseBtn.style.display = isRec ? 'inline-flex' : 'none';
  if (elements.resumeBtn) elements.resumeBtn.style.display = isPaused ? 'inline-flex' : 'none';
  if (elements.stopBtn) {
    elements.stopBtn.style.display = (isRec || isPaused) ? 'inline-flex' : 'none';
    elements.stopBtn.disabled = status === 'calibrating';
  }
  
  // 5. SUBMIT BUTTON
  if (elements.submitBtn) {
      if (status === 'submitting') {
          elements.submitBtn.textContent = 'Uploading...';
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
    timer: root.querySelector('[data-starmus-timer]'),
    timerElapsed: root.querySelector('.starmus-timer-elapsed'),
    fallbackContainer: root.querySelector('[data-starmus-fallback-container]'),
    recorderContainer: root.querySelector('[data-starmus-recorder-container]')
  };

  // 4. BIND BUTTONS (Using Force Bind)

  // Continue Button
  let continueBtn = root.querySelector('[data-starmus-action="next"]');
  if (continueBtn) {
      forceBind(continueBtn, 'click', () => {
          // Validate Inputs
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

  // Recorder Buttons
  let setupBtn = root.querySelector('[data-starmus-action="setup-mic"]');
  if(setupBtn) el.setupMicBtn = forceBind(setupBtn, 'click', () => BUS?.dispatch('setup-mic', {}, { instanceId: instId }));

  let recBtn = root.querySelector('[data-starmus-action="record"]');
  if(recBtn) el.recordBtn = forceBind(recBtn, 'click', () => BUS?.dispatch('start-recording', {}, { instanceId: instId }));

  let pauseBtn = root.querySelector('[data-starmus-action="pause"]');
  if(pauseBtn) el.pauseBtn = forceBind(pauseBtn, 'click', () => BUS?.dispatch('pause-mic', {}, { instanceId: instId }));

  let resumeBtn = root.querySelector('[data-starmus-action="resume"]');
  if(resumeBtn) el.resumeBtn = forceBind(resumeBtn, 'click', () => BUS?.dispatch('resume-mic', {}, { instanceId: instId }));

  let stopBtn = root.querySelector('[data-starmus-action="stop"]');
  if(stopBtn) el.stopBtn = forceBind(stopBtn, 'click', () => BUS?.dispatch('stop-mic', {}, { instanceId: instId }));

  // Submit Button
  let submitBtn = root.querySelector('[data-starmus-action="submit"]');
  if (submitBtn) {
      el.submitBtn = forceBind(submitBtn, 'click', (e) => {
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