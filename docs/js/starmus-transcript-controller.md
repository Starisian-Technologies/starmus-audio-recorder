# starmus-transcript-controller.js

**Source:** `src/js/starmus-transcript-controller.js`

---

## Modules

<dl>
<dt><a href="#module_{function}">{function}</a></dt>
<dd><p>ES6 module exports for modern build systems.</p>
</dd>
</dl>

## Classes

<dl>
<dt><a href="#StarmusTranscript">StarmusTranscript</a></dt>
<dd><p>StarmusTranscript class for synchronized transcript display.
Manages word-level highlighting, click-to-seek, and auto-scrolling
synchronized with audio playback through Peaks.js integration.</p>
</dd>
</dl>

## Members

<dl>
<dt><a href="#StarmusTranscript">StarmusTranscript</a> : <code>function</code></dt>
<dd><p>Global StarmusTranscript class reference.</p>
</dd>
</dl>

## Objects

<dl>
<dt><a href="#StarmusTranscriptController">StarmusTranscriptController</a> : <code>object</code></dt>
<dd><p>Global transcript controller object with class and factory function.</p>
</dd>
</dl>

## Constants

<dl>
<dt><a href="#BUS">BUS</a> : <code>object</code></dt>
<dd><p>Global command bus reference with fallback.
Used for dispatching transcript events and debugging.</p>
</dd>
<dt><a href="#debugLog">debugLog</a> : <code>function</code></dt>
<dd><p>Debug logging function with fallback no-op.</p>
</dd>
</dl>

## Functions

<dl>
<dt><a href="#init">init(peaksInstance, containerId, transcriptData)</a> ⇒ <code><a href="#StarmusTranscript">StarmusTranscript</a></code></dt>
<dd><p>Factory function to create a new StarmusTranscript instance.
Provides a convenient way to initialize transcript controller.</p>
</dd>
</dl>

<a name="module_{function}"></a>

## {function}

ES6 module exports for modern build systems.

<a name="exp_module_{function}--module.exports"></a>

### module.exports ⏏

Default export object for ES6 import statements.

**Kind**: Exported member  
<a name="StarmusTranscript"></a>

## StarmusTranscript

StarmusTranscript class for synchronized transcript display.
Manages word-level highlighting, click-to-seek, and auto-scrolling
synchronized with audio playback through Peaks.js integration.

**Kind**: global class  

* [StarmusTranscript](#StarmusTranscript)
  * [new StarmusTranscript(peaksInstance, containerId, transcriptData)](#new_StarmusTranscript_new)
  * [.peaks](#StarmusTranscript+peaks) : <code>Object</code>
  * [.container](#StarmusTranscript+container) : <code>HTMLElement</code> \| <code>null</code>
  * [.data](#StarmusTranscript+data) : <code>Array.&lt;Object&gt;</code>
  * [.activeTokenIndex](#StarmusTranscript+activeTokenIndex) : <code>number</code>
  * [.isUserScrolling](#StarmusTranscript+isUserScrolling) : <code>boolean</code>
  * [.scrollTimeout](#StarmusTranscript+scrollTimeout) : <code>number</code> \| <code>null</code>
  * [.boundOnTimeUpdate](#StarmusTranscript+boundOnTimeUpdate) : <code>function</code> \| <code>null</code>
  * [.boundOnSeeked](#StarmusTranscript+boundOnSeeked) : <code>function</code> \| <code>null</code>
  * [.boundOnClick](#StarmusTranscript+boundOnClick) : <code>function</code> \| <code>null</code>
  * [.boundOnScroll](#StarmusTranscript+boundOnScroll) : <code>function</code> \| <code>null</code>
  * [.init()](#StarmusTranscript+init) ⇒ <code>void</code>
  * [.render()](#StarmusTranscript+render) ⇒ <code>void</code>
  * [.bindEvents()](#StarmusTranscript+bindEvents) ⇒ <code>void</code>
  * [.boundOnClick()](#StarmusTranscript+boundOnClick)
  * [.boundOnScroll()](#StarmusTranscript+boundOnScroll)
  * [.boundOnTimeUpdate()](#StarmusTranscript+boundOnTimeUpdate)
  * [.boundOnSeeked()](#StarmusTranscript+boundOnSeeked)
  * [.findTokenIndex(time)](#StarmusTranscript+findTokenIndex) ⇒ <code>number</code>
  * [.syncHighlight(currentTime)](#StarmusTranscript+syncHighlight) ⇒ <code>void</code>
  * [.updateDOM(newIndex)](#StarmusTranscript+updateDOM) ⇒ <code>void</code>
  * [.clearHighlight()](#StarmusTranscript+clearHighlight) ⇒ <code>void</code>
  * [.scrollToWord(el)](#StarmusTranscript+scrollToWord) ⇒ <code>void</code>
  * [.updateData(newData)](#StarmusTranscript+updateData) ⇒ <code>void</code>
  * [.unbindEvents()](#StarmusTranscript+unbindEvents) ⇒ <code>void</code>
  * [.destroy()](#StarmusTranscript+destroy) ⇒ <code>void</code>

<a name="new_StarmusTranscript_new"></a>

### new StarmusTranscript(peaksInstance, containerId, transcriptData)

Creates a StarmusTranscript instance.

| Param | Type | Description |
| --- | --- | --- |
| peaksInstance | <code>Object</code> | Peaks.js waveform instance with player |
| peaksInstance.player | <code>Object</code> | Audio player with seek functionality |
| peaksInstance.player.seek | <code>function</code> | Function to seek to time position |
| peaksInstance.player.getMediaElement | <code>function</code> | Function to get media element |
| peaksInstance.instanceId | <code>string</code> | Instance ID for event dispatching |
| containerId | <code>string</code> | DOM element ID for transcript container |
| transcriptData | <code>Array.&lt;Object&gt;</code> | Array of word timing objects |
| transcriptData[].text | <code>string</code> | Word text content |
| transcriptData[].start | <code>number</code> | Start time in seconds |
| transcriptData[].end | <code>number</code> | End time in seconds |
| [transcriptData[].confidence] | <code>number</code> | Confidence score (0.0-1.0) |

**Example**  

```js
const transcript = new StarmusTranscript(
  peaksInstance,
  'transcript-container',
  [{ text: 'Hello', start: 0.0, end: 0.5, confidence: 0.95 }]
);
```

<a name="StarmusTranscript+peaks"></a>

### starmusTranscript.peaks : <code>Object</code>

Peaks.js instance for audio control.

**Kind**: instance property of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+container"></a>

### starmusTranscript.container : <code>HTMLElement</code> \| <code>null</code>

DOM container element for transcript display.

**Kind**: instance property of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+data"></a>

### starmusTranscript.data : <code>Array.&lt;Object&gt;</code>

Array of word timing data objects.

**Kind**: instance property of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+activeTokenIndex"></a>

### starmusTranscript.activeTokenIndex : <code>number</code>

Index of currently highlighted word.

**Kind**: instance property of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+isUserScrolling"></a>

### starmusTranscript.isUserScrolling : <code>boolean</code>

Flag indicating user is manually scrolling.

**Kind**: instance property of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+scrollTimeout"></a>

### starmusTranscript.scrollTimeout : <code>number</code> \| <code>null</code>

Timeout ID for scroll detection reset.

**Kind**: instance property of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+boundOnTimeUpdate"></a>

### starmusTranscript.boundOnTimeUpdate : <code>function</code> \| <code>null</code>

Bound timeupdate event handler reference.

**Kind**: instance property of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+boundOnSeeked"></a>

### starmusTranscript.boundOnSeeked : <code>function</code> \| <code>null</code>

Bound seeked event handler reference.

**Kind**: instance property of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+boundOnClick"></a>

### starmusTranscript.boundOnClick : <code>function</code> \| <code>null</code>

Bound click event handler reference.

**Kind**: instance property of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+boundOnScroll"></a>

### starmusTranscript.boundOnScroll : <code>function</code> \| <code>null</code>

Bound scroll event handler reference.

**Kind**: instance property of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+init"></a>

### starmusTranscript.init() ⇒ <code>void</code>

Initializes the transcript controller.
Sets up DOM rendering and event binding if container exists.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+render"></a>

### starmusTranscript.render() ⇒ <code>void</code>

Renders transcript words into DOM container.
Creates word spans with timing data attributes and confidence indicators.
Uses DocumentFragment for efficient bulk DOM updates.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+bindEvents"></a>

### starmusTranscript.bindEvents() ⇒ <code>void</code>

Event handlers:

* Click: Seeks audio to clicked word's start time
* Scroll: Detects user scrolling to pause auto-scroll
* Timeupdate: Syncs highlight with audio playback position
* Seeked: Updates highlight when audio position changes

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+boundOnClick"></a>

### starmusTranscript.boundOnClick()

Click-to-seek handler for word elements.
Extracts start time and seeks audio player to that position.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+boundOnScroll"></a>

### starmusTranscript.boundOnScroll()

Scroll detection handler.
Sets user scrolling flag and resets it after timeout.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+boundOnTimeUpdate"></a>

### starmusTranscript.boundOnTimeUpdate()

Timeupdate handler for continuous playback sync.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+boundOnSeeked"></a>

### starmusTranscript.boundOnSeeked()

Seeked handler for position change sync.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+findTokenIndex"></a>

### starmusTranscript.findTokenIndex(time) ⇒ <code>number</code>

Uses binary search algorithm for O(log n) performance.
Checks if time falls within token's start-end range.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
**Returns**: <code>number</code> - Index of matching token, or -1 if not found  

| Param | Type | Description |
| --- | --- | --- |
| time | <code>number</code> | Current audio time in seconds |

<a name="StarmusTranscript+syncHighlight"></a>

### starmusTranscript.syncHighlight(currentTime) ⇒ <code>void</code>

Synchronizes word highlighting with current audio time.
Updates active token index and triggers DOM updates if changed.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  

| Param | Type | Description |
| --- | --- | --- |
| currentTime | <code>number</code> | Current audio playback time |

<a name="StarmusTranscript+updateDOM"></a>

### starmusTranscript.updateDOM(newIndex) ⇒ <code>void</code>

Updates DOM to highlight new active word.
Removes previous highlight and adds new one with optional auto-scroll.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  

| Param | Type | Description |
| --- | --- | --- |
| newIndex | <code>number</code> | Index of word to highlight |

<a name="StarmusTranscript+clearHighlight"></a>

### starmusTranscript.clearHighlight() ⇒ <code>void</code>

Clears all word highlighting.
Removes active class and resets active token index.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+scrollToWord"></a>

### starmusTranscript.scrollToWord(el) ⇒ <code>void</code>

Scrolls container to show specified word element.
Uses smooth scrolling with center alignment when available.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  

| Param | Type | Description |
| --- | --- | --- |
| el | <code>HTMLElement</code> | Word element to scroll to |

<a name="StarmusTranscript+updateData"></a>

### starmusTranscript.updateData(newData) ⇒ <code>void</code>

Updates transcript data and re-initializes display.
Replaces current data, re-renders DOM, and rebinds events.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  

| Param | Type | Description |
| --- | --- | --- |
| newData | <code>Array.&lt;Object&gt;</code> | New transcript data array |
| newData[].text | <code>string</code> | Word text content |
| newData[].start | <code>number</code> | Start time in seconds |
| newData[].end | <code>number</code> | End time in seconds |
| [newData[].confidence] | <code>number</code> | Confidence score (0.0-1.0) |

<a name="StarmusTranscript+unbindEvents"></a>

### starmusTranscript.unbindEvents() ⇒ <code>void</code>

Unbinds all event handlers to prevent memory leaks.
Removes listeners from container and media elements.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+destroy"></a>

### starmusTranscript.destroy() ⇒ <code>void</code>

Destroys the transcript instance and cleans up all resources.
Unbinds events, clears timeouts, empties container, and resets state.
Call this method when transcript is no longer needed.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript"></a>

## StarmusTranscript : <code>function</code>

Global StarmusTranscript class reference.

**Kind**: global variable  

* [StarmusTranscript](#StarmusTranscript) : <code>function</code>
  * [new StarmusTranscript(peaksInstance, containerId, transcriptData)](#new_StarmusTranscript_new)
  * [.peaks](#StarmusTranscript+peaks) : <code>Object</code>
  * [.container](#StarmusTranscript+container) : <code>HTMLElement</code> \| <code>null</code>
  * [.data](#StarmusTranscript+data) : <code>Array.&lt;Object&gt;</code>
  * [.activeTokenIndex](#StarmusTranscript+activeTokenIndex) : <code>number</code>
  * [.isUserScrolling](#StarmusTranscript+isUserScrolling) : <code>boolean</code>
  * [.scrollTimeout](#StarmusTranscript+scrollTimeout) : <code>number</code> \| <code>null</code>
  * [.boundOnTimeUpdate](#StarmusTranscript+boundOnTimeUpdate) : <code>function</code> \| <code>null</code>
  * [.boundOnSeeked](#StarmusTranscript+boundOnSeeked) : <code>function</code> \| <code>null</code>
  * [.boundOnClick](#StarmusTranscript+boundOnClick) : <code>function</code> \| <code>null</code>
  * [.boundOnScroll](#StarmusTranscript+boundOnScroll) : <code>function</code> \| <code>null</code>
  * [.init()](#StarmusTranscript+init) ⇒ <code>void</code>
  * [.render()](#StarmusTranscript+render) ⇒ <code>void</code>
  * [.bindEvents()](#StarmusTranscript+bindEvents) ⇒ <code>void</code>
  * [.boundOnClick()](#StarmusTranscript+boundOnClick)
  * [.boundOnScroll()](#StarmusTranscript+boundOnScroll)
  * [.boundOnTimeUpdate()](#StarmusTranscript+boundOnTimeUpdate)
  * [.boundOnSeeked()](#StarmusTranscript+boundOnSeeked)
  * [.findTokenIndex(time)](#StarmusTranscript+findTokenIndex) ⇒ <code>number</code>
  * [.syncHighlight(currentTime)](#StarmusTranscript+syncHighlight) ⇒ <code>void</code>
  * [.updateDOM(newIndex)](#StarmusTranscript+updateDOM) ⇒ <code>void</code>
  * [.clearHighlight()](#StarmusTranscript+clearHighlight) ⇒ <code>void</code>
  * [.scrollToWord(el)](#StarmusTranscript+scrollToWord) ⇒ <code>void</code>
  * [.updateData(newData)](#StarmusTranscript+updateData) ⇒ <code>void</code>
  * [.unbindEvents()](#StarmusTranscript+unbindEvents) ⇒ <code>void</code>
  * [.destroy()](#StarmusTranscript+destroy) ⇒ <code>void</code>

<a name="new_StarmusTranscript_new"></a>

### new StarmusTranscript(peaksInstance, containerId, transcriptData)

Creates a StarmusTranscript instance.

| Param | Type | Description |
| --- | --- | --- |
| peaksInstance | <code>Object</code> | Peaks.js waveform instance with player |
| peaksInstance.player | <code>Object</code> | Audio player with seek functionality |
| peaksInstance.player.seek | <code>function</code> | Function to seek to time position |
| peaksInstance.player.getMediaElement | <code>function</code> | Function to get media element |
| peaksInstance.instanceId | <code>string</code> | Instance ID for event dispatching |
| containerId | <code>string</code> | DOM element ID for transcript container |
| transcriptData | <code>Array.&lt;Object&gt;</code> | Array of word timing objects |
| transcriptData[].text | <code>string</code> | Word text content |
| transcriptData[].start | <code>number</code> | Start time in seconds |
| transcriptData[].end | <code>number</code> | End time in seconds |
| [transcriptData[].confidence] | <code>number</code> | Confidence score (0.0-1.0) |

**Example**  

```js
const transcript = new StarmusTranscript(
  peaksInstance,
  'transcript-container',
  [{ text: 'Hello', start: 0.0, end: 0.5, confidence: 0.95 }]
);
```

<a name="StarmusTranscript+peaks"></a>

### starmusTranscript.peaks : <code>Object</code>

Peaks.js instance for audio control.

**Kind**: instance property of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+container"></a>

### starmusTranscript.container : <code>HTMLElement</code> \| <code>null</code>

DOM container element for transcript display.

**Kind**: instance property of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+data"></a>

### starmusTranscript.data : <code>Array.&lt;Object&gt;</code>

Array of word timing data objects.

**Kind**: instance property of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+activeTokenIndex"></a>

### starmusTranscript.activeTokenIndex : <code>number</code>

Index of currently highlighted word.

**Kind**: instance property of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+isUserScrolling"></a>

### starmusTranscript.isUserScrolling : <code>boolean</code>

Flag indicating user is manually scrolling.

**Kind**: instance property of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+scrollTimeout"></a>

### starmusTranscript.scrollTimeout : <code>number</code> \| <code>null</code>

Timeout ID for scroll detection reset.

**Kind**: instance property of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+boundOnTimeUpdate"></a>

### starmusTranscript.boundOnTimeUpdate : <code>function</code> \| <code>null</code>

Bound timeupdate event handler reference.

**Kind**: instance property of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+boundOnSeeked"></a>

### starmusTranscript.boundOnSeeked : <code>function</code> \| <code>null</code>

Bound seeked event handler reference.

**Kind**: instance property of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+boundOnClick"></a>

### starmusTranscript.boundOnClick : <code>function</code> \| <code>null</code>

Bound click event handler reference.

**Kind**: instance property of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+boundOnScroll"></a>

### starmusTranscript.boundOnScroll : <code>function</code> \| <code>null</code>

Bound scroll event handler reference.

**Kind**: instance property of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+init"></a>

### starmusTranscript.init() ⇒ <code>void</code>

Initializes the transcript controller.
Sets up DOM rendering and event binding if container exists.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+render"></a>

### starmusTranscript.render() ⇒ <code>void</code>

Renders transcript words into DOM container.
Creates word spans with timing data attributes and confidence indicators.
Uses DocumentFragment for efficient bulk DOM updates.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+bindEvents"></a>

### starmusTranscript.bindEvents() ⇒ <code>void</code>

Event handlers:

* Click: Seeks audio to clicked word's start time
* Scroll: Detects user scrolling to pause auto-scroll
* Timeupdate: Syncs highlight with audio playback position
* Seeked: Updates highlight when audio position changes

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+boundOnClick"></a>

### starmusTranscript.boundOnClick()

Click-to-seek handler for word elements.
Extracts start time and seeks audio player to that position.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+boundOnScroll"></a>

### starmusTranscript.boundOnScroll()

Scroll detection handler.
Sets user scrolling flag and resets it after timeout.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+boundOnTimeUpdate"></a>

### starmusTranscript.boundOnTimeUpdate()

Timeupdate handler for continuous playback sync.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+boundOnSeeked"></a>

### starmusTranscript.boundOnSeeked()

Seeked handler for position change sync.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+findTokenIndex"></a>

### starmusTranscript.findTokenIndex(time) ⇒ <code>number</code>

Uses binary search algorithm for O(log n) performance.
Checks if time falls within token's start-end range.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
**Returns**: <code>number</code> - Index of matching token, or -1 if not found  

| Param | Type | Description |
| --- | --- | --- |
| time | <code>number</code> | Current audio time in seconds |

<a name="StarmusTranscript+syncHighlight"></a>

### starmusTranscript.syncHighlight(currentTime) ⇒ <code>void</code>

Synchronizes word highlighting with current audio time.
Updates active token index and triggers DOM updates if changed.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  

| Param | Type | Description |
| --- | --- | --- |
| currentTime | <code>number</code> | Current audio playback time |

<a name="StarmusTranscript+updateDOM"></a>

### starmusTranscript.updateDOM(newIndex) ⇒ <code>void</code>

Updates DOM to highlight new active word.
Removes previous highlight and adds new one with optional auto-scroll.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  

| Param | Type | Description |
| --- | --- | --- |
| newIndex | <code>number</code> | Index of word to highlight |

<a name="StarmusTranscript+clearHighlight"></a>

### starmusTranscript.clearHighlight() ⇒ <code>void</code>

Clears all word highlighting.
Removes active class and resets active token index.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+scrollToWord"></a>

### starmusTranscript.scrollToWord(el) ⇒ <code>void</code>

Scrolls container to show specified word element.
Uses smooth scrolling with center alignment when available.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  

| Param | Type | Description |
| --- | --- | --- |
| el | <code>HTMLElement</code> | Word element to scroll to |

<a name="StarmusTranscript+updateData"></a>

### starmusTranscript.updateData(newData) ⇒ <code>void</code>

Updates transcript data and re-initializes display.
Replaces current data, re-renders DOM, and rebinds events.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  

| Param | Type | Description |
| --- | --- | --- |
| newData | <code>Array.&lt;Object&gt;</code> | New transcript data array |
| newData[].text | <code>string</code> | Word text content |
| newData[].start | <code>number</code> | Start time in seconds |
| newData[].end | <code>number</code> | End time in seconds |
| [newData[].confidence] | <code>number</code> | Confidence score (0.0-1.0) |

<a name="StarmusTranscript+unbindEvents"></a>

### starmusTranscript.unbindEvents() ⇒ <code>void</code>

Unbinds all event handlers to prevent memory leaks.
Removes listeners from container and media elements.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+destroy"></a>

### starmusTranscript.destroy() ⇒ <code>void</code>

Destroys the transcript instance and cleans up all resources.
Unbinds events, clears timeouts, empties container, and resets state.
Call this method when transcript is no longer needed.

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscriptController"></a>

## StarmusTranscriptController : <code>object</code>

Global transcript controller object with class and factory function.

**Kind**: global namespace  
**Properties**

| Name | Type | Description |
| --- | --- | --- |
| StarmusTranscript | <code>function</code> | The main transcript class |
| init | <code>function</code> | Factory function for creating instances |

<a name="BUS"></a>

## BUS : <code>object</code>

Global command bus reference with fallback.
Used for dispatching transcript events and debugging.

**Kind**: global constant  
<a name="debugLog"></a>

## debugLog : <code>function</code>

Debug logging function with fallback no-op.

**Kind**: global constant  
<a name="init"></a>

## init(peaksInstance, containerId, transcriptData) ⇒ [<code>StarmusTranscript</code>](#StarmusTranscript)

Factory function to create a new StarmusTranscript instance.
Provides a convenient way to initialize transcript controller.

**Kind**: global function  
**Returns**: [<code>StarmusTranscript</code>](#StarmusTranscript) - New transcript controller instance  

| Param | Type | Description |
| --- | --- | --- |
| peaksInstance | <code>Object</code> | Peaks.js waveform instance |
| containerId | <code>string</code> | DOM element ID for transcript container |
| transcriptData | <code>Array.&lt;Object&gt;</code> | Array of word timing objects |

**Example**  

```js
const transcript = init(peaks, 'transcript-div', wordData);
```

---

_Generated by Starisian JS Documentation Generator_
