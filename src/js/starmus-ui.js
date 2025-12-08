/**
 * @file starmus-ui.js
 * @version 4.2.2 (ES Module)
 * @description Pure view layer. Maps store state to DOM elements.
 * Supports Timer, Volume Meter, Live Transcript, and Review Controls.
 */

'use strict';

let starmusClipWarned = false;

function starmusMaybeCoachUser(normalizedLevel, elements) {
  if (normalizedLevel >= 0.85 && !starmusClipWarned) {
    starmusClipWarned = true;

    const msg =
      elements.messageBox || document.querySelector('[data-starmus-message-box]');
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
  if (seconds === undefined || seconds === null || isNaN(seconds)) {
    return '00m 00s';
  }
  const m = Math.floor(seconds / 60);
  const s = Math.floor(seconds % 60);
  return `${m.toString().padStart(2, '0')}m ${s.toString().padStart(2, '0')}s`;
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

export function render(state, elements) {
  const {
    status,
    error,
    source = {},
    submission = {},
    calibration = {},
    recorder = {},
    instanceId,
  } = state;

  if (state.tier === 'C' || state.fallbackActive === true) {
    ['recordBtn','pauseBtn','resumeBtn','stopBtn','recorderContainer'].forEach((k) => {
      if (elements[k]) elements[k].style.display = 'none';
    });
    if (elements.fallbackContainer) {
      elements.fallbackContainer.style.display = 'block';
    }
    return;
  }

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

  const MAX_DURATION = 1200;
  const ORANGE_THRESHOLD = 900;
  const RED_THRESHOLD = 1020;

  if (elements.timer || elements.timerElapsed) {
    const time = recorder.duration || 0;
    const formattedTime = formatTime(time);

    if (elements.timerElapsed) {
      elements.timerElapsed.textContent = formattedTime;
    } else if (elements.timer) {
      elements.timer.textContent = formattedTime;
    }

    if (elements.timer) {
      if (status === 'recording') {
        elements.timer.classList.add('starmus-timer--recording');
      } else {
        elements.timer.classList.remove('starmus-timer--recording');
      }
    }
  }

  if (elements.durationProgress) {
    const time = recorder.duration || 0;
    const showProgress =
      status === 'recording' ||
      status === 'paused' ||
      status === 'calibrating';

    if (elements.durationProgress.parentElement) {
      elements.durationProgress.parentElement.style.display = showProgress
        ? 'block'
        : 'none';
    }

    if (showProgress) {
      const progressPercent = Math.min(100, (time / MAX_DURATION) * 100);
      elements.durationProgress.style.setProperty(
        '--starmus-recording-progress',
        `${progressPercent}%`
      );
      elements.durationProgress.setAttribute(
        'aria-valuenow',
        Math.floor(time)
      );

      if (time >= RED_THRESHOLD) {
        elements.durationProgress.setAttribute('data-level', 'danger');
      } else if (time >= ORANGE_THRESHOLD) {
        elements.durationProgress.setAttribute('data-level', 'warning');
      } else {
        elements.durationProgress.setAttribute('data-level', 'safe');
      }

      if (time >= MAX_DURATION && status === 'recording') {
        if (window.CommandBus) {
          window.CommandBus.dispatch(
            'stop-mic',
            {},
            { instanceId: instanceId }
          );
        }
      }
    }
  }

  if (elements.volumeMeter) {
    const showMeter =
      status === 'calibrating' ||
      status === 'recording' ||
      status === 'paused';

    if (elements.volumeMeter.parentElement) {
      elements.volumeMeter.parentElement.style.display = showMeter
        ? 'block'
        : 'none';
    }

    if (showMeter) {
      let vol = 0;
      if (status === 'calibrating') {
        vol = calibration.volumePercent || 0;
      } else {
        vol = recorder.amplitude || 0;
      }

      vol = Math.max(0, Math.min(100, vol));
      const normalizedLevel = vol / 100;

      elements.volumeMeter.style.setProperty(
        '--starmus-audio-level',
        `${vol}%`
      );

      if (normalizedLevel < 0.6) {
        elements.volumeMeter.setAttribute('data-level', 'safe');
      } else if (normalizedLevel < 0.85) {
        elements.volumeMeter.setAttribute('data-level', 'hot');
      } else {
        elements.volumeMeter.setAttribute('data-level', 'clip');
      }

      if (status === 'recording') {
        starmusMaybeCoachUser(normalizedLevel, elements);
      }
    } else {
      elements.volumeMeter.style.setProperty('--starmus-audio-level', '0%');
      elements.volumeMeter.removeAttribute('data-level');
    }
  }

  const isRecorded = status === 'ready_to_submit';
  const isRecording = status === 'recording';
  const isCalibrating = status === 'calibrating';
  const isReady = status === 'ready';
  const isCalibrated = calibration && calibration.complete === true;
  const isPaused = status === 'paused';
  const showStopBtn = isRecording || isCalibrating;

  if (elements.setupMicBtn && elements.setupContainer) {
    const showSetup = status === 'ready_to_record' && !isCalibrated;
    elements.setupContainer.style.display = showSetup ? 'block' : 'none';
  }

  if (elements.recordBtn) {
    const showRecordBtn =
      isReady &&
      isCalibrated &&
      !isRecorded &&
      !showStopBtn &&
      !isPaused &&
      status !== 'submitting' &&
      status !== 'processing';
    elements.recordBtn.style.display = showRecordBtn
      ? 'inline-flex'
      : 'none';

    if (isReady && isCalibrated) {
      elements.recordBtn.innerHTML =
        '<span class="dashicons dashicons-microphone"></span> Start Recording';
    }
  }

  if (elements.pauseBtn) {
    elements.pauseBtn.style.display = isRecording ? 'inline-flex' : 'none';
  }

  if (elements.resumeBtn) {
    elements.resumeBtn.style.display = isPaused ? 'inline-flex' : 'none';
  }

  if (elements.stopBtn) {
    const showStop = isRecording || isCalibrating || isPaused;
    elements.stopBtn.style.display = showStop ? 'inline-flex' : 'none';

    if (isCalibrating) {
      elements.stopBtn.innerHTML =
        '<span class="dashicons dashicons-update"></span> Calibrating...';
      elements.stopBtn.disabled = true;
    } else {
      elements.stopBtn.innerHTML =
        '<span class="dashicons dashicons-media-default"></span> Stop';
      elements.stopBtn.disabled = false;
    }
  }

  if (elements.reviewControls) {
    elements.reviewControls.style.display =
      isRecorded || isPaused ? 'flex' : 'none';
  }

  if (elements.playBtn) {
    const isPlaying = !!recorder.isPlaying;
    elements.playBtn.textContent = isPlaying ? 'Pause' : 'Play Preview';
    elements.playBtn.setAttribute(
      'aria-label',
      isPlaying ? 'Pause audio' : 'Play recorded audio'
    );
  }

  if (elements.submitBtn) {
    elements.submitBtn.disabled = status !== 'ready_to_submit';

    if (status === 'submitting') {
      elements.submitBtn.innerHTML =
        '<span class="starmus-spinner"></span> Uploading...';
      elements.submitBtn.classList.add('starmus-btn--loading');
    } else {
      elements.submitBtn.textContent = 'Submit Recording';
      elements.submitBtn.classList.remove('starmus-btn--loading');
    }
  }

  if (elements.progressEl && elements.progressWrap) {
    if (status === 'submitting') {
      elements.progressWrap.style.display = 'block';
      const pct = (submission.progress || 0) * 100;
      elements.progressEl.style.width = `${Math.max(0, Math.min(100, pct))}%`;
    } else {
      elements.progressWrap.style.display = 'none';
    }
  }

  if (elements.transcriptBox) {
    const hasFinal = source.transcript && source.transcript.length > 0;
    const hasInterim =
      source.interimTranscript && source.interimTranscript.length > 0;

    if (hasFinal || hasInterim) {
      elements.transcriptBox.style.display = 'block';

      const finalHTML = hasFinal
        ? `<span class="starmus-transcript--final">${escapeHtml(
            source.transcript
          )}</span>`
        : '';
      const interimHTML = hasInterim
        ? `<span class="starmus-transcript--interim">${escapeHtml(
            source.interimTranscript
          )}</span>`
        : '';

      elements.transcriptBox.innerHTML = finalHTML + (interimHTML ? ' ' + interimHTML : '');

      if (hasFinal && status !== 'calibrating') {
        elements.transcriptBox.classList.remove('starmus-transcript--pulse');
        void elements.transcriptBox.offsetWidth;
        elements.transcriptBox.classList.add('starmus-transcript--pulse');
      }
    } else {
      elements.transcriptBox.style.display = 'none';
      elements.transcriptBox.textContent = '';
    }
  }

  if (elements.statusEl) {
    const messageEl = elements.statusEl;
    let message = '';
    let msgClass = 'starmus-status';

    if (error) {
      msgClass += ' starmus-status--error';
      message = error.message || 'An error occurred.';
      if (error.retryable) {
        message += ' Please try again.';
      }
    } else {
      switch (status) {
        case 'idle':
          message = 'Please fill out the details to continue.';
          break;
        case 'calibrating':
          message = calibration.message || 'Adjusting microphone...';
          break;
        case 'ready':
          message = calibration.complete
            ? `Mic calibrated (Gain: ${(calibration.gain || 1.0).toFixed(
                1
              )}x). Click "Start Recording" to begin.`
            : 'Ready to record.';
          msgClass += ' starmus-status--success';
          break;
        case 'ready_to_record':
          message = 'Ready to record.';
          break;
        case 'recording':
          message = 'Recording in progress...';
          msgClass += ' starmus-status--recording';
          break;
        case 'paused':
          message =
            'Recording paused. Click Resume to continue or Stop to finish.';
          msgClass += ' starmus-status--info';
          break;
        case 'processing':
          message = 'Processing audio... Please wait.';
          msgClass += ' starmus-status--info';
          break;
        case 'ready_to_submit':
          if (submission.isQueued) {
            message = 'Network offline. Recording saved to queue.';
            msgClass += ' starmus-status--warning';
          } else {
            message = 'Recording complete. Review or Submit.';
            msgClass += ' starmus-status--success';
          }
          break;
        case 'submitting':
          message = `Uploading... ${Math.round(
            (submission.progress || 0) * 100
          )}%`;
          break;
        case 'complete':
          if (submission.isQueued) {
            message =
              'Saved to offline queue. Will upload automatically when online.';
            msgClass += ' starmus-status--warning';
          } else {
            message = 'Upload successful! Redirecting...';
            msgClass += ' starmus-status--success';
            setTimeout(() => {
              const redirectUrl =
                window.starmusConfig?.myRecordingsUrl || '/my-submissions/';
              window.location.href = redirectUrl;
            }, 2000);
          }
          break;
        default:
          message = '';
      }
    }

    messageEl.className = msgClass;
    messageEl.textContent = message;
    messageEl.style.display = message ? 'block' : 'none';
  }
}

export function initInstance(store, elements) {
  const unsubscribe = store.subscribe((nextState) => render(nextState, elements));
  render(store.getState(), elements);
  return unsubscribe;
}
