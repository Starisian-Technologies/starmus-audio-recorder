<?php
namespace Starmus\templates;


/*
 * Starmus Audio Recorder UI
 * This HTML is designed to work with mic-recorder-wrapper.js and uses the native MediaRecorder API.
 * It enables a user to record audio in-browser.
 * The JavaScript (starmus-audio-recorder.js) is responsible for handling the recorded audio data
 * for playback and any subsequent submission or processing.
*/
$form_id = 'sparxstarAudioForm_' . $unique_suffix;
?>
<form id="<?php echo esc_attr( $form_id ); ?>" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url('admin-ajax.php') ); ?>">
  

  <input type="hidden" name="action" id="action_<?php echo esc_attr( $form_id ); ?>" value="starmus_submit_audio" />
  <!-- nonce -->
  <?php wp_nonce_field( 'starmus_submit_audio_action', 'starmus_audio_nonce_field' ); ?>

  <!--audioWrapper-->
  <div id="starmus_audioWrapper_<?php echo esc_attr( $form_id ); ?>" class="sparxstar-audioWrapper" data-enabled-recorder>
    <h2 id="sparxstar_audioRecorderHeading_<?php echo esc_attr( $form_id ); ?>" class="sparxstar-h2">Audio Recorder</h2>
    
      <!-- Consent Checkbox -->
      <label for="audio_consent_<?php echo esc_attr( $form_id ); ?>" ...>
        <input type="checkbox" id="audio_consent_<?php echo esc_attr( $form_id ); ?>" name="audio_consent" required>
        I give permission to record and submit my audio.
      </label>
    
    <!-- Recorder -->
    <div id="sparxstar_audioRecorder_<?php echo esc_attr( $form_id ); ?>" class="sparxstar_audioRecorder" role="region" aria-labelledby="sparxstar_audioRecorderHeading_<?php echo esc_attr( $form_id ); ?>">  
      
      <!-- Status Message -->
      <div id="sparxstar_status_<?php echo esc_attr( $form_id ); ?>" role="status" aria-live="polite" class="sparxstar_visually_hidden">
        <span class="sparxstar_status__text">Ready to record.</span>
      </div>
      
      <!-- Recorder Controls -->
      <div class="sparxstar_recorderControls" role="group" aria-label="Recording controls">
        <button type="button" id="recordButton_<?php echo esc_attr( $form_id ); ?>" class="sparxstar_button">Record</button>
        <button type="button" id="pauseButton_<?php echo esc_attr( $form_id ); ?>" class="sparxstar_button" disabled>Pause</button>
        <button type="button" id="deleteButton_<?php echo esc_attr( $form_id ); ?>" class="sparxstar_button sparxstar_button--danger sparxstar_visually_hidden" disabled>Delete</button>

      </div>
    
      <!-- Volume Meter -->
      <div id="sparxstar_audioLevelWrap_<?php echo esc_attr( $form_id ); ?>" class="sparxstar_audioLevelWrap" aria-hidden="true">
        <div id="sparxstar_audioLevelBar_<?php echo esc_attr( $form_id ); ?>"
             class="sparxstar_audioLevelBar"
             role="meter"
             aria-valuenow="0"
             aria-valuemin="0"
             aria-valuemax="100"
             aria-label="Microphone input level"></div>
      </div>
    
      <!-- Timer Display -->
      <div id="sparxstar_timer_<?php echo esc_attr( $form_id ); ?>" class="sparxstar_timer" role="timer" aria-live="polite">00:00</div>
    
      <!-- Audio Playback -->
      <audio id="sparxstar_audioPlayer_<?php echo esc_attr( $form_id ); ?>" class="sparxstar_audioPlayer sparxstar_visually_hidden" controls aria-label="Recorded audio preview"></audio>
    
      <!-- Hidden Inputs for Form Submission -->
      <input type="hidden" name="audio_uuid" id="audio_uuid_<?php echo esc_attr( $form_id ); ?>"/>
      <input type="file" name="audio_file" id="audio_file_<?php echo esc_attr($form_id); ?>" class="sparxstar_hidden sparxstar_visually_hidden" accept="audio/*">
    </div>
    
    <!-- Submit -->
    <button type="submit" id="submit_button_<?php echo esc_attr( $form_id ); ?>" class="sparxstar_submitButton" disabled>Submit Recording</button>
    <!-- Submit Loader -->
    <div id="sparxstar_loader_<?php echo esc_attr( $form_id ); ?>" class="sparxstar_status sparxstar_visually_hidden" aria-live="polite">
      <span class="sparxstar_status__text">Submitting… please wait.</span>
    </div>

  </div>
</form>
