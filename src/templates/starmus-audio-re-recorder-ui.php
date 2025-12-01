=<?php
/**
 * Starmus Re-Recorder UI Template
 *
 * FIXED: Forces 'audio_file_type' to 'audio/webm' to prevent 415 Errors.
 * Browsers record in WebM, so we must tell the server to expect WebM.
 *
 * @package Starisian\Sparxstar\Starmus\templates
 * @version 1.1.2
 */

if (! defined('ABSPATH')) {
	exit;
}

/** @var string $form_id */
/** @var int $post_id - The ID to update */
/** @var string $existing_title */
/** @var int $existing_language */
/** @var int $existing_type */
/** @var string $allowed_file_types */

$form_id ??= 'rerecord';
$instance_id = 'starmus_form_' . sanitize_key($form_id . '_' . wp_generate_uuid4());

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
		data-starmus-update-id="<?php echo esc_attr($post_id); ?>"
		data-starmus-instance="<?php echo esc_attr($instance_id); ?>">

		<!-- Step 1: Confirmation -->
		<div
			id="starmus_step1_<?php echo esc_attr($instance_id); ?>"
			class="starmus-step starmus-step-1"
			data-starmus-step="1">
			
			<h2><?php esc_html_e('Re-Record Audio', 'starmus-audio-recorder'); ?></h2>

			<div
				id="starmus_step1_usermsg_<?php echo esc_attr($instance_id); ?>"
				class="starmus-user-message"
				style="display:none;"
				role="alert"
				aria-live="polite"
				data-starmus-message-box></div>

			<div class="starmus-notice starmus-notice--info">
				<p>
					<?php esc_html_e('You are replacing the audio for:', 'starmus-audio-recorder'); ?>
					<strong><?php echo esc_html($existing_title); ?></strong>
				</p>
			</div>

			<!-- CRITICAL: Hidden fields to trigger Update Logic -->
			<input type="hidden" name="action" value="starmus_update_audio">
			<input type="hidden" name="recording_id" value="<?php echo esc_attr($post_id); ?>">
			<input type="hidden" name="update_post_id" value="<?php echo esc_attr($post_id); ?>">
			
			<!-- Metadata Persistence -->
			<input type="hidden" name="starmus_title" value="<?php echo esc_attr($existing_title); ?>">
			<input type="hidden" name="starmus_language" value="<?php echo esc_attr($existing_language); ?>">
			<input type="hidden" name="starmus_recording_type" value="<?php echo esc_attr($existing_type); ?>">
			
			<!-- FIX: Always set to 'audio/webm' to match browser recording format. -->
			<input type="hidden" name="audio_file_type" value="audio/webm">

			<fieldset class="starmus-consent-fieldset">
				<legend class="starmus-fieldset-legend">
					<?php esc_html_e('Confirmation', 'starmus-audio-recorder'); ?>
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
				<?php esc_html_e('Proceed to Recorder', 'starmus-audio-recorder'); ?>
			</button>
		</div>

		<!-- Step 2: Audio Recording -->
		<div
			id="starmus_step2_<?php echo esc_attr($instance_id); ?>"
			class="starmus-step starmus-step-2"
			data-starmus-step="2"
			style="display:none;">

			<h2 id="starmus_audioRecorderHeading_<?php echo esc_attr($instance_id); ?>" tabindex="-1">
				<?php esc_html_e('Record New Audio', 'starmus-audio-recorder'); ?>
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

			<!-- TIER C FALLBACK -->
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
					accept="audio/webm,audio/wav,audio/mp3"
					style="display:none;">
				
			</div>

			<!-- TIER A/B RECORDER UI -->
			<div
				id="starmus_recorder_container_<?php echo esc_attr($instance_id); ?>"
				class="starmus-recorder-container"
				data-starmus-recorder-container>

				<!-- VISUALIZER STAGE -->
				<div class="starmus-visualizer-stage">
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

					<!-- Waveform Container -->
					<div id="starmus_waveform_<?php echo esc_attr($instance_id); ?>" class="starmus-waveform-view" data-starmus-waveform></div>

					<!-- Volume Meter -->
					<div class="starmus-meter-wrap">
						<label for="starmus_vol_meter_<?php echo esc_attr($instance_id); ?>" class="starmus-meter-label"><?php esc_html_e('Microphone Volume:', 'starmus-audio-recorder'); ?></label>
						<div id="starmus_vol_meter_<?php echo esc_attr($instance_id); ?>" class="starmus-meter-bar" data-starmus-volume-meter></div>
					</div>
				</div> 
				
				<!-- CONTROLS DECK -->
				<div class="starmus-recorder-controls">
					<button
						type="button"
						id="starmus_record_btn_<?php echo esc_attr($instance_id); ?>"
						class="starmus-btn starmus-btn--record starmus-btn--large"
						data-starmus-action="record">
						<span class="dashicons dashicons-microphone"></span> <?php esc_html_e('Start Recording', 'starmus-audio-recorder'); ?>
					</button>

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

					<button
						type="button"
						id="starmus_resume_btn_<?php echo esc_attr($instance_id); ?>"
						class="starmus-btn starmus-btn--resume starmus-btn--large"
						data-starmus-action="resume"
						style="display:none;">
						<span class="dashicons dashicons-controls-play"></span> <?php esc_html_e('Resume Recording', 'starmus-audio-recorder'); ?>
					</button>

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
				<?php esc_html_e('Update Recording', 'starmus-audio-recorder'); ?>
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
						accept="audio/webm,audio/wav,audio/mp3">
					<!-- FORCE webm to match browser output -->
					<input type="hidden" name="audio_file_type" value="audio/webm">
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