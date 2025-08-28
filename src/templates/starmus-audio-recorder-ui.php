<?php
/**
 * Starmus Audio Recorder UI Template
 *
 * This template provides the HTML structure for the audio recorder.
 * It is designed to work with the resilient, chunked-upload JavaScript modules.
 *
 * @package Starmus\templates
 */

namespace Starmus\templates;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

// This variable is passed from the render_recorder_shortcode method.
if (!isset($form_id)) {
    $form_id = 'starmus_default_form';
}
if (!isset($data_policy_url)) {
    $data_policy_url = '';
}
if (!isset($consent_message)) {
    $consent_message = 'I consent to the recording of my voice.';
}
?>

<!-- 
    The form action and method are now handled by JavaScript (e.preventDefault()).
    The tag itself is still crucial as it groups all the inputs for FormData collection.
-->
<?php
/**
 * Starmus Audio Recorder UI Template (Complete Multi-Part & Dynamic Version)
 *
 * This template provides a two-step HTML structure for the audio recorder.
 * Step 1 collects details, with dropdowns built dynamically from taxonomies.
 * Step 2 shows the complete, original recorder UI with all feedback elements.
 *
 * @package Starmus\templates
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

// Variables passed from the render_recorder_shortcode() method in the main class.
$form_id = $form_id ?? 'starmus_default_form';
$data_policy_url = $data_policy_url ?? '';
$consent_message = $consent_message ?? 'I consent to the recording of my voice.';
// $languages and $recording_types are also passed but used below.
?>

<form id="<?php echo esc_attr($form_id); ?>" class="starmus-recorder-form" novalidate>
    <div id="starmus_audioWrapper_<?php echo esc_attr($form_id); ?>" class="sparxstar-audioWrapper" data-enabled-recorder>

        <!-- =================================================================== -->
        <!-- STEP 1: DETAILS SECTION (Visible by default)                      -->
        <!-- =================================================================== -->
        <div id="starmus_step_1_<?php echo esc_attr($form_id); ?>" class="starmus-form-step">
            <h2 class="sparxstar-h2"><?php esc_html_e('Step 1: Tell Us About Your Recording', 'starmus_audio_recorder'); ?></h2>

            <div class="starmus-form-field">
                <label for="audio_title_<?php echo esc_attr($form_id); ?>"><?php esc_html_e('Title of Recording', 'starmus_audio_recorder'); ?></label>
                <input type="text" id="audio_title_<?php echo esc_attr($form_id); ?>" name="audio_title" required>
            </div>

            <div class="starmus-form-field">
                <label for="language_<?php echo esc_attr($form_id); ?>"><?php esc_html_e('Language', 'starmus_audio_recorder'); ?></label>
                <select name="language" id="language_<?php echo esc_attr($form_id); ?>" required>
                    <option value="" disabled selected><?php esc_html_e('-- Select a language --', 'starmus_audio_recorder'); ?></option>
                    <?php if (!empty($languages) && !is_wp_error($languages)) : ?>
                        <?php foreach ($languages as $term) : ?>
                            <option value="<?php echo esc_attr($term->slug); ?>"><?php echo esc_html($term->name); ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="starmus-form-field">
                <label for="recording_type_<?php echo esc_attr($form_id); ?>"><?php esc_html_e('Type of Recording', 'starmus_audio_recorder'); ?></label>
                <select name="recording_type" id="recording_type_<?php echo esc_attr($form_id); ?>" required>
                    <option value="" disabled selected><?php esc_html_e('-- Select a type --', 'starmus_audio_recorder'); ?></option>
                    <?php if (!empty($recording_types) && !is_wp_error($recording_types)) : ?>
                        <?php foreach ($recording_types as $term) : ?>
                            <option value="<?php echo esc_attr($term->slug); ?>"><?php echo esc_html($term->name); ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="starmus-form-field starmus-consent-field">
                <label for="audio_consent_<?php echo esc_attr($form_id); ?>">
                    <input type="checkbox" id="audio_consent_<?php echo esc_attr($form_id); ?>" name="audio_consent" required>
                    <?php echo wp_kses_post($consent_message); ?>
                </label>
                 <?php if (!empty($data_policy_url)) : ?>
                    <a href="<?php echo esc_url($data_policy_url); ?>" target="_blank" rel="noopener" class="starmus-data-policy-link"><?php esc_html_e('(View data policy)', 'starmus_audio_recorder'); ?></a>
                <?php endif; ?>
            </div>

            <div id="starmus_step_1_error_<?php echo esc_attr($form_id); ?>" class="starmus-error-message" style="display: none;" role="alert"></div>

            <button type="button" id="starmus_continue_btn_<?php echo esc_attr($form_id); ?>" class="sparxstar_button">
                <?php esc_html_e('Continue to Recording', 'starmus_audio_recorder'); ?>
            </button>
        </div>

        <!-- =================================================================== -->
        <!-- STEP 2: RECORDER SECTION (Hidden by default)                        -->
        <!-- This section contains your UNTOUCHED original recorder UI.          -->
        <!-- =================================================================== -->
        <div id="starmus_step_2_<?php echo esc_attr($form_id); ?>" class="starmus-form-step" style="display: none;">

            <h2 id="sparxstar_audioRecorderHeading_<?php echo esc_attr($form_id); ?>" class="sparxstar-h2">
                <?php esc_html_e('Step 2: Record Audio', 'starmus_audio_recorder'); ?>
            </h2>

            <!-- The actual recorder UI, controlled by JavaScript -->
            <div id="sparxstar_audioRecorder_<?php echo esc_attr($form_id); ?>" class="sparxstar_audioRecorder" role="region" aria-labelledby="sparxstar_audioRecorderHeading_<?php echo esc_attr($form_id); ?>">

                <!-- Status Message Area -->
                <div id="sparxstar_status_<?php echo esc_attr($form_id); ?>" class="sparxstar_status sparxstar_visually_hidden" role="status" aria-live="polite">
                    <span class="sparxstar_status__text"></span>
                </div>

                <!-- Recorder Controls -->
                <div class="sparxstar_recorderControls" role="group" aria-label="Recording controls">
                    <button type="button" id="recordButton_<?php echo esc_attr($form_id); ?>" class="sparxstar_button"><?php esc_html_e('Record', 'starmus_audio_recorder'); ?></button>
                    <button type="button" id="pauseButton_<?php echo esc_attr($form_id); ?>" class="sparxstar_button" disabled><?php esc_html_e('Pause', 'starmus_audio_recorder'); ?></button>
                    <button type="button" id="deleteButton_<?php echo esc_attr($form_id); ?>" class="sparxstar_button sparxstar_button--danger sparxstar_visually_hidden" disabled><?php esc_html_e('Delete', 'starmus_audio_recorder'); ?></button>
                </div>

                <!-- Volume Meter -->
                <div id="sparxstar_audioLevelContainer_<?php echo esc_attr($form_id); ?>" class="sparxstar_audioLevelContainer">
                    <div id="sparxstar_audioLevelWrap_<?php echo esc_attr($form_id); ?>" class="sparxstar_audioLevelWrap">
                        <div id="sparxstar_audioLevelBar_<?php echo esc_attr($form_id); ?>" class="sparxstar_audioLevelBar" role="meter" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </div>

                <!-- Timer Display -->
                <div id="sparxstar_timer_<?php echo esc_attr($form_id); ?>" class="sparxstar_timer" role="timer" aria-live="off">00:00</div>

                <!-- Audio Playback Preview -->
                <audio id="sparxstar_audioPlayer_<?php echo esc_attr($form_id); ?>" class="sparxstar_audioPlayer sparxstar_visually_hidden" controls aria-label="Recorded audio preview"></audio>
            </div>

            <!-- Hidden fields needed for submission, including new GPS fields -->
            <input type="hidden" name="audio_uuid" id="audio_uuid_<?php echo esc_attr($form_id); ?>" />
            <input type="hidden" name="fileName" id="fileName_<?php echo esc_attr($form_id); ?>" />
            <input type="file" name="audio_file" id="audio_file_<?php echo esc_attr($form_id); ?>" class="sparxstar_visually_hidden" accept="audio/*">
            <input type="hidden" name="gps_latitude" id="gps_latitude_<?php echo esc_attr($form_id); ?>" />
            <input type="hidden" name="gps_longitude" id="gps_longitude_<?php echo esc_attr($form_id); ?>" />

            <!-- Final Submit Button -->
            <button type="submit" id="submit_button_<?php echo esc_attr($form_id); ?>" class="sparxstar_submitButton" disabled>
                <?php esc_html_e('Submit Recording', 'starmus_audio_recorder'); ?>
            </button>
        </div>

        <!-- Loader Overlay for the final submission -->
        <div id="sparxstar_loader_overlay_<?php echo esc_attr($form_id); ?>" class="sparxstar_loader_overlay sparxstar_visually_hidden" role="alert" aria-live="assertive">
            <div class="sparxstar_loader_content">
                <div class="sparxstar_spinner"></div>
                <span class="sparxstar_status__text"><?php esc_html_e('Submitting your recording…', 'starmus_audio_recorder'); ?></span>
            </div>
        </div>

    </div>
</form>
