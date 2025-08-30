/**
 * Starmus Audio Recorder Module - Secure Version
 * Fixes security vulnerabilities and adds proper input validation
 */

(function() {
    'use strict';

    // Secure logging with sanitization
    function sanitizeForLog(input) {
        if (typeof input !== 'string') return String(input);
        return input.replace(/[\r\n\t<>]/g, ' ').substring(0, 100);
    }

    function secureLog(level, message, data) {
        if (typeof console === 'undefined' || !console[level]) return;
        var sanitizedMessage = sanitizeForLog(message);
        var sanitizedData = data ? sanitizeForLog(String(data)) : '';
        console[level]('[Starmus Recorder]', sanitizedMessage, sanitizedData);
    }

    // Input validation helpers
    function validateFormInstanceId(id) {
        return typeof id === 'string' && /^[a-zA-Z0-9_-]+$/.test(id) && id.length < 100;
    }

    function validateAudioBlob(blob) {
        return blob instanceof Blob && blob.type.startsWith('audio/') && blob.size > 0 && blob.size < 50 * 1024 * 1024;
    }

    // Main recorder object
    window.StarmusAudioRecorder = {
        instances: {},
        
        init: function(options) {
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

        initializeInstance: function(instanceId) {
            var self = this;
            
            return new Promise(function(resolve, reject) {
                try {
                    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                        reject(new Error('MediaRecorder not supported'));
                        return;
                    }

                    navigator.mediaDevices.getUserMedia({ audio: true })
                        .then(function(stream) {
                            self.instances[instanceId] = {
                                stream: stream,
                                recorder: null,
                                chunks: [],
                                isRecording: false
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

        setupUI: function(instanceId) {
            var container = document.getElementById('starmus_recorder_container_' + instanceId);
            if (!container) return;

            container.innerHTML = [
                '<div class="starmus-recorder-controls">',
                '<button type="button" id="starmus_record_btn_' + instanceId + '" class="starmus-btn starmus-record-btn">Record</button>',
                '<button type="button" id="starmus_stop_btn_' + instanceId + '" class="starmus-btn starmus-stop-btn" disabled>Stop</button>',
                '<button type="button" id="starmus_play_btn_' + instanceId + '" class="starmus-btn starmus-play-btn" disabled>Play</button>',
                '</div>',
                '<div class="starmus-recorder-status" id="starmus_recorder_status_' + instanceId + '">Ready to record</div>',
                '<audio id="starmus_audio_preview_' + instanceId + '" controls style="display:none;"></audio>'
            ].join('');

            this.bindEvents(instanceId);
        },

        bindEvents: function(instanceId) {
            var self = this;
            var recordBtn = document.getElementById('starmus_record_btn_' + instanceId);
            var stopBtn = document.getElementById('starmus_stop_btn_' + instanceId);
            var playBtn = document.getElementById('starmus_play_btn_' + instanceId);

            if (recordBtn) {
                recordBtn.addEventListener('click', function() {
                    self.startRecording(instanceId);
                });
            }

            if (stopBtn) {
                stopBtn.addEventListener('click', function() {
                    self.stopRecording(instanceId);
                });
            }

            if (playBtn) {
                playBtn.addEventListener('click', function() {
                    self.playRecording(instanceId);
                });
            }
        },

        startRecording: function(instanceId) {
            var instance = this.instances[instanceId];
            if (!instance || instance.isRecording) return;

            try {
                instance.recorder = new MediaRecorder(instance.stream);
                instance.chunks = [];
                instance.isRecording = true;

                instance.recorder.ondataavailable = function(event) {
                    if (event.data.size > 0) {
                        instance.chunks.push(event.data);
                    }
                };

                instance.recorder.onstop = function() {
                    var blob = new Blob(instance.chunks, { type: 'audio/webm' });
                    if (validateAudioBlob(blob)) {
                        instance.audioBlob = blob;
                        var url = URL.createObjectURL(blob);
                        var audio = document.getElementById('starmus_audio_preview_' + instanceId);
                        if (audio) {
                            audio.src = url;
                            audio.style.display = 'block';
                        }
                    }
                };

                instance.recorder.start();
                this.updateUI(instanceId, 'recording');
                
            } catch (error) {
                secureLog('error', 'Recording start failed', error.message);
                instance.isRecording = false;
            }
        },

        stopRecording: function(instanceId) {
            var instance = this.instances[instanceId];
            if (!instance || !instance.isRecording) return;

            try {
                instance.recorder.stop();
                instance.isRecording = false;
                this.updateUI(instanceId, 'stopped');
                
                // Enable submit button
                var submitBtn = document.getElementById('submit_button_' + instanceId);
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
                
            } catch (error) {
                secureLog('error', 'Recording stop failed', error.message);
            }
        },

        playRecording: function(instanceId) {
            var audio = document.getElementById('starmus_audio_preview_' + instanceId);
            if (audio && audio.src) {
                audio.play().catch(function(error) {
                    secureLog('error', 'Playback failed', error.message);
                });
            }
        },

        updateUI: function(instanceId, state) {
            var recordBtn = document.getElementById('starmus_record_btn_' + instanceId);
            var stopBtn = document.getElementById('starmus_stop_btn_' + instanceId);
            var playBtn = document.getElementById('starmus_play_btn_' + instanceId);
            var status = document.getElementById('starmus_recorder_status_' + instanceId);

            if (!recordBtn || !stopBtn || !playBtn || !status) return;

            switch (state) {
                case 'recording':
                    recordBtn.disabled = true;
                    stopBtn.disabled = false;
                    playBtn.disabled = true;
                    status.textContent = 'Recording...';
                    break;
                case 'stopped':
                    recordBtn.disabled = false;
                    stopBtn.disabled = true;
                    playBtn.disabled = false;
                    status.textContent = 'Recording complete';
                    break;
                default:
                    recordBtn.disabled = false;
                    stopBtn.disabled = true;
                    playBtn.disabled = true;
                    status.textContent = 'Ready to record';
            }
        },

        submit: function(instanceId) {
            var instance = this.instances[instanceId];
            if (!instance || !instance.audioBlob) {
                return Promise.reject(new Error('No audio recording available'));
            }

            if (!validateAudioBlob(instance.audioBlob)) {
                return Promise.reject(new Error('Invalid audio data'));
            }

            return this.uploadAudio(instanceId, instance.audioBlob);
        },

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

                    // Add form data
                    var form = document.getElementById(instanceId);
                    if (form) {
                        var formDataEntries = new FormData(form);
                        for (var pair of formDataEntries.entries()) {
                            formData.append(pair[0], pair[1]);
                        }
                    }

                    fetch(starmusFormData.rest_url, {
                        method: 'POST',
                        headers: {
                            'X-WP-Nonce': starmusFormData.rest_nonce
                        },
                        body: formData
                    })
                    .then(function(response) {
                        if (!response.ok) {
                            throw new Error('Upload failed: ' + response.status);
                        }
                        return response.json();
                    })
                    .then(function(data) {
                        offset += chunk.size;
                        
                        if (offset >= totalSize) {
                            resolve(data);
                        } else {
                            uploadChunk();
                        }
                    })
                    .catch(reject);
                }

                uploadChunk();
            });
        },

        generateUUID: function() {
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                var r = Math.random() * 16 | 0;
                var v = c === 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
        },

        cleanup: function(instanceId) {
            var instance = this.instances[instanceId];
            if (instance) {
                if (instance.stream) {
                    instance.stream.getTracks().forEach(function(track) {
                        track.stop();
                    });
                }
                delete this.instances[instanceId];
            }
        }
    };

})();