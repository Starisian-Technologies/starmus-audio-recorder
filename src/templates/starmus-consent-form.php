<?php

declare(strict_types=1);

// starmus-consent-form.php
if ( ! defined('ABSPATH')) {
    exit;
}

?>

<div class="starmus-consent-container">
    <form id="starmus-legal-form" method="post" enctype="multipart/form-data" action="">
        <?php wp_nonce_field('starmus_consent_action', 'starmus_consent_nonce'); ?>
        <input type="hidden" name="starmus_consent_action" value="1">
        <input type="hidden" name="sparxstar_terms_type" value="signwrap">

        <input type="hidden" id="sparxstar_signatory_fingerprint_id" name="sparxstar_signatory_fingerprint_id" value="">
        <input type="hidden" id="sparxstar_lat" name="sparxstar_lat" value="">
        <input type="hidden" id="sparxstar_lng" name="sparxstar_lng" value="">

        <div class="starmus-form-group">
            <label for="sparxstar_legal_name"><?php esc_html_e('Legal Name', 'starmus-audio-recorder'); ?></label>
            <input type="text" id="sparxstar_legal_name" name="sparxstar_legal_name" required
                placeholder="<?php esc_attr_e('Enter your full legal name', 'starmus-audio-recorder'); ?>" autocomplete="name" inputmode="text">
        </div>

        <div class="starmus-form-group">
            <label for="sparxstar_email"><?php esc_html_e('Email Address', 'starmus-audio-recorder'); ?></label>
            <input type="email" id="sparxstar_email" name="sparxstar_email" required
                placeholder="<?php esc_attr_e('name@example.com', 'starmus-audio-recorder'); ?>" autocomplete="email" inputmode="email">
        </div>

        <div class="starmus-form-group">
            <label for="sparxstar_terms_purpose"><?php esc_html_e('Purpose', 'starmus-audio-recorder'); ?></label>
            <select id="sparxstar_terms_purpose" name="sparxstar_terms_purpose">
                <option value="contribute"><?php esc_html_e('Contribution License', 'starmus-audio-recorder'); ?></option>
                <option value="interview"><?php esc_html_e('Interview Release', 'starmus-audio-recorder'); ?></option>
            </select>
        </div>

        <div class="starmus-terms-wrapper">
            <p class="starmus-terms-label">
                <strong><?php esc_html_e('Agreement', 'starmus-audio-recorder'); ?></strong>
            </p>
            <div id="starmus-terms-scroll-area" class="starmus-scroll-box">
                <div class="starmus-legal-content">
                    <h3><?php esc_html_e('Contributor Agreement', 'starmus-audio-recorder'); ?></h3>
                    <p><strong><?php esc_html_e('1. License Grant.', 'starmus-audio-recorder'); ?></strong> <?php esc_html_e('The Contributor hereby grants...', 'starmus-audio-recorder'); ?></p>
                    <p><?php esc_html_e('(Content must be sufficient length to scroll on mobile)...', 'starmus-audio-recorder'); ?></p>
                    <p><?php esc_html_e('Lorem ipsum dolor sit amet...', 'starmus-audio-recorder'); ?></p>
                    <p><strong><?php esc_html_e('[...Legal Text End...]', 'starmus-audio-recorder'); ?></strong></p>
                </div>
            </div>
            <div id="starmus-scroll-notice" class="starmus-notice"><?php esc_html_e('Scroll to the bottom to sign.', 'starmus-audio-recorder'); ?></div>
        </div>

        <div class="starmus-signature-wrapper" id="starmus-signature-section" style="opacity: 0.5; pointer-events: none;">
            <label><?php esc_html_e('Digital Signature', 'starmus-audio-recorder'); ?></label>
            <div class="starmus-canvas-container">
                <canvas id="starmus-signature-pad"></canvas>
            </div>
            <div class="starmus-sig-controls">
                <button type="button" id="starmus-clear-sig" class="starmus-btn-secondary"><?php esc_html_e('Clear', 'starmus-audio-recorder'); ?></button>
                <span class="starmus-sig-status"><?php esc_html_e('Sign with finger', 'starmus-audio-recorder'); ?></span>
            </div>
            <input type="file" id="starmus_contributor_signature" name="starmus_contributor_signature" style="display: none;" accept="image/png">
        </div>

        <div class="starmus-form-actions">
            <button type="submit" id="starmus-submit-btn" class="starmus-btn-primary" disabled><?php esc_html_e('I Agree & Sign', 'starmus-audio-recorder'); ?></button>
        </div>
    </form>
</div>
