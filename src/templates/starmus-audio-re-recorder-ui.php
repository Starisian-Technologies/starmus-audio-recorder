<?php
if (!defined('ABSPATH')) exit;

/** @var int $post_id */
/** @var int $target_post_id */
/** @var string $consent_message */
/** @var string $data_policy_url */

?>
<form id="starmus-rerecorder-autostep"
      method="post"
      action="<?php echo esc_url( home_url('/' . get_option('starmus_recorder_page_slug', 'record') . '/') ); ?>">

    <input type="hidden" name="starmus_rerecord" value="1">
    <input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>">
    <input type="hidden" name="artifact_id" value="<?php echo esc_attr($target_post_id); ?>">

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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('#starmus-rerecorder-autostep');
    if (!form) return;
    // Auto-submit once consent clicked
    form.querySelector('input[type=checkbox]').addEventListener('change', function () {
        form.submit();
    });
});
</script>