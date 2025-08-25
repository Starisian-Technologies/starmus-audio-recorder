<?php
/**
 * Starmus Audio Recorder UI Template
 *
 * This template provides the HTML structure for the audio recorder.
 * It is designed to work with the resilient, chunked-upload JavaScript modules.
 *
 * @package Starmus\templates
 */

namespace Starisian\templates;

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
?>

<!-- 
    The form action and method are now handled by JavaScript (e.preventDefault()).
    The tag itself is still crucial as it groups all the inputs for FormData collection.
-->
<form id="<?php echo esc_attr($form_id); ?>" class="starmus-recorder-form" novalidate>

    <!-- The main interactive component for the audio recorder -->
    <div id="starmus_audioWrapper_<?php echo esc_attr($form_id); ?>" class="sparxstar-audioWrapper" data-enabled-recorder>
        
        <h2 id="sparxstar_audioRecorderHeading_<?php echo esc_attr($form_id); ?>" class="sparxstar-h2">
            <?php esc_html_e('Audio Recorder', 'starmus'); ?>
        </h2>

        <?php if (!empty($data_policy_url)) : ?>
            <p class="starmus-data-policy"><a href="<?php echo esc_url($data_policy_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('View data policy', 'starmus'); ?></a></p>
        <?php endif; ?>

        <!-- Consent Checkbox is a required part of the form -->
        <div class="starmus-form-field starmus-consent-field">
            <label for="audio_consent_<?php echo esc_attr($form_id); ?>">
                <input type="checkbox" id="audio_consent_<?php echo esc_attr($form_id); ?>" name="audio_consent" required>
                <?php
                // Display the customizable consent message from settings.
                // Note: The variable $consent_message is passed from the render method.
                echo wp_kses_post($consent_message);
                ?>
            </label>
        </div>

        <!-- The actual recorder UI, controlled by JavaScript -->
        <div id="sparxstar_audioRecorder_<?php echo esc_attr($form_id); ?>" class="sparxstar_audioRecorder" role="region" aria-labelledby="sparxstar_audioRecorderHeading_<?php echo esc_attr($form_id); ?>">

            <!-- Status Message Area -->
            <div id="sparxstar_status_<?php echo esc_attr($form_id); ?>" class="sparxstar_status sparxstar_visually_hidden" role="status" aria-live="polite">
                <span class="sparxstar_status__text"></span>
            </div>

            <!-- Recorder Controls -->
            <div class="sparxstar_recorderControls" role="group" aria-label="Recording controls">
                <button type="button" id="recordButton_<?php echo esc_attr($form_id); ?>" class="sparxstar_button"><?php esc_html_e('Record', 'starmus'); ?></button>
                <button type="button" id="pauseButton_<?php echo esc_attr($form_id); ?>" class="sparxstar_button" disabled><?php esc_html_e('Pause', 'starmus'); ?></button>
                <button type="button" id="deleteButton_<?php echo esc_attr($form_id); ?>" class="sparxstar_button sparxstar_button--danger sparxstar_visually_hidden" disabled><?php esc_html_e('Delete', 'starmus'); ?></button>
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

            <!-- 
                FIX: These hidden fields are the ONLY ones needed for submission.
                The JavaScript will populate their values before creating the FormData object.
                The old `action` and `nonce` fields are removed because they are handled by JS.
            -->
            <input type="hidden" name="audio_uuid" id="audio_uuid_<?php echo esc_attr($form_id); ?>" />
            <input type="hidden" name="fileName" id="fileName_<?php echo esc_attr($form_id); ?>" />
            <input type="file" name="audio_file" id="audio_file_<?php echo esc_attr($form_id); ?>" class="sparxstar_visually_hidden" accept="audio/*">
        </div>

        <!-- Submit Button -->
        <button type="submit" id="submit_button_<?php echo esc_attr($form_id); ?>" class="sparxstar_submitButton" disabled>
            <?php esc_html_e('Submit Recording', 'starmus'); ?>
        </button>

        <!-- Loader Overlay for visual feedback during submission -->
        <div id="sparxstar_loader_overlay_<?php echo esc_attr($form_id); ?>" class="sparxstar_loader_overlay sparxstar_visually_hidden" role="alert" aria-live="assertive">
            <div class="sparxstar_loader_content">
                <div class="sparxstar_spinner"></div>
                <span class="sparxstar_status__text"><?php esc_html_e('Submitting your recording…', 'starmus'); ?></span>
            </div>
        </div>

    </div>
</form>
