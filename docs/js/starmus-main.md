# starmus-main.js

**Source:** `src/js/starmus-main.js`

---

## Modules

<dl>
<dt><a href="#module_starmus-main">starmus-main</a></dt>
<dd><p>Main entry point for the Starmus Audio Recorder system.</p>
<p>Orchestrates the initialization of all core modules including:</p>
<ul>
<li>State management (Redux-style store)</li>
<li>Audio recording and playback</li>
<li>UI rendering and interaction</li>
<li>Offline queue management</li>
<li>Automatic metadata synchronization</li>
<li>Transcript controller</li>
<li>Audio editor (Peaks.js)</li>
</ul>
<p>Supports two main modes:</p>
<ol>
<li><strong>Recorder Mode</strong>: Full audio recording workflow with calibration, recording, and submission</li>
<li><strong>Editor Mode</strong>: Waveform visualization and annotation editing with Peaks.js</li>
</ol>
<p>Bootstrap process:</p>
<ul>
<li>Detects page type (recorder form or editor)</li>
<li>Initializes appropriate component set</li>
<li>Exposes global APIs for external integration</li>
</ul>
</dd>
</dl>

## Members

<dl>
<dt><a href="#StarmusRecorder">StarmusRecorder</a> : <code>function</code></dt>
<dd><p>Global recorder initialization function.
Allows external scripts to initialize recorder functionality.</p>
</dd>
<dt><a href="#StarmusTus">StarmusTus</a> : <code>Object</code></dt>
<dd><p>Global TUS upload utilities for offline submission management.
Provides access to queuing system for failed uploads.</p>
</dd>
<dt><a href="#StarmusOfflineQueue">StarmusOfflineQueue</a> ⇒ <code>Object</code></dt>
<dd><p>Global offline queue access function.
Provides direct access to the offline submission queue.</p>
</dd>
<dt><a href="#StarmusTranscriptController">StarmusTranscriptController</a> : <code>Object</code></dt>
<dd><p>Global transcript controller module for speech recognition integration.
Provides karaoke-style transcript synchronization with audio playback.</p>
</dd>
<dt><a href="#StarmusAudioEditor">StarmusAudioEditor</a> : <code>Object</code></dt>
<dd><p>Global audio editor module for waveform annotation editing.
Provides Peaks.js-based audio editing interface.</p>
</dd>
<dt><a href="#SparxstarIntegration">SparxstarIntegration</a> : <code>Object</code></dt>
<dd><p>Global SPARXSTAR integration access for external scripts.
Provides access to environment detection and error reporting.</p>
</dd>
</dl>

## Constants

<dl>
<dt><a href="#store">store</a> : <code>Object</code></dt>
<dd><p>Central Redux-style store for application state management.
Manages recording state, calibration data, submission progress, and UI state.
Accessible globally for debugging and external integration.</p>
</dd>
</dl>

<a name="module_starmus-main"></a>

## starmus-main

Main entry point for the Starmus Audio Recorder system.

Orchestrates the initialization of all core modules including:

- State management (Redux-style store)
- Audio recording and playback
- UI rendering and interaction
- Offline queue management
- Automatic metadata synchronization
- Transcript controller
- Audio editor (Peaks.js)

Supports two main modes:

1. **Recorder Mode**: Full audio recording workflow with calibration, recording, and submission
2. **Editor Mode**: Waveform visualization and annotation editing with Peaks.js

Bootstrap process:

- Detects page type (recorder form or editor)
- Initializes appropriate component set
- Exposes global APIs for external integration

**Requires**: <code>module:peaks.js</code>, <code>module:starmus-hooks</code>, <code>module:starmus-state-store</code>, <code>module:starmus-core</code>, <code>module:starmus-ui</code>, <code>module:starmus-recorder</code>, <code>module:starmus-offline</code>, <code>module:starmus-metadata-auto</code>, <code>module:starmus-transcript-controller</code>, <code>module:starmus-audio-editor</code>, <code>module:starmus-integrator</code>  
**Version**: 7.1.0-OFFLINE-FIX  

- [starmus-main](#module_starmus-main)
  - [~GlobalExports](#module_starmus-main..GlobalExports) : <code>object</code>
  - [~initRecorderInstance(recorderForm, instanceId)](#module_starmus-main..initRecorderInstance) ⇒ <code>void</code>
  - [~initEditorInstance()](#module_starmus-main..initEditorInstance) ⇒ <code>void</code>

<a name="module_starmus-main..GlobalExports"></a>

### starmus-main~GlobalExports : <code>object</code>

Global API exports for external integration and debugging.
Provides access to core functionality through window object.

**Kind**: inner namespace of [<code>starmus-main</code>](#module_starmus-main)  
<a name="module_starmus-main..initRecorderInstance"></a>

### starmus-main~initRecorderInstance(recorderForm, instanceId) ⇒ <code>void</code>

Initialization sequence:

1. **Form Setup**: Prevents default form submission
2. **Core Module**: Initializes command bus and state management
3. **UI Module**: Sets up DOM bindings and rendering
4. **Recorder Module**: Initializes MediaRecorder and audio processing
5. **Offline Module**: Sets up IndexedDB queue for failed uploads
6. **Metadata Module**: Syncs state to hidden form fields

**Kind**: inner method of [<code>starmus-main</code>](#module_starmus-main)  
**See**

- [initCore](initCore) Core module initialization
- [initUI](initUI) UI module initialization
- [initRecorder](initRecorder) Recording module initialization
- [initOffline](initOffline) Offline queue initialization
- [initAutoMetadata](initAutoMetadata) Metadata synchronization

| Param | Type | Description |
| --- | --- | --- |
| recorderForm | <code>HTMLFormElement</code> | Form element with data-starmus-instance attribute |
| instanceId | <code>string</code> | Unique identifier for this recorder instance |

**Example**  

```js
// Manual initialization
const form = document.querySelector('form[data-starmus-instance="rec-123"]');
initRecorderInstance(form, 'rec-123');
```

<a name="module_starmus-main..initEditorInstance"></a>

### starmus-main~initEditorInstance() ⇒ <code>void</code>

Features:

- Waveform visualization with overview and zoom views
- Interactive annotation regions with editable labels
- Audio playback controls and navigation
- Save annotations via WordPress REST API

**Kind**: inner method of [<code>starmus-main</code>](#module_starmus-main)  
**See**

- [StarmusAudioEditor.init](StarmusAudioEditor.init) Audio editor initialization
- [window.STARMUS_EDITOR_DATA](window.STARMUS_EDITOR_DATA) Required global configuration

**Example**  

```js
// Automatic initialization (called by bootstrap)
initEditorInstance();
```

<a name="StarmusRecorder"></a>

## StarmusRecorder : <code>function</code>

Global recorder initialization function.
Allows external scripts to initialize recorder functionality.

**Kind**: global variable  
**See**: [initRecorder](initRecorder) Recorder module initialization  
**Example**  

```js
// External initialization
window.StarmusRecorder(store, 'custom-instance-id');
```

<a name="StarmusTus"></a>

## StarmusTus : <code>Object</code>

Global TUS upload utilities for offline submission management.
Provides access to queuing system for failed uploads.

**Kind**: global variable  
**Properties**

| Name | Type | Description |
| --- | --- | --- |
| queueSubmission | <code>function</code> | Queue a submission for offline processing |

**Example**  

```js
// Queue a failed submission
window.StarmusTus.queueSubmission({
  url: '/upload/endpoint',
  file: audioBlob,
  metadata: { postId: 123 }
});
```

<a name="StarmusOfflineQueue"></a>

## StarmusOfflineQueue ⇒ <code>Object</code>

Global offline queue access function.
Provides direct access to the offline submission queue.

**Kind**: global variable  
**Returns**: <code>Object</code> - Offline queue instance with add, getAll, remove methods  
**Example**  

```js
// Access offline queue
const queue = window.StarmusOfflineQueue();
const pending = await queue.getAll();
console.log('Pending submissions:', pending.length);
```

<a name="StarmusTranscriptController"></a>

## StarmusTranscriptController : <code>Object</code>

Global transcript controller module for speech recognition integration.
Provides karaoke-style transcript synchronization with audio playback.

**Kind**: global variable  
**See**: [TranscriptModule](TranscriptModule) Transcript controller implementation  
**Example**  

```js
// Initialize transcript controller
window.StarmusTranscriptController.init(peaksInstance, transcriptData);
```

<a name="StarmusAudioEditor"></a>

## StarmusAudioEditor : <code>Object</code>

Global audio editor module for waveform annotation editing.
Provides Peaks.js-based audio editing interface.

**Kind**: global variable  
**See**: [StarmusAudioEditor](#StarmusAudioEditor) Audio editor implementation  
**Example**  

```js
// Initialize editor manually
window.StarmusAudioEditor.init();
```

<a name="SparxstarIntegration"></a>

## SparxstarIntegration : <code>Object</code>

Global SPARXSTAR integration access for external scripts.
Provides access to environment detection and error reporting.

**Kind**: global variable  
**See**: [sparxstarIntegration](sparxstarIntegration) SPARXSTAR integration implementation  
**Example**  

```js
// Get current environment data
const envData = window.SparxstarIntegration.getEnvironmentData();
console.log('Current tier:', envData.tier);
```

**Example**  

```js
// Report custom error
window.SparxstarIntegration.reportError('custom_error', {
  message: 'Something went wrong',
  context: 'user_action'
});
```

<a name="store"></a>

## store : <code>Object</code>

Central Redux-style store for application state management.
Manages recording state, calibration data, submission progress, and UI state.
Accessible globally for debugging and external integration.

**Kind**: global constant  
**Properties**

| Name | Type | Description |
| --- | --- | --- |
| getState | <code>function</code> | Returns current application state |
| dispatch | <code>function</code> | Dispatches actions to update state |
| subscribe | <code>function</code> | Subscribes to state changes |

**Example**  

```js
// Access global store
const currentState = window.StarmusStore.getState();
```

**Example**  

```js
// Dispatch action
window.StarmusStore.dispatch({ type: 'starmus/reset' });
```

---

_Generated by Starisian JS Documentation Generator_
