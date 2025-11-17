/**
 * @file starmus-integrator.js
 * @version 3.1.0
 * @description Master orchestrator. Waits for the SparxStar environment, then
 * wires StateStore, UI, Recorder, and Core for each Starmus recorder form.
 */
/* global window, document, FormData */
(function (window, document) {
    'use strict';

    if (!window.STARMUS) {
        window.STARMUS = {};
    }

    var STARMUS = window.STARMUS;
    var StateStore = STARMUS.StateStore;
    var CommandBus = STARMUS.CommandBus;
    var UI = STARMUS.UI;
    var Recorder = STARMUS.Recorder;
    var Core = STARMUS.Core;

    // Global registry so we can look up instance stores (e.g., for reset).
    if (!STARMUS.instances) {
        STARMUS.instances = {};
    }

    STARMUS.getInstanceStore = function (instanceId) {
        var inst = STARMUS.instances[instanceId];
        return inst ? inst.store : null;
    };

    function wireInstance(env, formEl) {
        var instanceId = formEl.getAttribute('data-starmus-id');
        if (!instanceId) {
            instanceId = 'starmus_' + Date.now() + '_' + Math.random().toString(16).slice(2);
            formEl.setAttribute('data-starmus-id', instanceId);
        }

        var store = StateStore.create();

        var elements = {
            recordBtn: formEl.querySelector('[data-starmus-action="record"]'),
            stopBtn: formEl.querySelector('[data-starmus-action="stop"]'),
            submitBtn: formEl.querySelector('[data-starmus-action="submit"]'),
            resetBtn: formEl.querySelector('[data-starmus-action="reset"]'),
            fileInput: formEl.querySelector('input[type="file"]'),
            statusEl: formEl.querySelector('[data-starmus-status]'),
            progressEl: formEl.querySelector('[data-starmus-progress]')
        };

        UI.initInstance(store, elements);
        Recorder.init(store);
        Core.init(store);

        // Register instance globally
        STARMUS.instances[instanceId] = {
            store: store,
            form: formEl,
            elements: elements
        };

        // Seed initial state
        store.dispatch({
            type: 'starmus/init',
            payload: {
                instanceId: instanceId,
                env: env,
                speechSupported: !!(window.SpeechRecognition || window.webkitSpeechRecognition)
            }
        });

        // Wire DOM -> CommandBus
        if (elements.recordBtn) {
            elements.recordBtn.addEventListener('click', function () {
                CommandBus.dispatch('start-mic', {}, { instanceId: instanceId });
            });
        }

        if (elements.stopBtn) {
            elements.stopBtn.addEventListener('click', function () {
                CommandBus.dispatch('stop-mic', {}, { instanceId: instanceId });
            });
        }

        if (elements.fileInput) {
            elements.fileInput.addEventListener('change', function () {
                if (elements.fileInput.files && elements.fileInput.files.length > 0) {
                    CommandBus.dispatch('attach-file', {
                        file: elements.fileInput.files[0]
                    }, { instanceId: instanceId });
                }
            });
        }

        formEl.addEventListener('submit', function (event) {
            event.preventDefault();
            var formData = new FormData(formEl);
            var formFields = {};
            formData.forEach(function (value, key) {
                // If multiple values per key, last one wins; adjust if needed.
                formFields[key] = value;
            });
            CommandBus.dispatch('submit', { formFields: formFields }, { instanceId: instanceId });
        });

        if (elements.resetBtn) {
            elements.resetBtn.addEventListener('click', function () {
                CommandBus.dispatch('reset', {}, { instanceId: instanceId });
            });
        }
    }

    function onEnvironmentReady(event) {
        var env = event.detail || {};
        var forms = document.querySelectorAll('form[data-starmus="recorder"]');
        if (!forms || !forms.length) {
            return;
        }
        Array.prototype.forEach.call(forms, function (formEl) {
            wireInstance(env, formEl);
        });
    }

    // High-level reset command handler
    if (CommandBus) {
        CommandBus.subscribe('reset', function (cmd) {
            var store = STARMUS.getInstanceStore(cmd.meta.instanceId);
            if (store) {
                store.dispatch({ type: 'starmus/reset' });
            }
        });
    }

    document.addEventListener('sparxstar:environment-ready', onEnvironmentReady, { once: true });

}(window, document));
