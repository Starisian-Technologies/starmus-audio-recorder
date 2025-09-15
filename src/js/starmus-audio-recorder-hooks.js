// FILE: starmus-hooks.js (Load this file FIRST)
/**
 * Starmus Extensibility Hooks
 * A simple, WordPress-like action and filter system for client-side code.
 * 
 * DESIGNED FOR PWA INTEGRATION:
 * These hooks provide strategic extension points for Progressive Web App features
 * including offline caching, background sync, push notifications, and service workers.
 *
 * @file starmus-audio-recorder-hooks.js
 * @version 0.4.5
 * @since 0.1.0
 * 
 * PWA HOOK REFERENCE:
 * 
 * SUBMISSION LIFECYCLE:
 * - starmus_before_submit(shouldProceed, submissionPackage) - Filter: Intercept for offline handling
 * - starmus_submission_started(instanceId, submissionPackage) - Action: Upload begins
 * - starmus_submission_queued(instanceId, submissionPackage) - Action: PWA took over submission
 * - starmus_upload_success(instanceId, url) - Action: Upload completed successfully
 * - starmus_submission_complete(instanceId, submissionPackage) - Action: Full process done
 * - starmus_submission_failed(instanceId, error) - Action: Failed, cache for retry
 * 
 * NETWORK STATE:
 * - starmus_network_online() - Action: Connection restored, process queue
 * - starmus_network_offline() - Action: Connection lost, enable offline mode
 * 
 * RECORDING EVENTS:
 * - starmus_recording_started(instanceId) - Action: Recording begins
 * - starmus_recording_stopped(instanceId, audioBlob) - Action: Audio ready for PWA processing
 * - starmus_speech_recognized(instanceId, transcript) - Action: Real-time transcription
 * 
 * EXAMPLE PWA INTEGRATION:
 * 
 * // Service Worker Registration
 * StarmusHooks.addAction('starmus_submissions_handler_ready', () => {
 *     navigator.serviceWorker.register('/sw.js');
 * });
 * 
 * // Offline Submission Handling
 * StarmusHooks.addFilter('starmus_before_submit', (proceed, data) => {
 *     if (!navigator.onLine) {
 *         cacheSubmissionForBackgroundSync(data);
 *         return false; // PWA handles it
 *     }
 *     return proceed;
 * });
 * 
 * // Background Sync Trigger
 * StarmusHooks.addAction('starmus_network_online', () => {
 *     navigator.serviceWorker.ready.then(reg => {
 *         reg.sync.register('starmus-upload-sync');
 *     });
 * });
 * 
 * // Push Notification on Success
 * StarmusHooks.addAction('starmus_submission_complete', (instanceId, data) => {
 *     new Notification('Recording uploaded successfully!');
 * });
 */
(function(window) {
    'use strict';
    const hooks = {
        actions: Object.create(null),
        filters: Object.create(null)
    };
    // NEW: A set to track which action hooks have already been fired.
    const firedHooks = new Set();

    function isValidTag(tag) {
        return typeof tag === 'string' && tag.length > 0 && !/[._]/.test(tag) && !/(proto|constructor)/.test(tag);
    }

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
            if (isValidTag(tag)) {
                firedHooks.add(tag); // Mark hook as fired
                if (tag in hooks.actions) {
                    hooks.actions[tag].forEach(hook => {
                        try {
                            hook.callback(...args);
                        } catch (error) {
                            console.error(`Error in action '${tag}':`, error);
                        }
                    });
                }
            }
        },
        hasFired: function(tag) {
            return isValidTag(tag) && firedHooks.has(tag);
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

    // Announce that the hook system is ready for PWA integration
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => window.StarmusHooks.doAction('starmus_hooks_ready'));
    } else {
        window.StarmusHooks.doAction('starmus_hooks_ready');
    }
    
    // PWA Helper: Check if running as installed app
    window.StarmusHooks.isPWA = () => {
        return window.matchMedia('(display-mode: standalone)').matches ||
               window.navigator.standalone === true;
    };

})(window);
