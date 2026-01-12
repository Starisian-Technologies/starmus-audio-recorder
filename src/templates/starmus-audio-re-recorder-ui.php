<?php

/**
 * Starmus Re-Recorder UI Template
 *
 * NOTE: DESIGN INTENT
 * This template operates in "update mode" but intentionally mimics the standard
 * recorder UI to reduce user friction. Users do not need to know they are
 * "replacing" a file; they just need to provide a new one.
 * The explicit "Re-Record" context is hidden by design.
 *
 * @version 1.0.2-DATA-SAFE
 */
if ( ! defined('ABSPATH')) {
    exit;
}

/** @var int $post_id */
/** @var string $existing_title */

$instance_id = 'starmus_form_' . sanitize_key('rerecord_' . wp_generate_uuid4());

$allowed_file_types ??= 'webm';
$allowed_types_arr = array_values(array_filter(array_map(trim(...), explode(',', (string) $allowed_file_types)), fn($v): bool => $v !== ''));
$is_admin          = current_user_can('manage_options');
$consent_message ??= __('By submitting this recording, you agree to our', 'starmus-audio-recorder');
$data_policy_url ??= '';
?>

<div class="starmus-recorder-form sparxstar-glass-card">
    <form
        id="<?php echo esc_attr($instance_id); ?>"
        class="starmus-audio-form"
        method="post"
        enctype="multipart/form-data"
        novalidate
        data-starmus="recorder"
        data-starmus-mode="update"
        data-starmus-instance="<?php echo esc_attr($instance_id); ?>">

        <!-- HIDDEN FIELDS: Props propagated from Shortcode/UI -->
        <!-- Essential for linking recording to Script and setting Title -->
        <!-- NOTE: dc_creator is mapped to Post Title in StarmusSchemaMapper/SubmissionHandler -->
        <input type="hidden" name="dc_creator" value="<?php echo esc_attr($existing_title ?? ''); ?>">
        <input type="hidden" name="artifact_id" value="<?php echo esc_attr($script_id ?? 0); ?>">
        <input type="hidden" name="post_id" value="<?php echo esc_attr($post_id ?? 0); ?>">

        <div id="starmus_step1_<?php echo esc_attr($instance_id); ?>" class="starmus-step" data-starmus-step="1">
            <h2><?php esc_html_e('Initial Setup', 'starmus-audio-recorder'); ?></h2>

            <div class="starmus-notice">
                <p><?php esc_html_e('Please confirm consent to begin.', 'starmus-audio-recorder'); ?></p>
            </div>

            <div
                class="starmus-user-message"
                style="display:none;"
                role="alert"
                aria-live="polite"
                data-starmus-message-box></div>

            <!-- UPDATE LOGIC -->
            <input type="hidden" name="post_id" value="<?php echo esc_attr((string) $post_id); ?>">
            <input type="hidden" name="action" value="starmus_update_audio">

            <!-- METADATA PERSISTENCE -->
            <input type="hidden" name="starmus_dc_creator" value="<?php echo esc_attr($existing_title ?? ''); ?>">
            <input type="hidden" name="audio_file_type" value="audio/webm">

            <!-- Taxonomy Persistence -->
            <input type="hidden" name="language" value="<?php echo esc_attr($existing_language ?? ''); ?>">
            <input type="hidden" name="recording_type" value="<?php echo esc_attr($existing_type ?? ''); ?>">
            <input type="hidden" name="dialect" value="<?php echo esc_attr($existing_dialect ?? ''); ?>">

            <!-- INJECTED BY JS (Protected by Safe Sync) -->
            <input type="hidden" name="_starmus_env" value="">
            <input type="hidden" name="_starmus_calibration" value="">
            <input type="hidden" name="starmus_recording_metadata" value="">

            <!-- INJECTED FROM PHP (If Available) -->

            <fieldset class="starmus-consent-fieldset">
                <legend class="starmus-fieldset-legend">
                    <?php esc_html_e('Consent Agreement', 'starmus-audio-recorder'); ?>
                </legend>
                <div class="starmus-consent-field">
                    <input
                        type="checkbox"
                        id="starmus_consent_<?php echo esc_attr($instance_id); ?>"
                        name="agreement_to_terms_toggle"
                        value="1"
                        required>
                    <label for="starmus_consent_<?php echo esc_attr($instance_id); ?>">
                        <?php echo wp_kses_post($consent_message); ?>
                        <?php if ( ! empty($data_policy_url)) { ?>
                            <a
                                href="<?php echo esc_url($data_policy_url); ?>"
                                target="_blank"
                                rel="noopener noreferrer"><?php esc_html_e('Privacy Policy', 'starmus-audio-recorder'); ?></a>
                        <?php } ?>
                    </label>
                </div>
            </fieldset>

            <button type="button" class="starmus-btn starmus-btn--primary" data-starmus-action="next">
                <?php esc_html_e('Proceed to Recorder', 'starmus-audio-recorder'); ?>
            </button>
        </div>

        <!-- Step 2: Recorder -->
        <div id="starmus_step2_<?php echo esc_attr($instance_id); ?>" class="starmus-step" style="display:none;" data-starmus-step="2">
            <h2 tabindex="-1"><?php esc_html_e('Record Replacement', 'starmus-audio-recorder'); ?></h2>

            <div class="starmus-setup-container" data-starmus-setup-container>
                <button type="button" class="starmus-btn starmus-btn--primary" data-starmus-action="setup-mic">
                    <span class="dashicons dashicons-microphone" aria-hidden="true"></span> <?php esc_html_e('Setup Mic', 'starmus-audio-recorder'); ?>
                </button>
            </div>

            <div class="starmus-recorder-container" data-starmus-recorder-container>
                <div class="starmus-visualizer-stage">
                    <div class="starmus-timer-wrapper">
                        <span class="starmus-timer-label"><?php esc_html_e('Time:', 'starmus-audio-recorder'); ?></span>
                        <div class="starmus-timer" data-starmus-timer>
                            <span class="starmus-timer-elapsed">00m 00s</span>
                        </div>
                    </div>
                    <div class="starmus-duration-progress-wrapper">
                        <span class="starmus-progress-label" id="starmus_progress_lbl_<?php echo esc_attr($instance_id); ?>">
                            <?php esc_html_e('Length:', 'starmus-audio-recorder'); ?>
                        </span>
                        <div class="starmus-duration-progress"
                            data-starmus-duration-progress
                            role="progressbar"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-valuenow="0"
                            aria-labelledby="starmus_progress_lbl_<?php echo esc_attr($instance_id); ?>"></div>
                    </div>
                    <div class="starmus-meter-wrap">
                        <span class="starmus-meter-label"><?php esc_html_e('Volume:', 'starmus-audio-recorder'); ?></span>
                        <div class="starmus-meter-bar" data-starmus-volume-meter></div>
                    </div>
                </div>

                <div class="starmus-recorder-controls">
                    <button type="button" class="starmus-btn starmus-btn--record" data-starmus-action="record">
                        <span class="dashicons dashicons-microphone" aria-hidden="true"></span> <?php esc_html_e('Record', 'starmus-audio-recorder'); ?>
                    </button>
                    <button type="button" class="starmus-btn starmus-btn--stop" data-starmus-action="stop" style="display:none;">
                        <span class="dashicons dashicons-media-default" aria-hidden="true"></span> <?php esc_html_e('Stop', 'starmus-audio-recorder'); ?>
                    </button>
                    <div class="starmus-review-controls" style="display:none;">
                        <button type="button" class="starmus-btn starmus-btn--secondary" data-starmus-action="play">
                            <span class="dashicons dashicons-controls-play" aria-hidden="true"></span> <?php esc_html_e('Play', 'starmus-audio-recorder'); ?>
                        </button>
                        <button type="button" class="starmus-btn starmus-btn--outline" data-starmus-action="reset">
                            <span class="dashicons dashicons-undo" aria-hidden="true"></span> <?php esc_html_e('Retake', 'starmus-audio-recorder'); ?>
                        </button>
                    </div>
                </div>

                <div data-starmus-transcript style="display:none;" role="log" aria-live="polite"></div>
            </div>

            <button type="submit" class="starmus-btn starmus-btn--primary starmus-btn--full" data-starmus-action="submit" disabled>
                <?php esc_html_e('Save Replacement', 'starmus-audio-recorder'); ?>
            </button>
        </div>
    </form>
</div>
