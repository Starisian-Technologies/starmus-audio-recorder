<?php
/**
 * Starmus Re-Recorder UI Template
 * @version 1.0.1-UPDATE-FIX
 */
if (! defined('ABSPATH')) exit;

/** @var int $post_id */
/** @var string $existing_title */

$instance_id = 'starmus_form_' . sanitize_key('rerecord_' . wp_generate_uuid4());
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

        <div id="starmus_step1_<?php echo esc_attr($instance_id); ?>" class="starmus-step" data-starmus-step="1">
            <h2><?php esc_html_e('Re-Record Audio', 'starmus-audio-recorder'); ?></h2>
            
            <div class="starmus-notice">
                <p><?php esc_html_e('You are replacing audio for:', 'starmus-audio-recorder'); ?> <strong><?php echo esc_html($existing_title); ?></strong></p>
            </div>

            <!-- CRITICAL FIX: Use 'post_id' so handler treats this as an update -->
            <input type="hidden" name="post_id" value="<?php echo esc_attr((string) $post_id); ?>">
            <input type="hidden" name="action" value="starmus_update_audio">
            
            <!-- Metadata Persist -->
            <input type="hidden" name="starmus_title" value="<?php echo esc_attr($existing_title); ?>">
            <input type="hidden" name="audio_file_type" value="audio/webm">
            
            <!-- JS Targets -->
            <input type="hidden" name="_starmus_env" value="">
            <input type="hidden" name="_starmus_calibration" value="">
            <input type="hidden" name="first_pass_transcription" value="">

            <button type="button" class="starmus-btn starmus-btn--primary" data-starmus-action="next">
                <?php esc_html_e('Proceed to Recorder', 'starmus-audio-recorder'); ?>
            </button>
        </div>

        <!-- Step 2: Recorder (Identical to Standard) -->
        <div id="starmus_step2_<?php echo esc_attr($instance_id); ?>" class="starmus-step" style="display:none;" data-starmus-step="2">
            <h2><?php esc_html_e('Record New Audio', 'starmus-audio-recorder'); ?></h2>
            
            <!-- Setup -->
            <div class="starmus-setup-container" data-starmus-setup-container>
                <button type="button" class="starmus-btn starmus-btn--primary" data-starmus-action="setup-mic">
                    <span class="dashicons dashicons-microphone"></span> Setup
                </button>
            </div>

            <!-- Recorder -->
            <div class="starmus-recorder-container" data-starmus-recorder-container>
                <div class="starmus-visualizer-stage">
                    <div class="starmus-timer" data-starmus-timer><span class="starmus-timer-elapsed">00m 00s</span></div>
                    <div class="starmus-duration-progress-wrapper">
                        <div class="starmus-duration-progress" data-starmus-duration-progress></div>
                    </div>
                    <div class="starmus-meter-wrap">
                        <div class="starmus-meter-bar" data-starmus-volume-meter></div>
                    </div>
                </div>
                
                <div class="starmus-recorder-controls">
                    <button type="button" class="starmus-btn starmus-btn--record" data-starmus-action="record">Record</button>
                    <button type="button" class="starmus-btn starmus-btn--stop" data-starmus-action="stop" style="display:none;">Stop</button>
                    <div class="starmus-review-controls" style="display:none;">
                        <button type="button" class="starmus-btn starmus-btn--secondary" data-starmus-action="play">Play</button>
                        <button type="button" class="starmus-btn starmus-btn--outline" data-starmus-action="reset">Retake</button>
                    </div>
                </div>
                
                <!-- Hidden Transcript -->
                <div data-starmus-transcript style="display:none;"></div>
            </div>

            <button type="submit" class="starmus-btn starmus-btn--primary starmus-btn--full" data-starmus-action="submit" disabled>
                <?php esc_html_e('Save Replacement', 'starmus-audio-recorder'); ?>
            </button>
        </div>
    </form>
</div>