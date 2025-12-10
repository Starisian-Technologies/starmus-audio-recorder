/**
 * @file starmus-hooks.js
 * @version 5.3.0-SIMPLE
 * @description Simplified Event Bus. No complex keys.
 */

'use strict';

const globalScope = typeof window !== 'undefined' ? window : globalThis;

if (!globalScope.StarmusRegistry) {
    globalScope.StarmusRegistry = {};
}
const registry = globalScope.StarmusRegistry;

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

function debugLog(...args) { /* console.log(...args); */ }

const Bus = { subscribe, dispatch, debugLog };
globalScope.CommandBus = Bus;
globalScope.StarmusHooks = Bus;

export { subscribe, dispatch, debugLog, Bus as CommandBus, Bus as StarmusHooks };