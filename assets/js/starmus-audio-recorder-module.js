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
 */

/**
 * @file This module encapsulates all UI and hardware interaction for the Starmus audio recorder.
 * @description It manages microphone permissions, MediaRecorder state (recording, paused, stopped),
 * UI updates (timers, buttons, status messages), and provides a clean API for other scripts to
 * initialize, control, and clean up the recorder instance. It is designed to be self-contained
 * and prevent global namespace pollution using an IIFE pattern.
 */

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
 * @namespace StarmusAudioRecorder
 */
const StarmusAudioRecorder = (function () {
    "use strict";

    // --- Module Configuration & State ---
    let config = {
        formInstanceId: null,
        recordButtonId: "recordButton",
        pauseButtonId: "pauseButton",
        deleteButtonId: "deleteButton",
        timerDisplayId: "sparxstar_timer",
        audioPlayerId: "sparxstar_audioPlayer",
        statusDisplayId: "sparxstar_status",
        levelBarId: "sparxstar_audioLevelBar",
        uuidFieldId: "audio_uuid",
        fileInputId: "audio_file",
        submitButtonId: "submit_button",
        recorderContainerSelector: "[data-enabled-recorder]",
        logPrefix: "STARMUS:",
    };

    let dom = {};
    let internalState = { micPermission: "prompt" };
    let eventHandlers = {};
    let recordButtonEnforcer = null;

    let mediaRecorder, currentStream;
    let audioChunks = [];
    let isRecording = false;
    let isPaused = false;
    let accumulatedElapsedTime = 0;

    // --- Private Helper Functions ---
    /**
     * Logs a message to the console with the module's prefix.
     * @param {...*} args - The arguments to log.
     */
    function _log(...args) { console.log(config.logPrefix, ...args); }
    function _warn(...args) { console.warn(config.logPrefix, ...args); }
    function _error(...args) { console.error(config.logPrefix, ...args); }
    
    /**
     * Caches all necessary DOM elements for a specific form instance.
     * @returns {boolean} True if all essential elements were found, otherwise false.
     * @private
     */
    function _cacheDomElements() {
        const id = (baseId) => `${baseId}_${config.formInstanceId}`;
        const elementIds = {
            container: `starmus_audioWrapper_${config.formInstanceId}`,
            recordButton: id(config.recordButtonId),
            pauseButton: id(config.pauseButtonId),
            deleteButton: id(config.deleteButtonId),
            timerDisplay: id(config.timerDisplayId),
            audioPlayer: id(config.audioPlayerId),
            statusDisplay: id(config.statusDisplayId),
            uuidField: id(config.uuidFieldId),
            fileInput: id(config.fileInputId),
            submitButton: id(config.submitButtonId)
        };
        
        Object.keys(elementIds).forEach(key => {
            dom[key] = document.getElementById(elementIds[key]);
        });

        return dom.container && dom.recordButton && dom.pauseButton && dom.timerDisplay && dom.audioPlayer && dom.statusDisplay;
    }
    
    function _formatTime(ms) {
        const totalSeconds = Math.floor(ms / 1000);
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;
        return `${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
    }

    function _updateStatus(msg) {
        if (dom.statusDisplay && typeof msg === 'string') {
            const sanitizedMsg = String(msg).replace(/[<>"'&\r\n]/g, char => ({ '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#x27;', '&': '&amp;', '\r': ' ', '\n': ' ' }[char] || char));
            const span = dom.statusDisplay.querySelector(".sparxstar_status__text") || dom.statusDisplay;
            span.textContent = sanitizedMsg;
            dom.statusDisplay.classList.remove("sparxstar_visually_hidden");
        }
    }

    function _generateUniqueAudioId() {
        try {
            if (crypto && crypto.randomUUID) return crypto.randomUUID();
            if (crypto && crypto.getRandomValues) {
                const buffer = new Uint8Array(16);
                crypto.getRandomValues(buffer);
                buffer[6] = (buffer[6] & 0x0f) | 0x40;
                buffer[8] = (buffer[8] & 0x3f) | 0x80;
                const U = i => buffer[i].toString(16).padStart(2, "0");
                return `${U(0)}${U(1)}${U(2)}${U(3)}-${U(4)}${U(5)}-${U(6)}${U(7)}-${U(8)}${U(9)}-${U(10)}${U(11)}${U(12)}${U(13)}${U(14)}${U(15)}`;
            }
        } catch (error) {
            _error("Crypto API failed during UUID generation, falling back.", error);
        }
        _warn("Generating AudioID using Math.random() for compatibility.");
        let d = new Date().getTime() + (performance?.now() || 0);
        return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, c => {
            const r = (d + Math.random() * 16) % 16 | 0;
            d = Math.floor(d / 16);
            return (c === "x" ? r : (r & 0x3) | 0x8).toString(16);
        });
    }

    /**
     * Attaches the recorded audio blob to the form's file input and dispatches a custom event.
     * @param {Blob} audioBlob - The recorded audio data.
     * @param {string} fileType - The file extension (e.g., 'webm').
     * @private
     */
    function _attachAudioToForm(audioBlob, fileType) {
        const generatedAudioID = _generateUniqueAudioId();
        const fileName = `audio_${generatedAudioID}.${fileType}`;
        _log(`Attaching audio to form with filename: ${fileName}`);

        if (dom.uuidField) dom.uuidField.value = generatedAudioID;
        if (!dom.fileInput) return _warn("File input not found. Cannot attach audio to form.");

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

        const event = new CustomEvent("starmusAudioReady", { detail: { audioId: generatedAudioID, fileName, durationMs: accumulatedElapsedTime } });
        dom.container.dispatchEvent(event);
    }
    
    // --- Core Logic & Handlers ---

    /**
     * Resets the UI to the "Ready to Record" state.
     * @private
     */
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
            if (dom.audioPlayer.src && dom.audioPlayer.src.startsWith("blob:")) {
                URL.revokeObjectURL(dom.audioPlayer.src);
            }
            dom.audioPlayer.src = "";
            dom.audioPlayer.classList.add("sparxstar_visually_hidden");
        }
        _updateStatus("Ready to record.");
    }
    
    /**
     * Event handler for the MediaRecorder's 'dataavailable' event.
     * @param {BlobEvent} event - The event containing the audio data chunk.
     * @private
     */
    function _handleDataAvailable(event) {
        if (event.data.size > 0) audioChunks.push(event.data);
    }
    
    /**
     * Event handler for the MediaRecorder's 'stop' event. Finalizes the recording.
     * @private
     */
    function _handleStop() {
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
            if (dom.audioPlayer.src && dom.audioPlayer.src.startsWith("blob:")) {
                URL.revokeObjectURL(dom.audioPlayer.src);
            }
            dom.audioPlayer.src = audioUrl;
            dom.audioPlayer.controls = true;
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
            currentStream.getTracks().forEach(track => track.stop());
            currentStream = null;
        }
    }
    
    // --- Public Methods (The Module's API) ---
    const publicMethods = {
        /**
         * Initializes the recorder module for a specific form instance. This is the main entry point.
         * @param {object} userConfig - Configuration object to override defaults.
         * @param {string} userConfig.formInstanceId - The unique ID of the form instance to control.
         * @returns {Promise<boolean>} A promise that resolves to true on successful initialization, false on failure.
         * @public
         */
        init: async function (userConfig = {}) {
            _log("Initializing recorder module...");
            Object.assign(config, userConfig);
            if (!config.formInstanceId) return _error("`formInstanceId` is missing in config. Initialization failed."), false;
            if (!_cacheDomElements()) return _error("One or more essential UI elements are missing. Cannot initialize."), false;
            this.setupEventListeners();
            return this.setupPermissionsAndUI();
        },

        /**
         * Sets up or removes event listeners for the UI controls.
         * @param {boolean} [remove=false] - If true, removes the event listeners instead of adding them.
         * @public
         */
        setupEventListeners: function (remove = false) {
            if (!dom.recordButton || !dom.pauseButton || !dom.deleteButton) return;
            eventHandlers.recordClick = eventHandlers.recordClick || (() => isRecording ? this.stop() : this.start());
            eventHandlers.pauseClick = eventHandlers.pauseClick || (() => isRecording && (isPaused ? this.resume() : this.pause()));
            eventHandlers.deleteClick = eventHandlers.deleteClick || (() => this.cleanup());
            const action = remove ? 'removeEventListener' : 'addEventListener';
            dom.recordButton[action]('click', eventHandlers.recordClick);
            dom.pauseButton[action]('click', eventHandlers.pauseClick);
            dom.deleteButton[action]('click', eventHandlers.deleteClick);
        },

        /**
         * Queries for microphone permissions using the Permissions API and sets up the UI accordingly.
         * @returns {Promise<boolean>} A promise that resolves to true on success.
         * @public
         */
        setupPermissionsAndUI: async function () {
            _handleRecordingReady();
            if (!navigator.permissions?.query) {
                _warn("Permissions API not supported. Button enabled by default.");
                internalState.micPermission = "prompt";
                if (dom.recordButton) dom.recordButton.disabled = false;
                return true;
            }
            try {
                const permissionStatus = await navigator.permissions.query({ name: "microphone" });
                internalState.micPermission = permissionStatus.state;
                _log(`Initial microphone permission state: ${internalState.micPermission}`);
                permissionStatus.onchange = () => {
                    internalState.micPermission = permissionStatus.state;
                    _log(`Permission changed to: ${internalState.micPermission}`);
                    if (dom.recordButton) dom.recordButton.disabled = !["granted", "prompt"].includes(internalState.micPermission);
                };
                if (recordButtonEnforcer) recordButtonEnforcer.disconnect();
                if (dom.recordButton) recordButtonEnforcer = createButtonStateEnforcer(dom.recordButton, internalState, "micPermission", _log);
                return true;
            } catch (err) {
                _error("Permissions API query failed:", err);
                internalState.micPermission = "prompt";
                if (dom.recordButton) dom.recordButton.disabled = false;
                return false;
            }
        },
        
        /**
         * Starts the audio recording process. Requests microphone access if needed.
         * @returns {Promise<void>}
         * @public
         */
        start: async function () {
            _log("start() called.");
            if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) return _updateStatus("Audio recording is not supported on your browser.");
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                currentStream = stream;
                audioChunks = [];
                const selectedMimeType = ["audio/webm;codecs=opus", "audio/mp4"].find(MediaRecorder.isTypeSupported) || "audio/webm";
                mediaRecorder = new MediaRecorder(stream, { mimeType: selectedMimeType });
                mediaRecorder.ondataavailable = _handleDataAvailable;
                mediaRecorder.onstop = _handleStop;
                mediaRecorder.start();
                isRecording = true;
                isPaused = false;
                if (dom.recordButton) dom.recordButton.textContent = "Stop";
                if (dom.pauseButton) dom.pauseButton.disabled = false;
                _updateStatus("Recording...");
            } catch (error) {
                _error("getUserMedia error:", error.name);
                _updateStatus(error.name === "NotAllowedError" ? "Microphone permission denied." : "No microphone found.");
            }
        },
        
        /**
         * Stops the current recording and triggers the processing of the captured audio.
         * @public
         */
        stop: function () {
            if (isRecording && mediaRecorder?.state !== "inactive") mediaRecorder.stop();
        },

        /**
         * Pauses the current recording.
         * @public
         */
        pause: function () {
            if (isRecording && !isPaused && mediaRecorder?.state === "recording") {
                mediaRecorder.pause();
                isPaused = true;
                if (dom.pauseButton) dom.pauseButton.textContent = "Resume";
                _updateStatus("Recording paused.");
            }
        },

        /**
         * Resumes a paused recording.
         * @public
         */
        resume: function () {
            if (isRecording && isPaused && mediaRecorder?.state === "paused") {
                mediaRecorder.resume();
                isPaused = false;
                if (dom.pauseButton) dom.pauseButton.textContent = "Pause";
                _updateStatus("Recording resumed.");
            }
        },

        /**
         * Resets the recorder to its initial state, deleting any captured audio.
         * @public
         */
        cleanup: function () {
             _log("Cleanup called.");
             if (isRecording) this.stop();
             if (currentStream) currentStream.getTracks().forEach(track => track.stop());
             audioChunks = [];
             isRecording = false;
             isPaused = false;
             _handleRecordingReady();
             if (dom.uuidField) dom.uuidField.value = "";
             if (dom.fileInput) dom.fileInput.value = "";
        },

        /**
         * Completely tears down the module instance, removing event listeners and cleaning up resources.
         * @public
         */
        destroy: function () {
            _log("Destroying recorder instance.");
            this.cleanup();
            this.setupEventListeners(true);
            if (dom.audioPlayer?.src.startsWith("blob:")) URL.revokeObjectURL(dom.audioPlayer.src);
            recordButtonEnforcer?.disconnect();
            recordButtonEnforcer = null;
            dom = {};
        },

        /**
         * Gets the unique ID of the last recorded audio file.
         * @returns {string|null} The audio UUID or null if not set.
         * @public
         */
        getAudioId: function () {
            return dom.uuidField ? dom.uuidField.value : null;
        },
    };

    window.addEventListener("offline", () => isRecording && (_updateStatus("Network offline. Recording paused."), publicMethods.pause()));
    window.addEventListener("online", () => audioChunks.length > 0 && _updateStatus("Network restored. You can now submit your recording."));

    return publicMethods;
})();