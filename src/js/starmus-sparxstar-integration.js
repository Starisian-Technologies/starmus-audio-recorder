(function ($) {
  $(document).ready(function () {
    
    // Listen for the ACF AJAX success event
    acf.add_action('wp_ajax_success', function( response ){
        
        if( response && response.data && response.data.post_id ) {
            const newTargetId = response.data.post_id;

            // 1. Capture text for the Prompter while Step 1 is still visible
            const titleText = $('#acf-_post_title').val();
            let contentText = '';
            
            if (typeof tinyMCE !== "undefined" && tinyMCE.get('acf-editor-58')) {
                contentText = tinyMCE.get('acf-editor-58').getContent({format: 'text'});
            } else {
                contentText = $('#acf-_post_content').val();
            }

            // 2. Transition UI to Step 2
            $('#aiwa-step-1').fadeOut(300, function() {
                
                // Set the Script text for the contributor to read
                $('#script-title').text(titleText);
                $('#script-content').text(contentText);
                $('#aiwa-step-2').fadeIn(300);

                // 3. Request the Recorder Shortcode with the correct IDs
                $.ajax({
                    url: aiwa_recorder_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'aiwa_load_prompter_recorder',
                        target_post_id: newTargetId, // The word/artifact
                        audio_post_id: 0             // New recording
                    },
                    success: function(html) {
                        $('#aiwa-recorder-load-point').html(html);
                    }
                });
            });
        }
    });

    // Listen for the final signal from Starmus
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
})(jQuery);
