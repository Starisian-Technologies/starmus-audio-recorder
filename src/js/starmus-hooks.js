/**
 * @file starmus-hooks.js
 * @version 3.1.0
 * @description Core hooks system and command bus for Starmus.
 * Provides WordPress-style actions/filters plus a dedicated CommandBus
 * built on top of the same engine.
 */
/* global window */
(function (window) {
    'use strict';

    if (!window.STARMUS) {
        window.STARMUS = {};
    }

    var STARMUS = window.STARMUS;

    // ------------------------------
    // Simple Hooks Engine
    // ------------------------------

    var _actions = Object.create(null);
    var _filters = Object.create(null);

    function _addHook(store, type, tag, callback, priority) {
        if (typeof tag !== 'string' || typeof callback !== 'function') {
            return;
        }
        var bucket = store[tag];
        if (!bucket) {
            bucket = [];
            store[tag] = bucket;
        }
        bucket.push({
            type: type,
            callback: callback,
            priority: typeof priority === 'number' ? priority : 10
        });
        bucket.sort(function (a, b) {
            return a.priority - b.priority;
        });
    }

    function _removeHook(store, tag, callback) {
        var bucket = store[tag];
        if (!bucket || !bucket.length) {
            return;
        }
        if (!callback) {
            delete store[tag];
            return;
        }
        store[tag] = bucket.filter(function (hook) {
            return hook.callback !== callback;
        });
        if (!store[tag].length) {
            delete store[tag];
        }
    }

    function _runActions(store, tag, args) {
        var bucket = store[tag];
        if (!bucket || !bucket.length) {
            return;
        }
        // copy to prevent mutation issues during iteration
        bucket.slice().forEach(function (hook) {
            try {
                hook.callback.apply(null, args);
            } catch (e) {
                // Swallow errors to avoid breaking the chain
                // but log for debugging.
                if (window.console && console.error) {
                    console.error('STARMUS Hooks action error for tag "' + tag + '":', e);
                }
            }
        });
    }

    function _runFilters(store, tag, value, args) {
        var bucket = store[tag];
        if (!bucket || !bucket.length) {
            return value;
        }
        var filtered = value;
        bucket.slice().forEach(function (hook) {
            try {
                var allArgs = [filtered].concat(args);
                filtered = hook.callback.apply(null, allArgs);
            } catch (e) {
                if (window.console && console.error) {
                    console.error('STARMUS Hooks filter error for tag "' + tag + '":', e);
                }
            }
        });
        return filtered;
    }

    var Hooks = {
        addAction: function (tag, callback, priority) {
            _addHook(_actions, 'action', tag, callback, priority);
        },

        removeAction: function (tag, callback) {
            _removeHook(_actions, tag, callback);
        },

        doAction: function (tag) {
            var args = Array.prototype.slice.call(arguments, 1);
            _runActions(_actions, tag, args);
        },

        addFilter: function (tag, callback, priority) {
            _addHook(_filters, 'filter', tag, callback, priority);
        },

        removeFilter: function (tag, callback) {
            _removeHook(_filters, tag, callback);
        },

        applyFilters: function (tag, value) {
            var args = Array.prototype.slice.call(arguments, 2);
            return _runFilters(_filters, tag, value, args);
        }
    };

    // ------------------------------
    // Command Bus (built on Hooks)
    // ------------------------------
    // Commands are strongly namespaced under "starmus:command:<type>"

    var CommandBus = (function () {
        var PREFIX = 'starmus:command:';

        function subscribe(type, handler, priority) {
            if (typeof handler !== 'function') {
                return;
            }
            var tag = PREFIX + type;
            Hooks.addAction(tag, function (command) {
                // command = { type, payload, meta }
                handler(command);
            }, priority);
        }

        function dispatch(type, payload, meta) {
            var tag = PREFIX + type;
            var command = {
                type: type,
                payload: payload || {},
                meta: meta || {}
            };
            Hooks.doAction(tag, command);
        }

        return {
            subscribe: subscribe,
            dispatch: dispatch
        };
    }());

    STARMUS.Hooks = Hooks;
    STARMUS.CommandBus = CommandBus;

}(window));
