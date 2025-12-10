/**
 * @file starmus-hooks.js
 * @version 5.0.3-FULL
 * @description Unified Command Bus with backward‑compatible ES5 fallbacks.
 * Correctly integrates with starmusConfig.debug and exports debugLog.
 */

;(function (global) {
  'use strict';

  /**
   * ---------------------------------------------------------------------------
   * SECTION 1 — CORE UTILITIES (Logging & Fallbacks)
   * ---------------------------------------------------------------------------
   */

  // Unified debug logger (ES5-safe)
  var DEBUG = !!(global.starmusConfig && global.starmusConfig.debug);

  function debugLog() {
    if (!DEBUG) return;
    try {
      (console.log || function(){})
        .apply(console, arguments);
    } catch (_) {}
  }
  
  // Feature detect Set; fallback to array‑based storage
  var hasSet = typeof Set === 'function';
  var createHandlerStore = function () {
    return hasSet ? new Set() : [];
  };

  var addHandler = function (store, fn) {
    if (hasSet) {
      store.add(fn);
    } else if (store.indexOf(fn) === -1) {
      store.push(fn);
    }
  };

  var removeHandler = function (store, fn) {
    if (hasSet) {
      store.delete(fn);
    } else {
      var i = store.indexOf(fn);
      if (i !== -1) store.splice(i, 1);
    }
  };

  var iterateHandlers = function (store, cb) {
    if (hasSet) {
      store.forEach(cb);
    } else {
      // copy to avoid mutation during iteration
      var clone = store.slice();
      for (var i = 0; i < clone.length; i++) {
        cb(clone[i]);
      }
    }
  };


  /**
   * ---------------------------------------------------------------------------
   * SECTION 2 — CORE REGISTRIES
   * ---------------------------------------------------------------------------
   */

  // Handler registry keyed by command names
  // USE A GLOBAL REGISTRY if it exists, to prevent multiple bundles splitting the bus
  var handlers = global.StarmusHooksRegistry || {}; 
  global.StarmusHooksRegistry = handlers;

  // Recursion guard
  var activeDispatches = {}; // { "cmd::instanceId": true }

  /**
   * ---------------------------------------------------------------------------
   * SECTION 3 — API: subscribe()
   * ---------------------------------------------------------------------------
   */

  function subscribe(commandName, handler, instanceId) {
    var key = commandName + '::' + (instanceId || '*');
    if (!handlers[key]) {
      handlers[key] = createHandlerStore();
    }
    addHandler(handlers[key], handler);

    // Return explicitly ES5-safe unsubscribe
    return function unsubscribe() {
      if (handlers[key]) {
        removeHandler(handlers[key], handler);
      }
    };
  }

  /**
   * ---------------------------------------------------------------------------
   * SECTION 4 — API: dispatch()
   * ---------------------------------------------------------------------------
   */

  function dispatch(commandName, payload, meta) {
    payload = payload || {};
    meta = meta || {};

    var instance = meta.instanceId || '*';
    var key = commandName + '::' + instance;

    if (activeDispatches[key]) {
      debugLog('Prevented recursive dispatch:', commandName); 
      return;
    }

    activeDispatches[key] = true;

    try {
        // Check instance-specific handlers first, then global
        var handlerStore = handlers[key] || handlers[commandName + '::*'] || handlers[commandName];

        if (!handlerStore) {
            // debugLog('No handlers found for', key);
            return;
        }

        iterateHandlers(handlerStore, function (handler) {
            // Execute the handler inside try-catch to prevent one bad handler from stopping the bus
            try {
                handler(payload, meta);
            } catch (e) {
                console.error('Error executing handler for command ' + commandName + ':', e);
            }
        });
    } finally {
        // Crucial: always clean up the recursion guard
        delete activeDispatches[key];
    }
  }
  
  /**
   * ---------------------------------------------------------------------------
   * SECTION 5 — GLOBAL EXPORTS (Must match your expected API)
   * ---------------------------------------------------------------------------
   */

  // Define the global namespace object if it doesn't exist
  if (typeof global.StarmusHooks === 'undefined') {
    global.StarmusHooks = {};
  }
  
  global.StarmusHooks.subscribe = subscribe;
  global.StarmusHooks.dispatch = dispatch;
  global.StarmusHooks.debugLog = debugLog;

  // --- CRITICAL PATCH: Bridge CommandBus to StarmusHooks ---
  // This ensures that if UI calls CommandBus.dispatch, StarmusHooks listeners hear it.
  global.CommandBus = {
      subscribe: subscribe,
      dispatch: dispatch,
      debugLog: debugLog
  };
    
})(typeof window !== 'undefined' ? window : globalThis);

// ------------------------------------------------------------
// ES Module Export Bridge
// ------------------------------------------------------------
const _G = (typeof window !== 'undefined' ? window : globalThis);

export const debugLog = (_G.StarmusHooks && _G.StarmusHooks.debugLog) || function () {};
export const dispatch = (_G.StarmusHooks && _G.StarmusHooks.dispatch) || function () {};
export const subscribe = (_G.StarmusHooks && _G.StarmusHooks.subscribe) || function () {};

// Ensure the module export also points to the same singleton
export const CommandBus = _G.CommandBus;

export default {
  debugLog,
  subscribe,
  dispatch,
  CommandBus
};