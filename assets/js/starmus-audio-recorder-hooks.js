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

    function addHook(type, tag, callback, priority = 10) {
        if (!isValidTag(tag) || typeof callback !== 'function') return false;
        const hookType = type === 'actions' ? hooks.actions : hooks.filters;
        if (!(tag in hookType)) {
            hookType[tag] = [];
        }
        hookType[tag].push({ callback, priority });
        hookType[tag].sort((a, b) => a.priority - b.priority);
        return true;
    }

    function removeHook(type, tag, callback) {
        if (!isValidTag(tag)) return false;
        const hookType = type === 'actions' ? hooks.actions : hooks.filters;
        if (!(tag in hookType)) return false;
        const index = hookType[tag].findIndex(hook => hook.callback === callback);
        if (index > -1) {
            hookType[tag].splice(index, 1);
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
                const actionHooks = hooks.actions[tag];
                actionHooks.forEach(hook => {
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
                const filterHooks = hooks.filters[tag];
                filterHooks.forEach(hook => {
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
