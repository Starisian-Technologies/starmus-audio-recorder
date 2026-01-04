# starmus-tus.js

**Source:** `src/js/starmus-tus.js`

---

## Modules

<dl>
<dt><a href="#module_uploadDirect">uploadDirect</a> ⇒ <code>Promise.&lt;Object&gt;</code></dt>
<dd><p>Direct upload implementation as fallback for TUS.
Uploads file directly to WordPress REST API using FormData and XMLHttpRequest.
Handles progress tracking and proper metadata mapping for WordPress controller.</p>
</dd>
<dt><a href="#module_uploadWithPriority">uploadWithPriority</a> ⇒ <code>Promise.&lt;Object&gt;</code></dt>
<dd><p>Priority upload wrapper that tries TUS first, then falls back to direct upload.
Automatically selects the best upload method based on availability and blob size.
Supports both object parameter and individual arguments for backward compatibility.</p>
</dd>
<dt><a href="#module_isTusAvailable">isTusAvailable</a> ⇒ <code>boolean</code></dt>
<dd><p>Checks if TUS upload is available and viable.
Verifies TUS library presence, endpoint configuration, and minimum file size.</p>
</dd>
<dt><a href="#module_uploadWithTus">uploadWithTus</a> ⇒ <code>Promise.&lt;Object&gt;</code> | <code>boolean</code> | <code>string</code> | <code>string</code></dt>
<dd><p>Process:</p>
<ol>
<li>Prepares metadata by flattening objects to strings</li>
<li>Configures TUS upload with security headers</li>
<li>Attempts to resume previous uploads if found</li>
<li>Starts chunked upload with progress tracking</li>
<li>Resolves when transfer completes (PHP hook processes asynchronously)</li>
</ol>
</dd>
<dt><a href="#module_estimateUploadTime">estimateUploadTime</a> ⇒ <code>number</code></dt>
<dd><p>Estimates upload time based on file size and network information.
Uses connection downlink speed to calculate approximate transfer duration.
Includes 50% buffer for realistic estimation with network variations.</p>
</dd>
<dt><a href="#module_formatUploadEstimate">formatUploadEstimate</a> ⇒ <code>string</code></dt>
<dd><p>Formats upload time estimate into human-readable string.
Converts seconds to either &quot;<del>Xs&quot; or &quot;</del>Xm&quot; format for display.</p>
</dd>
</dl>

## Classes

<dl>
<dt><a href="#UploadCircuitBreaker">UploadCircuitBreaker</a></dt>
<dd><p>Circuit breaker for upload failures</p>
</dd>
</dl>

## Constants

<dl>
<dt><a href="#getDefaultConfig">getDefaultConfig</a> : <code>Object</code></dt>
<dd><p>Default configuration object for TUS uploads.
Contains chunk sizes, retry settings, and endpoint configuration.
Enhanced with tier-based optimization from SPARXSTAR.</p>
</dd>
</dl>

## Functions

<dl>
<dt><a href="#getConfig">getConfig()</a> ⇒ <code>Object</code></dt>
<dd><p>Gets merged configuration from defaults and global settings.
Combines DEFAULT_CONFIG with window.starmusTus or window.starmusConfig.</p>
</dd>
<dt><a href="#normalizeFormFields">normalizeFormFields(fields)</a> ⇒ <code>Object</code></dt>
<dd><p>Normalizes form fields to ensure object type.
Converts non-object values to empty object for safety.</p>
</dd>
<dt><a href="#sanitizeMetadata">sanitizeMetadata(value)</a> ⇒ <code>string</code></dt>
<dd><p>Sanitizes metadata values for TUS compatibility.
TUS metadata must be strings, so objects are JSON stringified.
Removes newlines, tabs, and carriage returns from strings.</p>
</dd>
</dl>

<a name="module_uploadDirect"></a>

## uploadDirect ⇒ <code>Promise.&lt;Object&gt;</code>

Direct upload implementation as fallback for TUS.
Uploads file directly to WordPress REST API using FormData and XMLHttpRequest.
Handles progress tracking and proper metadata mapping for WordPress controller.

**Returns**: <code>Promise.&lt;Object&gt;</code> - Upload result from WordPress API  
**Throws**:

- <code>Error</code> When blob is invalid, network fails, or server responds with error

| Param | Type | Default | Description |
| --- | --- | --- | --- |
| blob | <code>Blob</code> |  | Audio file blob to upload |
| fileName | <code>string</code> |  | Name for the uploaded file |
| [formFields] | <code>Object</code> | <code>{}</code> | Form data fields (consent, language, etc.) |
| [metadata] | <code>Object</code> | <code>{}</code> | Additional metadata object |
| [metadata.transcript] | <code>string</code> |  | Transcription text |
| [metadata.calibration] | <code>Object</code> |  | Calibration settings |
| [metadata.env] | <code>Object</code> |  | Environment data |
| [metadata.tier] | <code>string</code> |  | Browser capability tier |
| [_instanceId] | <code>string</code> | <code>&quot;&#x27;&#x27;&quot;</code> | Instance identifier (unused) |
| [onProgress] | <code>function</code> |  | Progress callback function |
| onProgress.loaded | <code>number</code> |  | Bytes uploaded |
| onProgress.total | <code>number</code> |  | Total bytes to upload |

**Example**  

```js
const result = await uploadDirect(
  audioBlob,
  'recording.webm',
  { consent: 'yes', language: 'en' },
  { transcript: 'Hello world', tier: 'A' },
  'rec-123',
  (loaded, total) => console.log(`${loaded}/${total}`)
);
```

<a name="module_uploadWithPriority"></a>

## uploadWithPriority ⇒ <code>Promise.&lt;Object&gt;</code>

Priority upload wrapper that tries TUS first, then falls back to direct upload.
Automatically selects the best upload method based on availability and blob size.
Supports both object parameter and individual arguments for backward compatibility.

**Returns**: <code>Promise.&lt;Object&gt;</code> - Upload result from chosen method  
**Throws**:

- <code>Error</code> When no blob provided or all upload methods fail

| Param | Type | Description |
| --- | --- | --- |
| arg1 | <code>Object</code> \| <code>Blob</code> | Upload parameters object or blob (legacy) |
| arg1.blob | <code>Blob</code> | Audio file blob to upload |
| arg1.fileName | <code>string</code> | Name for the uploaded file |
| arg1.formFields | <code>Object</code> | Form data fields |
| arg1.metadata | <code>Object</code> | Additional metadata |
| arg1.instanceId | <code>string</code> | Instance identifier |
| arg1.onProgress | <code>function</code> | Progress callback function |
| [fileName] | <code>string</code> | Legacy parameter: file name |
| [formFields] | <code>Object</code> | Legacy parameter: form fields |
| [metadata] | <code>Object</code> | Legacy parameter: metadata |
| [instanceId] | <code>string</code> | Legacy parameter: instance ID |
| [onProgress] | <code>function</code> | Legacy parameter: progress callback |

**Example**  

```js
// Object syntax
const result = await uploadWithPriority({
  blob: audioBlob,
  fileName: 'recording.webm',
  formFields: { consent: 'yes' },
  metadata: { transcript: 'Hello' },
  instanceId: 'rec-123',
  onProgress: (loaded, total) => console.log(`${loaded}/${total}`)
});

// Legacy syntax
const result = await uploadWithPriority(
  audioBlob, 'recording.webm', {}, {}, 'rec-123', progressFn
);
```

<a name="module_isTusAvailable"></a>

## isTusAvailable ⇒ <code>boolean</code>

Checks if TUS upload is available and viable.
Verifies TUS library presence, endpoint configuration, and minimum file size.

**Returns**: <code>boolean</code> - True if TUS upload can be used  

| Param | Type | Default | Description |
| --- | --- | --- | --- |
| [blobSize] | <code>number</code> | <code>0</code> | Size of blob to upload in bytes |

**Example**  

```js
if (isTusAvailable(audioBlob.size)) {
  console.log('TUS upload available');
}
```

<a name="module_uploadWithTus"></a>

## uploadWithTus ⇒ <code>Promise.&lt;Object&gt;</code> \| <code>boolean</code> \| <code>string</code> \| <code>string</code>

Process:

1. Prepares metadata by flattening objects to strings
2. Configures TUS upload with security headers
3. Attempts to resume previous uploads if found
4. Starts chunked upload with progress tracking
5. Resolves when transfer completes (PHP hook processes asynchronously)

**Returns**: <code>Promise.&lt;Object&gt;</code> - Upload result with TUS URL and status<code>boolean</code> - returns.success - Whether upload completed successfully<code>string</code> - returns.tus_url - TUS upload URL for tracking<code>string</code> - returns.message - Status message  

| Param | Type | Description |
| --- | --- | --- |
| blob | <code>Blob</code> | Audio file blob to upload |
| fileName | <code>string</code> | Name for the uploaded file |
| formFields | <code>Object</code> | Form data fields (consent, language, etc.) |
| metadata | <code>Object</code> | Additional metadata object |
| metadata.transcript | <code>string</code> | Transcription text |
| metadata.calibration | <code>Object</code> | Calibration settings |
| metadata.env | <code>Object</code> | Environment data |
| metadata.tier | <code>string</code> | Browser capability tier |
| instanceId | <code>string</code> | Instance identifier |
| onProgress | <code>function</code> | Progress callback function |
| onProgress.bytesUploaded | <code>number</code> | Bytes uploaded so far |
| onProgress.bytesTotal | <code>number</code> | Total bytes to upload |

**Example**  

```js
const result = await uploadWithTus(
  audioBlob,
  'recording.webm',
  { consent: 'yes', post_id: '123' },
  { transcript: 'Hello', tier: 'A' },
  'rec-123',
  (uploaded, total) => console.log(`${uploaded}/${total}`)
);
```

- [uploadWithTus](#module_uploadWithTus) ⇒ <code>Promise.&lt;Object&gt;</code> \| <code>boolean</code> \| <code>string</code> \| <code>string</code>
  - [~onError(error)](#module_uploadWithTus..onError)
  - [~onProgress(bytesUploaded, bytesTotal)](#module_uploadWithTus..onProgress)
  - [~onSuccess()](#module_uploadWithTus..onSuccess)

<a name="module_uploadWithTus..onError"></a>

### uploadWithTus~onError(error)

Error handler for upload failures.

**Kind**: inner method of [<code>uploadWithTus</code>](#module_uploadWithTus)  

| Param | Type | Description |
| --- | --- | --- |
| error | <code>Error</code> | TUS upload error |

<a name="module_uploadWithTus..onProgress"></a>

### uploadWithTus~onProgress(bytesUploaded, bytesTotal)

Progress handler for upload tracking.

**Kind**: inner method of [<code>uploadWithTus</code>](#module_uploadWithTus)  

| Param | Type | Description |
| --- | --- | --- |
| bytesUploaded | <code>number</code> | Bytes uploaded so far |
| bytesTotal | <code>number</code> | Total bytes to upload |

<a name="module_uploadWithTus..onSuccess"></a>

### uploadWithTus~onSuccess()

Success handler when upload transfer completes.
Note: PHP post-finish hook runs asynchronously.

**Kind**: inner method of [<code>uploadWithTus</code>](#module_uploadWithTus)  
<a name="module_estimateUploadTime"></a>

## estimateUploadTime ⇒ <code>number</code>

Estimates upload time based on file size and network information.
Uses connection downlink speed to calculate approximate transfer duration.
Includes 50% buffer for realistic estimation with network variations.

**Returns**: <code>number</code> - Estimated upload time in seconds  

| Param | Type | Default | Description |
| --- | --- | --- | --- |
| fileSize | <code>number</code> |  | File size in bytes |
| [networkInfo] | <code>Object</code> |  | Network connection information |
| [networkInfo.downlink] | <code>number</code> | <code>0.5</code> | Downlink speed in Mbps |

**Example**  

```js
const estimate = estimateUploadTime(1024000, { downlink: 2.5 });
console.log(`Estimated: ${estimate} seconds`);
```

<a name="module_formatUploadEstimate"></a>

## formatUploadEstimate ⇒ <code>string</code>

Formats upload time estimate into human-readable string.
Converts seconds to either "~Xs" or "~Xm" format for display.

**Returns**: <code>string</code> - Formatted time string or '...' for invalid input  

| Param | Type | Description |
| --- | --- | --- |
| s | <code>number</code> | Time in seconds |

**Example**  

```js
formatUploadEstimate(45)  // '~45s'
formatUploadEstimate(120) // '~2m'
formatUploadEstimate(NaN) // '...'
```

- [formatUploadEstimate](#module_formatUploadEstimate) ⇒ <code>string</code>
  - [module.exports](#exp_module_formatUploadEstimate--module.exports) ⏏
    - [~StarmusTus](#module_formatUploadEstimate--module.exports..StarmusTus) : <code>Object</code>

<a name="exp_module_formatUploadEstimate--module.exports"></a>

### module.exports ⏏

Default export for ES6 modules.

**Kind**: Exported member  
**Default**: <code>StarmusTus</code>  
<a name="module_formatUploadEstimate--module.exports..StarmusTus"></a>

#### module.exports~StarmusTus : <code>Object</code>

StarmusTus module object with all upload functions.
Provides unified interface for TUS and direct upload functionality.

**Kind**: inner constant of [<code>module.exports</code>](#exp_module_formatUploadEstimate--module.exports)  
**Properties**

| Name | Type | Description |
| --- | --- | --- |
| uploadWithTus | <code>function</code> | TUS resumable upload |
| uploadDirect | <code>function</code> | Direct upload fallback |
| uploadWithPriority | <code>function</code> | Priority upload wrapper |
| isTusAvailable | <code>function</code> | TUS availability check |
| estimateUploadTime | <code>function</code> | Upload time estimation |
| formatUploadEstimate | <code>function</code> | Time format utility |

<a name="UploadCircuitBreaker"></a>

## UploadCircuitBreaker

Circuit breaker for upload failures

**Kind**: global class  
<a name="getDefaultConfig"></a>

## getDefaultConfig : <code>Object</code>

Default configuration object for TUS uploads.
Contains chunk sizes, retry settings, and endpoint configuration.
Enhanced with tier-based optimization from SPARXSTAR.

**Kind**: global constant  
**Properties**

| Name | Type | Description |
| --- | --- | --- |
| chunkSize | <code>number</code> | Size of each upload chunk in bytes (optimized per tier) |
| retryDelays | <code>Array.&lt;number&gt;</code> | Retry delay intervals in milliseconds |
| removeFingerprintOnSuccess | <code>boolean</code> | Whether to remove fingerprint after success |
| maxChunkRetries | <code>number</code> | Maximum retry attempts per chunk |
| endpoint | <code>string</code> | TUS server endpoint URL |
| webhookSecret | <code>string</code> | Secret for webhook authentication |

<a name="getConfig"></a>

## getConfig() ⇒ <code>Object</code>

Gets merged configuration from defaults and global settings.
Combines DEFAULT_CONFIG with window.starmusTus or window.starmusConfig.

**Kind**: global function  
**Returns**: <code>Object</code> - Merged configuration object  
**Example**  

```js
const config = getConfig();
console.log(config.chunkSize); // 524288 (512KB)
```

<a name="normalizeFormFields"></a>

## normalizeFormFields(fields) ⇒ <code>Object</code>

Normalizes form fields to ensure object type.
Converts non-object values to empty object for safety.

**Kind**: global function  
**Returns**: <code>Object</code> - Normalized form fields object  

| Param | Type | Description |
| --- | --- | --- |
| fields | <code>\*</code> | Form fields of any type |

<a name="sanitizeMetadata"></a>

## sanitizeMetadata(value) ⇒ <code>string</code>

Sanitizes metadata values for TUS compatibility.
TUS metadata must be strings, so objects are JSON stringified.
Removes newlines, tabs, and carriage returns from strings.

**Kind**: global function  
**Returns**: <code>string</code> - Sanitized string value safe for TUS metadata  

| Param | Type | Description |
| --- | --- | --- |
| value | <code>\*</code> | Value to sanitize (any type) |

**Example**  

```js
sanitizeMetadata({key: 'value'}) // '{"key":"value"}'
sanitizeMetadata('text\nwith\ttabs') // 'text with tabs'
```

---

_Generated by Starisian JS Documentation Generator_
