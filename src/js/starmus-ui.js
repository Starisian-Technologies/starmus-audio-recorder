/**
 * @file starmus-ui.js
 * @version 4.2.3
 * @description Pure view layer + user interaction wiring.
 * Maps store state to DOM and dispatches UI actions to CommandBus.
 */

'use strict';

// --- LOCAL STATE ---
let starmusClipWarned = false;

/* -------------------------------------------------------------------------
 * UTILITIES
 * ------------------------------------------------------------------------- */

function starmusMaybeCoachUser(normalizedLevel, elements) {
  if (normalizedLevel >= 0.85 && !starmusClipWarned) {
    starmusClipWarned = true;

    const msg = elements.messageBox || document.querySelector('[data-starmus-message-box]');
    if (msg) {
      msg.textContent =
        '⚠️ Your microphone is too loud. Move back 6–12 inches or speak softer for a cleaner recording.';
      msg.style.display = 'block';
      msg.setAttribute('role', 'alert');
      msg.setAttribute('aria-live', 'assertive');

      setTimeout(() => {
        msg.style.display = 'none';
        msg.removeAttribute('role');
        msg.removeAttribute('aria-live');
      }, 6000);
    }
  }
}

function formatTime(seconds) {
  if (!Number.isFinite(seconds)) return '00m 00s';
  const m = Math.floor(seconds / 60);
  const s = Math.floor(seconds % 60);
  return `${m.toString().padStart(2, '0')}m ${s.toString().padStart(2, '0')}s`;
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

/* -------------------------------------------------------------------------
 * RENDER LOOP (STORE → DOM)
 * ------------------------------------------------------------------------- */

export function render(state, elements) {
  const {
    status,
    error,
    source = {},
    submission = {},
    calibration = {},
    recorder = {},
    instanceId
  } = state;

  /* -------------------- TIER C FALLBACK -------------------- */
  if (state.tier === 'C' || state.fallbackActive === true) {
    ['recordBtn','pauseBtn','resumeBtn','stopBtn','recorderContainer'].forEach((k) => {
      if (elements[k]) elements[k].style.display = 'none';
    });
    if (elements.fallbackContainer) {
      elements.fallbackContainer.style.display = 'block';
    }
    return;
  }

  /* -------------------- STEP MANAGEMENT -------------------- */
  if (elements.step1 && elements.step2) {
    if (status === 'uninitialized') {
      elements.step1.style.display = 'block';
      elements.step2.style.display = 'none';
      return;
    }
    const showStep2 = status !== 'idle' && status !== 'uninitialized';
    elements.step1.style.display = showStep2 ? 'none' : 'block';
    elements.step2.style.display = showStep2 ? 'block' : 'none';
  }

  /* -------------------- TIMER -------------------- */
  const MAX_DURATION = 1200;
  const ORANGE_THRESHOLD = 900;
  const RED_THRESHOLD = 1020;

  if (elements.timer || elements.timerElapsed) {
    const time = recorder.duration || 0;
    const formatted = formatTime(time);

    if (elements.timerElapsed) {
      elements.timerElapsed.textContent = formatted;
    } else if (elements.timer) {
      elements.timer.textContent = formatted;
    }

    if (elements.timer) {
      if (status === 'recording') {
        elements.timer.classList.add('starmus-timer--recording');
      } else {
        elements.timer.classList.remove('starmus-timer--recording');
      }
    }
  }

  /* -------------------- PROGRESS BAR -------------------- */
  if (elements.durationProgress) {
    const time = recorder.duration || 0;
    const show =
      status === 'recording' ||
      status === 'paused' ||
      status === 'calibrating';

    if (elements.durationProgress.parentElement) {
      elements.durationProgress.parentElement.style.display = show ? 'block' : 'none';
    }

    if (show) {
      const pct = Math.min(100, (time / MAX_DURATION) * 100);
      elements.durationProgress.style.setProperty('--starmus-recording-progress', `${pct}%`);
      elements.durationProgress.setAttribute('aria-valuenow', Math.floor(time));

      if (time >= RED_THRESHOLD) {
        elements.durationProgress.setAttribute('data-level', 'danger');
      } else if (time >= ORANGE_THRESHOLD) {
        elements.durationProgress.setAttribute('data-level', 'warning');
      } else {
        elements.durationProgress.setAttribute('data-level', 'safe');
      }

      if (time >= MAX_DURATION && status === 'recording' && window.CommandBus) {
        window.CommandBus.dispatch('stop-mic', {}, { instanceId });
      }
    }
  }

  /* -------------------- VOLUME METER -------------------- */
  if (elements.volumeMeter) {
    const active =
      status === 'recording' ||
      status === 'paused' ||
      status === 'calibrating';

    if (elements.volumeMeter.parentElement) {
      elements.volumeMeter.parentElement.style.display = active ? 'block' : 'none';
    }

    if (!active) {
      elements.volumeMeter.style.setProperty('--starmus-audio-level', '0%');
      elements.volumeMeter.removeAttribute('data-level');
    } else {
      const vol = Math.max(0, Math.min(100,
        status === 'calibrating'
          ? calibration.volumePercent || 0
          : recorder.amplitude || 0
      ));

      elements.volumeMeter.style.setProperty('--starmus-audio-level', `${vol}%`);

      const norm = vol / 100;
      if (norm < 0.6) elements.volumeMeter.setAttribute('data-level', 'safe');
      else if (norm < 0.85) elements.volumeMeter.setAttribute('data-level', 'hot');
      else elements.volumeMeter.setAttribute('data-level', 'clip');

      if (status === 'recording') starmusMaybeCoachUser(norm, elements);
    }
  }

  /* -------------------- BUTTON STATES -------------------- */
  const isRec = status === 'recording';
  const isPaused = status === 'paused';
  const isReady = status === 'ready';
  const isCalibrating = status === 'calibrating';
  const isRecorded = status === 'ready_to_submit';
  const isCalibrated = calibration && calibration.complete === true;
  const showStop = isRec || isPaused || isCalibrating;

  if (elements.recordBtn) {
    elements.recordBtn.style.display =
      isReady && isCalibrated && !showStop && !isRecorded
        ? 'inline-flex'
        : 'none';
  }

  if (elements.pauseBtn) {
    elements.pauseBtn.style.display = isRec ? 'inline-flex' : 'none';
  }

  if (elements.resumeBtn) {
    elements.resumeBtn.style.display = isPaused ? 'inline-flex' : 'none';
  }

  if (elements.stopBtn) {
    elements.stopBtn.style.display = showStop ? 'inline-flex' : 'none';
    elements.stopBtn.disabled = isCalibrating;
    elements.stopBtn.innerHTML = isCalibrating
      ? '<span class="dashicons dashicons-update"></span> Calibrating...'
      : '<span class="dashicons dashicons-media-default"></span> Stop';
  }

  if (elements.submitBtn) {
    elements.submitBtn.disabled = status !== 'ready_to_submit';
    elements.submitBtn.textContent =
      status === 'submitting' ? 'Uploading…' : 'Submit Recording';
  }

  /* -------------------- TRANSCRIPT -------------------- */
  if (elements.transcriptBox) {
    const hasFinal = source.transcript?.length > 0;
    const hasInterim = source.interimTranscript?.length > 0;

    if (hasFinal || hasInterim) {
      elements.transcriptBox.style.display = 'block';
      elements.transcriptBox.innerHTML =
        (hasFinal ? `<span class="starmus-transcript--final">${escapeHtml(source.transcript)}</span>` : '')
        + (hasInterim ? ` <span class="starmus-transcript--interim">${escapeHtml(source.interimTranscript)}</span>` : '');
    } else {
      elements.transcriptBox.style.display = 'none';
    }
  }

  /* -------------------- STATUS MESSAGES -------------------- */
  if (elements.statusEl) {
    const el = elements.statusEl;
    let msg = '';
    let cls = 'starmus-status';

    if (error) {
      msg = error.message || 'An error occurred.';
      cls += ' starmus-status--error';
    } else {
      switch (status) {
        case 'ready': msg = 'Mic calibrated. Click Start Recording.'; cls += ' starmus-status--success'; break;
        case 'recording': msg = 'Recording...'; cls += ' starmus-status--recording'; break;
        case 'paused': msg = 'Paused — Resume or Stop.'; break;
        case 'submitting': msg = `Uploading ${Math.round(submission.progress * 100)}%`; break;
        case 'complete': msg = 'Upload successful!'; cls += ' starmus-status--success'; break;
        default: msg = '';
      }
    }

    el.className = cls;
    el.textContent = msg;
    el.style.display = msg ? 'block' : 'none';
  }
}

/* -------------------------------------------------------------------------
 * UI INITIALIZATION (WIRING DOM EVENTS → COMMAND BUS)
 * ------------------------------------------------------------------------- */

export function initInstance(store, elements) {
  console.log('[StarmusUI] initInstance called with elements:', {
    recordBtn: !!elements.recordBtn,
    setupMicBtn: !!elements.setupMicBtn,
    volumeMeter: !!elements.volumeMeter,
    timer: !!elements.timer,
    elementKeys: Object.keys(elements)
  });
  
  const instId = store.getState().instanceId;
  const BUS = window.CommandBus || window.StarmusHooks;

  console.log('[StarmusUI] CommandBus found:', !!BUS, 'Instance ID:', instId);

  function dispatch(action) {
    if (!BUS) return console.warn('[StarmusUI] No CommandBus detected.');
    console.log('[StarmusUI] Dispatching:', action);
    BUS.dispatch(action, {}, { instanceId: instId });
  }

  /* BUTTON EVENT LISTENERS — THIS WAS WHAT YOU LOST */
  console.log('[StarmusUI] Attaching event listeners...');
  if (elements.recordBtn) {
    console.log('[StarmusUI] Record button element:', elements.recordBtn);
    console.log('[StarmusUI] Button disabled:', elements.recordBtn.disabled);
    console.log('[StarmusUI] Button style display:', getComputedStyle(elements.recordBtn).display);
    console.log('[StarmusUI] Button style visibility:', getComputedStyle(elements.recordBtn).visibility);
    console.log('[StarmusUI] Button style pointer-events:', getComputedStyle(elements.recordBtn).pointerEvents);
    
    elements.recordBtn.addEventListener('click', function(e) {
      console.log('[StarmusUI] Record button clicked! Event:', e);
      console.log('[StarmusUI] Button disabled at click time:', elements.recordBtn.disabled);
      dispatch('start-recording');
    });
    
    // Add a second listener to test if ANY events work
    elements.recordBtn.addEventListener('mousedown', function(e) {
      console.log('[StarmusUI] Record button MOUSEDOWN detected!', e);
    });
    
    // Try to manually test the button
    setTimeout(() => {
      console.log('[StarmusUI] Testing manual click...');
      elements.recordBtn.click();
    }, 2000);
    
    console.log('[StarmusUI] Record button listener attached');
  } else {
    console.log('[StarmusUI] No record button found!');
  }
  elements.pauseBtn?.addEventListener('click', () => dispatch('pause-mic'));
  elements.resumeBtn?.addEventListener('click', () => dispatch('resume-mic'));
  elements.stopBtn?.addEventListener('click', () => dispatch('stop-mic'));
  elements.submitBtn?.addEventListener('click', () => dispatch('submit'));
  elements.resetBtn?.addEventListener('click', () => dispatch('reset'));

  elements.setupMicBtn?.addEventListener('click', () => dispatch('setup-mic'));

  if (elements.fileInput) {
    elements.fileInput.addEventListener('change', (e) => {
      const file = e.target.files?.[0];
      if (!file) return;
      BUS.dispatch('file-attached', { file }, { instanceId: instId });
    });
  }

  // SUBSCRIBE TO STORE
  const unsubscribe = store.subscribe((next) => render(next, elements));
  render(store.getState(), elements);

  return unsubscribe;
}
// at end of the file
if (typeof window !== 'undefined') {
    window.initUI = initInstance;
}


