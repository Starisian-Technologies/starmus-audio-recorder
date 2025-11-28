<?php

/**
 * Starmus Re-Recorder UI Template - Standalone Version
 *
 * This template is for re-recording existing audio submissions.
 * It skips Step 1 (metadata entry) and goes directly to consent + recording.
 *
 * @package Starisian\Sparxstar\Starmus\templates
 *
 * @version 1.0.0
 *
 * @since   0.8.5
 *
 * @var string $form_id         Base ID for the form.
 * @var int $post_id         The existing audio recording post ID.
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
$allowed_file_types ??= 'webm';
$allowed_types_arr     = array_values(array_filter(array_map('trim', explode(',', (string) $allowed_file_types)), fn($v) => $v !== ''));
$show_file_type_select = count($allowed_types_arr) > 1;

// Get existing audio URL for re-recording
$existing_audio_id  = get_post_meta($post_id, 'mastered_mp3', true);
$existing_audio_url = $existing_audio_id ? wp_get_attachment_url($existing_audio_id) : '';

// Get transcript data if available
$transcript_json = get_post_meta($post_id, 'star_transcript_json', true);
$transcript_data = $transcript_json ? json_decode($transcript_json, true) : [];
if (!is_array($transcript_data)) {
    $transcript_data = [];
}
?>

<!-- Bootstrap Contract: Re-Recorder Data -->
<script>
    window.STARMUS_RERECORDER_DATA = {
        instanceId: "<?php echo esc_js($instance_id); ?>",
        postId: <?php echo (int) $post_id; ?>,
        audioUrl: "<?php echo esc_js($existing_audio_url); ?>",
        transcript: <?php echo wp_json_encode($transcript_data); ?>,
        mode: "rerecord",
        canCommit: <?php echo current_user_can('edit_posts') ? 'true' : 'false'; ?>,
        title: "<?php echo esc_js($title); ?>",
        language: "<?php echo esc_js($language); ?>",
        recordingType: "<?php echo esc_js($recording_type); ?>"
    };
</script>

<div class="starmus-recorder-form starmus-re-recorder">
    <form
        id="<?php echo esc_attr($instance_id); ?>"
        class="starmus-audio-form sparxstar-glass-card"
        method="post"
        enctype="multipart/form-data"
        novalidate
        data-starmus="recorder"
        data-starmus-instance="<?php echo esc_attr($instance_id); ?>"
        data-starmus-rerecord="true">

        <!-- Hidden Fields: Pre-filled metadata from existing post -->
        <input type="hidden" name="post_id" value="<?php echo esc_attr((string) $post_id); ?>">
        <input type="hidden" name="target_post_id" value="<?php echo esc_attr((string) ($target_post_id ?? 0)); ?>">
        <input type="hidden" name="starmus_title" value="<?php echo esc_attr($title); ?>">
        <input type="hidden" name="starmus_language" value="<?php echo esc_attr($language); ?>">
        <input type="hidden" name="starmus_recording_type" value="<?php echo esc_attr($recording_type); ?>">

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

        <?php if ($show_file_type_select) { ?>
            <input type="hidden" name="audio_file_type" value="audio/<?php echo esc_attr((string) $allowed_types_arr[0]); ?>">
        <?php } else { ?>
            <input type="hidden" name="audio_file_type" value="audio/<?php echo esc_attr($allowed_file_types); ?>">
        <?php } ?>

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
                    <?php esc_html_e('Consent Agreement', 'starmus-audio-recorder'); ?>
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
                        <?php if (! empty($data_policy_url)) { ?>
                            <a
                                href="<?php echo esc_url($data_policy_url); ?>"
                                target="_blank"
                                rel="noopener noreferrer"><?php esc_html_e('Privacy Policy', 'starmus-audio-recorder'); ?></a>
                        <?php } ?>
                    </label>
                </div>
            </fieldset>

            <button
                type="button"
                id="starmus_continue_btn_<?php echo esc_attr($instance_id); ?>"
                class="starmus-btn starmus-btn--primary"
                data-starmus-action="continue">
                <?php esc_html_e('Continue to Recording', 'starmus-audio-recorder'); ?>
            </button>
        </div>

        <!-- Step 2: Audio Recording -->
        <div
            id="starmus_step2_<?php echo esc_attr($instance_id); ?>"
            class="starmus-step starmus-step-2"
            data-starmus-step="2"
            style="display:none;">

            <h2 id="starmus_audioRecorderHeading_<?php echo esc_attr($instance_id); ?>" tabindex="-1">
                <?php esc_html_e('Record Your Audio', 'starmus-audio-recorder'); ?>
            </h2>

            <!-- Microphone Setup Button -->
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
                    <?php esc_html_e('Click the button above to test your microphone and adjust audio levels.', 'starmus-audio-recorder'); ?>
                </p>
            </div>

            <!-- TIER C FALLBACK (Displayed if browser cannot record) -->
            <div
                id="starmus_fallback_container_<?php echo esc_attr($instance_id); ?>"
                class="starmus-fallback-container"
                style="display:none;"
                data-starmus-fallback-container>
                <p class="starmus-alert starmus-alert--warning">
                    <?php esc_html_e('Live recording is not supported on this browser.', 'starmus-audio-recorder'); ?>
                </p>
                <label for="starmus_fallback_input_<?php echo esc_attr($instance_id); ?>" class="starmus-btn starmus-btn--secondary">
                    <?php esc_html_e('Click to Upload Audio File', 'starmus-audio-recorder'); ?>
                </label>
                <input
                    type="file"
                    id="starmus_fallback_input_<?php echo esc_attr($instance_id); ?>"
                    name="audio_file"
                    accept="audio/*"
                    style="display:none;">
            </div>

            <!-- TIER A/B RECORDER UI -->
            <div
                id="starmus_recorder_container_<?php echo esc_attr($instance_id); ?>"
                class="starmus-recorder-container"
                data-starmus-recorder-container>

                <!-- VISUALIZER STAGE -->
                <div class="starmus-visualizer-stage">
                    <!-- Timer with Duration Progress -->
                    <div class="starmus-timer-wrapper">
                        <div id="starmus_timer_<?php echo esc_attr($instance_id); ?>" class="starmus-timer" data-starmus-timer>
                            <span class="starmus-timer-elapsed">00:00</span>
                            <span class="starmus-timer-separator">/</span>
                            <span class="starmus-timer-max">20:00</span>
                        </div>
                        <div class="starmus-duration-progress-wrapper">
                            <div id="starmus_duration_progress_<?php echo esc_attr($instance_id); ?>"
                                class="starmus-duration-progress"
                                data-starmus-duration-progress
                                role="progressbar"
                                aria-valuemin="0"
                                aria-valuemax="1200"
                                aria-valuenow="0"
                                aria-label="Recording duration progress"></div>
                        </div>
                    </div>

                    <!-- Waveform Container (Peaks.js) -->
                    <div id="starmus_waveform_<?php echo esc_attr($instance_id); ?>" class="starmus-waveform-view" data-starmus-waveform></div>

                    <!-- Volume Meter -->
                    <div class="starmus-meter-wrap">
                        <div id="starmus_vol_meter_<?php echo esc_attr($instance_id); ?>" class="starmus-meter-bar" data-starmus-volume-meter></div>
                    </div>
                </div>

                <!-- CONTROLS DECK -->
                <div class="starmus-recorder-controls">
                    <!-- 1. IDLE STATE -->
                    <button
                        type="button"
                        id="starmus_record_btn_<?php echo esc_attr($instance_id); ?>"
                        class="starmus-btn starmus-btn--record starmus-btn--large"
                        data-starmus-action="record">
                        <span class="dashicons dashicons-microphone"></span> <?php esc_html_e('Start Recording', 'starmus-audio-recorder'); ?>
                    </button>

                    <!-- 2. RECORDING STATE -->
                    <button
                        type="button"
                        id="starmus_pause_btn_<?php echo esc_attr($instance_id); ?>"
                        class="starmus-btn starmus-btn--pause starmus-btn--large"
                        data-starmus-action="pause"
                        style="display:none;">
                        <span class="dashicons dashicons-controls-pause"></span> <?php esc_html_e('Pause', 'starmus-audio-recorder'); ?>
                    </button>

                    <button
                        type="button"
                        id="starmus_stop_btn_<?php echo esc_attr($instance_id); ?>"
                        class="starmus-btn starmus-btn--stop starmus-btn--large"
                        data-starmus-action="stop"
                        style="display:none;">
                        <span class="dashicons dashicons-media-default"></span> <?php esc_html_e('Stop', 'starmus-audio-recorder'); ?>
                    </button>

                    <!-- 2b. PAUSED STATE -->
                    <button
                        type="button"
                        id="starmus_resume_btn_<?php echo esc_attr($instance_id); ?>"
                        class="starmus-btn starmus-btn--resume starmus-btn--large"
                        data-starmus-action="resume"
                        style="display:none;">
                        <span class="dashicons dashicons-controls-play"></span> <?php esc_html_e('Resume Recording', 'starmus-audio-recorder'); ?>
                    </button>

                    <!-- 3. REVIEW STATE -->
                    <div id="starmus_review_controls_<?php echo esc_attr($instance_id); ?>" class="starmus-review-controls" style="display:none;">
                        <button
                            type="button"
                            id="starmus_play_btn_<?php echo esc_attr($instance_id); ?>"
                            class="starmus-btn starmus-btn--secondary"
                            data-starmus-action="play">
                            <?php esc_html_e('Play / Pause', 'starmus-audio-recorder'); ?>
                        </button>

                        <button
                            type="button"
                            id="starmus_reset_btn_<?php echo esc_attr($instance_id); ?>"
                            class="starmus-btn starmus-btn--outline"
                            data-starmus-action="reset">
                            <?php esc_html_e('Retake', 'starmus-audio-recorder'); ?>
                        </button>
                    </div>
                </div>

                <!-- Live Transcript Display -->
                <div
                    id="starmus_transcript_<?php echo esc_attr($instance_id); ?>"
                    class="starmus-transcript"
                    data-starmus-transcript
                    style="display:none;"
                    role="log"></div>
            </div>

            <!-- SUBMISSION AREA -->
            <div
                id="starmus_status_<?php echo esc_attr($instance_id); ?>"
                class="starmus-status"
                data-starmus-status
                role="status"
                style="display:none;"></div>

            <!-- Upload Progress -->
            <div
                id="starmus_progress_wrap_<?php echo esc_attr($instance_id); ?>"
                class="starmus-progress-wrap"
                style="display:none;">
                <div
                    id="starmus_progress_<?php echo esc_attr($instance_id); ?>"
                    class="starmus-progress"
                    data-starmus-progress></div>
            </div>

            <button
                type="submit"
                id="starmus_submit_btn_<?php echo esc_attr($instance_id); ?>"
                class="starmus-btn starmus-btn--primary starmus-btn--full"
                data-starmus-action="submit"
                disabled>
                <?php esc_html_e('Submit Recording', 'starmus-audio-recorder'); ?>
            </button>
        </div>
    </form>
</div>