// FILE: starmus-hooks.js (Load this file FIRST)
/**
 * Starmus Extensibility Hooks
 * A simple, WordPress-like action and filter system for client-side code.
 */
(function(window) {
    'use strict';
    const hooks = {
        actions: Object.create(null),
        filters: Object.create(null)
    };

    function isValidTag(tag) {
        return typeof tag === 'string' && tag.length > 0 && !/[.__proto__constructor]/.test(tag);
    }

    const hasOwnProp = Object.prototype.hasOwnProperty;

    function addHook(type, tag, callback, priority = 10) {
        if (!isValidTag(tag) || typeof callback !== 'function') return false;
        if (!(tag in hooks[type])) {
            hooks[type][tag] = [];
        }
        hooks[type][tag].push({ callback, priority });
        hooks[type][tag].sort((a, b) => a.priority - b.priority);
        return true;
    }

    function removeHook(type, tag, callback) {
        if (!isValidTag(tag) || !(tag in hooks[type])) return false;
        const index = hooks[type][tag].findIndex(hook => hook.callback === callback);
        if (index > -1) {
            hooks[type][tag].splice(index, 1);
            return true;
        }
        return false;
    }

    window.StarmusHooks = {
        addAction: function(tag, callback, priority = 10) {
            addHook('actions', tag, callback, priority);
        },
        removeAction: function(tag, callback) {
            return removeHook('actions', tag, callback);
        },
        doAction: function(tag, ...args) {
            if (isValidTag(tag) && tag in hooks.actions) {
                hooks.actions[tag].forEach(hook => {
                    try {
                        hook.callback(...args);
                    } catch (error) {
                        console.error(`Error in action '${tag}':`, error);
                    }
                });
            }
        },
        addFilter: function(tag, callback, priority = 10) {
            addHook('filters', tag, callback, priority);
        },
        removeFilter: function(tag, callback) {
            return removeHook('filters', tag, callback);
        },
        applyFilters: function(tag, value, ...args) {
            let filteredValue = value;
            if (isValidTag(tag) && tag in hooks.filters) {
                hooks.filters[tag].forEach(hook => {
                    try {
                        filteredValue = hook.callback(filteredValue, ...args);
                    } catch (error) {
                        console.error(`Error in filter '${tag}':`, error);
                    }
                });
            }
            return filteredValue;
        }
    };
})(window);
