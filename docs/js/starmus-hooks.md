# starmus-hooks.js

**Source:** `src/js/starmus-hooks.js`

---

## Members

<dl>
<dt><a href="#CommandBus">CommandBus</a> : <code>object</code></dt>
<dd><p>Global CommandBus reference for legacy compatibility.</p>
</dd>
<dt><a href="#StarmusHooks">StarmusHooks</a> : <code>object</code></dt>
<dd><p>Global StarmusHooks reference for module integration.</p>
</dd>
</dl>

## Objects

<dl>
<dt><a href="#StarmusRegistry">StarmusRegistry</a> : <code>object</code></dt>
<dd><p>Initialize global StarmusRegistry if it doesn&#39;t exist.
Registry stores event handlers organized by command name.</p>
</dd>
</dl>

## Constants

<dl>
<dt><a href="#globalScope">globalScope</a> : <code>object</code></dt>
<dd><p>Global scope detection for cross-environment compatibility.
Uses window in browser environments, globalThis in Node.js/workers.</p>
</dd>
<dt><a href="#registry">registry</a> : <code>object</code></dt>
<dd><p>Event handler registry object.
Maps command names to arrays of handler functions.</p>
</dd>
<dt><a href="#Bus">Bus</a> : <code>object</code></dt>
<dd><p>Event Bus object containing all bus functionality.
Provides subscribe, dispatch, and debugLog methods.</p>
</dd>
</dl>

## Functions

<dl>
<dt><a href="#subscribe">subscribe(command, handler)</a> ⇒ <code>function</code></dt>
<dd><p>Subscribes a handler function to a specific command.
When the command is dispatched, the handler will be called with payload and meta data.</p>
</dd>
<dt><a href="#dispatch">dispatch(command, [payload], [meta])</a> ⇒ <code>void</code></dt>
<dd><p>Dispatches a command to all registered handlers.
Calls all handler functions subscribed to the specified command with provided data.</p>
</dd>
<dt><a href="#debugLog">debugLog(...args)</a> ⇒ <code>void</code></dt>
<dd><p>Debug logging utility (currently disabled).
Can be enabled for development debugging by uncommenting the console.log.</p>
</dd>
</dl>

<a name="CommandBus"></a>

## CommandBus : <code>object</code>
Global CommandBus reference for legacy compatibility.

**Kind**: global variable  
<a name="StarmusHooks"></a>

## StarmusHooks : <code>object</code>
Global StarmusHooks reference for module integration.

**Kind**: global variable  
<a name="StarmusRegistry"></a>

## StarmusRegistry : <code>object</code>
Initialize global StarmusRegistry if it doesn't exist.
Registry stores event handlers organized by command name.

**Kind**: global namespace  
<a name="globalScope"></a>

## globalScope : <code>object</code>
Global scope detection for cross-environment compatibility.
Uses window in browser environments, globalThis in Node.js/workers.

**Kind**: global constant  
<a name="registry"></a>

## registry : <code>object</code>
Event handler registry object.
Maps command names to arrays of handler functions.

**Kind**: global constant  
<a name="Bus"></a>

## Bus : <code>object</code>
Event Bus object containing all bus functionality.
Provides subscribe, dispatch, and debugLog methods.

**Kind**: global constant  
**Properties**

| Name | Type | Description |
| --- | --- | --- |
| subscribe | <code>function</code> | Subscribe to commands |
| dispatch | <code>function</code> | Dispatch commands |
| debugLog | <code>function</code> | Debug logging utility |

<a name="subscribe"></a>

## subscribe(command, handler) ⇒ <code>function</code>
Subscribes a handler function to a specific command.
When the command is dispatched, the handler will be called with payload and meta data.

**Kind**: global function  
**Returns**: <code>function</code> - Unsubscribe function to remove this handler  

| Param | Type | Description |
| --- | --- | --- |
| command | <code>string</code> | The command name to listen for |
| handler | <code>function</code> | The function to call when command is dispatched |
| handler.payload | <code>object</code> | Data payload from dispatch |
| handler.meta | <code>object</code> | Metadata from dispatch (instanceId, etc.) |

**Example**  
```js
const unsubscribe = subscribe('submit', (payload, meta) => {
  console.log('Received submit command:', payload);
});
// Later: unsubscribe();
```
<a name="dispatch"></a>

## dispatch(command, [payload], [meta]) ⇒ <code>void</code>
Dispatches a command to all registered handlers.
Calls all handler functions subscribed to the specified command with provided data.

**Kind**: global function  

| Param | Type | Default | Description |
| --- | --- | --- | --- |
| command | <code>string</code> |  | The command name to dispatch |
| [payload] | <code>object</code> | <code>{}</code> | Data to send to handlers |
| [meta] | <code>object</code> | <code>{}</code> | Metadata to send to handlers (instanceId, source, etc.) |

**Example**  
```js
dispatch('submit', { formFields: {...} }, { instanceId: 'rec-123' });
dispatch('reset', {}, { instanceId: 'rec-123' });
```
<a name="debugLog"></a>

## debugLog(...args) ⇒ <code>void</code>
Debug logging utility (currently disabled).
Can be enabled for development debugging by uncommenting the console.log.

**Kind**: global function  

| Param | Type | Description |
| --- | --- | --- |
| ...args | <code>\*</code> | Arguments to log to console |



---

_Generated by Starisian JS Documentation Generator_
