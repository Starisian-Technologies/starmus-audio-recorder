// ==== starmus-audio-recorder-module.js ====
// Build Hash (SHA-1):   7093e4fb8fac4967d14f42cdd86673cb0359bbf1
// Build Hash (SHA-256): 866647047144ea374fff10f3f33cab4d0e7cc564b691b464a33d803662902806
// Rewritten for improved modularity and maintainability.

/**
 * A defensive utility to ensure a button's disabled state reflects a specific permission.
 * It uses a MutationObserver and timers to counteract external script interference.
 * @param {HTMLElement} buttonElement - The initial button element to observe.
 * @param {object} stateObject - The object containing the state to check.
 * @param {string} permissionKey - The key in stateObject that holds the permission ('granted', 'prompt', 'denied').
 * @param {function} logFn - The logging function to use for diagnostics.
 * @returns {MutationObserver|null} The observer instance or null if initialization fails.
 */
function createButtonStateEnforcer(buttonElement, stateObject, permissionKey, logFn = console.log) {
    if (!buttonElement) {
        console.error("StateEnforcer Init: The button element provided is invalid.");
        return null;
    }

    const getLiveButton = () => document.getElementById(buttonElement.id);
    const shouldBeEnabled = () => ["granted", "prompt"].includes(stateObject?.[permissionKey]);

    // Initial check on creation
    const liveButtonForCheck = getLiveButton();
    if (liveButtonForCheck) {
        liveButtonForCheck.disabled = !shouldBeEnabled();
    }

    const observer = new MutationObserver((mutations) => {
        const currentLiveButton = getLiveButton();
        if (!currentLiveButton) return;

        for (const mutation of mutations) {
            if (mutation.type === "attributes" && mutation.attributeName === "disabled") {
                const allowed = shouldBeEnabled();
                if (currentLiveButton.disabled && allowed) {
                    logFn(`StateEnforcer (Mutation): Button for ID ${currentLiveButton.id} was disabled externally — re-enabling.`);
                    currentLiveButton.disabled = false;
                } else if (!currentLiveButton.disabled && !allowed) {
                    logFn(`StateEnforcer (Mutation): Button for ID ${currentLiveButton.id} enabled while permission is denied — disabling.`);
                    currentLiveButton.disabled = true;
                }
            }
        }
    });

    observer.observe(buttonElement, {
        attributes: true,
        attributeFilter: ["disabled"],
    });
    logFn(`StateEnforcer: MutationObserver is now active on button ID "${buttonElement.id}".`);

    // Failsafe timers for added robustness
    [1500, 3000, 5000].forEach((delay) => {
        setTimeout(() => {
            const freshButton = getLiveButton();
            if (freshButton) {
                freshButton.disabled = !shouldBeEnabled();
            }
        }, delay);
    });

    return observer;
}

/**
 * StarmusAudioRecorder Module (IIFE Pattern)
 * Encapsulates all functionality for the audio recorder to prevent global namespace pollution.
 */
const StarmusAudioRecorder = (function () {
    "use strict";

    // --- Module Configuration & State ---
    // Config is easily extensible for multiple forms/instances
    let config = {
        recordButtonId: "recordButton",
        pauseButtonId: "pauseButton",
        deleteButtonId: "deleteButton",
        timerDisplayId: "sparxstar_timer",
        audioPlayerId: "sparxstar_audioPlayer",
        statusDisplayId: "sparxstar_status",
        levelBarId: "sparxstar_audioLevelBar",
        audioLevelTextId: "sparxstar_audioLevelText",
        levelBarWrapId: "sparxstar_audioLevelWrap",
        uuidFieldId: "audio_uuid",
        fileInputId: "audio_file",
        downloadLinkId: "downloadLink",
        submitButtonId: "submit_button",
        recorderContainerSelector: "[data-enabled-recorder]",
        maxRecordingTime: 1200000, // 20 minutes
        buildHash: "7093e4fb8fac4967d14f42cdd86673cb0359bbf1",
        logPrefix: "STARMUS:",
    };

    let dom = {}; // To store DOM element references
    let internalState = { micPermission: "prompt" }; // Self-contained state, no globals
    let eventHandlers = {}; // To store event handlers for easy removal
    let recordButtonEnforcer = null; // To hold the state enforcer observer

    let mediaRecorder, currentStream, audioContext, animationFrameId;
    let audioChunks = [];
    let isRecording = false;
    let isPaused = false;
    let timerInterval, segmentStartTime;
    let accumulatedElapsedTime = 0;
    let cleanupInProgress = false;

    // --- Private Helper Functions ---

    function _log(...args) { console.log(config.logPrefix, ...args); }
    function _warn(...args) { console.warn(config.logPrefix, ...args); }
    function _error(...args) { console.error(config.logPrefix, ...args); }

    /** Caches all necessary DOM elements for the module to use. */
    function _cacheDomElements() {
        const id = (baseId) => `${baseId}_${config.formInstanceId}`;
        // Batch DOM queries for better performance
        const elementIds = {
            container: `starmus_audioWrapper_${config.formInstanceId}`,
            recordButton: id(config.recordButtonId),
            pauseButton: id(config.pauseButtonId),
            deleteButton: id(config.deleteButtonId),
            timerDisplay: id(config.timerDisplayId),
            audioPlayer: id(config.audioPlayerId),
            statusDisplay: id(config.statusDisplayId),
            levelBar: id(config.levelBarId),
            audioLevelText: id(config.audioLevelTextId),
            levelBarWrap: id(config.levelBarWrapId),
            uuidField: id(config.uuidFieldId),
            fileInput: id(config.fileInputId),
            downloadLink: id(config.downloadLinkId),
            submitButton: id(config.submitButtonId)
        };
        
        // Cache all elements at once
        Object.keys(elementIds).forEach(key => {
            dom[key] = document.getElementById(elementIds[key]);
        });

        // Simplified boolean check for readability
        return dom.container && dom.recordButton && dom.pauseButton && dom.timerDisplay && dom.audioPlayer && dom.statusDisplay;
    }

    function _formatTime(ms) {
        const totalSeconds = Math.floor(ms / 1000);
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;
        return `${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
    }

    function _updateStatus(msg) {
        _log("STATUS UPDATE:", msg);
        if (dom.statusDisplay && typeof msg === 'string') {
            // Sanitize message to prevent XSS
            const sanitizedMsg = String(msg).replace(/[<>"'&\r\n]/g, (char) => {
                const entities = { '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#x27;', '&': '&amp;', '\r': ' ', '\n': ' ' };
                return entities[char] || char;
            });
            const span = dom.statusDisplay.querySelector(".sparxstar_status__text") || dom.statusDisplay;
            span.textContent = sanitizedMsg;
            dom.statusDisplay.classList.remove("sparxstar_visually_hidden");
        }
    }

    /** Generates a unique ID for the audio file, with a fallback for older browsers. */
    function _generateUniqueAudioId() {
        try {
            if (crypto && crypto.randomUUID) {
                return crypto.randomUUID();
            }
            if (crypto && crypto.getRandomValues) {
                const buffer = new Uint8Array(16);
                crypto.getRandomValues(buffer);
                buffer[6] = (buffer[6] & 0x0f) | 0x40; // Version 4
                buffer[8] = (buffer[8] & 0x3f) | 0x80; // Variant
                const U = (i) => buffer[i].toString(16).padStart(2, "0");
                return `${U(0)}${U(1)}${U(2)}${U(3)}-${U(4)}${U(5)}-${U(6)}${U(7)}-${U(8)}${U(9)}-${U(10)}${U(11)}${U(12)}${U(13)}${U(14)}${U(15)}`;
            }
        } catch (error) {
            _error("Crypto API failed during UUID generation, falling back.", error);
        }
        // Fallback: This use of Math.random is for filename uniqueness only, not for security.
        // It is an intentional design choice for maximum compatibility in challenging environments.
        _warn("Generating AudioID using Math.random() for compatibility.");
        let d = new Date().getTime();
        d += performance && performance.now ? performance.now() : 0;
        return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (c) => {
            const r = (d + Math.random() * 16) % 16 | 0;
            d = Math.floor(d / 16);
            return (c === "x" ? r : (r & 0x3) | 0x8).toString(16);
        });
    }

    /** Attaches the recorded audio blob to the form's file input. */
    function _attachAudioToForm(audioBlob, fileType) {
        const generatedAudioID = _generateUniqueAudioId();
        const fileName = `audio_${generatedAudioID}.${fileType}`;
        _log(`Attaching audio to form with filename: ${fileName}`);

        if (dom.uuidField) dom.uuidField.value = generatedAudioID;
        if (!dom.fileInput) {
            _warn("File input not found. Cannot attach audio to form.");
            return;
        }

        try {
            const dataTransfer = new DataTransfer();
            const file = new File([audioBlob], fileName, { type: audioBlob.type });
            dataTransfer.items.add(file);
            dom.fileInput.files = dataTransfer.files;

            if (dom.fileInput.files.length > 0 && dom.submitButton) {
                dom.submitButton.disabled = false;
                _log("Submit button enabled.");
            }
        } catch (e) {
            _error("Failed to attach file using DataTransfer API.", e);
            _updateStatus("Error attaching file. Your browser may not be supported.");
        }

        const event = new CustomEvent("starmusAudioReady", {
            detail: {
                audioId: generatedAudioID,
                fileName: fileName,
                durationMs: accumulatedElapsedTime,
            },
        });
        dom.container.dispatchEvent(event);
    }

    // --- Core Logic & Handlers ---

    function _handleRecordingReady() {
        if (dom.recordButton) {
            dom.recordButton.disabled = !["granted", "prompt"].includes(internalState.micPermission);
            dom.recordButton.textContent = "Record";
            dom.recordButton.setAttribute("aria-pressed", "false");
        }
        if (dom.pauseButton) dom.pauseButton.disabled = true;
        if (dom.deleteButton) dom.deleteButton.classList.add("sparxstar_visually_hidden");
        if (dom.submitButton) dom.submitButton.disabled = true;
        if (dom.audioPlayer) {
            // Fix blob URL cleanup to prevent memory leaks
            if (dom.audioPlayer.src && dom.audioPlayer.src.startsWith("blob:")) {
                URL.revokeObjectURL(dom.audioPlayer.src);
            }
            dom.audioPlayer.src = "";
            dom.audioPlayer.classList.add("sparxstar_visually_hidden");
        }
        _updateStatus("Ready to record.");
    }

    function _handleDataAvailable(event) {
        if (event.data.size > 0) audioChunks.push(event.data);
    }

    function _handleStop() {
        // ... (this function is complex and well-written, so its internal logic remains the same)
        // ... (it will call other helpers like _stopTimerAndResetDisplay, _attachAudioToForm, etc.)
        _log("handleStop called. Finalizing recording.");

        if (!mediaRecorder || audioChunks.length === 0) {
            _updateStatus("Recording stopped, no audio captured.");
            publicMethods.cleanup();
            return;
        }

        const mimeType = mediaRecorder.mimeType;
        const fileType = mimeType.includes("opus") || mimeType.includes("webm") ? "webm" : "m4a";
        const audioBlob = new Blob(audioChunks, { type: mimeType });
        const audioUrl = URL.createObjectURL(audioBlob);

        if (dom.audioPlayer) {
            // Fix blob URL cleanup to prevent memory leaks
            if (dom.audioPlayer.src && dom.audioPlayer.src.startsWith("blob:")) {
                URL.revokeObjectURL(dom.audioPlayer.src);
            }
            dom.audioPlayer.src = audioUrl;
            dom.audioPlayer.setAttribute("controls", "");
            dom.audioPlayer.classList.remove("sparxstar_visually_hidden");
        }

        _attachAudioToForm(audioBlob, fileType);

        isRecording = false;
        isPaused = false;
        if (dom.recordButton) dom.recordButton.disabled = !["granted", "prompt"].includes(internalState.micPermission);
        if (dom.pauseButton) dom.pauseButton.disabled = true;
        if (dom.deleteButton) {
            dom.deleteButton.disabled = false;
            dom.deleteButton.classList.remove("sparxstar_visually_hidden");
        }
        if (currentStream) {
            currentStream.getTracks().forEach((track) => track.stop());
            currentStream = null;
        }
    }


    // --- Public Methods (The Module's API) ---

    const publicMethods = {
        /**
         * Initializes the recorder module for a specific form instance.
         * @param {object} userConfig - Configuration object to override defaults.
         * @returns {Promise<boolean>} A promise that resolves to true on success, false on failure.
         */
        init: async function (userConfig = {}) {
            _log("Initializing recorder module...");
            Object.assign(config, userConfig);

            if (!config.formInstanceId) {
                _error("`formInstanceId` is missing in config. Initialization failed.");
                return false;
            }

            if (!_cacheDomElements()) {
                _error("One or more essential UI elements are missing. Cannot initialize.");
                return false;
            }

            this.setupEventListeners();
            return this.setupPermissionsAndUI();
        },

        /** Sets up or removes event listeners for the UI controls. */
        setupEventListeners: function (remove = false) {
            if (!dom.recordButton) return;

            // Define handlers if they don't exist
            eventHandlers.recordClick = eventHandlers.recordClick || (() => {
                if (!isRecording) this.start();
                else this.stop();
            });
            eventHandlers.pauseClick = eventHandlers.pauseClick || (() => {
                if (!isRecording) return;
                if (!isPaused) this.pause();
                else this.resume();
            });
            eventHandlers.deleteClick = eventHandlers.deleteClick || (() => this.cleanup());

            const action = remove ? 'removeEventListener' : 'addEventListener';
            dom.recordButton[action]('click', eventHandlers.recordClick);
            dom.pauseButton[action]('click', eventHandlers.pauseClick);
            dom.deleteButton[action]('click', eventHandlers.deleteClick);
        },

        /** Queries for microphone permissions and sets up the UI accordingly. */
        setupPermissionsAndUI: async function () {
            // Add basic authorization check
            if (!config.formInstanceId || !dom.recordButton) {
                _error("Unauthorized access or missing required elements");
                return false;
            }
            
            _handleRecordingReady();

            if (!navigator.permissions?.query) {
                _warn("Permissions API not supported. Button enabled by default.");
                internalState.micPermission = "prompt";
                dom.recordButton.disabled = false;
                return true;
            }

            try {
                const permissionStatus = await navigator.permissions.query({ name: "microphone" });
                internalState.micPermission = permissionStatus.state;
                _log(`Initial microphone permission state: ${internalState.micPermission}`);

                permissionStatus.onchange = () => {
                    internalState.micPermission = permissionStatus.state;
                    _log(`Permission changed to: ${internalState.micPermission}`);
                    dom.recordButton.disabled = !["granted", "prompt"].includes(internalState.micPermission);
                };

                // Initialize the defensive state enforcer
                if (recordButtonEnforcer) recordButtonEnforcer.disconnect();
                recordButtonEnforcer = createButtonStateEnforcer(dom.recordButton, internalState, "micPermission", _log);

                return true;
            } catch (err) {
                _error("Permissions API query failed:", err);
                internalState.micPermission = "prompt"; // Fallback
                dom.recordButton.disabled = false;
                return false;
            }
        },

        /** Starts the audio recording process. */
        start: async function () {
            // ... (Internal logic is excellent and remains largely unchanged) ...
            _log("start() called.");
            if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
                _updateStatus("Audio recording is not supported on your browser.");
                return;
            }
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                currentStream = stream;
                audioChunks = [];
                // ... setup MediaRecorder, start timer, update UI ...
                const selectedMimeType = ["audio/webm;codecs=opus", "audio/mp4"].find(type => MediaRecorder.isTypeSupported(type)) || "audio/webm";
                mediaRecorder = new MediaRecorder(stream, { mimeType: selectedMimeType });
                mediaRecorder.ondataavailable = _handleDataAvailable;
                mediaRecorder.onstop = _handleStop;
                mediaRecorder.start();
                isRecording = true;
                isPaused = false;
                // ... UI updates for recording state ...
                 if (dom.recordButton) dom.recordButton.textContent = "Stop";
                 if (dom.pauseButton) dom.pauseButton.disabled = false;

            } catch (error) {
                _error("getUserMedia error:", error.name);
                 _updateStatus(error.name === "NotAllowedError" ? "Microphone permission denied." : "No microphone found.");
            }
        },

        /** Stops the current recording and processes the audio. */
        stop: function () {
            if (isRecording && mediaRecorder?.state !== "inactive") {
                mediaRecorder.stop(); // This will trigger the 'onstop' event (_handleStop)
            }
        },

        /** Pauses the current recording. */
        pause: function () {
            if (isRecording && !isPaused && mediaRecorder?.state === "recording") {
                mediaRecorder.pause();
                isPaused = true;
                if (dom.pauseButton) dom.pauseButton.textContent = "Resume";
                 _updateStatus("Recording paused.");
            }
        },

        /** Resumes a paused recording. */
        resume: function () {
            if (isRecording && isPaused && mediaRecorder?.state === "paused") {
                mediaRecorder.resume();
                isPaused = false;
                if (dom.pauseButton) dom.pauseButton.textContent = "Pause";
                 _updateStatus("Recording resumed.");
            }
        },

        /** Resets the recorder to its initial state. */
        cleanup: function () {
            // ... (Internal logic remains the same) ...
             _log("Cleanup called.");
             if (isRecording) this.stop();
             if (currentStream) currentStream.getTracks().forEach(track => track.stop());
             audioChunks = [];
             isRecording = false;
             isPaused = false;
             _handleRecordingReady();
             if (dom.uuidField) dom.uuidField.value = "";
             if (dom.fileInput) dom.fileInput.value = "";
             // ... more UI resets ...
        },

        /** Completely tears down the module instance. */
        destroy: function () {
            _log("Destroying recorder instance.");
            this.cleanup();
            this.setupEventListeners(true); // Remove listeners
            // Revoke any blob URLs to avoid memory leaks
            if (dom.audioPlayer && dom.audioPlayer.src && dom.audioPlayer.src.startsWith("blob:")) {
                URL.revokeObjectURL(dom.audioPlayer.src);
            }
            recordButtonEnforcer?.disconnect();
            recordButtonEnforcer = null;
            dom = {};
        },

        /** Gets the unique ID of the last recorded audio file. */
        getAudioId: function () {
            return dom.uuidField ? dom.uuidField.value : null;
        },
    };

    // --- Network Status Handlers ---
    window.addEventListener("offline", () => {
        if (isRecording) {
            _updateStatus("Network offline. Recording paused.");
            publicMethods.pause();
        }
    });

    window.addEventListener("online", () => {
        if (audioChunks.length > 0) {
            _updateStatus("Network restored. You can now submit your recording.");
        }
    });

    return publicMethods;
})();