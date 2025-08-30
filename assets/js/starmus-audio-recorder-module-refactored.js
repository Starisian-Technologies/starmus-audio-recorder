/**
 * Starmus Audio Recorder - Optimized for Low-Bandwidth & Legacy Browsers
 * Compatible with Android 4.4+ and iOS 8+ browsers
 */

(function(window) {
    'use strict';

    // Feature detection and polyfills
    var hasMediaRecorder = !!(window.MediaRecorder && window.navigator.mediaDevices);
    var hasCrypto = !!(window.crypto && (window.crypto.randomUUID || window.crypto.getRandomValues));
    var hasPromise = typeof Promise !== 'undefined';
    
    // Simple Promise polyfill for older browsers
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

    // Secure UUID generation with fallbacks
    function generateUUID() {
        // Modern browsers
        if (hasCrypto && window.crypto.randomUUID) {
            try {
                return window.crypto.randomUUID();
            } catch (e) {
                // Fallback if randomUUID fails
            }
        }
        
        // Crypto.getRandomValues fallback
        if (hasCrypto && window.crypto.getRandomValues) {
            try {
                var buffer = new Uint8Array(16);
                window.crypto.getRandomValues(buffer);
                buffer[6] = (buffer[6] & 0x0f) | 0x40; // Version 4
                buffer[8] = (buffer[8] & 0x3f) | 0x80; // Variant
                
                var hex = [];
                for (var i = 0; i < buffer.length; i++) {
                    hex.push(buffer[i].toString(16).padStart ? 
                        buffer[i].toString(16).padStart(2, '0') :
                        ('0' + buffer[i].toString(16)).slice(-2));
                }
                return [
                    hex.slice(0, 4).join(''),
                    hex.slice(4, 6).join(''),
                    hex.slice(6, 8).join(''),
                    hex.slice(8, 10).join(''),
                    hex.slice(10).join('')
                ].join('-');
            } catch (e) {
                // Fallback to Math.random
            }
        }
        
        // Math.random fallback for very old browsers
        var d = new Date().getTime();
        if (window.performance && window.performance.now) {
            d += window.performance.now();
        }
        
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            var r = (d + Math.random() * 16) % 16 | 0;
            d = Math.floor(d / 16);
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    // Lightweight DOM utilities
    function $(id) {
        return document.getElementById(id);
    }
    
    function addClass(el, className) {
        if (el && el.classList) {
            el.classList.add(className);
        } else if (el) {
            el.className += ' ' + className;
        }
    }
    
    function removeClass(el, className) {
        if (el && el.classList) {
            el.classList.remove(className);
        } else if (el) {
            el.className = el.className.replace(new RegExp('\\b' + className + '\\b', 'g'), '');
        }
    }

    // Main recorder module
    function StarmusAudioRecorder() {
        var self = this;
        
        // State
        this.state = {
            isRecording: false,
            isPaused: false,
            hasPermission: false,
            startTime: 0,
            pausedTime: 0,
            audioChunks: [],
            mediaRecorder: null,
            stream: null
        };
        
        // Configuration
        this.config = {
            maxDuration: 300000, // 5 minutes
            chunkSize: 1024, // Small chunks for low bandwidth
            mimeType: this.getSupportedMimeType(),
            sampleRate: 22050 // Lower sample rate for smaller files
        };
        
        // DOM elements cache
        this.dom = {};
        
        // Timer
        this.timerInterval = null;
    }

    StarmusAudioRecorder.prototype = {
        // Get supported MIME type with fallbacks
        getSupportedMimeType: function() {
            var types = [
                'audio/webm;codecs=opus',
                'audio/webm',
                'audio/mp4',
                'audio/wav'
            ];
            
            for (var i = 0; i < types.length; i++) {
                if (hasMediaRecorder && MediaRecorder.isTypeSupported(types[i])) {
                    return types[i];
                }
            }
            return 'audio/webm'; // Default fallback
        },

        // Initialize recorder
        init: function(formId) {
            var self = this;
            
            if (!hasMediaRecorder) {
                this.showError('Audio recording not supported in this browser');
                return false;
            }
            
            this.formId = formId;
            this.cacheDOMElements();
            this.bindEvents();
            this.checkPermissions();
            
            return true;
        },

        // Cache DOM elements
        cacheDOMElements: function() {
            var prefix = this.formId ? '_' + this.formId : '';
            
            this.dom = {
                recordBtn: $('recordButton' + prefix),
                pauseBtn: $('pauseButton' + prefix),
                stopBtn: $('stopButton' + prefix),
                timer: $('timer' + prefix),
                status: $('status' + prefix),
                audioPlayer: $('audioPlayer' + prefix),
                fileInput: $('audio_file' + prefix),
                uuidInput: $('audio_uuid' + prefix)
            };
        },

        // Bind event handlers
        bindEvents: function() {
            var self = this;
            
            if (this.dom.recordBtn) {
                this.dom.recordBtn.onclick = function() { self.toggleRecording(); };
            }
            
            if (this.dom.pauseBtn) {
                this.dom.pauseBtn.onclick = function() { self.togglePause(); };
            }
            
            if (this.dom.stopBtn) {
                this.dom.stopBtn.onclick = function() { self.stopRecording(); };
            }
            
            // Handle page visibility changes to pause recording
            if (document.addEventListener) {
                document.addEventListener('visibilitychange', function() {
                    if (document.hidden && self.state.isRecording && !self.state.isPaused) {
                        self.togglePause();
                    }
                });
            }
        },

        // Check microphone permissions
        checkPermissions: function() {
            var self = this;
            
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                this.showError('Microphone access not supported');
                return;
            }
            
            // Try to get permission
            navigator.mediaDevices.getUserMedia({ audio: true })
                .then(function(stream) {
                    self.state.hasPermission = true;
                    self.updateUI();
                    // Stop the stream immediately, we'll get it again when recording
                    stream.getTracks().forEach(function(track) {
                        track.stop();
                    });
                })
                .catch(function(error) {
                    self.showError('Microphone permission denied');
                    self.state.hasPermission = false;
                    self.updateUI();
                });
        },

        // Start/stop recording
        toggleRecording: function() {
            if (this.state.isRecording) {
                this.stopRecording();
            } else {
                this.startRecording();
            }
        },

        // Start recording
        startRecording: function() {
            var self = this;
            
            if (!this.state.hasPermission) {
                this.checkPermissions();
                return;
            }
            
            navigator.mediaDevices.getUserMedia({ 
                audio: {
                    sampleRate: this.config.sampleRate,
                    channelCount: 1,
                    echoCancellation: true,
                    noiseSuppression: true
                }
            })
            .then(function(stream) {
                self.state.stream = stream;
                self.state.mediaRecorder = new MediaRecorder(stream, {
                    mimeType: self.config.mimeType
                });
                
                self.state.mediaRecorder.ondataavailable = function(event) {
                    if (event.data.size > 0) {
                        self.state.audioChunks.push(event.data);
                    }
                };
                
                self.state.mediaRecorder.onstop = function() {
                    self.processRecording();
                };
                
                self.state.mediaRecorder.start(self.config.chunkSize);
                self.state.isRecording = true;
                self.state.startTime = Date.now();
                self.startTimer();
                self.updateUI();
                self.showStatus('Recording...', 'recording');
                
                // Auto-stop after max duration
                setTimeout(function() {
                    if (self.state.isRecording) {
                        self.stopRecording();
                    }
                }, self.config.maxDuration);
            })
            .catch(function(error) {
                self.showError('Failed to start recording: ' + error.message);
            });
        },

        // Stop recording
        stopRecording: function() {
            if (!this.state.isRecording) return;
            
            this.state.isRecording = false;
            this.state.isPaused = false;
            
            if (this.state.mediaRecorder && this.state.mediaRecorder.state !== 'inactive') {
                this.state.mediaRecorder.stop();
            }
            
            if (this.state.stream) {
                this.state.stream.getTracks().forEach(function(track) {
                    track.stop();
                });
            }
            
            this.stopTimer();
            this.updateUI();
        },

        // Toggle pause/resume
        togglePause: function() {
            if (!this.state.isRecording) return;
            
            if (this.state.isPaused) {
                this.resumeRecording();
            } else {
                this.pauseRecording();
            }
        },

        // Pause recording
        pauseRecording: function() {
            if (this.state.mediaRecorder && this.state.mediaRecorder.state === 'recording') {
                this.state.mediaRecorder.pause();
                this.state.isPaused = true;
                this.state.pausedTime = Date.now();
                this.stopTimer();
                this.updateUI();
                this.showStatus('Paused', 'paused');
            }
        },

        // Resume recording
        resumeRecording: function() {
            if (this.state.mediaRecorder && this.state.mediaRecorder.state === 'paused') {
                this.state.mediaRecorder.resume();
                this.state.isPaused = false;
                this.startTimer();
                this.updateUI();
                this.showStatus('Recording...', 'recording');
            }
        },

        // Process completed recording
        processRecording: function() {
            var self = this;
            
            if (this.state.audioChunks.length === 0) {
                this.showError('No audio data recorded');
                return;
            }
            
            var blob = new Blob(this.state.audioChunks, { type: this.config.mimeType });
            var uuid = generateUUID();
            
            // Create audio player
            if (this.dom.audioPlayer) {
                this.dom.audioPlayer.src = URL.createObjectURL(blob);
                this.dom.audioPlayer.style.display = 'block';
            }
            
            // Set form data
            if (this.dom.uuidInput) {
                this.dom.uuidInput.value = uuid;
            }
            
            // Convert blob to file for form submission
            this.createFileFromBlob(blob, uuid);
            
            this.showStatus('Recording complete', 'success');
            this.state.audioChunks = [];
        },

        // Create file from blob for form submission
        createFileFromBlob: function(blob, uuid) {
            var self = this;
            
            if (!this.dom.fileInput) return;
            
            // Create a File object (if supported) or use blob directly
            var file;
            try {
                file = new File([blob], uuid + '.webm', { type: this.config.mimeType });
            } catch (e) {
                // Fallback for browsers that don't support File constructor
                file = blob;
                file.name = uuid + '.webm';
            }
            
            // Create a new FileList (not directly possible, so we'll handle this in the upload)
            this.dom.fileInput.files = null; // Clear existing
            this.dom.fileInput.blob = file; // Store for later use
        },

        // Timer functions
        startTimer: function() {
            var self = this;
            this.timerInterval = setInterval(function() {
                self.updateTimer();
            }, 1000);
        },

        stopTimer: function() {
            if (this.timerInterval) {
                clearInterval(this.timerInterval);
                this.timerInterval = null;
            }
        },

        updateTimer: function() {
            if (!this.dom.timer) return;
            
            var elapsed = Date.now() - this.state.startTime;
            var minutes = Math.floor(elapsed / 60000);
            var seconds = Math.floor((elapsed % 60000) / 1000);
            
            this.dom.timer.textContent = 
                (minutes < 10 ? '0' : '') + minutes + ':' +
                (seconds < 10 ? '0' : '') + seconds;
        },

        // UI updates
        updateUI: function() {
            if (this.dom.recordBtn) {
                this.dom.recordBtn.disabled = !this.state.hasPermission;
                this.dom.recordBtn.textContent = this.state.isRecording ? 'Stop' : 'Record';
            }
            
            if (this.dom.pauseBtn) {
                this.dom.pauseBtn.disabled = !this.state.isRecording;
                this.dom.pauseBtn.textContent = this.state.isPaused ? 'Resume' : 'Pause';
                this.dom.pauseBtn.style.display = this.state.isRecording ? 'inline-block' : 'none';
            }
        },

        // Status messages
        showStatus: function(message, type) {
            if (!this.dom.status) return;
            
            this.dom.status.textContent = message;
            this.dom.status.className = 'status ' + (type || 'info');
        },

        showError: function(message) {
            this.showStatus(message, 'error');
            console.error('StarmusAudioRecorder:', message);
        },

        // Cleanup
        destroy: function() {
            this.stopRecording();
            this.stopTimer();
            
            if (this.state.stream) {
                this.state.stream.getTracks().forEach(function(track) {
                    track.stop();
                });
            }
            
            // Clear DOM references
            this.dom = {};
            this.state = {};
        }
    };

    // Export to global scope
    window.StarmusAudioRecorder = StarmusAudioRecorder;

    // Auto-initialize if DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            if (window.starmusAutoInit !== false) {
                window.starmusRecorder = new StarmusAudioRecorder();
            }
        });
    } else {
        if (window.starmusAutoInit !== false) {
            window.starmusRecorder = new StarmusAudioRecorder();
        }
    }

})(window);