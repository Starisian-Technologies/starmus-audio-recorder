/**
 * @file starmus-main.js
 * @version 7.1.0-OFFLINE-FIX
 * @description Main entry point for the Starmus Audio Recorder system.
 *
 * Orchestrates the initialization of all core modules including:
 * - State management (Redux-style store)
 * - Audio recording and playback
 * - UI rendering and interaction
 * - Offline queue management
 * - Automatic metadata synchronization
 * - Transcript controller
 * - Audio editor (Peaks.js)
 *
 * Supports two main modes:
 * 1. **Recorder Mode**: Full audio recording workflow with calibration, recording, and submission
 * 2. **Editor Mode**: Waveform visualization and annotation editing with Peaks.js
 *
 * Bootstrap process:
 * - Detects page type (recorder form or editor)
 * - Initializes appropriate component set
 * - Exposes global APIs for external integration
 *
 * @module starmus-main
 * @requires peaks.js
 * @requires starmus-hooks
 * @requires starmus-state-store
 * @requires starmus-core
 * @requires starmus-ui
 * @requires starmus-recorder
 * @requires starmus-offline
 * @requires starmus-metadata-auto
 * @requires starmus-transcript-controller
 * @requires starmus-audio-editor
 * @requires starmus-integrator
 */

"use strict";

/* 1. GLOBALS & HOOKS */
import Peaks from "peaks.js";
if (!window.Peaks) {
    window.Peaks = Peaks;
}
import "./starmus-hooks.js";
/* 2. MODULE IMPORTS */
import { createStore } from "./starmus-state-store.js";
import { initCore } from "./starmus-core.js";
import { initInstance as initUI } from "./starmus-ui.js";
import { initRecorder } from "./starmus-recorder.js";
// CRITICAL FIX: Added getOfflineQueue to imports
import { initOffline, queueSubmission, getOfflineQueue } from "./starmus-offline.js";
import { initAutoMetadata } from "./starmus-metadata-auto.js";
import TranscriptModule from "./starmus-transcript-controller.js";
import { default as StarmusAudioEditor } from "./starmus-audio-editor.js";
import StarmusCueEventsManager from "./starmus-cue-events.js";
import "./starmus-integrator.js";
import sparxstarIntegration from "./starmus-sparxstar-integration.js";

(function () {
    const log = (type, data) => {
        console.warn("[STARMUS RUNTIME]", type, data);
    };

    window.addEventListener("error", (e) => {
        log("window.error", {
            message: e.message,
            file: e.filename,
            line: e.lineno,
            col: e.colno,
        });
    });

    window.addEventListener("unhandledrejection", (e) => {
        log("unhandledrejection", e.reason);
    });
})();

/* 3. SETUP STORE */
/**
 * Central Redux-style store for application state management.
 * Manages recording state, calibration data, submission progress, and UI state.
 * Accessible globally for debugging and external integration.
 *
 * @type {Object}
 * @global
 * @property {function} getState - Returns current application state
 * @property {function} dispatch - Dispatches actions to update state
 * @property {function} subscribe - Subscribes to state changes
 *
 * @example
 * // Access global store
 * const currentState = window.StarmusStore.getState();
 *
 * @example
 * // Dispatch action
 * window.StarmusStore.dispatch({ type: 'starmus/reset' });
 */
const store = createStore();
window.StarmusStore = store;

/**
 * Initializes the Starmus Recorder components for a given form instance.
 * Sets up complete recording workflow including state management, UI controls,
 * audio recording, offline queue, and automatic metadata synchronization.
 *
 * @function initRecorderInstance
 * @param {HTMLFormElement} recorderForm - Form element with data-starmus-instance attribute
 * @param {string} instanceId - Unique identifier for this recorder instance
 * @returns {void}
 *
 * @description Initialization sequence:
 * 1. **Form Setup**: Prevents default form submission
 * 2. **Core Module**: Initializes command bus and state management
 * 3. **UI Module**: Sets up DOM bindings and rendering
 * 4. **Recorder Module**: Initializes MediaRecorder and audio processing
 * 5. **Offline Module**: Sets up IndexedDB queue for failed uploads
 * 6. **Metadata Module**: Syncs state to hidden form fields
 *
 * @example
 * // Manual initialization
 * const form = document.querySelector('form[data-starmus-instance="rec-123"]');
 * initRecorderInstance(form, 'rec-123');
 *
 * @see {@link initCore} Core module initialization
 * @see {@link initUI} UI module initialization
 * @see {@link initRecorder} Recording module initialization
 * @see {@link initOffline} Offline queue initialization
 * @see {@link initAutoMetadata} Metadata synchronization
 */
function initRecorderInstance(recorderForm, instanceId) {
    console.log("[StarmusMain] Booting RECORDER for ID:", instanceId);

    recorderForm.addEventListener("submit", (e) => e.preventDefault());

    // Initialize SPARXSTAR integration first, then other modules
    sparxstarIntegration
        .init()
        .then((environmentData) => {
            console.log("[StarmusMain] SPARXSTAR integration ready:", environmentData);

            // Initialize other modules with environment data
            initCore(store, instanceId, environmentData);
            initUI(store, {}, instanceId);
            initRecorder(store, instanceId);
            initOffline();
            initAutoMetadata(store, recorderForm, {});
        })
        .catch((error) => {
            console.warn("[StarmusMain] SPARXSTAR integration failed, using fallback:", error);

            // Fallback initialization without SPARXSTAR
            initCore(store, instanceId, {});
            initUI(store, {}, instanceId);
            initRecorder(store, instanceId);
            initOffline();
            initAutoMetadata(store, recorderForm, {});
        });
}

/**
 * Initializes the Starmus Audio Editor component for waveform editing.
 * Provides Peaks.js-based audio annotation interface for existing recordings.
 * Requires STARMUS_EDITOR_DATA to be present in global scope.
 *
 * @function initEditorInstance
 * @returns {void}
 *
 * @description Required global data structure:
 * ```javascript
 * window.STARMUS_EDITOR_DATA = {
 *   restUrl: '/wp-json/star-/v1/save-annotations',
 *   nonce: 'wp_nonce_value',
 *   postId: 123,
 *   audioUrl: '/uploads/recording.wav',
 *   annotations: [{ startTime: 5.0, endTime: 10.0, label: 'Intro' }]
 * };
 * ```
 *
 * @description Features:
 * - Waveform visualization with overview and zoom views
 * - Interactive annotation regions with editable labels
 * - Audio playback controls and navigation
 * - Save annotations via WordPress REST API
 *
 * @example
 * // Automatic initialization (called by bootstrap)
 * initEditorInstance();
 *
 * @see {@link StarmusAudioEditor.init} Audio editor initialization
 * @see {@link window.STARMUS_EDITOR_DATA} Required global configuration
 */

function initEditorInstance() {
    console.log("[StarmusMain] Booting EDITOR...");

    if (window.STARMUS_EDITOR_DATA && window.STARMUS_EDITOR_DATA.audioUrl) {
        // Initialize the core Editor
        StarmusAudioEditor.init()
            .then((peaksInstance) => {
                // 2. INITIALIZE CUE EVENTS MANAGER
                // We pass the peaksInstance returned by the Editor's init
                window.StarmusCueEvents = new StarmusCueEventsManager(peaksInstance, {
                    pointsTableId: "points-list",
                    segmentsTableId: "segments-list",
                    showNotifications: true,
                    autoHighlight: true,
                });

                console.log("[StarmusMain] Cue Events Manager connected to Peaks.");
            })
            .catch((err) => {
                console.error("[StarmusMain] Editor or Cue Manager failed to init:", err);
            });
    } else {
        console.warn("[StarmusMain] Editor data missing.");
    }
}

/**
 * Bootstrap process - automatically detects and initializes appropriate components.
 * Runs when DOM is ready and determines whether to initialize recorder or editor mode.
 *
 * @description Detection logic:
 * 1. **Recorder Mode**: Looks for `form[data-starmus-instance]` element
 * 2. **Editor Mode**: Looks for `#starmus-editor-root` element
 * 3. **None Found**: Logs warning but doesn't throw error
 *
 * @description Error handling:
 * - Catches and logs initialization errors
 * - Continues execution even if bootstrap fails
 * - Provides console feedback for debugging
 *
 * @listens DOMContentLoaded
 *
 * @example
 * // For recorder mode, ensure DOM contains:
 * // <form data-starmus-instance="rec-123">...</form>
 *
 * @example
 * // For editor mode, ensure DOM contains:
 * // <div id="starmus-editor-root">...</div>
 */
/* 4. BOOTSTRAP ON DOM READY */
document.addEventListener("DOMContentLoaded", () => {
    try {
        const recorderForm = document.querySelector("form[data-starmus-instance]");
        const editorRoot = document.getElementById("starmus-editor-root");

        if (recorderForm) {
            const instanceId = recorderForm.getAttribute("data-starmus-instance");
            initRecorderInstance(recorderForm, instanceId);
        } else if (editorRoot) {
            initEditorInstance();
        } else {
            console.warn("[StarmusMain] ⚠️ No Starmus form or editor found.");
        }
    } catch (e) {
        console.error("[StarmusMain] Boot failed:", e);
    }
});

/**
 * Global API exports for external integration and debugging.
 * Provides access to core functionality through window object.
 *
 * @namespace GlobalExports
 */
/* 5. EXPORTS */

/**
 * Global recorder initialization function.
 * Allows external scripts to initialize recorder functionality.
 *
 * @global
 * @type {Function}
 * @memberof GlobalExports
 * @see {@link initRecorder} Recorder module initialization
 *
 * @example
 * // External initialization
 * window.StarmusRecorder(store, 'custom-instance-id');
 */
window.StarmusRecorder = initRecorder;

/**
 * Global TUS upload utilities for offline submission management.
 * Provides access to queuing system for failed uploads.
 *
 * @global
 * @type {Object}
 * @memberof GlobalExports
 * @property {Function} queueSubmission - Queue a submission for offline processing
 *
 * @example
 * // Queue a failed submission
 * window.StarmusTus.queueSubmission({
 *   url: '/upload/endpoint',
 *   file: audioBlob,
 *   metadata: { postId: 123 }
 * });
 */
window.StarmusTus = { queueSubmission };

/**
 * Global offline queue access function.
 * Provides direct access to the offline submission queue.
 *
 * @global
 * @type {Function}
 * @memberof GlobalExports
 * @returns {Object} Offline queue instance with add, getAll, remove methods
 *
 * @example
 * // Access offline queue
 * const queue = window.StarmusOfflineQueue();
 * const pending = await queue.getAll();
 * console.log('Pending submissions:', pending.length);
 */
window.StarmusOfflineQueue = getOfflineQueue;

/**
 * Global transcript controller module for speech recognition integration.
 * Provides karaoke-style transcript synchronization with audio playback.
 *
 * @global
 * @type {Object}
 * @memberof GlobalExports
 * @see {@link TranscriptModule} Transcript controller implementation
 *
 * @example
 * // Initialize transcript controller
 * window.StarmusTranscriptController.init(peaksInstance, transcriptData);
 */
window.StarmusTranscriptController = TranscriptModule;

/**
 * Global audio editor module for waveform annotation editing.
 * Provides Peaks.js-based audio editing interface.
 *
 * @global
 * @type {Object}
 * @memberof GlobalExports
 * @see {@link StarmusAudioEditor} Audio editor implementation
 *
 * @example
 * // Initialize editor manually
 * window.StarmusAudioEditor.init();
 */
window.StarmusAudioEditor = StarmusAudioEditor;

/**
 * Global SPARXSTAR integration access for external scripts.
 * Provides access to environment detection and error reporting.
 *
 * @global
 * @type {Object}
 * @memberof GlobalExports
 * @see {@link sparxstarIntegration} SPARXSTAR integration implementation
 *
 * @example
 * // Get current environment data
 * const envData = window.SparxstarIntegration.getEnvironmentData();
 * console.log('Current tier:', envData.tier);
 *
 * @example
 * // Report custom error
 * window.SparxstarIntegration.reportError('custom_error', {
 *   message: 'Something went wrong',
 *   context: 'user_action'
 * });
 */
window.SparxstarIntegration = sparxstarIntegration;
