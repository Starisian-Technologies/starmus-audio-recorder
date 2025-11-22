<?php

/**
 * Starmus Re-Recorder UI Template - Standalone Version
 *
 * This template is for re-recording existing audio submissions.
 * It skips Step 1 (metadata entry) and goes directly to consent + recording.
 *
 * @package Starisian\Sparxstar\Starmus\templates
 * @version 1.0.0
 * @since   0.8.5
 * @var string $form_id         Base ID for the form.
 * @var int    $post_id         The existing audio recording post ID.
 * @var string $title           Pre-filled title from existing post.
 * @var string $language        Pre-filled language term slug.
 * @var string $recording_type  Pre-filled recording type term slug.
 * @var string $consent_message The user consent message.
 * @var string $data_policy_url The URL to the data policy.
 * @var string $allowed_file_types Comma-separated allowed file types.
 */

if (! defined('ABSPATH')) {
    exit;
}

$instance_id = 'starmus_rerecord_' . sanitize_key($form_id . '_' . wp_generate_uuid4());

// Get allowed file types from settings
$allowed_file_types    = isset($allowed_file_types) ? $allowed_file_types : 'webm';
$allowed_types_arr     = array_filter(array_map('trim', explode(',', $allowed_file_types)));
$show_file_type_select = count($allowed_types_arr) > 1;
?>

<div class="starmus-recorder-form starmus-re-recorder">
    <form
        id="<?php echo esc_attr($instance_id); ?>"
        class="starmus-audio-form sparxstar-glass-card"
        method="post"
        enctype="multipart/form-data"
        novalidate
        data-starmus-instance="<?php echo esc_attr($instance_id); ?>"
        data-starmus-mode="rerecord">
        <?php wp_nonce_field('starmus_audio_form', 'starmus_nonce_' . $instance_id); ?>

        <!-- Hidden Fields: Pre-filled metadata from existing post -->
        <input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>">
        <input type="hidden" name="starmus_title" value="<?php echo esc_attr($title); ?>">
        <input type="hidden" name="language" value="<?php echo esc_attr($language); ?>">
        <input type="hidden" name="recording_type" value="<?php echo esc_attr($recording_type); ?>">

        <!-- Other hidden fields (same as main recorder) -->
        <input type="hidden" name="project_collection_id" value="">
        <input type="hidden" name="accession_number" value="">
        <input type="hidden" name="session_date" value="">
        <input type="hidden" name="session_start_time" value="">
        <input type="hidden" name="session_end_time" value="">
        <input type="hidden" name="location" value="">
        <input type="hidden" name="gps_coordinates" value="">
        <input type="hidden" name="contributor_id" value="">
        <input type="hidden" name="interviewers_recorders" value="">
        <input type="hidden" name="recording_equipment" value="">
        <input type="hidden" name="audio_files_originals" value="">
        <input type="hidden" name="media_condition_notes" value="">
        <input type="hidden" name="related_consent_agreement" value="">
        <input type="hidden" name="usage_restrictions_rights" value="">
        <input type="hidden" name="access_level" value="">
        <input type="hidden" name="first_pass_transcription" value="">
        <input type="hidden" name="audio_quality_score" value="">
        <input type="hidden" name="recording_metadata" value="">
        <input type="hidden" name="mic-rest-adjustments" value="">
        <input type="hidden" name="device" value="">
        <input type="hidden" name="user_agent" value="">

        <?php if ($show_file_type_select) : ?>
            <input type="hidden" name="audio_file_type" value="audio/<?php echo esc_attr($allowed_types_arr[0]); ?>">
        <?php else : ?>
            <input type="hidden" name="audio_file_type" value="audio/<?php echo esc_attr($allowed_file_types); ?>">
        <?php endif; ?>

        <!-- Step 1: Consent Only -->
        <div
            id="starmus_step1_<?php echo esc_attr($instance_id); ?>"
            class="starmus-step starmus-step-1 starmus-rerecord-consent"
            data-starmus-step="1">
            <h2><?php esc_html_e('Re-record Audio', 'starmus-audio-recorder'); ?></h2>

            <div
                id="starmus_step1_usermsg_<?php echo esc_attr($instance_id); ?>"
                class="starmus-user-message"
                style="display:none;"
                role="alert"
                aria-live="polite"
                data-starmus-message-box></div>

            <p class="starmus-rerecord-info">
                <?php
                printf(
                    /* translators: %s: Recording title */
                    esc_html__('You are about to re-record: %s', 'starmus-audio-recorder'),
                    '<strong>' . esc_html($title) . '</strong>'
                );
                ?>
            </p>

            <fieldset class="starmus-consent-fieldset">
                <legend class="starmus-fieldset-legend">
                    <?php esc_html_e('Consent', 'starmus-audio-recorder'); ?>
                </legend>
                <div class="starmus-consent-field">
                    <label for="starmus_consent_<?php echo esc_attr($instance_id); ?>">
                        <input
                            type="checkbox"
                            id="starmus_consent_<?php echo esc_attr($instance_id); ?>"
                            name="agreement_to_terms"
                            value="1"
                            required>
                        <span>
                            <?php echo esc_html($consent_message); ?>
                            <?php if (! empty($data_policy_url)) : ?>
                                <a
                                    href="<?php echo esc_url($data_policy_url); ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="starmus-policy-link">
                                    <?php esc_html_e('Read our data policy', 'starmus-audio-recorder'); ?>
                                </a>
                            <?php endif; ?>
                        </span>
                    </label>
                </div>
            </fieldset>

            <button
                type="button"
                id="starmus_continue_btn_<?php echo esc_attr($instance_id); ?>"
                class="starmus-btn starmus-btn--primary"
                data-starmus-role="continue">
                <?php esc_html_e('Continue to Recording', 'starmus-audio-recorder'); ?>
            </button>
        </div>

        <!-- Step 2: Audio Recording (identical to main recorder) -->
        <div
            id="starmus_step2_<?php echo esc_attr($instance_id); ?>"
            class="starmus-step starmus-step-2"
            data-starmus-step="2"
            style="display:none;">
            <h2 id="starmus_audioRecorderHeading_<?php echo esc_attr($instance_id); ?>" tabindex="-1">
                <?php esc_html_e('Record Your Audio', 'starmus-audio-recorder'); ?>
            </h2>

            <div
                id="starmus_recorder_container_<?php echo esc_attr($instance_id); ?>"
                class="starmus-recorder-container"
                data-starmus-recorder-container>

                <!-- Recording Controls -->
                <div class="starmus-recorder-controls">
                    <button
                        type="button"
                        id="starmus_record_btn_<?php echo esc_attr($instance_id); ?>"
                        class="starmus-btn starmus-btn--record"
                        data-starmus-action="record">
                        <?php esc_html_e('Record', 'starmus-audio-recorder'); ?>
                    </button>

                    <button
                        type="button"
                        id="starmus_stop_btn_<?php echo esc_attr($instance_id); ?>"
                        class="starmus-btn starmus-btn--stop"
                        data-starmus-action="stop"
                        style="display:none;">
                        <?php esc_html_e('Stop', 'starmus-audio-recorder'); ?>
                    </button>

                    <button
                        type="button"
                        id="starmus_reset_btn_<?php echo esc_attr($instance_id); ?>"
                        class="starmus-btn starmus-btn--secondary"
                        data-starmus-action="reset"
                        style="display:none;">
                        <?php esc_html_e('Reset', 'starmus-audio-recorder'); ?>
                    </button>
                </div>

                <!-- Live Transcript Display -->
                <div
                    id="starmus_transcript_<?php echo esc_attr($instance_id); ?>"
                    class="starmus-transcript"
                    data-starmus-transcript
                    style="display:none;"
                    role="log"
                    aria-live="polite"
                    aria-label="<?php esc_attr_e('Live transcript', 'starmus-audio-recorder'); ?>"></div>
            </div>

            <div
                id="starmus_fallback_container_<?php echo esc_attr($instance_id); ?>"
                class="starmus-fallback-container"
                style="display:none;"
                data-starmus-fallback-container>
                <p><?php esc_html_e('Live recording is not supported on this browser.', 'starmus-audio-recorder'); ?></p>
                <label for="starmus_fallback_input_<?php echo esc_attr($instance_id); ?>">
                    <?php esc_html_e('Upload an audio file instead:', 'starmus-audio-recorder'); ?>
                </label>
                <input
                    type="file"
                    id="starmus_fallback_input_<?php echo esc_attr($instance_id); ?>"
                    name="audio_file"
                    accept="audio/*">
            </div>

            <div
                id="starmus_loader_overlay_<?php echo esc_attr($instance_id); ?>"
                class="starmus-loader"
                style="display:none;">
                <?php esc_html_e('Uploading...', 'starmus-audio-recorder'); ?>
            </div>

            <!-- Status and Progress Elements -->
            <div
                id="starmus_status_<?php echo esc_attr($instance_id); ?>"
                class="starmus-status"
                data-starmus-status
                role="status"
                aria-live="polite"
                style="display:none;"></div>

            <div
                id="starmus_progress_wrap_<?php echo esc_attr($instance_id); ?>"
                class="starmus-progress-wrap"
                style="display:none;">
                <div
                    id="starmus_progress_<?php echo esc_attr($instance_id); ?>"
                    class="starmus-progress"
                    data-starmus-progress
                    role="progressbar"
                    aria-valuenow="0"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    style="width:0%;"></div>
            </div>

            <button
                type="submit"
                id="starmus_submit_btn_<?php echo esc_attr($instance_id); ?>"
                class="starmus-btn starmus-btn--primary"
                data-starmus-action="submit"
                disabled>
                <?php esc_html_e('Submit Recording', 'starmus-audio-recorder'); ?>
            </button>
        </div>
    </form>
</div>