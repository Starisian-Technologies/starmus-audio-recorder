<?php
/*
 * Starmus Audio Recorder UI
 * This HTML is designed to work with mic-recorder-wrapper.js and uses the native MediaRecorder API.
 * It enables a user to record audio in-browser.
 * The JavaScript (starmus-audio-recorder.js) is responsible for handling the recorded audio data
 * for playback and any subsequent submission or processing.
*/
?>
<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
  <!-- nonce -->
  <?php wp_nonce_field( 'starmus_submit_audio_action', 'starmus_audio_nonce_field' ); ?>
  <div id="starmus_audioWrapper" class="sparxstar-audioWrapper" data-enabled-recorder>
    <h2 id="sparxstar_audioRecorderHeading" class="sparxstar-h2">Audio Recorder</h2>
      <!-- Consent Checkbox -->
      <label for="audio_consent" class="sparxstar_consent">
        <input type="checkbox" id="audio_consent" name="audio_consent" required>
        I give permission to record and submit my audio.
      </label>
    <!-- Recorder -->
    <div id="sparxstar_audioRecorder" class="sparxstar_audioRecorder" role="region" aria-labelledby="sparxstar_audioRecorderHeading">
      <!-- Recorder Controls -->
      <div class="sparxstar_recorderControls" role="group" aria-label="Recording controls">
        <button type="button" id="recordButton" class="sparxstar_button">Record</button>
        <button type="button" id="pauseButton" class="sparxstar_button" disabled>Pause</button>
        <button type="button" id="playButton" class="sparxstar_button" disabled>Play</button>
      </div>
    
      <!-- Volume Meter -->
      <div id="sparxstar_audioLevelWrap" class="sparxstar_audioLevelWrap" aria-hidden="true">
        <div id="sparxstar_audioLevelBar"
             class="sparxstar_audioLevelBar"
             role="meter"
             aria-valuenow="0"
             aria-valuemin="0"
             aria-valuemax="100"
             aria-label="Microphone input level"></div>
      </div>
    
      <!-- Status Message -->
      <div id="sparxstar_status" role="status" aria-live="polite" class="visually-hidden"></div>
    
      <!-- Timer Display -->
      <div id="sparxstar_timer" class="sparxstar_timer" role="timer" aria-live="polite">00:00</div>
    
      <!-- Audio Playback -->
      <audio id="sparxstar_audioPlayer" class="sparxstar_audioPlayer sparxstar_hidden" controls aria-label="Recorded audio preview"></audio>
    
      <!-- Hidden Inputs for Form Submission -->
      <input type="hidden" name="audio_uuid" id="audio_uuid" class="sparxstar_hidden"/>
      <input type="file" name="audio_file" id="audio_file" accept="audio/*" class="sparxstar_hidden" />
    </div>
    
    <!-- Submit -->
    <button type="submit" class="sparxstar_submitButton">Submit Recording</button>

  </div>
</form>
