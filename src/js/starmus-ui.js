/**
 * @file starmus-ui.js
 * @version 4.2.4
 * @description Pure view layer + user interaction wiring.
 * FIXED: elements map auto-build + null-safe guards + stable recorder boot
 */

'use strict';

window.StarmusInstances = window.StarmusInstances || {};
let starmusClipWarned = false;

/* ---------------------------- UTILITIES ---------------------------- */

function starmusMaybeCoachUser(normalizedLevel, elements) {
  if (normalizedLevel >= 0.85 && !starmusClipWarned) {
    starmusClipWarned = true;

    const msg = elements.messageBox || document.querySelector('[data-starmus-message-box]');
    if (!msg) return;

    msg.textContent =
      'Your microphone is too loud. Move back 6–12 inches or speak softer for a cleaner recording.';
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

function formatTime(seconds) {
  if (!Number.isFinite(seconds)) return '00m 00s';
  const m = Math.floor(seconds / 60);
  const s = Math.floor(seconds % 60);
  return `${m.toString().padStart(2,'0')}m ${s.toString().padStart(2,'0')}s`;
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

/* ---------------------------- RENDER ---------------------------- */

export function render(state, elements) {
  if (!elements) return; // MUST NEVER CRASH

  const {
    status,
    error,
    source = {},
    submission = {},
    calibration = {},
    recorder = {},
    instanceId
  } = state;

  /* SAFETY: Tier C fallback */
  if (state.tier === 'C' || state.fallbackActive) {
    ['recordBtn','pauseBtn','resumeBtn','stopBtn','recorderContainer'].forEach(k => {
      if (elements[k]) elements[k].style.display = 'none';
    });
    if (elements.fallbackContainer) elements.fallbackContainer.style.display = 'block';
    return;
  }

  /* TIMER */
  const MAX = 1200;
  const time = recorder.duration || 0;
  const formatted = formatTime(time);

  if (elements.timerElapsed) {
    elements.timerElapsed.textContent = formatted;
  } else if (elements.timer) {
    elements.timer.textContent = formatted;
  }

  if (elements.timer) {
    if (status === 'recording') elements.timer.classList.add('starmus-timer--recording');
    else elements.timer.classList.remove('starmus-timer--recording');
  }

  /* BUTTON STATES */
  const isRec = status === 'recording';
  const isPaused = status === 'paused';
  const isReady = status === 'ready' || status === 'ready_to_record';
  const isCalibrated = calibration && calibration.complete === true;
  const showStop = isRec || isPaused;

  if (elements.recordBtn)
    elements.recordBtn.style.display = (isReady && isCalibrated && !showStop) ? 'inline-flex' : 'none';

  if (elements.pauseBtn)
    elements.pauseBtn.style.display = isRec ? 'inline-flex' : 'none';

  if (elements.resumeBtn)
    elements.resumeBtn.style.display = isPaused ? 'inline-flex' : 'none';

  if (elements.stopBtn) {
    elements.stopBtn.style.display = showStop ? 'inline-flex' : 'none';
    elements.stopBtn.disabled = status === 'calibrating';
  }
}

/* ---------------------------- INIT ---------------------------- */

export function initInstance(store, incomingElements = {}) {
  console.log('[StarmusUI] initInstance starting');

  const instId = store?.getState()?.instanceId;
  const BUS = window.CommandBus || window.StarmusHooks;

  // AUTO-HYDRATE ELEMENT MAP IF CALLER DID NOT PROVIDE ONE
  const root = document.querySelector(`[data-starmus-instance="${instId}"]`) || document;
  const elements = {
    recordBtn: incomingElements.recordBtn || root.querySelector('[data-starmus-action="record"]'),
    pauseBtn: incomingElements.pauseBtn || root.querySelector('[data-starmus-action="pause"]'),
    resumeBtn: incomingElements.resumeBtn || root.querySelector('[data-starmus-action="resume"]'),
    stopBtn: incomingElements.stopBtn || root.querySelector('[data-starmus-action="stop"]'),
    submitBtn: incomingElements.submitBtn || root.querySelector('[data-starmus-action="submit"]'),
    setupMicBtn: incomingElements.setupMicBtn || root.querySelector('[data-starmus-action="setup-mic"]'),
    timer: incomingElements.timer || root.querySelector('[data-starmus-timer]'),
    timerElapsed: incomingElements.timerElapsed || root.querySelector('[data-starmus-timer-elapsed]'),
    volumeMeter: incomingElements.volumeMeter || root.querySelector('[data-starmus-volume-meter]')
  };

  console.log('[StarmusUI] Elements normalized:', elements);

  // REQUIRED INIT
  store.dispatch({ type: 'starmus/init', payload: { instanceId: instId } });
  BUS?.dispatch('setup-mic', {}, { instanceId: instId });

  /* --- BUTTON HANDLERS (NULL SAFE) --- */
  elements.recordBtn?.addEventListener('click', () => BUS?.dispatch('start-recording', {}, { instanceId: instId }));
  elements.pauseBtn?.addEventListener('click', () => BUS?.dispatch('pause-mic', {}, { instanceId: instId }));
  elements.resumeBtn?.addEventListener('click', () => BUS?.dispatch('resume-mic', {}, { instanceId: instId }));
  elements.stopBtn?.addEventListener('click', () => BUS?.dispatch('stop-mic', {}, { instanceId: instId }));

  elements.submitBtn?.addEventListener('click', (e) => {
    const form = e.target.closest('form');
    const data = form ? Object.fromEntries(new FormData(form).entries()) : {};
    BUS?.dispatch('submit', { formFields: data }, { instanceId: instId });
  });

  /**
 * ABSOLUTE PATCH
 * Forces Starmus to locate the record button AFTER WordPress layouts finish,
 * rebinds click handler if theme, block wrappers, or overlays interfered.
 */
document.addEventListener('DOMContentLoaded', function starmusLateBindFix() {
    try {
        // Find ANY Starmus record button on page
        const btn = document.querySelector('[data-starmus-action="record"], button[id^="starmus_record_btn_"]');
        if (!btn) {
            console.warn('[Starmus PATCH] No record button found on DOMContentLoaded');
            return;
        }

        console.log('[Starmus PATCH] Record button located:', btn);

        // Ensure button is actually clickable
        btn.style.pointerEvents = 'auto';
        btn.style.zIndex = '999999';
        btn.style.position = 'relative';

        // Wipe any broken handlers
        btn.replaceWith(btn.cloneNode(true)); 
        const freshBtn = document.querySelector('[data-starmus-action="record"], button[id^="starmus_record_btn_"]');

        // Get active instance
        const form = freshBtn.closest('[data-starmus-instance]');
        const id = form?.getAttribute('data-starmus-instance');
        const store = window.StarmusInstances?.[id]?.store;

        if (!store) {
            console.error('[Starmus PATCH] No store for instance', id);
            return;
        }

        const BUS = window.CommandBus || window.StarmusHooks;

        // Bind FINAL guaranteed working handler
        freshBtn.addEventListener('click', function () {
            console.log('[Starmus PATCH] Record button CLICK captured — dispatch start-recording');
            BUS.dispatch('start-recording', {}, { instanceId: id });
        });

        console.log('[Starmus PATCH] Record button rebound successfully');
    } catch (e) {
        console.error('[Starmus PATCH ERROR]', e);
    }
});


  /* SUBSCRIBE & INITIAL RENDER */
  const unsubscribe = store.subscribe(next => render(next, elements));
  render(store.getState(), elements);

  return unsubscribe;
}

/* GLOBAL EXPORT FOR INTEGRATOR */
if (typeof window !== 'undefined') window.initUI = initInstance;
