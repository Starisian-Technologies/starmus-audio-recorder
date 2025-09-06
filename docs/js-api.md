## Objects

<dl>
<dt><a href="#StarmusAudioRecorder">StarmusAudioRecorder</a> : <code>object</code></dt>
<dd><p>The main public module for the Starmus Audio Recorder. This object is attached
to the window and serves as the public API for other scripts. It manages
multiple recorder instances, handles UI creation, and orchestrates uploads.</p>
</dd>
</dl>

## Constants

<dl>
<dt><a href="#STARMUS_EDITOR_DATA">STARMUS_EDITOR_DATA</a> : <code>object</code></dt>
<dd><p>Data localized from PHP.</p>
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

<a name="StarmusAudioRecorder"></a>

## StarmusAudioRecorder : <code>object</code>
The main public module for the Starmus Audio Recorder. This object is attached
to the window and serves as the public API for other scripts. It manages
multiple recorder instances, handles UI creation, and orchestrates uploads.

**Kind**: global namespace  
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

