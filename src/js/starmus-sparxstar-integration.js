/**
 * @file starmus-sparxstar-integration.js
 * @version 7.1.1-CACHED-INIT
 * @description Integration layer between Sparxstar UEC and Starmus Recorder.
 * Includes event caching to prevent timeouts on double-initialization.
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
const sparxstarIntegration = {
    isAvailable: true,
    _cachedData: null, // Private cache to store environment data

    /**
     * Internal listener: Starts watching for the Sparxstar event immediately 
     * when the script loads so we don't miss it.
     */
    _listen: function() {
        window.addEventListener('sparxstar:environment-ready', (e) => {
            console.log('[SparxstarIntegration] Data captured and cached.');
            this._cachedData = e.detail || {};
        }, { once: true });
    },

    /**
     * Initializes the integration. 
     * If data is already cached, it returns it instantly.
     * 
     * @returns {Promise<Object>} Resolves with normalized environment data.
     */
    init: function() {
        return new Promise((resolve) => {
            // If data was already received by the listener, resolve immediately
            if (this._cachedData) {
                console.log('[SparxstarIntegration] Returning cached environment data.');
                return resolve(this._cachedData);
            }

            console.log('[SparxstarIntegration] Initializing environment sync...');

            const handleReady = (e) => {
                const data = e.detail || {};
                this._cachedData = data; // Cache for any subsequent calls
                resolve(data);
            };

            // Listen for the event dispatched by the UEC script
            window.addEventListener('sparxstar:environment-ready', handleReady, { once: true });

            // Safety timeout: 3.5 seconds
            setTimeout(() => {
                // Final check to see if data arrived just as we timed out
                if (this._cachedData) {
                    return resolve(this._cachedData);
                }

                window.removeEventListener('sparxstar:environment-ready', handleReady);
                console.warn('[SparxstarIntegration] Environment timeout. Using fallback.');
                resolve(this.getEnvironmentData());
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

// Start the listener immediately
sparxstarIntegration._listen();

// Export for Rollup/Webpack and starmus-main.js
export default sparxstarIntegration;
