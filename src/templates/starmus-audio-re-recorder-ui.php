<?php

/**
 * Re-recorder template - Uses same two-step flow as regular recorder
 * with pre-filled metadata from existing recording
 *
 * @package Starisian\Sparxstar\Starmus\templates
 * @version 1.0.0
 */

if (!defined('ABSPATH')) exit;

/** @var string $form_id */
/** @var int $post_id - The original recording post ID */
/** @var int $artifact_id - Same as post_id, used to link to original */
/** @var string $existing_title */
/** @var int $existing_language */
/** @var int $existing_type */
/** @var string $consent_message */
/** @var string $data_policy_url */
/** @var array $languages */
/** @var array $recording_types */
/** @var string $allowed_file_types */

$form_id ??= 'rerecord';
$instance_id = 'starmus_form_' . sanitize_key($form_id . '_' . wp_generate_uuid4());

// Get allowed file types from settings
$allowed_types_arr     = array_values(array_filter(array_map('trim', explode(',', (string) $allowed_file_types)), fn($v) => $v !== ''));
$show_file_type_select = count($allowed_types_arr) > 1;

if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('Re-recorder template loaded with post_id: ' . $post_id);
}
?>

<div class="starmus-recorder-form sparxstar-glass-card">
    <form
        id="<?php echo esc_attr($instance_id); ?>"
        class="starmus-audio-form"
        method="post"
        enctype="multipart/form-data"
        novalidate
        data-starmus="recorder"
        data-starmus-rerecord="true"
        data-starmus-instance="<?php echo esc_attr($instance_id); ?>">

        <!-- Step 1: Consent + Pre-filled Details -->
        <div
            id="starmus_step1_<?php echo esc_attr($instance_id); ?>"
            class="starmus-step starmus-step-1"
            data-starmus-step="1">

            <h2><?php esc_html_e('Re-Record Audio', 'starmus-audio-recorder'); ?></h2>
            <p class="starmus-rerecord-notice">
                <?php esc_html_e('You are creating a new recording to replace:', 'starmus-audio-recorder'); ?>
                <strong><?php echo esc_html($existing_title); ?></strong>
            </p>

            <div
                id="starmus_step1_usermsg_<?php echo esc_attr($instance_id); ?>"
                class="starmus-user-message"
                style="display:none;"
                role="alert"
                aria-live="polite"
                data-starmus-message-box></div>

            <!-- Hidden fields with existing data -->
            <input type="hidden" name="starmus_title" value="<?php echo esc_attr($existing_title); ?>">
            <input type="hidden" name="starmus_language" value="<?php echo esc_attr($existing_language); ?>">
            <input type="hidden" name="starmus_recording_type" value="<?php echo esc_attr($existing_type); ?>">
            <input type="hidden" name="artifact_id" value="<?php echo esc_attr($artifact_id); ?>">

            <?php if ($show_file_type_select) { ?>
                <div class="starmus-field-group">
                    <label for="starmus_audio_file_type_<?php echo esc_attr($instance_id); ?>">
                        <?php esc_html_e('Audio File Type', 'starmus-audio-recorder'); ?>
                    </label>
                    <select
                        id="starmus_audio_file_type_<?php echo esc_attr($instance_id); ?>"
                        name="audio_file_type">
                        <?php foreach ($allowed_types_arr as $type) { ?>
                            <option value="audio/<?php echo esc_attr($type); ?>">
                                <?php echo esc_html(strtoupper($type)); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
            <?php } else { ?>
                <input type="hidden" name="audio_file_type" value="audio/<?php echo esc_attr($allowed_types_arr[0]); ?>">
            <?php } ?>

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
                        <?php if (!empty($data_policy_url)) { ?>
                            <a
                                href="<?php echo esc_url($data_policy_url); ?>"
                                target="_blank"
                                rel="noopener noreferrer"><?php esc_html_e('Privacy Policy', 'starmus-audio-recorder'); ?></a>
                        <?php } ?>
                    </label>
                </div>
            </fieldset>

            <div class="starmus-field-actions">
                <button
                    type="button"
                    class="button button-primary button-large"
                    data-starmus-action="continue">
                    <?php esc_html_e('Continue to Recording', 'starmus-audio-recorder'); ?>
                </button>
            </div>
        </div>

        <!-- Step 2: Recording Interface (same as regular recorder) -->
        <div
            id="starmus_step2_<?php echo esc_attr($instance_id); ?>"
            class="starmus-step starmus-step-2"
            style="display:none;"
            data-starmus-step="2">

            <h2><?php esc_html_e('Record New Audio', 'starmus-audio-recorder'); ?></h2>

            <div
                class="starmus-status-message"
                role="status"
                aria-live="polite"
                data-starmus-status></div>

            <!-- Microphone Setup (Calibration) -->
            <div class="starmus-setup-container" data-starmus-setup-container style="display:none;">
                <p><?php esc_html_e('Before recording, we need to calibrate your microphone for optimal audio quality.', 'starmus-audio-recorder'); ?></p>
                <button
                    type="button"
                    class="button button-primary"
                    data-starmus-action="setup-mic">
                    <?php esc_html_e('Setup Microphone', 'starmus-audio-recorder'); ?>
                </button>
            </div>

            <!-- Recording Controls -->
            <div class="starmus-recorder-container" data-starmus-recorder-container style="display:none;">
                <!-- Timer -->
                <div class="starmus-timer" data-starmus-timer>
                    <div class="starmus-timer-elapsed">00m 00s</div>
                </div>

                <!-- Duration Progress Bar -->
                <div class="starmus-duration-progress-wrap" style="display:none;">
                    <div class="starmus-duration-progress" data-starmus-duration-progress></div>
                </div>

                <!-- Volume Meter -->
                <div class="starmus-volume-meter-wrap" style="display:none;">
                    <div class="starmus-volume-meter" data-starmus-volume-meter></div>
                </div>

                <!-- Waveform Visualization -->
                <div class="starmus-waveform" data-starmus-waveform style="display:none;"></div>

                <!-- Control Buttons -->
                <div class="starmus-controls">
                    <button
                        type="button"
                        class="button button-primary"
                        data-starmus-action="record"
                        style="display:none;">
                        <?php esc_html_e('Record', 'starmus-audio-recorder'); ?>
                    </button>
                    <button
                        type="button"
                        class="button"
                        data-starmus-action="pause"
                        style="display:none;">
                        <?php esc_html_e('Pause', 'starmus-audio-recorder'); ?>
                    </button>
                    <button
                        type="button"
                        class="button"
                        data-starmus-action="resume"
                        style="display:none;">
                        <?php esc_html_e('Resume', 'starmus-audio-recorder'); ?>
                    </button>
                    <button
                        type="button"
                        class="button"
                        data-starmus-action="stop"
                        style="display:none;">
                        <?php esc_html_e('Stop', 'starmus-audio-recorder'); ?>
                    </button>
                </div>

                <!-- Review Controls -->
                <div class="starmus-review-controls" style="display:none;">
                    <button
                        type="button"
                        class="button"
                        data-starmus-action="play">
                        <?php esc_html_e('Play Preview', 'starmus-audio-recorder'); ?>
                    </button>
                </div>

                <!-- Live Transcript -->
                <div class="starmus-transcript" data-starmus-transcript style="display:none;"></div>
            </div>

            <!-- Tier C Fallback: File Upload -->
            <div class="starmus-fallback-container" data-starmus-fallback-container style="display:none;">
                <p><?php esc_html_e('Recording not supported on your device. Please upload an audio file instead.', 'starmus-audio-recorder'); ?></p>
                <input type="file" name="starmus_audio_file" accept="audio/*">
            </div>

            <!-- Upload Progress -->
            <div class="starmus-progress-wrap" style="display:none;">
                <div class="starmus-progress" data-starmus-progress style="width:0%;"></div>
            </div>

            <!-- Action Buttons -->
            <div class="starmus-field-actions">
                <button
                    type="submit"
                    class="button button-primary button-large"
                    data-starmus-action="submit"
                    disabled>
                    <?php esc_html_e('Submit Re-recording', 'starmus-audio-recorder'); ?>
                </button>
                <button
                    type="button"
                    class="button button-secondary"
                    data-starmus-action="reset">
                    <?php esc_html_e('Reset', 'starmus-audio-recorder'); ?>
                </button>
            </div>
        </div>
    </form>
</div>