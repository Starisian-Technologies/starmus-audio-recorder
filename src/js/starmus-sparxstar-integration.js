/**
 * @file starmus-sparxstar-integration.js
 * @version 7.1.5-FINAL
 * @description Integration layer between Sparxstar UEC and Starmus Recorder.
 * Maps environment profiles to recorder tiers and manages data caching.
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
    _cachedData: null,

    /**
     * Internal listener to capture the event as soon as it fires.
     */
    _listen: function() {
        window.addEventListener('sparxstar:environment-ready', (e) => {
            this._cachedData = this._normalizePayload(e.detail);
            console.log('[SparxstarIntegration] Data captured and normalized to Tier:', this._cachedData.tier);
        }, { once: true });
    },

    /**
     * Maps Sparxstar overallProfile to Starmus A/B/C Tiers.
     */
    _normalizePayload: function(raw) {
        const profile = raw?.technical?.profile?.overallProfile || 'low_capability';
        
        // Mapping Logic
        let tier = 'C';
        if (profile === 'high_capability') tier = 'A';
        else if (profile === 'midrange') tier = 'B';

        return {
            tier: tier,
            raw: raw,
            network: raw?.technical?.profile?.networkProfile || 'unknown',
            device: raw?.technical?.profile?.deviceClass || 'unknown',
            recordingSettings: { 
                uploadChunkSize: tier === 'A' ? 1048576 : 524288 
            }
        };
    },

    /**
     * Initializes the integration for the main recorder boot process.
     */
    init: function() {
        return new Promise((resolve) => {
            if (this._cachedData) {
                console.log('[SparxstarIntegration] Resolving with cached Tier:', this._cachedData.tier);
                return resolve(this._cachedData);
            }

            const handleReady = (e) => {
                this._cachedData = this._normalizePayload(e.detail);
                resolve(this._cachedData);
            };

            window.addEventListener('sparxstar:environment-ready', handleReady, { once: true });

            // Safety timeout
            setTimeout(() => {
                if (this._cachedData) return resolve(this._cachedData);
                window.removeEventListener('sparxstar:environment-ready', handleReady);
                console.warn('[SparxstarIntegration] Environment timeout. Using fallback Tier C.');
                resolve(this.getEnvironmentData());
            }, 3500);
        });
    },

    /**
     * Synchronous access for modules like Calibration.
     */
    getEnvironmentData: function() {
        if (this._cachedData) return this._cachedData;
        return {
            tier: 'C',
            network: 'unknown',
            device: 'desktop',
            recordingSettings: { uploadChunkSize: 524288 }
        };
    },

    /**
     * Reports telemetry or errors back to the Sparxstar logger.
     */
    reportError: function(msg, data) {
        console.log('[SparxstarIntegration] Reporting Event:', msg, data);
        // If a global log function exists from the UEC script, call it here.
    }
};

sparxstarIntegration._listen();
export default sparxstarIntegration;
