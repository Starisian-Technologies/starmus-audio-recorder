# starmus-ui.js

**Source:** `src/js/starmus-ui.js`

---

## Modules

<dl>
<dt><a href="#module_initInstance">initInstance</a> ⇒ <code>function</code></dt>
<dd><p>Setup process:</p>
<ol>
<li>Determines instance ID from parameter or store state</li>
<li>Finds form container or uses document as root</li>
<li>Queries for all required DOM elements using data attributes</li>
<li>Binds event handlers with safeBind for all interactive elements</li>
<li>Sets up form validation for step 1 continue button</li>
<li>Configures audio playback controls with URL.createObjectURL</li>
<li>Handles file input for Tier C browser fallback</li>
<li>Subscribes to offline queue updates</li>
<li>Dispatches init action and returns state subscription</li>
</ol>
</dd>
</dl>

## Members

<dl>
<dt><a href="#currentAudio">currentAudio</a> : <code>Audio</code> | <code>null</code></dt>
<dd><p>Currently playing audio instance for playback controls.
Used to manage audio playback state and prevent multiple simultaneous playback.</p>
</dd>
</dl>

## Functions

<dl>
<dt><a href="#formatTime">formatTime(seconds)</a> ⇒ <code>string</code></dt>
<dd><p>Formats seconds into MM&#39;m SS&#39;s format for timer display.
Handles invalid numbers gracefully by returning default format.</p>
</dd>
<dt><a href="#safeBind">safeBind(element, eventName, handler)</a> ⇒ <code>void</code></dt>
<dd><p>Safety features:</p>
<ul>
<li>Checks for null elements</li>
<li>Prevents duplicate event bindings</li>
<li>Calls preventDefault() on cancelable events</li>
<li>Stops event propagation</li>
<li>Respects element disabled state</li>
</ul>
</dd>
<dt><a href="#render">render(state, elements)</a> ⇒ <code>void</code></dt>
<dd><p>Rendering sections:</p>
<ol>
<li>Tier C fallback - Shows file upload for unsupported browsers</li>
<li>Audio meters - Updates volume and duration visual indicators</li>
<li>Step visibility - Controls step 1/2 container display</li>
<li>Calibration UI - Manages setup button state and messages</li>
<li>Recording controls - Shows/hides appropriate action buttons</li>
<li>Submit button - Updates upload progress and success states</li>
</ol>
</dd>
</dl>

<a name="module_initInstance"></a>

## initInstance ⇒ <code>function</code>
Setup process:
1. Determines instance ID from parameter or store state
2. Finds form container or uses document as root
3. Queries for all required DOM elements using data attributes
4. Binds event handlers with safeBind for all interactive elements
5. Sets up form validation for step 1 continue button
6. Configures audio playback controls with URL.createObjectURL
7. Handles file input for Tier C browser fallback
8. Subscribes to offline queue updates
9. Dispatches init action and returns state subscription

**Returns**: <code>function</code> - Unsubscribe function for state change listener  

| Param | Type | Description |
| --- | --- | --- |
| store | <code>Object</code> | Redux-style store for state management |
| store.getState | <code>function</code> | Function to get current state |
| store.dispatch | <code>function</code> | Function to dispatch actions |
| store.subscribe | <code>function</code> | Function to subscribe to state changes |
| [incomingElements] | <code>Object</code> | Optional pre-selected DOM elements (unused) |
| [forcedInstanceId] | <code>string</code> | Optional forced instance ID override |

**Example**  
```js
const unsubscribe = initInstance(store, null, 'rec-123');
// Later: unsubscribe() to clean up
```

* [initInstance](#module_initInstance) ⇒ <code>function</code>
    * [~el](#module_initInstance..el) : <code>Object</code>
    * [~fileInput](#module_initInstance..fileInput)

<a name="module_initInstance..el"></a>

### initInstance~el : <code>Object</code>
DOM element references object.
Contains all interactive elements found within the instance root.

**Kind**: inner property of [<code>initInstance</code>](#module_initInstance)  
<a name="module_initInstance..fileInput"></a>

### initInstance~fileInput
File input handler for Tier C browser fallback.
Handles audio file uploads when MediaRecorder is not supported.

**Kind**: inner property of [<code>initInstance</code>](#module_initInstance)  
<a name="currentAudio"></a>

## currentAudio : <code>Audio</code> \| <code>null</code>
Currently playing audio instance for playback controls.
Used to manage audio playback state and prevent multiple simultaneous playback.

**Kind**: global variable  
<a name="formatTime"></a>

## formatTime(seconds) ⇒ <code>string</code>
Formats seconds into MM'm SS's format for timer display.
Handles invalid numbers gracefully by returning default format.

**Kind**: global function  
**Returns**: <code>string</code> - Formatted time string (e.g., "02m 30s")  

| Param | Type | Description |
| --- | --- | --- |
| seconds | <code>number</code> | Time in seconds to format |

**Example**  
```js
formatTime(150) // Returns "02m 30s"
formatTime(65)  // Returns "01m 05s"
formatTime(NaN) // Returns "00m 00s"
```
<a name="safeBind"></a>

## safeBind(element, eventName, handler) ⇒ <code>void</code>
Safety features:
- Checks for null elements
- Prevents duplicate event bindings
- Calls preventDefault() on cancelable events
- Stops event propagation
- Respects element disabled state

**Kind**: global function  

| Param | Type | Description |
| --- | --- | --- |
| element | <code>HTMLElement</code> \| <code>null</code> | DOM element to bind event to |
| eventName | <code>string</code> | Event type (e.g., 'click', 'change') |
| handler | <code>function</code> | Event handler function to execute |

<a name="render"></a>

## render(state, elements) ⇒ <code>void</code>
Rendering sections:
1. Tier C fallback - Shows file upload for unsupported browsers
2. Audio meters - Updates volume and duration visual indicators
3. Step visibility - Controls step 1/2 container display
4. Calibration UI - Manages setup button state and messages
5. Recording controls - Shows/hides appropriate action buttons
6. Submit button - Updates upload progress and success states

**Kind**: global function  

| Param | Type | Description |
| --- | --- | --- |
| state | <code>Object</code> | Current application state from store |
| state.status | <code>string</code> | Current status (idle/recording/calibrating/etc.) |
| state.step | <code>number</code> | Current UI step (1 or 2) |
| state.tier | <code>string</code> | Browser capability tier (A/B/C) |
| state.recorder | <code>Object</code> | Recording state with duration and amplitude |
| state.recorder.duration | <code>number</code> | Current recording duration in seconds |
| state.recorder.amplitude | <code>number</code> | Current audio amplitude (0-100) |
| state.calibration | <code>Object</code> | Microphone calibration state |
| state.calibration.complete | <code>boolean</code> | Whether calibration is finished |
| state.calibration.volumePercent | <code>number</code> | Volume level during calibration |
| state.calibration.message | <code>string</code> | Current calibration message |
| state.submission | <code>Object</code> | Upload progress state |
| state.submission.progress | <code>number</code> | Upload progress (0.0 to 1.0) |
| elements | <code>Object</code> | DOM element references object |
| elements.step1 | <code>HTMLElement</code> | Step 1 container element |
| elements.step2 | <code>HTMLElement</code> | Step 2 container element |
| elements.setupContainer | <code>HTMLElement</code> | Microphone setup container |
| elements.recorderContainer | <code>HTMLElement</code> | Recording controls container |
| elements.volumeMeter | <code>HTMLElement</code> | Volume level meter element |
| elements.timerElapsed | <code>HTMLElement</code> | Timer display element |
| elements.durationProgress | <code>HTMLElement</code> | Recording progress indicator |
| elements.setupMicBtn | <code>HTMLElement</code> | Setup microphone button |
| elements.recordBtn | <code>HTMLElement</code> | Start recording button |
| elements.pauseBtn | <code>HTMLElement</code> | Pause recording button |
| elements.resumeBtn | <code>HTMLElement</code> | Resume recording button |
| elements.stopBtn | <code>HTMLElement</code> | Stop recording button |
| elements.playBtn | <code>HTMLElement</code> | Audio playback button |
| elements.resetBtn | <code>HTMLElement</code> | Reset/discard button |
| elements.submitBtn | <code>HTMLElement</code> | Submit recording button |
| elements.reviewControls | <code>HTMLElement</code> | Review controls container |



---

_Generated by Starisian JS Documentation Generator_
