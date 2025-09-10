/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * @module starmus-audio-recorder
 * @version 0.5.1
 * @file Starmus Audio Recorder Module - Secure, Hardened & Compatible
 * @description Secure, standalone audio recording engine with robust validation and improved legacy browser compatibility.
 */
(function(window) {
    'use strict';

    // --- Feature Detection ---
    var hasMediaRecorder = !!(window.MediaRecorder && window.navigator.mediaDevices);

    // --- Constants ---
    var ALLOWED_TYPES = [
        'audio/webm;codecs=opus',
        'audio/webm',
        'audio/ogg;codecs=opus', 
        'audio/ogg',
        'audio/mpeg',
        'audio/wav'
    ];

    function canUseMime(mime){
        try {
            return !!(window.MediaRecorder && typeof MediaRecorder.isTypeSupported==='function' && MediaRecorder.isTypeSupported(mime));
        } catch(_) { return false; }
    }

    function pickBestMime(){
        var order = [
            'audio/webm;codecs=opus',
            'audio/ogg;codecs=opus',
            'audio/webm',
            'audio/ogg'
        ];
        for (var i=0;i<order.length;i++){
            if (canUseMime(order[i])) return order[i];
        }
        return '';
    }

    // --- Secure Utilities ---

    function isSafeId(id) {
        if (typeof id !== 'string' || id.length === 0 || id.length > 100) {
            return false;
        }
        return /^[a-zA-Z0-9_-]+$/.test(id);
    }

    function sanitizeForLog(input) {
        if (typeof input !== 'string') return String(input);
        return input.replace(/[\u0020-\u007E]/g, function(match) { return /[<>"'&]/.test(match) ? ' ' : match; }).substring(0, 200);
    }

    function secureLog(level, message, data) {
        if (typeof console === 'undefined' || !console[level]) return;
        var sanitizedMessage = sanitizeForLog(message);
        var sanitizedData = data ? sanitizeForLog(String(data)) : '';
        console[level]('[Starmus Recorder]', sanitizedMessage, sanitizedData);
    }

    function validateAudioBlob(blob) {
        var MAX_BLOB_SIZE = 50 * 1024 * 1024;
        var MIN_BLOB_SIZE = 100;

        if (!(blob instanceof Blob)) return false;

        var isTypeAllowed = false;
        for (var i = 0; i < ALLOWED_TYPES.length; i++) {
            if (blob.type.startsWith(ALLOWED_TYPES[i].split(';')[0])) {
                isTypeAllowed = true;
                break;
            }
        }

        return isTypeAllowed && blob.size >= MIN_BLOB_SIZE && blob.size <= MAX_BLOB_SIZE;
    }

    // --- Main Public Module ---

    window.StarmusAudioRecorder = {
        instances: {},

        init: function(options) {
            var self = this;
            return new Promise(function(resolve, reject) {
                if (!hasMediaRecorder) {
                    var err = new Error('MediaRecorder API is not supported in this browser.');
                    secureLog('error', err.message);
                    return reject(err);
                }

                if (!options || !isSafeId(options.formInstanceId)) {
                    var validationError = new Error('Invalid or unsafe form instance ID provided.');
                    secureLog('error', validationError.message);
                    return reject(validationError);
                }

                var instanceId = options.formInstanceId;

                if (self.instances[instanceId]) {
                    secureLog('warn', 'Instance already exists, skipping re-initialization.', instanceId);
                    return resolve(true);
                }

                navigator.mediaDevices.getUserMedia({ audio: true })
                    .then(function(stream) {
                        // Create audio context and analyser for calibration
                        var audioContext = new (window.AudioContext || window.webkitAudioContext)();
                        var source = audioContext.createMediaStreamSource(stream);
                        var analyser = audioContext.createAnalyser();
                        var gainNode = audioContext.createGain();
                        
                        analyser.fftSize = 2048;
                        source.connect(analyser);
                        analyser.connect(gainNode);

                        self.instances[instanceId] = {
                            stream: stream,
                            recorder: null,
                            chunks: [],
                            audioBlob: null,
                            isRecording: false,
                            isPaused: false,
                            startTime: 0,
                            timerInterval: null,
                            ctx: audioContext,
                            analyser: analyser,
                            gain: gainNode,
                            isCalibrated: false,
                            tier: 'A'
                        };

                        self.setupUI(instanceId);
                        resolve({ ok: true, tier: 'A' });
                    })
                    .catch(function(error) {
                        secureLog('error', 'Microphone access was denied.', error.message);
                        var userError = new Error('Microphone permission is required to record audio.');
                        var status = document.getElementById('starmus_recorder_status_' + instanceId);
                        if(status) status.textContent = userError.message;
                        reject(userError);
                    });
            });
        },

        setupUI: function(instanceId) {
            if (!isSafeId(instanceId)) return;

            var container = document.getElementById('starmus_recorder_container_' + instanceId);
            if (!container) {
                secureLog('error', 'UI container not found for instanceId:', instanceId);
                return;
            }

            container.textContent = ''; // Clear container safely

            var statusDiv = document.createElement('div');
            statusDiv.className = 'starmus-recorder-status';
            statusDiv.id = 'starmus_recorder_status_' + instanceId;
            statusDiv.textContent = 'Ready to record';

            var timerDiv = document.createElement('div');
            timerDiv.className = 'starmus-recorder-timer';
            timerDiv.id = 'starmus_timer_' + instanceId;
            timerDiv.textContent = '00:00';

            var controlsDiv = document.createElement('div');
            controlsDiv.className = 'starmus-recorder-controls';

            var calibrateBtn = document.createElement('button');
            calibrateBtn.type = 'button';
            calibrateBtn.id = 'starmus_calibrate_btn_' + instanceId;
            calibrateBtn.className = 'starmus-btn starmus-calibrate-btn';
            calibrateBtn.textContent = 'Test Microphone';

            var calibrationStatus = document.createElement('div');
            calibrationStatus.id = 'starmus_calibration_status_' + instanceId;
            calibrationStatus.className = 'starmus-calibration-status';
            calibrationStatus.textContent = 'Click "Test Microphone" to calibrate audio levels';

            var volumeMeter = document.createElement('div');
            volumeMeter.className = 'starmus-volume-meter';
            volumeMeter.style.cssText = 'width:100%;height:20px;background:#f0f0f0;border:1px solid #ccc;margin:10px 0;position:relative;display:none';
            
            var volumeBar = document.createElement('div');
            volumeBar.id = 'starmus_volume_bar_' + instanceId;
            volumeBar.style.cssText = 'height:100%;background:linear-gradient(to right,#4CAF50 0%,#FFEB3B 70%,#F44336 100%);width:0%;transition:width 0.1s';
            
            var volumeLabel = document.createElement('div');
            volumeLabel.style.cssText = 'position:absolute;top:2px;left:5px;font-size:12px;color:#333';
            volumeLabel.textContent = 'Volume Level';
            
            volumeMeter.appendChild(volumeBar);
            volumeMeter.appendChild(volumeLabel);

            var recordBtn = document.createElement('button');
            recordBtn.type = 'button';
            recordBtn.id = 'starmus_record_btn_' + instanceId;
            recordBtn.className = 'starmus-btn starmus-record-btn';
            recordBtn.textContent = 'Record';
            recordBtn.disabled = true;

            var pauseBtn = document.createElement('button');
            pauseBtn.type = 'button';
            pauseBtn.id = 'starmus_pause_btn_' + instanceId;
            pauseBtn.className = 'starmus-btn starmus-pause-btn';
            pauseBtn.style.display = 'none';
            pauseBtn.textContent = 'Pause';

            var stopBtn = document.createElement('button');
            stopBtn.type = 'button';
            stopBtn.id = 'starmus_stop_btn_' + instanceId;
            stopBtn.className = 'starmus-btn starmus-stop-btn';
            stopBtn.disabled = true;
            stopBtn.textContent = 'Stop';

            var playBtn = document.createElement('button');
            playBtn.type = 'button';
            playBtn.id = 'starmus_play_btn_' + instanceId;
            playBtn.className = 'starmus-btn starmus-play-btn';
            playBtn.disabled = true;
            playBtn.textContent = 'Play';

            var audioPreview = document.createElement('audio');
            audioPreview.id = 'starmus_audio_preview_' + instanceId;
            audioPreview.controls = true;
            audioPreview.style.display = 'none';
            audioPreview.style.width = '100%';
            audioPreview.style.marginTop = '10px';

            controlsDiv.appendChild(calibrateBtn);
            controlsDiv.appendChild(recordBtn);
            controlsDiv.appendChild(pauseBtn);
            controlsDiv.appendChild(stopBtn);
            controlsDiv.appendChild(playBtn);

            container.appendChild(statusDiv);
            container.appendChild(calibrationStatus);
            container.appendChild(volumeMeter);
            container.appendChild(timerDiv);
            container.appendChild(controlsDiv);
            container.appendChild(audioPreview);

            this.bindEvents(instanceId);
        },

        bindEvents: function(instanceId) {
            if (!isSafeId(instanceId)) return;
            var self = this; // Maintain reference to the main object

            var calibrateBtn = document.getElementById('starmus_calibrate_btn_' + instanceId);
            var recordBtn = document.getElementById('starmus_record_btn_' + instanceId);
            var stopBtn = document.getElementById('starmus_stop_btn_' + instanceId);
            var playBtn = document.getElementById('starmus_play_btn_' + instanceId);
            var pauseBtn = document.getElementById('starmus_pause_btn_' + instanceId);

            if (calibrateBtn) calibrateBtn.addEventListener('click', function() { self.calibrate(instanceId); });
            if (recordBtn) recordBtn.addEventListener('click', function() { self.startRecording(instanceId); });
            if (stopBtn) stopBtn.addEventListener('click', function() { self.stopRecording(instanceId); });
            if (playBtn) playBtn.addEventListener('click', function() { self.playRecording(instanceId); });
            if (pauseBtn) pauseBtn.addEventListener('click', function() { self.togglePause(instanceId); });
        },

        calibrate: function(instanceId) {
            if (!isSafeId(instanceId) || !this.instances[instanceId]) return;
            var instance = this.instances[instanceId];
            if (instance.tier !== 'A') return;
            
            var label = document.getElementById('starmus_calibration_status_' + instanceId);
            var volumeMeter = document.querySelector('#' + instanceId + ' .starmus-volume-meter');
            var volumeBar = document.getElementById('starmus_volume_bar_' + instanceId);
            var analyser = instance.analyser;
            var buffer = new Float32Array(analyser.fftSize);
            
            if (volumeMeter) volumeMeter.style.display = 'block';
            
            var samples = [];
            var startTime = performance.now();
            var DUR = 10000; // 10 seconds
            // Calibration phases: quiet -> speak -> quiet
            
            function updateInstructions(elapsed) {
                var remaining = Math.ceil((DUR - elapsed) / 1000);
                if (elapsed < 3000) {
                    if (label) label.textContent = 'Be quiet for background noise (' + remaining + 's)';
                } else if (elapsed < 7000) {
                    if (label) label.textContent = 'Now speak normally (' + remaining + 's)';
                } else {
                    if (label) label.textContent = 'Be quiet again (' + remaining + 's)';
                }
            }
            
            function tick() {
                var elapsed = performance.now() - startTime;
                updateInstructions(elapsed);
                
                analyser.getFloatTimeDomainData(buffer);
                var sum = 0;
                for (var i = 0; i < buffer.length; i++) {
                    sum += buffer[i] * buffer[i];
                }
                var rms = Math.sqrt(sum / buffer.length);
                samples.push(rms);
                
                // Update volume bar (0-100%)
                var volumePercent = Math.min(100, rms * 2000);
                if (volumeBar) volumeBar.style.width = volumePercent + '%';
                
                if (elapsed < DUR) {
                    requestAnimationFrame(tick);
                } else {
                    done();
                }
            }
            
            function done() {
                var avg = samples.reduce(function(a, b) { return a + b; }, 0) / Math.max(1, samples.length);
                var target = 0.05;
                var gain = Math.max(0.5, Math.min(8.0, target / Math.max(1e-6, avg)));
                
                instance.gain.gain.setTargetAtTime(gain, instance.ctx.currentTime, 0.01);
                instance.isCalibrated = true;
                
                if (label) label.textContent = 'Mic calibrated (gain×' + gain.toFixed(2) + '). Ready to record.';
                if (volumeMeter) volumeMeter.style.display = 'none';
                
                var recordBtn = document.getElementById('starmus_record_btn_' + instanceId);
                if (recordBtn) recordBtn.disabled = false;
                
                secureLog('log', 'Calibration complete: avgRMS=' + avg.toFixed(4) + ' gain=' + gain.toFixed(2));
            }
            
            tick();
        },

        startRecording: function(instanceId) {
            if (!isSafeId(instanceId) || !this.instances[instanceId]) return;
            var instance = this.instances[instanceId];
            if (instance.isRecording || !instance.isCalibrated) return;
            
            // Show volume meter during recording
            var volumeMeter = document.querySelector('#' + instanceId + ' .starmus-volume-meter');
            if (volumeMeter) volumeMeter.style.display = 'block';
            
            // Start volume monitoring
            this.startVolumeMonitoring(instanceId);

            try {
                var mimeType = pickBestMime();
                var cfg = mimeType ? { mimeType: mimeType } : {};
                try {
                    instance.recorder = new MediaRecorder(instance.stream, cfg);
                } catch (e) {
                    instance.recorder = new MediaRecorder(instance.stream);
                }
                instance.chunks = [];
                instance.audioBlob = null;
                instance.isRecording = true;
                instance.isPaused = false;
                instance.startTime = Date.now();

                instance.recorder.ondataavailable = function(event) {
                    if (event.data && event.data.size > 0) {
                        instance.chunks.push(event.data);
                    }
                };

                var self = this;
                instance.recorder.onerror = function(event) {
                    secureLog('error', 'MediaRecorder error', event.error ? event.error.message : 'Unknown error');
                    instance.isRecording = false;
                    self.updateUI(instanceId, 'error');
                };

                var self = this;
                instance.recorder.onstop = function() {
                    try {
                        var type = (instance.recorder && instance.recorder.mimeType) || mimeType || 'audio/webm';
                        var blob = new Blob(instance.chunks, { type: type });

                        if (validateAudioBlob(blob)) {
                            instance.audioBlob = blob;
                            var audio = document.getElementById('starmus_audio_preview_' + instanceId);
                            if (audio) {
                                if (audio.src && audio.src.indexOf('blob:') === 0) { 
                                    try { 
                                        URL.revokeObjectURL(audio.src); 
                                    } catch(revokeError) {
                                        secureLog('warn', 'Failed to revoke blob URL', revokeError.message);
                                    } 
                                }
                                audio.src = URL.createObjectURL(blob);
                            }
                        } else {
                            secureLog('error', 'Generated audio blob invalid/too large');
                            self.updateUI(instanceId, 'error');
                        }
                    } catch (error) {
                        secureLog('error', 'Error processing recorded audio', error.message);
                        self.updateUI(instanceId, 'error');
                    }
                };

                instance.recorder.start(1000); // 1 second chunks
                this.startTimer(instanceId);
                this.updateUI(instanceId, 'recording');

            } catch (error) {
                secureLog('error', 'Failed to start recording.', error.message);
                instance.isRecording = false;
                this.updateUI(instanceId, 'error');
            }
        },

        stopRecording: function(instanceId) {
            if (!isSafeId(instanceId) || !this.instances[instanceId]) return;
            var instance = this.instances[instanceId];
            if (!instance.isRecording || !instance.recorder) return;
            try {
                if (instance.recorder && instance.recorder.state !== 'inactive') {
                    instance.recorder.stop();
                }
                instance.isRecording = false;
                instance.isPaused = false;
                this.stopTimer(instanceId);
                this.stopVolumeMonitoring(instanceId);
                
                // Hide volume meter
                var volumeMeter = document.querySelector('#' + instanceId + ' .starmus-volume-meter');
                if (volumeMeter) volumeMeter.style.display = 'none';
                
                this.updateUI(instanceId, 'stopped');
            } catch (error) {
                secureLog('error', 'Failed to stop recording.', error.message);
            }
        },

        startVolumeMonitoring: function(instanceId) {
            if (!isSafeId(instanceId) || !this.instances[instanceId]) return;
            var instance = this.instances[instanceId];
            var volumeBar = document.getElementById('starmus_volume_bar_' + instanceId);
            
            if (!volumeBar || !instance.analyser) return;
            
            var buffer = new Float32Array(instance.analyser.fftSize);
            function updateVolume() {
                if (!instance.isRecording) return;
                
                instance.analyser.getFloatTimeDomainData(buffer);
                var sum = 0;
                for (var i = 0; i < buffer.length; i++) {
                    sum += buffer[i] * buffer[i];
                }
                var rms = Math.sqrt(sum / buffer.length);
                var volumePercent = Math.min(100, rms * 2000);
                volumeBar.style.width = volumePercent + '%';
                
                requestAnimationFrame(updateVolume);
            }
            
            updateVolume();
        },

        stopVolumeMonitoring: function(_instanceId) {
            // Volume monitoring stops automatically when isRecording becomes false
            return true;
        },

        togglePause: function(instanceId) {
            if (!isSafeId(instanceId) || !this.instances[instanceId]) return;
            var instance = this.instances[instanceId];
            if (!instance.isRecording) return;
            if (instance.isPaused) {
                this.resumeRecording(instanceId);
            } else {
                this.pauseRecording(instanceId);
            }
        },

        pauseRecording: function(instanceId) {
            if (!isSafeId(instanceId) || !this.instances[instanceId]) return;
            var instance = this.instances[instanceId];
            if (instance.recorder && instance.recorder.state === 'recording') {
                instance.recorder.pause();
                instance.isPaused = true;
                this.stopTimer(instanceId);
                this.updateUI(instanceId, 'paused');
            }
        },

        resumeRecording: function(instanceId) {
            if (!isSafeId(instanceId) || !this.instances[instanceId]) return;
            var instance = this.instances[instanceId];
            if (instance.recorder && instance.recorder.state === 'paused') {
                instance.recorder.resume();
                instance.isPaused = false;
                this.startTimer(instanceId);
                this.updateUI(instanceId, 'recording');
            }
        },

        playRecording: function(instanceId) {
            if (!isSafeId(instanceId)) return;
            var audio = document.getElementById('starmus_audio_preview_' + instanceId);
            if (audio && audio.src) {
                if (audio.ended) {
                    audio.currentTime = 0;
                }
                audio.play().catch(function(error) {
                    secureLog('error', 'Audio playback failed.', error.message);
                    var status = document.getElementById('starmus_recorder_status_' + instanceId);
                    if (status) {
                        status.textContent = sanitizeForLog('Playback failed. Please try again.');
                    }
                });
            }
        },

        startTimer: function(instanceId) {
            if (!isSafeId(instanceId) || !this.instances[instanceId]) return;
            var instance = this.instances[instanceId];
            var self = this;
            this.stopTimer(instanceId);
            instance.timerInterval = setInterval(function() { self.updateTimer(instanceId); }, 1000);
        },

        stopTimer: function(instanceId) {
            if (!isSafeId(instanceId) || !this.instances[instanceId]) return;
            var instance = this.instances[instanceId];
            if (instance.timerInterval) {
                clearInterval(instance.timerInterval);
                instance.timerInterval = null;
            }
        },

        updateTimer: function(instanceId) {
            if (!isSafeId(instanceId) || !this.instances[instanceId]) return;
            var instance = this.instances[instanceId];
            var timerEl = document.getElementById('starmus_timer_' + instanceId);
            if (!timerEl) return;

            var elapsed = Math.max(0, Date.now() - instance.startTime);
            var minutes = Math.floor(elapsed / 60000);
            var seconds = Math.floor((elapsed % 60000) / 1000);

            timerEl.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
        },

        updateUI: function(instanceId, state) {
            if (!isSafeId(instanceId)) return;

            var elements = {
                recordBtn: document.getElementById('starmus_record_btn_' + instanceId),
                stopBtn: document.getElementById('starmus_stop_btn_' + instanceId),
                playBtn: document.getElementById('starmus_play_btn_' + instanceId),
                pauseBtn: document.getElementById('starmus_pause_btn_' + instanceId),
                status: document.getElementById('starmus_recorder_status_' + instanceId),
                timer: document.getElementById('starmus_timer_' + instanceId),
                audioPreview: document.getElementById('starmus_audio_preview_' + instanceId)
            };

            var allElementsExist = true;
            for (var key in elements) {
                if (!elements[key]) {
                    allElementsExist = false;
                    break;
                }
            }
            if (!allElementsExist) return;

            var statusMessages = {
                recording: 'Recording...',
                paused: 'Paused',
                stopped: 'Recording complete. Ready for submission.',
                error: 'An error occurred. Please refresh and try again.',
                ready: 'Ready to record'
            };

            elements.recordBtn.disabled = false;
            elements.stopBtn.disabled = true;
            elements.playBtn.disabled = true;
            elements.pauseBtn.style.display = 'none';
            elements.audioPreview.style.display = 'none';

            switch (state) {
                case 'recording':
                    elements.recordBtn.disabled = true;
                    elements.stopBtn.disabled = false;
                    elements.pauseBtn.disabled = false;
                    elements.pauseBtn.textContent = 'Pause';
                    elements.pauseBtn.style.display = 'inline-block';
                    break;
                case 'paused':
                    elements.recordBtn.disabled = true;
                    elements.stopBtn.disabled = false;
                    elements.pauseBtn.disabled = false;
                    elements.pauseBtn.textContent = 'Resume';
                    elements.pauseBtn.style.display = 'inline-block';
                    break;
                case 'stopped':
                    elements.playBtn.disabled = false;
                    elements.audioPreview.style.display = 'block';
                    break;
                case 'error':
                    elements.recordBtn.disabled = true;
                    break;
                default: // 'ready'
                    elements.timer.textContent = '00:00';
                    break;
            }
            elements.status.textContent = statusMessages[state] || statusMessages.ready;
        },

        getSubmissionData: function(instanceId) {
            if (!isSafeId(instanceId) || !this.instances[instanceId]) return null;
            var instance = this.instances[instanceId];
            
            if (instance.audioBlob && validateAudioBlob(instance.audioBlob)) {
                var t = instance.audioBlob.type || '';
                var ext = 'webm';
                if (t.indexOf('ogg')>=0) ext='ogg';
                else if (t.indexOf('mpeg')>=0) ext='mp3';
                else if (t.indexOf('wav')>=0) ext='wav';
                else if (t.indexOf('webm')>=0) ext='webm';
                
                var startedAt = instance.startTime ? new Date(instance.startTime).toISOString() : null;
                var durationMs = instance.startTime ? Math.max(0, Date.now()-instance.startTime) : null;

                return {
                    blob: instance.audioBlob,
                    fileName: 'starmus-recording.' + ext,
                    mimeType: t || 'audio/webm',
                    size: instance.audioBlob.size,
                    metadata: { startedAt: startedAt, durationMs: durationMs }
                };
            }
            return null;
        },

        cleanup: function(instanceId) {
            if (!isSafeId(instanceId) || !this.instances[instanceId]) return;
            var instance = this.instances[instanceId];

            if (instance.stream) {
                instance.stream.getTracks().forEach(function(track) { track.stop(); });
            }

            if (instance.recorder) {
                try {
                    if (instance.recorder.state !== 'inactive') {
                        instance.recorder.stop();
                    }
                } catch (e) {
                    secureLog('warn', 'Error stopping recorder during cleanup', e.message);
                }
            }

            var audio = document.getElementById('starmus_audio_preview_' + instanceId);
            if (audio && audio.src && audio.src.startsWith('blob:')) {
                URL.revokeObjectURL(audio.src);
            }

            this.stopTimer(instanceId);
            this.stopVolumeMonitoring(instanceId);
            this.instances[instanceId] = null;
            delete this.instances[instanceId];
        }
    };

})(window);
