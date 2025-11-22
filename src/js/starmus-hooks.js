/**
 * @file starmus-hooks.js
 * @version 4.1.0
 * @description Lightweight command bus and debug helpers.
 * Optimized for low-resource devices with recursion guards and memory safety.
 */

'use strict';

/**
 * Command handlers registry.
 * Object.create(null) prevents prototype pollution.
 */
const handlers = Object.create(null);

/**
 * Recursion guard to prevent infinite dispatch loops.
 * Tracks currently executing commands with instance scope.
 */
const activeDispatches = new Set();

/**
 * Subscribe to a command.
 * Returns a cleanup function to unsubscribe.
 * 
 * @param {string} commandName 
 * @param {function} handler 
 * @returns {function} unsubscribe
 */
function subscribe(commandName, handler) {
    if (!handlers[commandName]) {
        handlers[commandName] = new Set();
    }
    handlers[commandName].add(handler);
    return () => {
        if (handlers[commandName]) {
            handlers[commandName].delete(handler);
        }
    };
}

/**
 * Dispatch a command to all subscribers.
 * Includes safeguards against recursive loops and handler errors.
 * 
 * @param {string} commandName 
 * @param {object} payload 
 * @param {object} meta 
 */
function dispatch(commandName, payload = {}, meta = {}) {
    const key = commandName + '::' + (meta?.instanceId || '');

    if (activeDispatches.has(key)) {
        console.warn('[Starmus] Prevented recursive dispatch:', commandName);
        return;
    }

    const group = handlers[commandName];
    if (!group || !group.size) {
        return;
    }

    activeDispatches.add(key);
    try {
        // Array.from creates a snapshot, safe against handlers unsubscribing during execution
        for (const handler of Array.from(group)) {
            try {
                handler(payload, meta);
            } catch (e) {
                // Swallow individual handler errors so others still run
                console.error('[Starmus] Command handler error:', commandName, e);
            }
        }
    } finally {
        // Always release the guard, even if a handler crashes hard
        activeDispatches.delete(key);
    }
}

/**
 * Nuke all handlers.
 * Critical for SPA transitions, AJAX reloads, or "Reset" actions 
 * to prevent duplicate event listeners accumulating in memory.
 */
function clearAllHandlers() {
    for (const key in handlers) {
        if (handlers[key]) {
            handlers[key].clear();
        }
    }
}

export const CommandBus = {
    subscribe,
    dispatch,
    clearAllHandlers,
};

/**
 * Optimized Debug Logger.
 * Checks config ONCE at load time to avoid repeated DOM/Global lookups 
 * in tight loops (audio callbacks, animation frames).
 */
const IS_DEBUG = typeof window !== 'undefined' && (
    window.STARMUS_DEBUG || 
    new URLSearchParams(window.location.search).has('debug')
);

export function debugLog(...args) {
    if (IS_DEBUG) {
        // Use apply to preserve browser console formatting/line numbers
        console.log.apply(console, ['[Starmus]', ...args]);
    }
}
