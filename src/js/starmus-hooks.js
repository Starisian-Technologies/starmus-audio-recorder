/**
 * @file starmus-hooks.js
 * @version 5.0.1
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
  var handlers = {}; // safer than Object.create(null) on old Safari/WebView

  // Recursion guard
  var activeDispatches = {}; // { "cmd::instanceId": true }

  /**
   * ---------------------------------------------------------------------------
   * SECTION 3 — API: subscribe()
   * ---------------------------------------------------------------------------
   */

  function subscribe(commandName, handler) {
    if (!handlers[commandName]) {
      handlers[commandName] = createHandlerStore();
    }
    addHandler(handlers[commandName], handler);

    // Return explicitly ES5-safe unsubscribe
    return function unsubscribe() {
      if (handlers[commandName]) {
        removeHandler(handlers[commandName], handler);
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

    var key = commandName + '::' + (meta.instanceId || '');

    if (activeDispatches[key]) {
      debugLog('Prevented recursive dispatch:', commandName); 
      return;
    }

    activeDispatches[key] = true;

    try {
        var handlerStore = handlers[commandName];

        if (!handlerStore) {
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
  global.StarmusHooks.debugLog = debugLog; // 🔥 New export
    
})(typeof window !== 'undefined' ? window : globalThis);

// ------------------------------------------------------------
// ES Module Export Bridge
// Allows Rollup imports without breaking the legacy global IIFE
// ------------------------------------------------------------
// ------------------------------------------------------------
// ES Module Export Bridge (Correct global reference)
// ------------------------------------------------------------
const _G = (typeof window !== 'undefined' ? window : globalThis);

export const debugLog = (_G.StarmusHooks && _G.StarmusHooks.debugLog) || function () {};

