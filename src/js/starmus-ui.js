/**
 * @file starmus-ui.js
 * @version 4.6.0
 * @description UI Layer. Robust against missing state properties.
 */

'use strict';

function formatTime(seconds) {
    if (!seconds || isNaN(seconds)) return '00:00';
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60);
    const sStr = Math.floor(s).toString().padStart(2, '0');
    return `${m.toString().padStart(2, '0')}:${sStr}`;
}

function render(state, elements) {
    const { status, error, source = {}, submission = {}, calibration = {}, recorder = {} } = state;

    // 1. Step Logic
    if (elements.step1 && elements.step2) {
        const isActive = status !== 'idle' && status !== 'ready'; 
        elements.step1.style.display = isActive ? 'none' : 'block';
        elements.step2.style.display = isActive ? 'block' : 'none';
    }

    // 2. Timer (With Fallback)
    if (elements.timer) {
        const time = typeof recorder.duration === 'number' ? recorder.duration : 0;
        elements.timer.textContent = formatTime(time);
        elements.timer.classList.toggle('starmus-timer--recording', status === 'recording');
    }

    // 3. Volume Meter (Robust visibility)
    if (elements.volumeMeter) {
        const showMeter = status === 'calibrating' || status === 'recording';
        
        // Hide/Show Container
        const container = elements.volumeMeter.closest('.starmus-meter-wrap') || elements.volumeMeter.parentElement;
        if (container) container.style.display = showMeter ? 'block' : 'none';
        
        if (showMeter) {
            // Prefer Calibration volume first, then Recorder amplitude
            const vol = (status === 'calibrating') ? (calibration.volumePercent || 0) : (recorder.amplitude || 0);
            elements.volumeMeter.style.width = `${vol}%`;
            elements.volumeMeter.style.backgroundColor = vol > 90 ? '#ff4444' : '#4caf50';
        }
    }

    // 4. Buttons
    const isRecorded = status === 'ready_to_submit';
    const isRecording = status === 'recording' || status === 'calibrating';

    if (elements.recordBtn) {
        elements.recordBtn.style.display = (!isRecorded && !isRecording && status !== 'submitting') ? 'inline-flex' : 'none';
    }
    if (elements.stopBtn) {
        elements.stopBtn.style.display = (status === 'recording') ? 'inline-flex' : 'none';
    }
    if (elements.reviewControls) {
        elements.reviewControls.style.display = isRecorded ? 'flex' : 'none';
    }

    // Play Button
    if (elements.playBtn) {
        const isPlaying = !!recorder.isPlaying; 
        elements.playBtn.textContent = isPlaying ? 'Pause' : 'Play Preview';
    }

    // 5. Submit / Progress
    if (elements.submitBtn) {
        elements.submitBtn.disabled = status !== 'ready_to_submit';
        if (status === 'submitting') {
            elements.submitBtn.innerHTML = '<span class="starmus-spinner"></span> Uploading...';
            elements.submitBtn.classList.add('starmus-btn--loading');
        } else {
            elements.submitBtn.textContent = 'Submit Recording';
            elements.submitBtn.classList.remove('starmus-btn--loading');
        }
    }

    if (elements.progressEl && elements.progressWrap) {
        elements.progressWrap.style.display = (status === 'submitting') ? 'block' : 'none';
        if (status === 'submitting') {
            elements.progressEl.style.width = `${(submission.progress || 0) * 100}%`;
        }
    }

    // 6. Transcript
    if (elements.transcriptBox) {
        if (source.transcript) {
            elements.transcriptBox.style.display = 'block';
            elements.transcriptBox.textContent = source.transcript;
        } else {
            elements.transcriptBox.style.display = 'none';
        }
    }

    // 7. Status Text
    const messageEl = elements.statusEl;
    if (messageEl) {
        let message = '';
        let msgClass = 'starmus-status';

        if (error) {
            msgClass += ' starmus-status--error';
            message = error.message || 'An error occurred.';
        } else {
            switch (status) {
                case 'idle': message = 'Please fill out details.'; break;
                case 'calibrating': message = calibration.message || 'Adjusting mic...'; break;
                case 'recording': message = 'Recording...'; msgClass += ' starmus-status--recording'; break;
                case 'ready_to_submit': 
                    message = submission.isQueued ? 'Offline. Saved to queue.' : 'Recording complete.'; 
                    break;
                case 'submitting': message = 'Uploading...'; break;
                case 'complete': 
                    message = submission.isQueued ? 'Saved to offline queue.' : 'Upload successful!'; 
                    msgClass += ' starmus-status--success'; 
                    break;
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
