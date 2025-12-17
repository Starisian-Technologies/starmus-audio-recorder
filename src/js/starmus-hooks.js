/**
 * @file starmus-hooks.js
 * @version 5.3.0-SIMPLE
 * @description Simplified Event Bus. No complex keys.
 * Provides a global event system for Starmus components to communicate
 * without tight coupling. Supports command-based publish/subscribe pattern.
 */

'use strict';

/**
 * Global scope detection for cross-environment compatibility.
 * Uses window in browser environments, globalThis in Node.js/workers.
 * @type {object}
 */
const globalScope = typeof window !== 'undefined' ? window : globalThis;

/**
 * Initialize global StarmusRegistry if it doesn't exist.
 * Registry stores event handlers organized by command name.
 * @global
 * @namespace StarmusRegistry
 */
if (!globalScope.StarmusRegistry) {
    globalScope.StarmusRegistry = {};
}

/**
 * Event handler registry object.
 * Maps command names to arrays of handler functions.
 * @type {object}
 */
const registry = globalScope.StarmusRegistry;

/**
 * Subscribes a handler function to a specific command.
 * When the command is dispatched, the handler will be called with payload and meta data.
 * 
 * @function
 * @param {string} command - The command name to listen for
 * @param {function} handler - The function to call when command is dispatched
 * @param {object} handler.payload - Data payload from dispatch
 * @param {object} handler.meta - Metadata from dispatch (instanceId, etc.)
 * @returns {function} Unsubscribe function to remove this handler
 * 
 * @example
 * const unsubscribe = subscribe('submit', (payload, meta) => {
 *   console.log('Received submit command:', payload);
 * });
 * // Later: unsubscribe();
 */
function subscribe(command, handler) {
  if (!registry[command]) {
    registry[command] = [];
  }
  registry[command].push(handler);
  console.log(`[Bus] Listener added for: ${command}`);

  return () => {
      const idx = registry[command].indexOf(handler);
      if (idx > -1) registry[command].splice(idx, 1);
  };
}

/**
 * Dispatches a command to all registered handlers.
 * Calls all handler functions subscribed to the specified command with provided data.
 * 
 * @function
 * @param {string} command - The command name to dispatch
 * @param {object} [payload={}] - Data to send to handlers
 * @param {object} [meta={}] - Metadata to send to handlers (instanceId, source, etc.)
 * @returns {void}
 * 
 * @example
 * dispatch('submit', { formFields: {...} }, { instanceId: 'rec-123' });
 * dispatch('reset', {}, { instanceId: 'rec-123' });
 */
function dispatch(command, payload = {}, meta = {}) {
  const handlers = registry[command];
  if (!handlers || !handlers.length) {
      console.warn(`[Bus] ⚠️ Dispatched '${command}' but nobody is listening.`);
      return;
  }
  
  console.log(`[Bus] Dispatching '${command}' to ${handlers.length} listeners`, meta);
  
  handlers.forEach(fn => {
      try { fn(payload, meta); } catch(e) { console.error(e); }
  });
}

/**
 * Debug logging utility (currently disabled).
 * Can be enabled for development debugging by uncommenting the console.log.
 * 
 * @function
 * @param {...*} args - Arguments to log to console
 * @returns {void}
 */
function debugLog(...args) { /* console.log(...args); */ }

/**
 * Event Bus object containing all bus functionality.
 * Provides subscribe, dispatch, and debugLog methods.
 * @type {object}
 * @property {function} subscribe - Subscribe to commands
 * @property {function} dispatch - Dispatch commands
 * @property {function} debugLog - Debug logging utility
 */
const Bus = { subscribe, dispatch, debugLog };

/**
 * Global CommandBus reference for legacy compatibility.
 * @global
 * @type {object}
 */
globalScope.CommandBus = Bus;

/**
 * Global StarmusHooks reference for module integration.
 * @global  
 * @type {object}
 */
globalScope.StarmusHooks = Bus;

export { subscribe, dispatch, debugLog, Bus as CommandBus, Bus as StarmusHooks };