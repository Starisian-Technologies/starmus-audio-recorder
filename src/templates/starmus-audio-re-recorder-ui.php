<?php
if (!defined('ABSPATH')) exit;

/** @var int $post_id */
/** @var int $target_post_id */
/** @var string $consent_message */
/** @var string $data_policy_url */

// Debug logging
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('Re-recorder template loaded with post_id: ' . $post_id);
}
?>
<div class="starmus-rerecorder-wrapper">
    <form id="starmus-rerecorder-autostep"
        method="post"
        action="<?php echo esc_url(home_url('/' . get_option('starmus_recorder_page_slug', 'record') . '/')); ?>"
        data-post-id="<?php echo esc_attr($post_id); ?>"
        data-target-post-id="<?php echo esc_attr($target_post_id); ?>">

        <input type="hidden" name="starmus_rerecord" value="1">
        <input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>">
        <input type="hidden" name="artifact_id" value="<?php echo esc_attr($target_post_id); ?>">

        <p><strong>Re-recording for Post ID:</strong> <?php echo esc_html($post_id); ?></p>

        <label style="display:block;margin-bottom:8px;">
            <input type="checkbox" required>
            <?php echo esc_html($consent_message); ?>
            <?php if ($data_policy_url): ?>
                <a href="<?php echo esc_url($data_policy_url); ?>" target="_blank">Data Policy</a>
            <?php endif; ?>
        </label>

        <button type="submit" class="button button-primary" style="width:100%;">
            Start Re-recording
        </button>
    </form>
</div>

<script>
    (function() {
        'use strict';

        // Make post_id available in console for debugging
        window.STARMUS_RERECORDER_POST_ID = <?php echo json_encode($post_id); ?>;

        console.log('Re-recorder initialized with post_id:', window.STARMUS_RERECORDER_POST_ID);

        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('#starmus-rerecorder-autostep');
            if (!form) {
                console.error('Re-recorder form not found!');
                return;
            }

            console.log('Re-recorder form found:', {
                postId: form.dataset.postId,
                targetPostId: form.dataset.targetPostId
            });

            // Auto-submit once consent clicked
            const checkbox = form.querySelector('input[type=checkbox]');
            if (checkbox) {
                checkbox.addEventListener('change', function() {
                    if (this.checked) {
                        console.log('Consent given, submitting re-recorder form');
                        form.submit();
                    }
                });
            }
        });
    })();
</script>