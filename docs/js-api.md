## Constants

<dl>
<dt><a href="#STARMUS_EDITOR_DATA">STARMUS_EDITOR_DATA</a> : <code>object</code></dt>
<dd><p>Data localized from PHP.</p>
</dd>
<dt><a href="#handlers">handlers</a></dt>
<dd><p>Simple command bus for decoupled communication.
Command handlers receive (payload, meta).</p>
</dd>
</dl>

## Functions

<dl>
<dt><a href="#initCore">initCore(store, instanceId, env)</a></dt>
<dd><p>Wire submission logic for a specific instance.</p>
</dd>
<dt><a href="#wireInstance">wireInstance(env, formEl)</a></dt>
<dd><p>Wire a single <form data-starmus="recorder"> into the Starmus system.</p>
</dd>
<dt><a href="#onEnvironmentReady">onEnvironmentReady()</a></dt>
<dd><p>Entry point: waits for sparxstar-user-environment-check to fire,
then wires all recorder forms on the page.</p>
</dd>
<dt><a href="#initRecorder">initRecorder(store, instanceId)</a></dt>
<dd><p>Wires microphone + file logic for a specific instance.</p>
</dd>
<dt><a href="#reducer">reducer(state, action)</a> ⇒ <code>object</code></dt>
<dd><p>Pure reducer for Starmus state.</p>
</dd>
<dt><a href="#createStore">createStore([initial])</a> ⇒ <code>Object</code></dt>
<dd><p>Creates a minimal store with getState, dispatch, subscribe.</p>
</dd>
<dt><a href="#render">render(state, elements)</a></dt>
<dd><p>Renders the current state of a Starmus instance to the DOM.</p>
</dd>
<dt><a href="#initInstance">initInstance(store, elements)</a> ⇒ <code>function</code></dt>
<dd><p>Initializes a UI instance and binds it to a state store.</p>
</dd>
</dl>

## Typedefs

<dl>
<dt><a href="#Annotation">Annotation</a> : <code>object</code></dt>
<dd><p>This script initializes the audio waveform editor, handles user interactions
(playback, zoom, annotations), manages unsaved changes, and communicates with the
WordPress REST API to save annotation data.</p>
</dd>
</dl>

<a name="STARMUS_EDITOR_DATA"></a>

## STARMUS\_EDITOR\_DATA : <code>object</code>

Data localized from PHP.

**Kind**: global constant  
**Properties**

| Name | Type | Default | Description |
| --- | --- | --- | --- |
| restUrl | <code>string</code> |  | The URL for the REST API endpoint. |
| nonce | <code>string</code> |  | The nonce for REST API authentication. |
| postId | <code>number</code> |  | The ID of the post being edited. |
| audioUrl | <code>string</code> |  | The URL of the audio file to load. |
| [annotations] | [<code>Array.&lt;Annotation&gt;</code>](#Annotation) | <code>[]</code> | The initial array of annotation objects. |

<a name="handlers"></a>

## handlers

Simple command bus for decoupled communication.
Command handlers receive (payload, meta).

**Kind**: global constant  
<a name="initCore"></a>

## initCore(store, instanceId, env)

Wire submission logic for a specific instance.

**Kind**: global function  

| Param | Type | Description |
| --- | --- | --- |
| store | <code>object</code> |  |
| instanceId | <code>string</code> |  |
| env | <code>object</code> | Environment payload from sparxstar-user-environment-check. |

<a name="wireInstance"></a>

## wireInstance(env, formEl)

Wire a single <form data-starmus="recorder"> into the Starmus system.

**Kind**: global function  

| Param | Type | Description |
| --- | --- | --- |
| env | <code>object</code> | Environment payload from sparxstar-user-environment-check. |
| formEl | <code>HTMLFormElement</code> |  |

<a name="onEnvironmentReady"></a>

## onEnvironmentReady()

Entry point: waits for sparxstar-user-environment-check to fire,
then wires all recorder forms on the page.

**Kind**: global function  
<a name="initRecorder"></a>

## initRecorder(store, instanceId)

Wires microphone + file logic for a specific instance.

**Kind**: global function  

| Param | Type |
| --- | --- |
| store | <code>object</code> |
| instanceId | <code>string</code> |

<a name="reducer"></a>

## reducer(state, action) ⇒ <code>object</code>

Pure reducer for Starmus state.

**Kind**: global function  

| Param | Type |
| --- | --- |
| state | <code>object</code> |
| action | <code>object</code> |

<a name="createStore"></a>

## createStore([initial]) ⇒ <code>Object</code>

Creates a minimal store with getState, dispatch, subscribe.

**Kind**: global function  

| Param | Type |
| --- | --- |
| [initial] | <code>object</code> |

<a name="render"></a>

## render(state, elements)

Renders the current state of a Starmus instance to the DOM.

**Kind**: global function  

| Param | Type | Description |
| --- | --- | --- |
| state | <code>object</code> | The current state from the store. |
| elements | <code>object</code> | A map of DOM elements for the instance. |

<a name="initInstance"></a>

## initInstance(store, elements) ⇒ <code>function</code>

Initializes a UI instance and binds it to a state store.

**Kind**: global function  
**Returns**: <code>function</code> - An unsubscribe function.  

| Param | Type | Description |
| --- | --- | --- |
| store | <code>object</code> | The Starmus state store for the instance. |
| elements | <code>object</code> | A map of DOM elements for this instance. |

<a name="Annotation"></a>

## Annotation : <code>object</code>

This script initializes the audio waveform editor, handles user interactions
(playback, zoom, annotations), manages unsaved changes, and communicates with the
WordPress REST API to save annotation data.

**Kind**: global typedef  
**Properties**

| Name | Type | Description |
| --- | --- | --- |
| id | <code>string</code> | A unique identifier for the annotation. |
| startTime | <code>number</code> | The start time of the annotation in seconds. |
| endTime | <code>number</code> | The end time of the annotation in seconds. |
| [label] | <code>string</code> | The user-defined text for the annotation. |
