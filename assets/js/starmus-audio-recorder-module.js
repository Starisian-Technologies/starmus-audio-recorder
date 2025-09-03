/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * NOTICE: All information contained herein is, and remains, the property of Starisian Technologies and its suppliers, if any.
 * The intellectual and technical concepts contained herein are proprietary to Starisian Technologies and its suppliers and may
 * be covered by U.S. and foreign patents, patents in process, and are protected by trade secret or copyright law.
 *
 * Dissemination of this information or reproduction of this material is strictly forbidden unless
 * prior written permission is obtained from Starisian Technologies.
 *
 * SPDX-License-Identifier:  LicenseRef-Starisian-Technologies-Proprietary
 * License URI:              https://github.com/Starisian-Technologies/starmus-audio-recorder/LICENSE.md
 *
 * @package Starmus\submissions
 * @since 0.1.0
 * @version 0.4.0
 * @file Starmus Audio Recorder Module - Secure, Feature-Complete Version
 * @version 0.4.0
 * @description Merges a secure, modular architecture with features from the original
 * recorder, including pause/resume, a live timer, robust UUID generation,
 * and legacy browser support. It is designed to work with the Starmus
 * submission handler and PHP backend.
 */

(function(window) {
    'use strict';

    // --- Feature detection and Polyfills ---
    var hasMediaRecorder = !!(window.MediaRecorder && window.navigator.mediaDevices);
    var hasCrypto = !!(window.crypto && (window.crypto.randomUUID || window.crypto.getRandomValues));
    var hasPromise = typeof Promise !== 'undefined';
    
    /**
     * Simple Promise polyfill for older browsers if not natively supported.
     */
    if (!hasPromise) {
        window.Promise = function(executor) {
            var self = this;
            self.state = 'pending';
            self.value = undefined;
            self.handlers = [];
            
            function resolve(result) {
                if (self.state === 'pending') {
                    self.state = 'fulfilled';
                    self.value = result;
                    self.handlers.forEach(handle);
                    self.handlers = null;
                }
            }
            
            function reject(error) {
                if (self.state === 'pending') {
                    self.state = 'rejected';
                    self.value = error;
                    self.handlers.forEach(handle);
                    self.handlers = null;
                }
            }
            
            function handle(handler) {
                if (self.state === 'pending') {
                    self.handlers.push(handler);
                } else {
                    if (self.state === 'fulfilled' && handler.onFulfilled) {
                        handler.onFulfilled(self.value);
                    }
                    if (self.state === 'rejected' && handler.onRejected) {
                        handler.onRejected(self.value);
                    }
                }
            }
            
            this.then = function(onFulfilled, onRejected) {
                return new Promise(function(resolve, reject) {
                    handle({
                        onFulfilled: function(result) {
                            try {
                                resolve(onFulfilled ? onFulfilled(result) : result);
                            } catch (ex) {
                                reject(ex);
                            }
                        },
                        onRejected: function(error) {
                            try {
                                resolve(onRejected ? onRejected(error) : error);
                            } catch (ex) {
                                reject(ex);
                            }
                        }
                    });
                });
            };
            
            try {
                executor(resolve, reject);
            } catch (ex) {
                reject(ex);
            }
        };
    }

    /**
     * Sanitizes a string for logging by removing special characters and truncating.
     * @param {string|*} input The input to sanitize.
     * @returns {string} The sanitized string.
     */
    function sanitizeForLog(input) {
        if (typeof input !== 'string') return String(input);
        return input.replace(/[\r\n\t<>]/g, ' ').substring(0, 100);
    }

    /**
     * Logs a message to the console securely after sanitizing inputs.
     * @param {string} level The console log level (e.g., 'log', 'warn', 'error').
     * @param {string} message The main message to log.
     * @param {*} [data] Optional data to include in the log.
     * @returns {void}
     */
    function secureLog(level, message, data) {
        if (typeof console === 'undefined' || !console[level]) return;
        var sanitizedMessage = sanitizeForLog(message);
        var sanitizedData = data ? sanitizeForLog(String(data)) : '';
        console[level]('[Starmus Recorder]', sanitizedMessage, sanitizedData);
    }

    /**
     * Validates that a form instance ID is a safe, non-empty string.
     * @param {string} id The ID to validate.
     * @returns {boolean} True if the ID is valid, false otherwise.
     */
    function validateFormInstanceId(id) {
        return typeof id === 'string' && /^[a-zA-Z0-9_-]+$/.test(id) && id.length < 100;
    }

    /**
     * Validates that a blob is a non-empty audio file within size limits.
     * @param {Blob} blob The Blob object to validate.
     * @returns {boolean} True if the blob is valid, false otherwise.
     */
    function validateAudioBlob(blob) {
        return blob instanceof Blob && blob.type.startsWith('audio/') && blob.size > 0 && blob.size < 50 * 1024 * 1024;
    }

    /**
     * The main public module for the Starmus Audio Recorder. This object is attached
     * to the window and serves as the public API for other scripts. It manages
     * multiple recorder instances, handles UI creation, and orchestrates uploads.
     * @namespace StarmusAudioRecorder
     */
    window.StarmusAudioRecorder = {
        /**
         * A map of active recorder instances, keyed by their formInstanceId.
         * @property {Object.<string, object>} instances
         */
        instances: {},
        
        /**
         * Initializes a recorder instance for a given form. This is the main public entry point.
         * @param {object} options - The initialization options.
         * @param {string} options.formInstanceId - The unique ID of the form instance to manage.
         * @returns {Promise<boolean>} A promise that resolves to true on successful initialization or rejects with an error.
         */
        init: function(options) {
            if (!hasMediaRecorder) {
                secureLog('error', 'MediaRecorder not supported in this browser.');
                return Promise.reject(new Error('MediaRecorder not supported'));
            }

            if (!options || !validateFormInstanceId(options.formInstanceId)) {
                secureLog('error', 'Invalid form instance ID');
                return Promise.reject(new Error('Invalid form instance ID'));
            }

            var instanceId = options.formInstanceId;
            
            if (this.instances[instanceId]) {
                secureLog('warn', 'Instance already exists', instanceId);
                return Promise.resolve(true);
            }

            return this.initializeInstance(instanceId);
        },

        /**
         * Internal helper to set up a new recorder instance state and acquire microphone permissions.
         * @private
         * @param {string} instanceId The unique ID for the new instance.
         * @returns {Promise<boolean>} A promise that resolves to true on success or rejects on error.
         */
        initializeInstance: function(instanceId) {
            var self = this;
            
            return new Promise(function(resolve, reject) {
                try {
                    navigator.mediaDevices.getUserMedia({ audio: true })
                        .then(function(stream) {
                            self.instances[instanceId] = {
                                stream: stream,
                                recorder: null,
                                chunks: [],
                                isRecording: false,
                                isPaused: false,
                                startTime: 0,
                                timerInterval: null
                            };
                            
                            self.setupUI(instanceId);
                            resolve(true);
                        })
                        .catch(function(error) {
                            secureLog('error', 'Media access denied', error.message);
                            reject(error);
                        });
                } catch (error) {
                    reject(error);
                }
            });
        },

        /**
         * Dynamically creates the recorder's HTML UI inside its designated container.
         * @param {string} instanceId The ID of the instance whose UI should be created. Must be alphanumeric/underscore/dash.
         * @returns {void}
         * @throws Will abort if the instanceId contains unsafe characters.
        setupUI: function(instanceId) {
            // Only allow safe HTML ID characters: a-zA-Z0-9_- (no spaces, punctuation)
            if (!/^[a-zA-Z0-9_-]+$/.test(instanceId)) {
                secureLog('error', 'Unsafe instanceId value rejected by setupUI:', instanceId);
                return;
            }

            var container = document.getElementById('starmus_recorder_container_' + instanceId);
            if (!container) return;

            container.innerHTML = [
                '<div class="starmus-recorder-status" id="starmus_recorder_status_' + instanceId + '">Ready to record</div>',
                '<div class="starmus-recorder-timer" id="starmus_timer_' + instanceId + '">00:00</div>',
                '<div class="starmus-recorder-controls">',
                '<button type="button" id="starmus_record_btn_' + instanceId + '" class="starmus-btn starmus-record-btn">Record</button>',
                '<button type="button" id="starmus_pause_btn_' + instanceId + '" class="starmus-btn starmus-pause-btn" style="display:none;">Pause</button>',
                '<button type="button" id="starmus_stop_btn_' + instanceId + '" class="starmus-btn starmus-stop-btn" disabled>Stop</button>',
                '<button type="button" id="starmus_play_btn_' + instanceId + '" class="starmus-btn starmus-play-btn" disabled>Play</button>',
                '</div>',
                '<audio id="starmus_audio_preview_' + instanceId + '" controls style="display:none;"></audio>'
            ].join('');

            this.bindEvents(instanceId);
        },

        /**
         * Attaches event listeners to the newly created UI elements for an instance.
         * @param {string} instanceId The ID of the instance whose events should be bound.
         * @returns {void}
         */
        bindEvents: function(instanceId) {
            var self = this;
            var recordBtn = document.getElementById('starmus_record_btn_' + instanceId);
            var stopBtn = document.getElementById('starmus_stop_btn_' + instanceId);
            var playBtn = document.getElementById('starmus_play_btn_' + instanceId);
            var pauseBtn = document.getElementById('starmus_pause_btn_' + instanceId);

            if (recordBtn) recordBtn.addEventListener('click', function() { self.startRecording(instanceId); });
            if (stopBtn) stopBtn.addEventListener('click', function() { self.stopRecording(instanceId); });
            if (playBtn) playBtn.addEventListener('click', function() { self.playRecording(instanceId); });
            if (pauseBtn) pauseBtn.addEventListener('click', function() { self.togglePause(instanceId); });
        },

        /**
         * Begins the recording process for a specific instance.
         * @param {string} instanceId The ID of the instance to start recording.
         * @returns {void}
         */
        startRecording: function(instanceId) {
            var instance = this.instances[instanceId];
            if (!instance || instance.isRecording) return;

            try {
                instance.recorder = new MediaRecorder(instance.stream);
                instance.chunks = [];
                instance.isRecording = true;
                instance.isPaused = false;
                instance.startTime = Date.now();

                instance.recorder.ondataavailable = function(event) {
                    if (event.data.size > 0) instance.chunks.push(event.data);
                };

                instance.recorder.onstop = function() {
                    var blob = new Blob(instance.chunks, { type: 'audio/webm' });
                    if (validateAudioBlob(blob)) {
                        instance.audioBlob = blob;
                        var audio = document.getElementById('starmus_audio_preview_' + instanceId);
                        if (audio) {
                            audio.src = URL.createObjectURL(blob);
                            audio.style.display = 'block';
                        }
                    }
                };

                instance.recorder.start();
                this.startTimer(instanceId);
                this.updateUI(instanceId, 'recording');
                
            } catch (error) {
                secureLog('error', 'Recording start failed', error.message);
                instance.isRecording = false;
            }
        },

        /**
         * Stops the recording process for a specific instance.
         * @param {string} instanceId The ID of the instance to stop recording.
         * @returns {void}
         */
        stopRecording: function(instanceId) {
            var instance = this.instances[instanceId];
            if (!instance || !instance.isRecording) return;

            try {
                instance.recorder.stop();
                instance.isRecording = false;
                instance.isPaused = false;
                this.stopTimer(instanceId);
                this.updateUI(instanceId, 'stopped');
                
                var submitBtn = document.getElementById('submit_button_' + instanceId);
                if (submitBtn) submitBtn.disabled = false;
                
            } catch (error) {
                secureLog('error', 'Recording stop failed', error.message);
            }
        },
        
        /**
         * Toggles the pause/resume state of a recording.
         * @param {string} instanceId The ID of the instance to pause/resume.
         * @returns {void}
         */
        togglePause: function(instanceId) {
            var instance = this.instances[instanceId];
            if (!instance || !instance.isRecording) return;
            if (instance.isPaused) this.resumeRecording(instanceId);
            else this.pauseRecording(instanceId);
        },

        /**
         * Pauses an active recording.
         * @param {string} instanceId The ID of the instance to pause.
         * @returns {void}
         */
        pauseRecording: function(instanceId) {
            var instance = this.instances[instanceId];
            if (instance && instance.recorder && instance.recorder.state === 'recording') {
                instance.recorder.pause();
                instance.isPaused = true;
                this.stopTimer(instanceId);
                this.updateUI(instanceId, 'paused');
            }
        },

        /**
         * Resumes a paused recording.
         * @param {string} instanceId The ID of the instance to resume.
         * @returns {void}
         */
        resumeRecording: function(instanceId) {
            var instance = this.instances[instanceId];
            if (instance && instance.recorder && instance.recorder.state === 'paused') {
                instance.recorder.resume();
                instance.isPaused = false;
                this.startTimer(instanceId);
                this.updateUI(instanceId, 'recording');
            }
        },

        /**
         * Plays the recorded audio preview for an instance.
         * @param {string} instanceId The ID of the instance whose audio should be played.
         * @returns {void}
         */
        playRecording: function(instanceId) {
            var audio = document.getElementById('starmus_audio_preview_' + instanceId);
            if (audio && audio.src) {
                audio.play().catch(function(error) {
                    secureLog('error', 'Playback failed', error.message);
                });
            }
        },

        /**
         * Starts the visual timer for a recording instance.
         * @param {string} instanceId The ID of the instance.
         * @returns {void}
         */
        startTimer: function(instanceId) {
            var self = this;
            var instance = this.instances[instanceId];
            if (!instance) return;
            
            this.stopTimer(instanceId);
            instance.timerInterval = setInterval(function() { self.updateTimer(instanceId); }, 1000);
        },

        /**
         * Stops the visual timer for a recording instance.
         * @param {string} instanceId The ID of the instance.
         * @returns {void}
         */
        stopTimer: function(instanceId) {
            var instance = this.instances[instanceId];
            if (instance && instance.timerInterval) {
                clearInterval(instance.timerInterval);
                instance.timerInterval = null;
            }
        },

        /**
         * Updates the timer display with the elapsed recording time.
         * @param {string} instanceId The ID of the instance.
         * @returns {void}
         */
        updateTimer: function(instanceId) {
            var instance = this.instances[instanceId];
            var timerEl = document.getElementById('starmus_timer_' + instanceId);
            if (!instance || !timerEl) return;
            
            var elapsed = Date.now() - instance.startTime;
            var minutes = Math.floor(elapsed / 60000);
            var seconds = Math.floor((elapsed % 60000) / 1000);
            
            timerEl.textContent = 
                (minutes < 10 ? '0' : '') + minutes + ':' +
                (seconds < 10 ? '0' : '') + seconds;
        },

        /**
         * Updates the UI buttons and status messages based on the recorder's state.
         * @param {string} instanceId The ID of the instance to update.
         * @param {string} state The new state ('recording', 'paused', 'stopped', or default 'ready').
         * @returns {void}
         */
        updateUI: function(instanceId, state) {
            var recordBtn = document.getElementById('starmus_record_btn_' + instanceId);
            var stopBtn = document.getElementById('starmus_stop_btn_' + instanceId);
            var playBtn = document.getElementById('starmus_play_btn_' + instanceId);
            var pauseBtn = document.getElementById('starmus_pause_btn_' + instanceId);
            var status = document.getElementById('starmus_recorder_status_' + instanceId);
            var timer = document.getElementById('starmus_timer_' + instanceId);

            if (!recordBtn || !stopBtn || !playBtn || !status || !pauseBtn || !timer) return;

            switch (state) {
                case 'recording':
                    recordBtn.disabled = true; stopBtn.disabled = false; playBtn.disabled = true;
                    pauseBtn.disabled = false; pauseBtn.textContent = 'Pause'; pauseBtn.style.display = 'inline-block';
                    status.textContent = 'Recording...';
                    break;
                case 'paused':
                    recordBtn.disabled = true; stopBtn.disabled = false; playBtn.disabled = true;
                    pauseBtn.disabled = false; pauseBtn.textContent = 'Resume';
                    status.textContent = 'Paused';
                    break;
                case 'stopped':
                    recordBtn.disabled = false; stopBtn.disabled = true; playBtn.disabled = false;
                    pauseBtn.style.display = 'none';
                    status.textContent = 'Recording complete';
                    break;
                default:
                    recordBtn.disabled = false; stopBtn.disabled = true; playBtn.disabled = true;
                    pauseBtn.style.display = 'none';
                    status.textContent = 'Ready to record';
                    timer.textContent = '00:00';
            }
        },

        /**
         * Initiates the upload process for a completed recording. Called by the submission handler.
         * @param {string} instanceId The ID of the instance to submit.
         * @returns {Promise<object>} A promise that resolves with the server's JSON response or rejects with an error.
         */
        submit: function(instanceId) {
            var instance = this.instances[instanceId];
            if (!instance || !instance.audioBlob) return Promise.reject(new Error('No audio recording available'));
            if (!validateAudioBlob(instance.audioBlob)) return Promise.reject(new Error('Invalid audio data'));
            return this.uploadAudio(instanceId, instance.audioBlob);
        },

        /**
         * Handles the chunked file upload to the REST API.
         * @private
         * @param {string} instanceId The ID of the instance being uploaded.
         * @param {Blob} audioBlob The complete audio data to upload.
         * @returns {Promise<object>} A promise that resolves with the server's final JSON response or rejects with an error.
         */
        uploadAudio: function(instanceId, audioBlob) {
            var self = this;
            var chunkSize = 1024 * 1024; // 1MB chunks
            var totalSize = audioBlob.size;
            var offset = 0;
            var uuid = this.generateUUID();

            return new Promise(function(resolve, reject) {
                function uploadChunk() {
                    var chunk = audioBlob.slice(offset, offset + chunkSize);
                    var formData = new FormData();
                    
                    formData.append('audio_file', chunk);
                    formData.append('audio_uuid', uuid);
                    formData.append('chunk_offset', offset);
                    formData.append('total_size', totalSize);
                    formData.append('fileName', 'recording.webm');

                    var form = document.getElementById(instanceId);
                    if (form) {
                        var formDataEntries = new FormData(form);
                        for (var pair of formDataEntries.entries()) {
                            formData.append(pair[0], pair[1]);
                        }
                    }

                    fetch(starmusFormData.rest_url, {
                        method: 'POST',
                        headers: { 'X-WP-Nonce': starmusFormData.rest_nonce },
                        body: formData
                    })
                    .then(function(response) {
                        if (!response.ok) throw new Error('Upload failed: ' + response.status);
                        return response.json();
                    })
                    .then(function(data) {
                        offset += chunk.size;
                        if (offset >= totalSize) resolve(data);
                        else uploadChunk();
                    })
                    .catch(reject);
                }
                uploadChunk();
            });
        },
        
        /**
         * Generates a robust, cryptographically secure UUID with fallbacks for older browsers.
         * NOTE: This UUID is used only for chunked upload tracking, not for security purposes.
         * The Math.random() fallback is acceptable for this non-security context.
         * @returns {string} The generated UUID.
         */
        generateUUID: function() {
            if (hasCrypto && window.crypto.randomUUID) {
                try { return window.crypto.randomUUID(); } catch (e) {}
            }
            if (hasCrypto && window.crypto.getRandomValues) {
                try {
                    var buffer = new Uint8Array(16);
                    window.crypto.getRandomValues(buffer);
                    buffer[6] = (buffer[6] & 0x0f) | 0x40; // Version 4
                    buffer[8] = (buffer[8] & 0x3f) | 0x80; // Variant
                    var hex = [];
                    for (var i = 0; i < buffer.length; i++) {
                        hex.push(('0' + buffer[i].toString(16)).slice(-2));
                    }
                    return [
                        hex.slice(0, 4).join(''), hex.slice(4, 6).join(''),
                        hex.slice(6, 8).join(''), hex.slice(8, 10).join(''),
                        hex.slice(10).join('')
                    ].join('-');
                } catch (e) {}
            }
            // Fallback for legacy browsers - Math.random() is acceptable here as this UUID
            // is only used for upload chunk tracking, not for security-sensitive operations
            var d = new Date().getTime();
            if (window.performance && window.performance.now) d += window.performance.now();
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                var r = (d + Math.random() * 16) % 16 | 0; // lgtm[js/insecure-randomness]
                d = Math.floor(d / 16);
                return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
            });
        },

        /**
         * Stops media streams and removes an instance from memory to free up resources.
         * @param {string} instanceId The ID of the instance to clean up.
         * @returns {void}
         */
        cleanup: function(instanceId) {
            var instance = this.instances[instanceId];
            if (instance) {
                if (instance.stream) {
                    instance.stream.getTracks().forEach(function(track) { track.stop(); });
                }
                this.stopTimer(instanceId);
                delete this.instances[instanceId];
            }
        }
    };

})(window);