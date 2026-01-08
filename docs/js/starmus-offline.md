# starmus-offline.js

**Source:** `src/js/starmus-offline.js`

---

## Modules

<dl>
<dt><a href="#module_getOfflineQueue">getOfflineQueue</a> ⇒ <code><a href="#new_OfflineQueue_new">Promise.&lt;OfflineQueue&gt;</a></code></dt>
<dd><p>Gets the initialized offline queue instance.
Initializes database connection and network listeners on first access.</p>
</dd>
<dt><a href="#module_queueSubmission">queueSubmission</a> ⇒ <code>Promise.&lt;string&gt;</code></dt>
<dd><p>Queues an audio submission for offline processing.
Convenience function that gets queue instance and adds submission.</p>
</dd>
<dt><a href="#module_getPendingCount">getPendingCount</a> ⇒ <code>Promise.&lt;number&gt;</code></dt>
<dd><p>Gets the count of pending submissions in the offline queue.</p>
</dd>
<dt><a href="#module_initOffline">initOffline</a> ⇒ <code><a href="#new_OfflineQueue_new">Promise.&lt;OfflineQueue&gt;</a></code></dt>
<dd><p>Initializes the offline queue system.
Alias for getOfflineQueue for backward compatibility.</p>
</dd>
</dl>

## Members

<dl>
<dt><a href="#initOffline">initOffline</a> : <code>function</code></dt>
<dd><p>Global initOffline function reference.</p>
</dd>
<dt><a href="#StarmusOfflineQueue">StarmusOfflineQueue</a> : <code>function</code></dt>
<dd><p>Global offline queue getter function.</p>
</dd>
</dl>

## Constants

<dl>
<dt><a href="#CONFIG">CONFIG</a> : <code>Object</code></dt>
<dd><p>Configuration object for offline queue behavior.
Defines database settings, retry policies, and size limits.</p>
</dd>
<dt><a href="#offlineQueue">offlineQueue</a> : <code><a href="#new_OfflineQueue_new">OfflineQueue</a></code></dt>
<dd><p>Global offline queue instance.</p>
</dd>
</dl>

## Functions

<dl>
<dt><a href="#getMaxBlobSize">getMaxBlobSize(metadata)</a> ⇒ <code>number</code></dt>
<dd><p>Resolves the maximum allowed blob size based on environment tier metadata.
Uses conservative defaults for safety in low-bandwidth markets.</p>
</dd>
</dl>

<a name="module_getOfflineQueue"></a>

## getOfflineQueue ⇒ [<code>Promise.&lt;OfflineQueue&gt;</code>](#new_OfflineQueue_new)
Gets the initialized offline queue instance.
Initializes database connection and network listeners on first access.

**Returns**: [<code>Promise.&lt;OfflineQueue&gt;</code>](#new_OfflineQueue_new) - Configured offline queue instance  
<a name="module_queueSubmission"></a>

## queueSubmission ⇒ <code>Promise.&lt;string&gt;</code>
Queues an audio submission for offline processing.
Convenience function that gets queue instance and adds submission.

**Returns**: <code>Promise.&lt;string&gt;</code> - Unique submission ID for tracking  

| Param | Type | Description |
| --- | --- | --- |
| instanceId | <code>string</code> | Recorder instance identifier |
| audioBlob | <code>Blob</code> | Audio file blob to queue |
| fileName | <code>string</code> | Name for the audio file |
| formFields | <code>Object</code> | Form data (consent, language, etc.) |
| metadata | <code>Object</code> | Additional metadata (transcript, calibration, env) |

**Example**  
```js
const submissionId = await queueSubmission(
  'rec-123',
  audioBlob,
  'recording.webm',
  { consent: 'yes', language: 'en' },
  { transcript: 'Hello world', tier: 'A' }
);
```
<a name="module_getPendingCount"></a>

## getPendingCount ⇒ <code>Promise.&lt;number&gt;</code>
Gets the count of pending submissions in the offline queue.

**Returns**: <code>Promise.&lt;number&gt;</code> - Number of pending submissions  
<a name="module_initOffline"></a>

## initOffline ⇒ [<code>Promise.&lt;OfflineQueue&gt;</code>](#new_OfflineQueue_new)
Initializes the offline queue system.
Alias for getOfflineQueue for backward compatibility.

**Returns**: [<code>Promise.&lt;OfflineQueue&gt;</code>](#new_OfflineQueue_new) - Configured offline queue instance  
<a name="exp_module_initOffline--module.exports"></a>

### module.exports ⏏
Default export of the offline queue instance.

**Kind**: Exported member  
<a name="initOffline"></a>

## initOffline : <code>function</code>
Global initOffline function reference.

**Kind**: global variable  
<a name="StarmusOfflineQueue"></a>

## StarmusOfflineQueue : <code>function</code>
Global offline queue getter function.

**Kind**: global variable  
<a name="CONFIG"></a>

## CONFIG : <code>Object</code>
Configuration object for offline queue behavior.
Defines database settings, retry policies, and size limits.

**Kind**: global constant  
**Properties**

| Name | Type | Description |
| --- | --- | --- |
| dbName | <code>string</code> | IndexedDB database name |
| storeName | <code>string</code> | Object store name for submissions |
| dbVersion | <code>number</code> | Database schema version |
| maxRetries | <code>number</code> | Maximum retry attempts per submission |
| retryDelays | <code>Array.&lt;number&gt;</code> | Retry delay intervals in milliseconds |
| maxBlobSizes | <code>Object.&lt;string, number&gt;</code> | Tier-based maximum blob sizes in bytes |
| defaultMaxBlobSize | <code>number</code> | Fallback maximum blob size in bytes when tier is unknown |

<a name="offlineQueue"></a>

## offlineQueue : [<code>OfflineQueue</code>](#new_OfflineQueue_new)
Global offline queue instance.

**Kind**: global constant  
<a name="getMaxBlobSize"></a>

## getMaxBlobSize(metadata) ⇒ <code>number</code>
Resolves the maximum allowed blob size based on environment tier metadata.
Uses conservative defaults for safety in low-bandwidth markets.

**Kind**: global function  
**Returns**: <code>number</code> - Maximum blob size in bytes allowed for the submission  

| Param | Type | Description |
| --- | --- | --- |
| metadata | <code>Object</code> | Submission metadata containing environment details |
| [metadata.env] | <code>Object</code> | Environment object with tier classification |
| [metadata.tier] | <code>string</code> | Explicit tier override |



---

_Generated by Starisian JS Documentation Generator_
