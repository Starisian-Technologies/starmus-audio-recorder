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
 * @version 0.3.1
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
// LINTING FIX: Attach the module directly to the window object.
// This explicitly declares it as a global for other scripts to use,
// resolving both the 'no-redeclare' and 'no-unused-vars' linting errors.
window.StarmusAudioRecorder = (function () {
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
    let timerInterval = null;

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

        // Check for essential elements that must exist for the module to function.
        const essentialElements = [dom.container, dom.recordButton, dom.pauseButton, dom.uuidField, dom.fileInput];
        if (essentialElements.some(el => !el)) {
            _error('One or more essential UI elements are missing from the DOM.');
            return false;
        }
        return true;
    }
    
    function _formatTime(ms) {
        const totalSeconds = Math.floor(ms / 1000);
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;
        return `${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
    }

    function _updateStatus(msg, type = 'info') {
        if (dom.statusDisplay && typeof msg === 'string') {
            const textSpan = dom.statusDisplay.querySelector(".sparxstar_status__text");
            if (textSpan) {
                textSpan.textContent = msg;
            }
            dom.statusDisplay.className = `sparxstar_status ${type}`;
            dom.statusDisplay.classList.remove("sparxstar_visually_hidden");
        }
    }

    function _generateUniqueAudioId() {
        try {
            if (crypto && crypto.randomUUID) return crypto.randomUUID();
            // Fallback for environments without randomUUID but with getRandomValues
            if (crypto && crypto.getRandomValues) {
                const buffer = new Uint8Array(16);
                crypto.getRandomValues(buffer);
                buffer[6] = (buffer[6] & 0x0f) | 0x40; // Version 4
                buffer[8] = (buffer[8] & 0x3f) | 0x80; // Variant
                const hex = Array.from(buffer, byte => byte.toString(16).padStart(2, '0'));
                return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
            }
        } catch (error) {
            _error("Crypto API failed during UUID generation, falling back.", error);
        }
        _warn("Generating AudioID using Math.random() for compatibility.");
        let d = new Date().getTime() + (performance?.now() || 0);
        return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, function(c) {
            var r = (d + Math.random() * 16) % 16 | 0;
            d = Math.floor(d / 16);
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        }); "x" ? r : (r & 0x3) | 0x8).toString(16);
    }

    /**
     * Attaches the recorded audio blob to the form's file input and dispatches a custom event.
     * @param {Blob} audioBlob - The recorded audio data.
     * @param {string} fileType - The file extension (e.g., 'webm').
     * @private
     */
    function _attachAudioToForm(audioBlob, fileType) {
        const generatedAudioID = _generateUniqueAudioId();
        const fileName = `recording_${generatedAudioID}.${fileType}`;
        _log(`Attaching audio to form with filename: ${fileName}`);

        if (dom.uuidField) dom.uuidField.value = generatedAudioID;

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
            _updateStatus("Error attaching file. Your browser may not be supported.", "error");
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
        clearInterval(timerInterval);
        accumulatedElapsedTime = 0;
        if (dom.timerDisplay) dom.timerDisplay.textContent = "00:00";

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
        clearInterval(timerInterval);
        if (!mediaRecorder || audioChunks.length === 0) {
            _updateStatus("Recording stopped, but no audio was captured.", "info");
            publicMethods.cleanup(config.formInstanceId); // Pass instanceId to cleanup
            return;
        }

        const mimeType = mediaRecorder.mimeType;
        const fileType = mimeType.includes("opus") || mimeType.includes("webm") ? "webm" : "m4a";
        const audioBlob = new Blob(audioChunks, { type: mimeType });
        const audioUrl = URL.createObjectURL(audioBlob);

        if (dom.audioPlayer) {
            dom.audioPlayer.src = audioUrl;
            dom.audioPlayer.controls = true;
            dom.audioPlayer.classList.remove("sparxstar_visually_hidden");
        }

        _attachAudioToForm(audioBlob, fileType);
        isRecording = false;
        isPaused = false;
        if (dom.recordButton) dom.recordButton.disabled = false;
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
            Object.assign(config, userConfig);
            if (!config.formInstanceId) {
                _error("`formInstanceId` is missing in config. Initialization failed.");
                return false;
            }
            _log(`Initializing recorder module for instance: ${config.formInstanceId}`);
            if (!_cacheDomElements()) {
                _error("One or more essential UI elements are missing. Cannot initialize.");
                return false;
            }
            this.setupEventListeners();
            return this.setupPermissionsAndUI();
        },

        setupEventListeners: function (remove = false) {
            if (!dom.recordButton || !dom.pauseButton || !dom.deleteButton) return;
            eventHandlers.recordClick = eventHandlers.recordClick || (() => isRecording ? this.stop() : this.start());
            eventHandlers.pauseClick = eventHandlers.pauseClick || (() => isRecording && (isPaused ? this.resume() : this.pause()));
            eventHandlers.deleteClick = eventHandlers.deleteClick || (() => this.cleanup(config.formInstanceId));
            const action = remove ? 'removeEventListener' : 'addEventListener';
            dom.recordButton[action]('click', eventHandlers.recordClick);
            dom.pauseButton[action]('click', eventHandlers.pauseClick);
            dom.deleteButton[action]('click', eventHandlers.deleteClick);
        },

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
        
        start: async function () {
            if (isRecording) return;
            _log("start() called.");
            if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
                _updateStatus("Audio recording is not supported on your browser.", "error");
                return;
            }
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                currentStream = stream;
                audioChunks = [];
                const selectedMimeType = ["audio/webm;codecs=opus", "audio/mp4"].find(MediaRecorder.isTypeSupported) || "audio/webm";
                mediaRecorder = new MediaRecorder(stream, { mimeType: selectedMimeType });
                mediaRecorder.ondataavailable = _handleDataAvailable;
                mediaRecorder.onstop = _handleStop;
                mediaRecorder.start(1000); // Trigger dataavailable event every second
                
                const startTime = Date.now();
                timerInterval = setInterval(() => {
                    accumulatedElapsedTime = Date.now() - startTime;
                    if (dom.timerDisplay) dom.timerDisplay.textContent = _formatTime(accumulatedElapsedTime);
                }, 1000);

                isRecording = true;
                isPaused = false;
                if (dom.recordButton) dom.recordButton.textContent = "Stop";
                if (dom.pauseButton) dom.pauseButton.disabled = false;
                _updateStatus("Recording...", "info");
            } catch (error) {
                _error("getUserMedia error:", error.name, error.message);
                const message = error.name === "NotAllowedError" ? "Microphone permission denied." : "Could not find a microphone.";
                _updateStatus(message, "error");
            }
        },
        
        stop: function () {
            if (isRecording && mediaRecorder?.state !== "inactive") {
                mediaRecorder.stop();
            }
        },

        pause: function () {
            if (isRecording && !isPaused && mediaRecorder?.state === "recording") {
                mediaRecorder.pause();
                clearInterval(timerInterval);
                isPaused = true;
                if (dom.pauseButton) dom.pauseButton.textContent = "Resume";
                _updateStatus("Recording paused.", "info");
            }
        },

        resume: function () {
            if (isRecording && isPaused && mediaRecorder?.state === "paused") {
                mediaRecorder.resume();
                const resumeTime = Date.now();
                timerInterval = setInterval(() => {
                    const elapsedSinceResume = Date.now() - resumeTime;
                    if (dom.timerDisplay) dom.timerDisplay.textContent = _formatTime(accumulatedElapsedTime + elapsedSinceResume);
                }, 1000);

                isPaused = false;
                if (dom.pauseButton) dom.pauseButton.textContent = "Pause";
                _updateStatus("Recording resumed.", "info");
            }
        },

        cleanup: function (instanceId) {
             _log(`Cleanup called for instance: ${instanceId}`);
             if (isRecording) this.stop();
             if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
                currentStream = null;
             }
             audioChunks = [];
             isRecording = false;
             isPaused = false;
             _handleRecordingReady();
             if (dom.uuidField) dom.uuidField.value = "";
             if (dom.fileInput) dom.fileInput.value = "";
        },

        destroy: function () {
            _log("Destroying recorder instance.");
            this.cleanup(config.formInstanceId);
            this.setupEventListeners(true);
            recordButtonEnforcer?.disconnect();
            recordButtonEnforcer = null;
            dom = {};
        },

        getAudioId: function () {
            return dom.uuidField ? dom.uuidField.value : null;
        },
    };

    return publicMethods;
})();
