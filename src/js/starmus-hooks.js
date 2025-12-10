/**
 * @file starmus-hooks.js
 * @version 5.1.0-GLOBAL-FIX
 * @description The "Nervous System". Forces a global connection point.
 */

'use strict';

const globalScope = typeof window !== 'undefined' ? window : globalThis;

// 1. Force Global Registry (The "Socket")
if (!globalScope.StarmusRegistry) {
    globalScope.StarmusRegistry = {
        handlers: {}, // Must be a plain object for safety
        active: new Set()
    };
}
const registry = globalScope.StarmusRegistry;

/**
 * SUBSCRIBE
 */
function subscribe(command, handler, instanceId) {
  const key = command + '::' + (instanceId || '*');
  
  if (!registry.handlers[key]) {
      registry.handlers[key] = [];
  }
  // Prevent duplicate subscriptions
  if (registry.handlers[key].indexOf(handler) === -1) {
      registry.handlers[key].push(handler);
  }
  
  console.log(`[Bus] Subscribed to: ${key}`);

  return () => {
      const idx = registry.handlers[key].indexOf(handler);
      if (idx > -1) registry.handlers[key].splice(idx, 1);
  };
}

/**
 * DISPATCH
 */
function dispatch(command, payload = {}, meta = {}) {
  const id = meta.instanceId || '*';
  const specificKey = command + '::' + id;
  const globalKey = command + '::*';
  const universalKey = command;

  // Gather all matching handlers
  const targets = [
      ...(registry.handlers[specificKey] || []),
      ...(registry.handlers[globalKey] || []),
      ...(registry.handlers[universalKey] || [])
  ];

  if (targets.length === 0) {
      console.warn(`[Bus] ⚠️ No listeners for command: ${command} (ID: ${id})`);
      return;
  }

  console.log(`[Bus] Dispatching ${command} to ${targets.length} listeners`);

  targets.forEach(fn => {
      try {
          fn(payload, meta);
      } catch (e) {
          console.error('[Bus] Handler failed:', e);
      }
  });
}

function debugLog(...args) {
   // console.log(...args); // Uncomment for extreme debug mode
}

// 2. EXPORT & ATTACH
const Bus = { subscribe, dispatch, debugLog };
globalScope.CommandBus = Bus; 
globalScope.StarmusHooks = Bus;

export { subscribe, dispatch, debugLog, Bus as CommandBus };