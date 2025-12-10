/**
 * @file starmus-hooks.js
 * @version 5.2.0-PRODUCTION
 * @description Central Event Bus. Forces a Global Singleton to ensure connectivity.
 */

'use strict';

// 1. ESTABLISH GLOBAL SCOPE
const globalScope = typeof window !== 'undefined' ? window : globalThis;

// 2. CREATE/RETRIEVE SHARED REGISTRY (The "Central Brain")
if (!globalScope.StarmusHooksRegistry) {
    globalScope.StarmusHooksRegistry = {
        handlers: {}, // Object.create(null) can cause issues in some envs, using plain object
        activeDispatches: new Set()
    };
}
const registry = globalScope.StarmusHooksRegistry;

/**
 * UTILS: Debug Logger
 */
function debugLog(...args) {
    const debug = globalScope.starmusConfig && globalScope.starmusConfig.debug;
    if (debug) {
        try { console.log.apply(console, ['[Starmus]', ...args]); } catch (_) {}
    }
}

/**
 * SUBSCRIBE
 * Listens for a specific command on a specific instance.
 */
function subscribe(commandName, handler, instanceId) {
    // Create a specific key for this instance's command
    const key = commandName + '::' + (instanceId || '*');

    if (!registry.handlers[key]) {
        registry.handlers[key] = [];
    }
    
    // Add handler if not already present
    if (registry.handlers[key].indexOf(handler) === -1) {
        registry.handlers[key].push(handler);
    }

    // Return Unsubscribe Function
    return function unsubscribe() {
        if (registry.handlers[key]) {
            const idx = registry.handlers[key].indexOf(handler);
            if (idx > -1) registry.handlers[key].splice(idx, 1);
        }
    };
}

/**
 * DISPATCH
 * Sends a command to all relevant listeners (Specific Instance + Globals).
 */
function dispatch(commandName, payload = {}, meta = {}) {
    const instance = meta.instanceId || '*';
    const specificKey = commandName + '::' + instance;
    const globalKey = commandName + '::*';
    
    // Recursion Guard
    if (registry.activeDispatches.has(specificKey)) {
        debugLog('Prevented recursive dispatch:', commandName);
        return;
    }
    registry.activeDispatches.add(specificKey);

    try {
        // Collect all handlers (Specific + Wildcard + Legacy Global)
        const handlersList = [
            ...(registry.handlers[specificKey] || []),
            ...(registry.handlers[globalKey] || []),
            ...(registry.handlers[commandName] || []) // Legacy fallback
        ];

        if (handlersList.length === 0) {
            // debugLog('No listeners found for:', commandName, instance);
            return;
        }

        // Execute handlers
        handlersList.forEach(fn => {
            try {
                fn(payload, meta);
            } catch (e) {
                console.error('[Starmus] Handler error:', commandName, e);
            }
        });
    } finally {
        registry.activeDispatches.delete(specificKey);
    }
}

// 3. EXPORT & GLOBAL ATTACHMENT
// We attach the bus to window so legacy code (and the UI) finds it immediately.
const CommandBus = { subscribe, dispatch, debugLog };

globalScope.StarmusHooks = CommandBus;
globalScope.CommandBus = CommandBus;

export { subscribe, dispatch, debugLog, CommandBus };
export default CommandBus;