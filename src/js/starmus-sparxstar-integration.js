/**
 * @file starmus-sparxstar-integration.js
 * @version 7.1.5-FINAL
 * @description Integration layer between Sparxstar UEC and Starmus Recorder.
 * Maps environment profiles to recorder tiers and manages data caching.
 */

"use strict";

/* 1. WP/ACF INTEGRATION LOGIC */
(function ($) {
    /**
     * Initializes ACF and AIWA recorder bridge once the DOM is ready.
     *
     * @function attachAiwaRecorderHandlers
     * @returns {void}
     */
    function attachAiwaRecorderHandlers() {
        const acfInstance = typeof window.acf !== "undefined" ? window.acf : null;
        const tinyMCEInstance = typeof window.tinyMCE !== "undefined" ? window.tinyMCE : null;
        const aiwaRecorderData =
            typeof window.aiwa_recorder_data !== "undefined" ? window.aiwa_recorder_data : null;

        if (!$ || !$.fn) {
            console.warn("[SparxstarIntegration] jQuery missing; recorder bridge skipped.");
            return;
        }

        // Listen for the ACF AJAX success event
        if (acfInstance) {
            /**
             * Handles ACF AJAX post creation success.
             *
             * @function handleAcfAjaxSuccess
             * @param {Object} response - ACF AJAX response payload
             * @returns {void}
             */
            acfInstance.add_action("wp_ajax_success", function handleAcfAjaxSuccess(response) {
                if (response && response.data && response.data.post_id) {
                    const newTargetId = response.data.post_id;
                    const titleText = $("#acf-_post_title").val();
                    let contentText = "";

                    if (tinyMCEInstance && tinyMCEInstance.get("acf-editor-58")) {
                        contentText = tinyMCEInstance
                            .get("acf-editor-58")
                            .getContent({ format: "text" });
                    } else {
                        contentText = $("#acf-_post_content").val();
                    }

                    /**
                     * Handles transition to recorder step after ACF save.
                     *
                     * @function handleStepOneFade
                     * @returns {void}
                     */
                    $("#aiwa-step-1").fadeOut(300, function handleStepOneFade() {
                        $("#script-title").text(titleText);
                        $("#script-content").text(contentText);
                        $("#aiwa-step-2").fadeIn(300);

                        /**
                         * Processes recorder loader AJAX success.
                         *
                         * @function handleAjaxSuccess
                         * @param {string} html - Rendered recorder markup
                         * @returns {void}
                         */
                        $.ajax({
                            url: aiwaRecorderData
                                ? aiwaRecorderData.ajax_url
                                : "/wp-admin/admin-ajax.php",
                            type: "POST",
                            data: {
                                action: "aiwa_load_prompter_recorder",
                                target_post_id: newTargetId,
                                audio_post_id: 0,
                            },
                            success: function handleAjaxSuccess(html) {
                                $("#aiwa-recorder-load-point").html(html);
                            },
                        });
                    });
                }
            });
        }

        if (typeof $.fn.on === "function") {
            /**
             * Handles recorder completion notifications from child frames.
             *
             * @function handleRecorderComplete
             * @param {Object} event - Message event wrapper provided by jQuery
             * @returns {void}
             */
            $(window).on("message", function handleRecorderComplete(event) {
                const data = event.originalEvent.data;
                if (data && data.type === "starmusRecordingComplete") {
                    $("#aiwa-step-2").html(`
            <div style="text-align:center; padding:50px;">
                <h2 style="color:green;">✓ Saved and Recorded</h2>
                <p>Your entry and audio have been successfully linked.</p>
                <button onclick="window.location.reload();" class="button button-large">Add Another Entry</button>
            </div>
          `);
                }
            });
        }
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", attachAiwaRecorderHandlers);
    } else {
        attachAiwaRecorderHandlers();
    }
})(typeof jQuery !== "undefined" ? jQuery : null);

/* 2. SPARXSTAR INTEGRATION OBJECT */
/**
 * Provides integration hooks for Sparxstar and Starmus components.
 *
 * @global
 * @constant
 * @type {Object}
 */
const sparxstarIntegration = {
    /**
     * Indicates whether the integration layer is available.
     *
     * @type {boolean}
     */
    isAvailable: true,
    /**
     * Initializes integration and resolves environment data.
     *
     * @function init
     * @returns {Promise<Object>} Resolved environment payload
     */
    init: () => {
        return Promise.resolve(sparxstarIntegration.getEnvironmentData());
    },
    /**
     * Returns a default environment data object for compatibility.
     *
     * @function getEnvironmentData
     * @returns {Object} Default environment payload
     */
    getEnvironmentData: () => {
        // Return a default object so the TUS script doesn't crash
        return {
            tier: window.MediaRecorder ? "A" : "C",
            recordingSettings: { uploadChunkSize: 524288 },
            network: { type: "unknown" },
        };
    },
    /**
     * Reports integration errors to the console.
     *
     * @function reportError
     * @param {string} msg - Message describing the error
     * @param {Object} data - Supplemental error data
     * @returns {void}
     */
    reportError: (msg, data) => {
        console.warn("[Integration] Error:", msg, data);
    },
};

// This line fixes the Rollup error
export default sparxstarIntegration;
