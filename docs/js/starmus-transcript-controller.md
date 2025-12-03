# starmus-transcript-controller.js

**Source:** `src/js/starmus-transcript-controller.js`

---

## Classes

<dl>
<dt><a href="#StarmusTranscript">StarmusTranscript</a></dt>
<dd></dd>
<dt><a href="#StarmusTranscript">StarmusTranscript</a></dt>
<dd></dd>
</dl>

## Typedefs

<dl>
<dt><a href="#TranscriptToken">TranscriptToken</a> : <code>object</code></dt>
<dd><p>Handles the &quot;karaoke-style&quot; transcript panel that syncs with audio playback.
Provides click-to-seek, auto-scroll with user-scroll detection, and confidence indicators.</p>
</dd>
</dl>

<a name="StarmusTranscript"></a>

## StarmusTranscript
**Kind**: global class  

* [StarmusTranscript](#StarmusTranscript)
    * [new StarmusTranscript()](#new_StarmusTranscript_new)
    * [new StarmusTranscript(peaksInstance, containerId, transcriptData)](#new_StarmusTranscript_new)
    * [.init()](#StarmusTranscript+init)
    * [.render()](#StarmusTranscript+render)
    * [.bindEvents()](#StarmusTranscript+bindEvents)
    * [.syncHighlight(currentTime)](#StarmusTranscript+syncHighlight)
    * [.updateDOM(newIndex)](#StarmusTranscript+updateDOM)
    * [.scrollToWord(element)](#StarmusTranscript+scrollToWord)
    * [.updateData(newData)](#StarmusTranscript+updateData)
    * [.destroy()](#StarmusTranscript+destroy)

<a name="new_StarmusTranscript_new"></a>

### new StarmusTranscript()
Starmus Transcript Controller
Handles the bidirectional sync between audio time and text tokens.

<a name="new_StarmusTranscript_new"></a>

### new StarmusTranscript(peaksInstance, containerId, transcriptData)
Creates an instance of StarmusTranscript.


| Param | Type | Description |
| --- | --- | --- |
| peaksInstance | <code>object</code> | The initialized Peaks.js instance |
| containerId | <code>string</code> | ID of the transcript container element |
| transcriptData | [<code>Array.&lt;TranscriptToken&gt;</code>](#TranscriptToken) | Array of time-stamped tokens |

<a name="StarmusTranscript+init"></a>

### starmusTranscript.init()
Initialize the transcript controller

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+render"></a>

### starmusTranscript.render()
Renders JSON transcript data to HTML spans

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+bindEvents"></a>

### starmusTranscript.bindEvents()
Attach event listeners for interaction

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+syncHighlight"></a>

### starmusTranscript.syncHighlight(currentTime)
The Sync Logic
Highlights the current word based on playback time

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  

| Param | Type | Description |
| --- | --- | --- |
| currentTime | <code>number</code> | Current playback time in seconds |

<a name="StarmusTranscript+updateDOM"></a>

### starmusTranscript.updateDOM(newIndex)
Update the DOM to reflect the new active token

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  

| Param | Type | Description |
| --- | --- | --- |
| newIndex | <code>number</code> | Index of the new active token |

<a name="StarmusTranscript+scrollToWord"></a>

### starmusTranscript.scrollToWord(element)
Scroll the container to bring the active word into view

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  

| Param | Type | Description |
| --- | --- | --- |
| element | <code>HTMLElement</code> | The element to scroll to |

<a name="StarmusTranscript+updateData"></a>

### starmusTranscript.updateData(newData)
Update transcript data (e.g., when loading new audio)

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  

| Param | Type | Description |
| --- | --- | --- |
| newData | [<code>Array.&lt;TranscriptToken&gt;</code>](#TranscriptToken) | New transcript data |

<a name="StarmusTranscript+destroy"></a>

### starmusTranscript.destroy()
Destroy the controller and clean up event listeners

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript"></a>

## StarmusTranscript
**Kind**: global class  

* [StarmusTranscript](#StarmusTranscript)
    * [new StarmusTranscript()](#new_StarmusTranscript_new)
    * [new StarmusTranscript(peaksInstance, containerId, transcriptData)](#new_StarmusTranscript_new)
    * [.init()](#StarmusTranscript+init)
    * [.render()](#StarmusTranscript+render)
    * [.bindEvents()](#StarmusTranscript+bindEvents)
    * [.syncHighlight(currentTime)](#StarmusTranscript+syncHighlight)
    * [.updateDOM(newIndex)](#StarmusTranscript+updateDOM)
    * [.scrollToWord(element)](#StarmusTranscript+scrollToWord)
    * [.updateData(newData)](#StarmusTranscript+updateData)
    * [.destroy()](#StarmusTranscript+destroy)

<a name="new_StarmusTranscript_new"></a>

### new StarmusTranscript()
Starmus Transcript Controller
Handles the bidirectional sync between audio time and text tokens.

<a name="new_StarmusTranscript_new"></a>

### new StarmusTranscript(peaksInstance, containerId, transcriptData)
Creates an instance of StarmusTranscript.


| Param | Type | Description |
| --- | --- | --- |
| peaksInstance | <code>object</code> | The initialized Peaks.js instance |
| containerId | <code>string</code> | ID of the transcript container element |
| transcriptData | [<code>Array.&lt;TranscriptToken&gt;</code>](#TranscriptToken) | Array of time-stamped tokens |

<a name="StarmusTranscript+init"></a>

### starmusTranscript.init()
Initialize the transcript controller

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+render"></a>

### starmusTranscript.render()
Renders JSON transcript data to HTML spans

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+bindEvents"></a>

### starmusTranscript.bindEvents()
Attach event listeners for interaction

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="StarmusTranscript+syncHighlight"></a>

### starmusTranscript.syncHighlight(currentTime)
The Sync Logic
Highlights the current word based on playback time

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  

| Param | Type | Description |
| --- | --- | --- |
| currentTime | <code>number</code> | Current playback time in seconds |

<a name="StarmusTranscript+updateDOM"></a>

### starmusTranscript.updateDOM(newIndex)
Update the DOM to reflect the new active token

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  

| Param | Type | Description |
| --- | --- | --- |
| newIndex | <code>number</code> | Index of the new active token |

<a name="StarmusTranscript+scrollToWord"></a>

### starmusTranscript.scrollToWord(element)
Scroll the container to bring the active word into view

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  

| Param | Type | Description |
| --- | --- | --- |
| element | <code>HTMLElement</code> | The element to scroll to |

<a name="StarmusTranscript+updateData"></a>

### starmusTranscript.updateData(newData)
Update transcript data (e.g., when loading new audio)

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  

| Param | Type | Description |
| --- | --- | --- |
| newData | [<code>Array.&lt;TranscriptToken&gt;</code>](#TranscriptToken) | New transcript data |

<a name="StarmusTranscript+destroy"></a>

### starmusTranscript.destroy()
Destroy the controller and clean up event listeners

**Kind**: instance method of [<code>StarmusTranscript</code>](#StarmusTranscript)  
<a name="TranscriptToken"></a>

## TranscriptToken : <code>object</code>
Handles the "karaoke-style" transcript panel that syncs with audio playback.
Provides click-to-seek, auto-scroll with user-scroll detection, and confidence indicators.

**Kind**: global typedef  
**Properties**

| Name | Type | Description |
| --- | --- | --- |
| start | <code>number</code> | Start time in seconds |
| end | <code>number</code> | End time in seconds |
| text | <code>string</code> | The word or phrase text |
| [confidence] | <code>number</code> | Optional confidence score (0-1) |



---

_Generated by Starisian JS Documentation Generator_
