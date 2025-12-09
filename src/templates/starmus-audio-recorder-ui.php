<?php

/**
 * Starmus Audio Recorder UI Template - Final, Secure, and Accessible
 *
 * FIXED:
 * 1. Guarantees 'audio_file_type' is 'audio/webm' (Stops 415 Error).
 * 2. Includes ALL CRITICAL HIDDEN FIELDS required by the JS bundle
 *    to inject environment, calibration, and transcript data for submission.
 *
 * @package Starisian\Sparxstar\Starmus\templates
 *
 * @version 0.9.2
 */

if (! defined('ABSPATH')) {
    exit;
}

$form_id ??= 'default';
$instance_id = 'starmus_form_' . sanitize_key($form_id . '_' . wp_generate_uuid4());
$allowed_file_types ??= 'webm';
$allowed_types_arr = array_values(array_filter(array_map(trim(...), explode(',', (string) $allowed_file_types)), fn($v): bool => $v !== ''));
$is_admin          = current_user_can('manage_options');
?>

<div class="starmus-recorder-form sparxstar-glass-card">
    <form
        id="<?php echo esc_attr($instance_id); ?>"
        class="starmus-audio-form"
        method="post"
        enctype="multipart/form-data"
        novalidate
        data-starmus="recorder"
        data-starmus-instance="<?php echo esc_attr($instance_id); ?>">

        <!-- Step 1: Form Details -->
        <div
            id="starmus_step1_<?php echo esc_attr($instance_id); ?>"
            class="starmus-step starmus-step-1"
            data-starmus-step="1">
            <h2><?php esc_html_e('Recording Details', 'starmus-audio-recorder'); ?></h2>

            <div
                id="starmus_step1_usermsg_<?php echo esc_attr($instance_id); ?>"
                class="starmus-user-message"
                style="display:none;"
                role="alert"
                aria-live="polite"
                data-starmus-message-box></div>

            <div class="starmus-field-group">
                <label for="starmus_title_<?php echo esc_attr($instance_id); ?>">
                    <?php esc_html_e('Title', 'starmus-audio-recorder'); ?>
                    <span class="starmus-required">*</span>
                </label>
                <input
                    type="text"
                    id="starmus_title_<?php echo esc_attr($instance_id); ?>"
                    name="starmus_title"
                    maxlength="200"
                    required>
            </div>

            <div class="starmus-field-group">
                <label for="starmus_language_<?php echo esc_attr($instance_id); ?>">
                    <?php esc_html_e('Language', 'starmus-audio-recorder'); ?>
                    <span class="starmus-required">*</span>
                </label>
                <select
                    id="starmus_language_<?php echo esc_attr($instance_id); ?>"
                    name="starmus_language"
                    required>
                    <option value=""><?php esc_html_e('Select Language', 'starmus-audio-recorder'); ?></option>
                    <?php if (! empty($languages) && is_array($languages)) { ?>
                        <?php foreach ($languages as $lang) { ?>
                            <option value="<?php echo esc_attr($lang->term_id); ?>">
                                <?php echo esc_html($lang->name); ?>
                            </option>
                        <?php } ?>
                    <?php } ?>
                </select>
            </div>

            <div class="starmus-field-group">
                <label for="starmus_recording_type_<?php echo esc_attr($instance_id); ?>">
                    <?php esc_html_e('Recording Type', 'starmus-audio-recorder'); ?>
                    <span class="starmus-required">*</span>
                </label>
                <select
                    id="starmus_recording_type_<?php echo esc_attr($instance_id); ?>"
                    name="starmus_recording_type"
                    required>
                    <option value=""><?php esc_html_e('Select Type', 'starmus-audio-recorder'); ?></option>
                    <?php if (! empty($recording_types) && is_array($recording_types)) { ?>
                        <?php foreach ($recording_types as $type) { ?>
                            <option value="<?php echo esc_attr($type->term_id); ?>">
                                <?php echo esc_html($type->name); ?>
                            </option>
                        <?php } ?>
                    <?php } ?>
                </select>
            </div>

            <!-- FIX: Forces the recorder format to WebM -->
            <input type="hidden" name="audio_file_type" value="audio/webm">

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

            <!-- CRITICAL HIDDEN FIELDS - JS TARGETS -->
            <!-- These must be present and named exactly for the JS to inject final telemetry, transcript, and calibration data -->
            <input type="hidden" name="_starmus_calibration" value="">
            <input type="hidden" name="_starmus_env" value="">
            <input type="hidden" name="first_pass_transcription" value="">
            <input type="hidden" name="recording_metadata" value="">
            <input type="hidden" name="waveform_json" value="">

            <!-- MANUAL / ARCHIVAL FIELDS - Should be present to ensure form submission includes them -->
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
            <input type="hidden" name="audio_quality_score" value="">
            <input type="hidden" name="mic_rest_adjustments" value="">
            <input type="hidden" name="device" value="">
            <input type="hidden" name="user_agent" value="">

            <button
                type="button"
                id="starmus_continue_btn_<?php echo esc_attr($instance_id); ?>"
                class="starmus-btn starmus-btn--primary"
                data-starmus-action="next">
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
                        <label for="starmus_timer_<?php echo esc_attr($instance_id); ?>" class="starmus-timer-label"><?php esc_html_e('Recording Time:', 'starmus-audio-recorder'); ?></label>
                        <div id="starmus_timer_<?php echo esc_attr($instance_id); ?>" class="starmus-timer" data-starmus-timer>
                            <span class="starmus-timer-elapsed">00m 00s</span>
                            <span class="starmus-timer-separator">/</span>
                            <span class="starmus-timer-max">20m 00s</span>
                        </div>
                        <div class="starmus-duration-progress-wrapper">
                            <label class="starmus-progress-label"><?php esc_html_e('Recording Length:', 'starmus-audio-recorder'); ?></label>
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
                        <label for="starmus_vol_meter_<?php echo esc_attr($instance_id); ?>" class="starmus-meter-label"><?php esc_html_e('Microphone Volume:', 'starmus-audio-recorder'); ?></label>
                        <div id="starmus_vol_meter_<?php echo esc_attr($instance_id); ?>" class="starmus-meter-bar" data-starmus-volume-meter></div>
                    </div>
                </div> <!-- CONTROLS DECK -->
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

            <!-- Manual Upload Toggle (Admin/Editor Only) -->
            <?php if (current_user_can('upload_files')) { ?>
                <div class="starmus-upload-audio-link" style="margin-top:24px;text-align:right;">
                    <button
                        type="button"
                        id="starmus_show_upload_<?php echo esc_attr($instance_id); ?>"
                        class="starmus-btn starmus-btn--link"
                        aria-controls="starmus_manual_upload_wrap_<?php echo esc_attr($instance_id); ?>"
                        aria-expanded="false">
                        <?php esc_html_e('Switch to File Upload', 'starmus-audio-recorder'); ?>
                    </button>
                </div>
                <div
                    id="starmus_manual_upload_wrap_<?php echo esc_attr($instance_id); ?>"
                    style="display:none;margin-top:12px;">
                    <label for="starmus_manual_upload_input_<?php echo esc_attr($instance_id); ?>">
                        <?php esc_html_e('Select audio file to upload:', 'starmus-audio-recorder'); ?>
                    </label>
                    <input
                        type="file"
                        id="starmus_manual_upload_input_<?php echo esc_attr($instance_id); ?>"
                        name="audio_file"
                        accept="audio/*">
                </div>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const instanceId = <?php echo wp_json_encode($instance_id); ?>;
                        const toggle = document.getElementById('starmus_show_upload_' + instanceId);
                        const wrapper = document.getElementById('starmus_manual_upload_wrap_' + instanceId);
                        if (toggle && wrapper) {
                            toggle.addEventListener('click', function(event) {
                                event.preventDefault();
                                const isHidden = wrapper.style.display === 'none' || wrapper.style.display === '';
                                wrapper.style.display = isHidden ? 'block' : 'none';
                            });
                        }
                    });
                </script>
            <?php } ?>
        </div>
    </form>
</div>