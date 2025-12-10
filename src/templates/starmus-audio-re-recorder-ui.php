<?php

/**
 * Starmus Re-Recorder UI Template
 *
 * @package Starisian\Sparxstar\Starmus\templates
 * @version 1.0.0-RERECORD-FIXED
 */

if (! defined('ABSPATH')) {
    exit;
}

/** @var int $post_id - The ID to update */
/** @var int|null $artifact_id - Optional artifact ID */
/** @var string $existing_title */
/** @var int $existing_language */
/** @var int $existing_type */

$form_id ??= 'rerecord';
$consent_message ??= __('I confirm I am replacing the existing audio file.', 'starmus-audio-recorder');
$data_policy_url ??= '';
$instance_id = 'starmus_form_' . sanitize_key($form_id . '_' . wp_generate_uuid4());

$is_admin = current_user_can('manage_options');
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

        <!-- Step 1: Confirmation -->
        <div
            id="starmus_step1_<?php echo esc_attr($instance_id); ?>"
            class="starmus-step starmus-step-1"
            data-starmus-step="1">

            <h2><?php esc_html_e('Re-Record Audio', 'starmus-audio-recorder'); ?></h2>

            <div
                id="starmus_step1_usermsg_<?php echo esc_attr($instance_id); ?>"
                class="starmus-user-message"
                style="display:none;"
                role="alert"
                aria-live="polite"
                data-starmus-message-box></div>

            <div class="starmus-notice">
                <p>
                    <?php esc_html_e('You are replacing the audio for:', 'starmus-audio-recorder'); ?>
                    <br><strong><?php echo esc_html($existing_title); ?></strong>
                </p>
                <p style="font-size:0.85em; opacity:0.8;">
                    <?php esc_html_e('ID:', 'starmus-audio-recorder'); ?> <?php echo esc_html((string)$post_id); ?>
                </p>
            </div>

            <!-- CRITICAL: ID FIELDS FOR UPDATE LOGIC -->
            <!-- This name="post_id" triggers the update path in StarmusSubmissionHandler -->
            <input type="hidden" name="post_id" value="<?php echo esc_attr((string) $post_id); ?>">
            <input type="hidden" name="recording_id" value="<?php echo esc_attr((string) $post_id); ?>">
            <input type="hidden" name="action" value="starmus_update_audio">
            
            <!-- Pass Artifact ID if present -->
            <?php if (!empty($artifact_id)) { ?>
                <input type="hidden" name="artifact_id" value="<?php echo esc_attr((string) $artifact_id); ?>">
            <?php } ?>

            <!-- Metadata Persistence -->
            <input type="hidden" name="starmus_title" value="<?php echo esc_attr($existing_title); ?>">
            <input type="hidden" name="starmus_language" value="<?php echo esc_attr((string) $existing_language); ?>">
            <input type="hidden" name="starmus_recording_type" value="<?php echo esc_attr((string) $existing_type); ?>">

            <!-- Technical Fields -->
            <input type="hidden" name="audio_file_type" value="audio/webm">
            <input type="hidden" name="_starmus_calibration" value="">
            <input type="hidden" name="_starmus_env" value="">
            <input type="hidden" name="first_pass_transcription" value="">
            <input type="hidden" name="recording_metadata" value="">
            <input type="hidden" name="waveform_json" value="">
            
            <!-- Archival Fields -->
            <input type="hidden" name="audio_files_originals" value="">
            <input type="hidden" name="device" value="">
            <input type="hidden" name="user_agent" value="">

            <fieldset class="starmus-consent-fieldset">
                <legend class="starmus-fieldset-legend">
                    <?php esc_html_e('Confirmation', 'starmus-audio-recorder'); ?>
                </legend>
                <div class="starmus-consent-field">
                    <input
                        type="checkbox"
                        id="starmus_consent_<?php echo esc_attr($instance_id); ?>"
                        name="agreement_to_terms"
                        value="1"
                        required>
                    <label for="starmus_consent_<?php echo esc_attr($instance_id); ?>">
                        <?php echo wp_kses_post($consent_message); ?>
                    </label>
                </div>
            </fieldset>

            <button
                type="button"
                id="starmus_continue_btn_<?php echo esc_attr($instance_id); ?>"
                class="starmus-btn starmus-btn--primary"
                data-starmus-action="next">
                <?php esc_html_e('Proceed to Recorder', 'starmus-audio-recorder'); ?>
            </button>
        </div>

        <!-- Step 2: Audio Recording (Identical to Standard Recorder) -->
        <div
            id="starmus_step2_<?php echo esc_attr($instance_id); ?>"
            class="starmus-step starmus-step-2"
            data-starmus-step="2"
            style="display:none;">

            <h2 id="starmus_audioRecorderHeading_<?php echo esc_attr($instance_id); ?>" tabindex="-1">
                <?php esc_html_e('Record New Audio', 'starmus-audio-recorder'); ?>
            </h2>

            <!-- Setup -->
            <div
                id="starmus_setup_container_<?php echo esc_attr($instance_id); ?>"
                class="starmus-setup-container"
                data-starmus-setup-container>
                <button
                    type="button"
                    id="starmus_setup_mic_btn_<?php echo esc_attr($instance_id); ?>"
                    class="starmus-btn starmus-btn--primary starmus-btn--large"
                    data-starmus-action="setup-mic">
                    <span class="dashicons dashicons-microphone"></span> <?php esc_html_e('Setup Microphone', 'starmus-audio-recorder'); ?>
                </button>
                <p class="starmus-setup-instruction">
                    <?php esc_html_e('Click above to initialize the microphone.', 'starmus-audio-recorder'); ?>
                </p>
            </div>

            <!-- Fallback -->
            <div
                id="starmus_fallback_container_<?php echo esc_attr($instance_id); ?>"
                class="starmus-fallback-container"
                style="display:none;"
                data-starmus-fallback-container>
                <p class="starmus-alert starmus-alert--warning">
                    <?php esc_html_e('Live recording unavailable. Please upload a file.', 'starmus-audio-recorder'); ?>
                </p>
                <label for="starmus_fallback_input_<?php echo esc_attr($instance_id); ?>" class="starmus-btn starmus-btn--secondary">
                    <?php esc_html_e('Select Audio File', 'starmus-audio-recorder'); ?>
                </label>
                <input
                    type="file"
                    id="starmus_fallback_input_<?php echo esc_attr($instance_id); ?>"
                    name="audio_file"
                    accept="audio/*"
                    style="display:none;">
            </div>

            <!-- Recorder UI -->
            <div
                id="starmus_recorder_container_<?php echo esc_attr($instance_id); ?>"
                class="starmus-recorder-container"
                data-starmus-recorder-container>

                <div class="starmus-visualizer-stage">
                    <div class="starmus-timer-wrapper">
                        <label class="starmus-timer-label"><?php esc_html_e('Time:', 'starmus-audio-recorder'); ?></label>
                        <div class="starmus-timer" data-starmus-timer>
                            <span class="starmus-timer-elapsed">00m 00s</span>
                        </div>
                        <div class="starmus-duration-progress-wrapper">
                            <div class="starmus-duration-progress" data-starmus-duration-progress></div>
                        </div>
                    </div>

                    <!-- Volume Meter -->
                    <div class="starmus-meter-wrap">
                        <label class="starmus-meter-label"><?php esc_html_e('Volume:', 'starmus-audio-recorder'); ?></label>
                        <div class="starmus-meter-bar" data-starmus-volume-meter></div>
                    </div>
                </div>

                <div class="starmus-recorder-controls">
                    <button type="button" class="starmus-btn starmus-btn--record starmus-btn--large" data-starmus-action="record">
                        <span class="dashicons dashicons-microphone"></span> <?php esc_html_e('Record', 'starmus-audio-recorder'); ?>
                    </button>

                    <button type="button" class="starmus-btn starmus-btn--pause starmus-btn--large" data-starmus-action="pause" style="display:none;">
                        <span class="dashicons dashicons-controls-pause"></span> <?php esc_html_e('Pause', 'starmus-audio-recorder'); ?>
                    </button>

                    <button type="button" class="starmus-btn starmus-btn--stop starmus-btn--large" data-starmus-action="stop" style="display:none;">
                        <span class="dashicons dashicons-media-default"></span> <?php esc_html_e('Stop', 'starmus-audio-recorder'); ?>
                    </button>

                    <button type="button" class="starmus-btn starmus-btn--resume starmus-btn--large" data-starmus-action="resume" style="display:none;">
                        <span class="dashicons dashicons-controls-play"></span> <?php esc_html_e('Resume', 'starmus-audio-recorder'); ?>
                    </button>

                    <div class="starmus-review-controls" style="display:none;">
                        <button type="button" class="starmus-btn starmus-btn--secondary" data-starmus-action="play">
                            <span class="dashicons dashicons-controls-play"></span> <?php esc_html_e('Play / Pause', 'starmus-audio-recorder'); ?>
                        </button>
                        <button type="button" class="starmus-btn starmus-btn--outline" data-starmus-action="reset">
                            <?php esc_html_e('Retake', 'starmus-audio-recorder'); ?>
                        </button>
                    </div>
                </div>

                <!-- Hidden Transcript (Logic Required) -->
                <div class="starmus-transcript" data-starmus-transcript style="display:none;"></div>
            </div>

            <!-- Submit -->
            <button
                type="submit"
                id="starmus_submit_btn_<?php echo esc_attr($instance_id); ?>"
                class="starmus-btn starmus-btn--primary starmus-btn--full"
                data-starmus-action="submit"
                disabled>
                <?php esc_html_e('Save Replacement', 'starmus-audio-recorder'); ?>
            </button>

            <!-- Admin Upload Toggle -->
            <?php if (current_user_can('upload_files')) { ?>
                <div class="starmus-upload-audio-link" style="margin-top:24px;text-align:right;">
                    <button
                        type="button"
                        id="starmus_show_upload_<?php echo esc_attr($instance_id); ?>"
                        class="starmus-btn starmus-btn--link"
                        onclick="document.getElementById('starmus_manual_upload_wrap_<?php echo esc_attr($instance_id); ?>').style.display='block';">
                        <?php esc_html_e('Switch to File Upload', 'starmus-audio-recorder'); ?>
                    </button>
                </div>
                <div id="starmus_manual_upload_wrap_<?php echo esc_attr($instance_id); ?>" style="display:none;margin-top:12px;">
                    <input type="file" name="audio_file" accept="audio/*">
                </div>
            <?php } ?>
        </div>
    </form>
</div>