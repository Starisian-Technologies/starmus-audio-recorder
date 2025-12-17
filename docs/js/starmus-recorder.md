# starmus-recorder.js

**Source:** `src/js/starmus-recorder.js`

---

## Modules

<dl>
<dt><a href="#module_initRecorder">initRecorder</a> ⇒ <code>void</code></dt>
<dd><p>Registers handlers for these commands:</p>
<ul>
<li>&#39;setup-mic&#39;: Request microphone access and perform calibration</li>
<li>&#39;start-recording&#39;: Begin audio recording with speech recognition</li>
<li>&#39;stop-mic&#39;: Stop recording and save audio blob</li>
<li>&#39;pause-mic&#39;: Pause ongoing recording</li>
<li>&#39;resume-mic&#39;: Resume paused recording</li>
</ul>
<p>All commands are filtered by instanceId to support multiple recorder instances.</p>
</dd>
<dt><a href="#module_{function}">{function}</a></dt>
<dd><p>Explicit export for build system compatibility.
Exports initRecorder function for use in other modules.</p>
</dd>
</dl>

## Members

<dl>
<dt><a href="#sharedAudioContext">sharedAudioContext</a> : <code>AudioContext</code> | <code>null</code></dt>
<dd><p>Shared AudioContext instance for all recorder instances.
Reused to avoid multiple context creation and ensure proper resource management.</p>
</dd>
</dl>

## Constants

<dl>
<dt><a href="#recorderRegistry">recorderRegistry</a> : <code>Map.&lt;string, Object&gt;</code></dt>
<dd><p>Registry of active recorder instances mapped by instanceId.
Stores MediaRecorder, animation frame ID, and speech recognition objects.</p>
</dd>
<dt><a href="#SpeechRecognition">SpeechRecognition</a> : <code>function</code> | <code>undefined</code></dt>
<dd><p>Speech Recognition API with webkit fallback.
Used for real-time transcription during recording.</p>
</dd>
</dl>

## Functions

<dl>
<dt><a href="#getContext">getContext()</a> ⇒ <code>AudioContext</code></dt>
<dd><p>Gets or creates shared AudioContext with optimal settings.
Creates new context if none exists or previous was closed.
Sets global window.StarmusAudioContext reference.</p>
</dd>
<dt><a href="#wakeAudio">wakeAudio()</a> ⇒ <code>Promise.&lt;AudioContext&gt;</code></dt>
<dd><p>Wakes up AudioContext if suspended due to browser autoplay policies.
Must be called after user interaction to enable audio processing.</p>
</dd>
<dt><a href="#doCalibration">doCalibration(stream, onUpdate)</a> ⇒ <code>Promise.&lt;Object&gt;</code> | <code>boolean</code> | <code>number</code> | <code>number</code></dt>
<dd><p>Calibration phases:</p>
<ul>
<li>Phase 1 (0-5s): Measure background noise</li>
<li>Phase 2 (5-10s): Detect speech levels</li>
<li>Phase 3 (10-15s): Optimize settings</li>
</ul>
</dd>
</dl>

<a name="module_initRecorder"></a>

## initRecorder ⇒ <code>void</code>
Registers handlers for these commands:
- 'setup-mic': Request microphone access and perform calibration
- 'start-recording': Begin audio recording with speech recognition
- 'stop-mic': Stop recording and save audio blob
- 'pause-mic': Pause ongoing recording
- 'resume-mic': Resume paused recording

All commands are filtered by instanceId to support multiple recorder instances.


| Param | Type | Description |
| --- | --- | --- |
| store | <code>Object</code> | Redux-style store for state management |
| store.dispatch | <code>function</code> | Function to dispatch state actions |
| instanceId | <code>string</code> | Unique identifier for this recorder instance |

<a name="module_{function}"></a>

## {function}
Explicit export for build system compatibility.
Exports initRecorder function for use in other modules.

<a name="sharedAudioContext"></a>

## sharedAudioContext : <code>AudioContext</code> \| <code>null</code>
Shared AudioContext instance for all recorder instances.
Reused to avoid multiple context creation and ensure proper resource management.

**Kind**: global variable  
<a name="recorderRegistry"></a>

## recorderRegistry : <code>Map.&lt;string, Object&gt;</code>
Registry of active recorder instances mapped by instanceId.
Stores MediaRecorder, animation frame ID, and speech recognition objects.

**Kind**: global constant  
**Properties**

| Name | Type | Description |
| --- | --- | --- |
| mediaRecorder | <code>MediaRecorder</code> | MediaRecorder instance for audio capture |
| rafId | <code>number</code> \| <code>null</code> | RequestAnimationFrame ID for visual updates |
| recognition | [<code>SpeechRecognition</code>](#SpeechRecognition) \| <code>null</code> | Speech recognition instance |

<a name="SpeechRecognition"></a>

## SpeechRecognition : <code>function</code> \| <code>undefined</code>
Speech Recognition API with webkit fallback.
Used for real-time transcription during recording.

**Kind**: global constant  
<a name="getContext"></a>

## getContext() ⇒ <code>AudioContext</code>
Gets or creates shared AudioContext with optimal settings.
Creates new context if none exists or previous was closed.
Sets global window.StarmusAudioContext reference.

**Kind**: global function  
**Returns**: <code>AudioContext</code> - Shared AudioContext instance  
**Throws**:

- <code>Error</code> When Audio API is not supported in browser

<a name="wakeAudio"></a>

## wakeAudio() ⇒ <code>Promise.&lt;AudioContext&gt;</code>
Wakes up AudioContext if suspended due to browser autoplay policies.
Must be called after user interaction to enable audio processing.

**Kind**: global function  
**Returns**: <code>Promise.&lt;AudioContext&gt;</code> - Promise resolving to active AudioContext  
<a name="doCalibration"></a>

## doCalibration(stream, onUpdate) ⇒ <code>Promise.&lt;Object&gt;</code> \| <code>boolean</code> \| <code>number</code> \| <code>number</code>
Calibration phases:
- Phase 1 (0-5s): Measure background noise
- Phase 2 (5-10s): Detect speech levels
- Phase 3 (10-15s): Optimize settings

**Kind**: global function  
**Returns**: <code>Promise.&lt;Object&gt;</code> - Calibration results<code>boolean</code> - returns.complete - Always true when resolved<code>number</code> - returns.gain - Audio gain multiplier (currently 1.0)<code>number</code> - returns.speechLevel - Maximum detected volume level  

| Param | Type | Description |
| --- | --- | --- |
| stream | <code>MediaStream</code> | Audio stream from getUserMedia |
| onUpdate | <code>function</code> | Callback for calibration progress updates |
| onUpdate.message | <code>string</code> | Current calibration phase message |
| onUpdate.volumePercent | <code>number</code> | Volume level (0-100) |
| onUpdate.isComplete | <code>boolean</code> | Whether calibration finished |



---

_Generated by Starisian JS Documentation Generator_
