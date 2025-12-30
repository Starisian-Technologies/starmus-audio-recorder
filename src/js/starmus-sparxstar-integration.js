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
		const $ = typeof jQuery !== 'undefined' ? jQuery : function(){};
		const acf = typeof acf !== 'undefined' ? acf : null;
		const tinyMCE = typeof tinyMCE !== 'undefined' ? tinyMCE : null;
		const aiwa_recorder_data = typeof aiwa_recorder_data !== 'undefined' ? aiwa_recorder_data : null;
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
	getEnvironmentData: () => {
		// Return a default object so the TUS script doesn't crash
		return {
			tier: 'C',
			recordingSettings: { uploadChunkSize: 524288 },
			network: { type: 'unknown' }
		};
	},
	reportError: (msg, data) => {
		console.warn('[Integration] Error:', msg, data);
	}
};

// This line fixes the Rollup error
export default sparxstarIntegration;

