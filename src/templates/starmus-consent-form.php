<?php

/**
 * Starmus Consent Form Template
 *
 * @package Starisian\Sparxstar\Starmus\templates
 */

if ( ! defined('ABSPATH')) {
    exit;
}

$instance_id = 'starmus_consent_' . uniqid();
?>

<div class="starmus-recorder-form starmus-glass">
    <h2><?php esc_html_e('Contributor Consent', 'starmus-audio-recorder'); ?></h2>

    <?php if ( ! empty($error_message)) { ?>
        <div class="starmus-notice" role="alert">
            <strong>Error:</strong> <?php echo esc_html($error_message); ?>
        </div>
    <?php } ?>

    <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(remove_query_arg('starmus_consent_submitted')); ?>">
        <?php wp_nonce_field('starmus_consent_action', 'starmus_consent_nonce'); ?>

        <div class="starmus-field-group">
            <label for="legal_name_<?php echo esc_attr($instance_id); ?>">
                <?php esc_html_e('Legal Name', 'starmus-audio-recorder'); ?> <span class="required">*</span>
            </label>
            <input type="text"
                id="legal_name_<?php echo esc_attr($instance_id); ?>"
                name="sparxstar_legal_name"
                required>
        </div>

        <div class="starmus-field-group">
            <label for="email_<?php echo esc_attr($instance_id); ?>">
                <?php esc_html_e('Email Address', 'starmus-audio-recorder'); ?> <span class="required">*</span>
            </label>
            <input type="email"
                id="email_<?php echo esc_attr($instance_id); ?>"
                name="sparxstar_email"
                required>
        </div>

        <div class="starmus-field-group">
            <label for="terms_type_<?php echo esc_attr($instance_id); ?>">
                <?php esc_html_e('Agreement Type', 'starmus-audio-recorder'); ?>
            </label>
            <select id="terms_type_<?php echo esc_attr($instance_id); ?>" name="starmus_terms_type" required>
                <option value="Submission"><?php esc_html_e('Recorded Submission Terms', 'starmus-audio-recorder'); ?></option>
                <option value="Full"><?php esc_html_e('Full Terms', 'starmus-audio-recorder'); ?></option>
            </select>
        </div>

        <div class="starmus-field-group">
            <label for="signature_<?php echo esc_attr($instance_id); ?>">
                <?php esc_html_e('Signature (Upload Image/PDF)', 'starmus-audio-recorder'); ?>
            </label>
            <input type="file"
                id="signature_<?php echo esc_attr($instance_id); ?>"
                name="starmus_contributor_signature"
                accept="image/*,application/pdf">
            <p class="description" style="font-size: 0.8em; margin-top: 5px;"><?php esc_html_e('Upload a photo or scan of your signature.', 'starmus-audio-recorder'); ?></p>
        </div>

        <div class="starmus-form-actions">
            <button type="submit" name="starmus_consent_submit" class="starmus-btn" data-starmus-action="continue">
                <?php esc_html_e('I Agree & Continue', 'starmus-audio-recorder'); ?>
            </button>
        </div>
    </form>
</div>
