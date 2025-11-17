/**
 * @file starmus-hooks.js
 * @version 4.0.0
 * @description Lightweight command bus and debug helpers for Starmus.
 */

'use strict';

/**
 * Simple command bus for decoupled communication.
 * Command handlers receive (payload, meta).
 */
const handlers = Object.create(null);

function subscribe(commandName, handler) {
    if (!handlers[commandName]) {
        handlers[commandName] = new Set();
    }
    handlers[commandName].add(handler);
    return () => {
        handlers[commandName].delete(handler);
    };
}

function dispatch(commandName, payload = {}, meta = {}) {
    const group = handlers[commandName];
    if (!group || !group.size) {
        return;
    }
    for (const handler of Array.from(group)) {
        try {
            handler(payload, meta);
        } catch (e) {
            // Hard fail is worse than logging here.
            // eslint-disable-next-line no-console
            console.error('[Starmus] Command handler error:', commandName, e);
        }
    }
}

export const CommandBus = {
    subscribe,
    dispatch,
};

export function debugLog(...args) {
    if (typeof window !== 'undefined' && window.STARMUS_DEBUG) {
        // eslint-disable-next-line no-console
        console.log('[Starmus]', ...args);
    }
}
