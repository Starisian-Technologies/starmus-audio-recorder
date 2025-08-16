<?php
namespace Starmus\templates;


/*
 * Starmus Audio Recorder UI
 * This HTML is designed to work with mic-recorder-wrapper.js and uses the native MediaRecorder API.
 * It enables a user to record audio in-browser.
 * The JavaScript (starmus-audio-recorder.js) is responsible for handling the recorded audio data
 * for playback and any subsequent submission or processing.
 */
if (!isset($unique_suffix)) {
  $unique_suffix = uniqid();
}
?>
<form id="<?php echo esc_attr($form_id); ?>" method="post" enctype="multipart/form-data"
  action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">


  <input type="hidden" name="action" id="action_<?php echo esc_attr($form_id); ?>" value="starmus_submit_audio" />
  <!-- nonce -->
  <?php wp_nonce_field('starmus_submit_audio_action', 'starmus_audio_nonce_field'); ?>

  <!--audioWrapper-->
  <div id="starmus_audioWrapper_<?php echo esc_attr($form_id); ?>" class="sparxstar-audioWrapper" data-enabled-recorder>
    <h2 id="sparxstar_audioRecorderHeading_<?php echo esc_attr($form_id); ?>" class="sparxstar-h2"><?php echo esc_html__( 'Audio Recorder', 'starmus-audio-recorder' ); ?></h2>

    <!-- Consent Checkbox -->
    <label for="audio_consent_<?php echo esc_attr($form_id); ?>" ...>
      <input type="checkbox" id="audio_consent_<?php echo esc_attr($form_id); ?>" name="audio_consent" required>
      <?php echo esc_html__( 'I give permission to record and submit my audio.', 'starmus-audio-recorder' ); ?>
    </label>

    <!-- Recorder -->
    <div id="sparxstar_audioRecorder_<?php echo esc_attr($form_id); ?>" class="sparxstar_audioRecorder" role="region"
      aria-labelledby="sparxstar_audioRecorderHeading_<?php echo esc_attr($form_id); ?>">

      <!-- Status Message -->
      <div id="sparxstar_status_<?php echo esc_attr($form_id); ?>" role="status" aria-live="polite"
        class="sparxstar_visually_hidden">
        <span class="sparxstar_status__text"><?php echo esc_html__( 'Ready to record.', 'starmus-audio-recorder' ); ?></span>
      </div>

      <!-- Recorder Controls -->
      <div class="sparxstar_recorderControls" role="group" aria-label="<?php echo esc_attr__( 'Recording controls', 'starmus-audio-recorder' ); ?>">
        <button type="button" id="recordButton_<?php echo esc_attr($form_id); ?>"
          class="sparxstar_button"><?php echo esc_html__( 'Record', 'starmus-audio-recorder' ); ?></button>
        <button type="button" id="pauseButton_<?php echo esc_attr($form_id); ?>" class="sparxstar_button"
          disabled><?php echo esc_html__( 'Pause', 'starmus-audio-recorder' ); ?></button>
        <button type="button" id="deleteButton_<?php echo esc_attr($form_id); ?>"
          class="sparxstar_button sparxstar_button--danger sparxstar_visually_hidden" disabled><?php echo esc_html__( 'Delete', 'starmus-audio-recorder' ); ?></button>

      </div>

      <!-- Volume Meter -->
      <div id="sparxstar_audioLevelContainer_<?php echo esc_attr($form_id); ?>" class="sparxstar_audioLevelContainer">
        <label id="sparxstar_audioLevelVisibleLabel_<?php echo esc_attr($form_id); ?>"
          for="sparxstar_audioLevelBar_<?php echo esc_attr($form_id); ?>" class="sparxstar_audioLevelVisibleLabel">
          <?php echo esc_html__( 'Microphone Level:', 'starmus-audio-recorder' ); ?>
        </label>
        <div id="sparxstar_audioLevelWrap_<?php echo esc_attr($form_id); ?>" class="sparxstar_audioLevelWrap">
          <div id="sparxstar_audioLevelBar_<?php echo esc_attr($form_id); ?>" class="sparxstar_audioLevelBar"
            role="meter" aria-labelledby="sparxstar_audioLevelVisibleLabel_<?php echo esc_attr($form_id); ?>"
            aria-describedby="sparxstar_audioLevelText_<?php echo esc_attr($form_id); ?>" aria-valuenow="0"
            aria-valuemin="0" aria-valuemax="100">
            <!-- No inner text here if aria-labelledby is used -->
          </div>
        </div>
        <span id="sparxstar_audioLevelText_<?php echo esc_attr($form_id); ?>" class="sparxstar_audioLevelText"
          aria-live="polite">0%</span> <!-- aria-live if you want changes announced -->
      </div>

      <!-- Timer Display -->
      <div id="sparxstar_audioTimerContainer_<?php echo esc_attr($form_id); ?>" class="sparxstar_audioTimerContainer"
        aria-live="polite">
        <label for="sparxstar_timer_<?php echo esc_attr($form_id); ?>" class="sparxstar_visually_hidden"><?php echo esc_html__( 'Recording Timer', 'starmus-audio-recorder' ); ?></label>
        <div id="sparxstar_timer_<?php echo esc_attr($form_id); ?>" class="sparxstar_timer" role="timer"
          aria-live="polite">00:00</div>
      </div>


      <!-- Audio Playback -->
      <audio id="sparxstar_audioPlayer_<?php echo esc_attr($form_id); ?>" class="sparxstar_audioPlayer" controls
        aria-label="<?php echo esc_attr__( 'Recorded audio preview', 'starmus-audio-recorder' ); ?>"></audio>

      <!-- Download Link -->
      <a id="sparxstar_audioDownload_<?php echo esc_attr($form_id); ?>"
        class="sparxstar_button sparxstar_audioDownload sparxstar_visually_hidden" href="#"
        download="audio_recording.wav" aria-label="<?php echo esc_attr__( 'Download recorded audio', 'starmus-audio-recorder' ); ?>" aria-disabled="true">
        <!-- Use aria-disabled -->
        <?php echo esc_html__( 'Download', 'starmus-audio-recorder' ); ?>
      </a>

      <!-- Hidden Inputs for Form Submission -->
      <input type="hidden" name="audio_uuid" id="audio_uuid_<?php echo esc_attr($form_id); ?>" />
      <input type="file" name="audio_file" id="audio_file_<?php echo esc_attr($form_id); ?>"
        class="sparxstar_visually_hidden" accept="audio/*">
    </div>

    <!-- Submit -->
    <button type="submit" id="submit_button_<?php echo esc_attr($form_id); ?>" class="sparxstar_submitButton"
      disabled><?php echo esc_html( $submit_button_text ); ?></button>

    <!-- Hidden fields  -->
    <input type="hidden" name="submission_id" id="submission_id_<?php echo esc_attr($form_id); ?>" value="" />
    <input type="hidden" name="user_id" id="linked_user_id_<?php echo esc_attr($form_id); ?>" value="" />
    <input type="hidden" name="user_agent" id="linked_user_agent_<?php echo esc_attr($form_id); ?>" value="" />
    <input type="hidden" name="user_ip" id="linked_user_ip_<?php echo esc_attr($form_id); ?>" value="" />
    <input type="hidden" name="datetime" id="linked_datetime_<?php echo esc_attr($form_id); ?>" value="" />
    <input type="hidden" name="user_email" id="linked_user_email_<?php echo esc_attr($form_id); ?>" value="" />

    <!-- Submit Loader -->
    <div id="sparxstar_status_loader_<?php echo esc_attr($form_id); ?>" class="sparxstar_status sparxstar_visually_hidden"
      aria-live="polite">
      <span class="sparxstar_status__text"><?php echo esc_html__( 'Submitting… please wait.', 'starmus-audio-recorder' ); ?></span>
    </div>

    <!-- Submit Loader / Overlay -->
    <div id="sparxstar_loader_overlay_<?php echo esc_attr($form_id); ?>"
      class="sparxstar_loader_overlay sparxstar_visually_hidden" role="alert" aria-live="assertive">
      <div class="sparxstar_loader_content">
        <div class="sparxstar_spinner"></div>
        <span id="sparxstar_loader_text_<?php echo esc_attr($form_id); ?>" class="sparxstar_status__text"><?php echo esc_html__( 'Submitting your recording…', 'starmus-audio-recorder' ); ?></span>
        <p class="sparxstar_upload_eta_note"><?php echo esc_html__( 'Large recordings may take several minutes to upload. Please keep this window open.', 'starmus-audio-recorder' ); ?></p>
      </div>
    </div>
  </div>
</form>
