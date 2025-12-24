/**
 * @file starmus-sparxstar-integration.js
 * @version 7.1.0-INTEGRATION-FIX
 * @description Integration layer between Sparxstar UEC and Starmus Recorder.
 */

'use strict';

/* 1. WP/ACF INTEGRATION LOGIC */
(function ($) {
    $(document).ready(function () {
        // Listen for the ACF AJAX success event
        if (typeof acf !== 'undefined') {
            acf.add_action('wp_ajax_success', function( response ){
                if( response && response.data && response.data.post_id ) {
                    const newTargetId = response.data.post_id;
                    const titleText = $('#acf-_post_title').val();
                    let contentText = '';

                    if (typeof tinyMCE !== "undefined" && tinyMCE.get('acf-editor-58')) {
                        contentText = tinyMCE.get('acf-editor-58').getContent({format: 'text'});
                    } else {
                        contentText = $('#acf-_post_content').val();
                    }

                    $('#aiwa-step-1').fadeOut(300, function() {
                        $('#script-title').text(titleText);
                        $('#script-content').text(contentText);
                        $('#aiwa-step-2').fadeIn(300);

                        $.ajax({
                            url: typeof aiwa_recorder_data !== 'undefined' ? aiwa_recorder_data.ajax_url : '/wp-admin/admin-ajax.php',
                            type: 'POST',
                            data: {
                                action: 'aiwa_load_prompter_recorder',
                                target_post_id: newTargetId,
                                audio_post_id: 0
                            },
                            success: function(html) {
                                $('#aiwa-recorder-load-point').html(html);
                            }
                        });
                    });
                }
            });
        }

        $(window).on('message', function (event) {
            const data = event.originalEvent.data;
            if (data && data.type === 'starmusRecordingComplete') {
                $('#aiwa-step-2').html(`
                    <div style="text-align:center; padding:50px;">
                        <h2 style="color:green;">✓ Saved and Recorded</h2>
                        <p>Your entry and audio have been successfully linked.</p>
                        <button onclick="window.location.reload();" class="button button-large">Add Another Entry</button>
                    </div>
                `);
            }
        });
    });
})(typeof jQuery !== 'undefined' ? jQuery : function(){});


/* 2. SPARXSTAR INTEGRATION OBJECT */
/**
 * Integration object used by starmus-main.js to bootstrap the environment.
 * In the minified bundle, this object is referred to as 'pd'.
 */
const sparxstarIntegration = {
    isAvailable: true,

    /**
     * Initializes the integration and waits for the environment check to finish.
     * Required by initRecorderInstance in starmus-main.js.
     * 
     * @returns {Promise<Object>} Resolves with normalized environment data.
     */
    init: function() {
        return new Promise((resolve) => {
            console.log('[SparxstarIntegration] Initializing environment sync...');

            // If the event has already fired, or we need to wait for it:
            const handleReady = (e) => {
                console.log('[SparxstarIntegration] Environment event received.');
                const data = e.detail || {};
                resolve(data);
            };

            // Listen for the event dispatched by the UEC script
            window.addEventListener('sparxstar:environment-ready', handleReady, { once: true });

            // Safety timeout: If Sparxstar doesn't respond in 3.5 seconds, 
            // boot the recorder anyway with default settings.
            setTimeout(() => {
                window.removeEventListener('sparxstar:environment-ready', handleReady);
                
                // Check if data was already dispatched to the store by starmus-integrator.js
                // If not, resolve with defaults.
                const fallbackData = this.getEnvironmentData();
                console.warn('[SparxstarIntegration] Environment timeout. Using fallback.');
                resolve(fallbackData);
            }, 3500);
        });
    },

    /**
     * Returns baseline environment settings if the live check fails.
     */
    getEnvironmentData: function() {
        return {
            tier: 'C',
            recordingSettings: { 
                uploadChunkSize: 524288 
            },
            network: { 
                type: 'unknown',
                profile: 'standard'
            },
            device: {
                class: 'desktop'
            }
        };
    },

    /**
     * Error reporting bridge.
     */
    reportError: function(msg, data) {
        console.warn('[SparxstarIntegration] Error Reported:', msg, data);
    }
};

// Export for Rollup/Webpack and starmus-main.js
export default sparxstarIntegration;
