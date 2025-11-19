<?php

/**
 * Starmus Audio Re-Recorder Template - Single-Step Re-recording Form
 *
 * This template is used for re-recording existing audio submissions.
 * It shows only the consent checkbox and recorder UI - no title/language/type fields.
 * All metadata is passed via hidden post_id field.
 *
 * @package Starisian\Sparxstar\Starmus\templates
 * @version 0.9.0
 * @since   0.8.5
 * @var string $form_id         Base ID for the form, passed from the shortcode.
 * @var int    $post_id         The existing audio-recording post ID being re-recorded.
 * @var string $consent_message The user consent message.
 * @var string $data_policy_url The URL to the data policy.
 * @var string $allowed_file_types Comma-separated allowed file types.
 */

if (! defined('ABSPATH')) {
	exit;
}

$instance_id = 'starmus_rerecord_' . sanitize_key($form_id . '_' . wp_generate_uuid4());

// Get allowed file types from settings
$allowed_file_types = $allowed_file_types ?? 'webm';
$allowed_types_arr     = array_filter(array_map('trim', explode(',', $allowed_file_types)));
$show_file_type_select = count($allowed_types_arr) > 1;
?>

<div class="starmus-rerecorder-form">
	<form
		id="<?php echo esc_attr($instance_id); ?>"
		class="starmus-audio-form starmus-rerecord-form sparxstar-glass-card"
		method="post"
		enctype="multipart/form-data"
		novalidate
		data-starmus="recorder"
		data-starmus-instance="<?php echo esc_attr($instance_id); ?>"
		data-starmus-rerecord="true">
		<?php wp_nonce_field('starmus_audio_form', 'starmus_nonce_' . $instance_id); ?>

		<!-- Hidden post_id field for re-recording -->
		<input type="hidden" name="post_id" value="<?php echo esc_attr((string) $post_id); ?>">

		<!-- Single-step form: Consent + Recorder -->
		<div class="starmus-rerecord-container">

			<h2><?php esc_html_e('Re-record Your Audio', 'starmus-audio-recorder'); ?></h2>

			<!-- Consent Checkbox -->
			<fieldset class="starmus-consent-fieldset">
				<legend class="screen-reader-text">
					<?php esc_html_e('Consent Agreement', 'starmus-audio-recorder'); ?>
				</legend>
				<div class="starmus-checkbox-wrapper">
					<input
						type="checkbox"
						id="starmus_agreement_<?php echo esc_attr($instance_id); ?>"
						name="agreement_to_terms"
						value="1"
						required
						aria-required="true"
						aria-describedby="starmus_agreement_desc_<?php echo esc_attr($instance_id); ?>">
					<label for="starmus_agreement_<?php echo esc_attr($instance_id); ?>">
						<?php echo esc_html($consent_message); ?>
						<?php if (! empty($data_policy_url)) : ?>
							<a
								href="<?php echo esc_url($data_policy_url); ?>"
								target="_blank"
								rel="noopener noreferrer"
								aria-label="<?php esc_attr_e('Read our data policy (opens in new tab)', 'starmus-audio-recorder'); ?>">
								<?php esc_html_e('Learn more', 'starmus-audio-recorder'); ?>
							</a>
						<?php endif; ?>
					</label>
				</div>
				<p
					id="starmus_agreement_desc_<?php echo esc_attr($instance_id); ?>"
					class="starmus-field-description">
					<?php esc_html_e('You must agree to the terms before recording.', 'starmus-audio-recorder'); ?>
				</p>
			</fieldset>

			<!-- File type selection (if multiple types allowed) -->
			<?php if ($show_file_type_select) : ?>
				<div class="starmus-field-group">
					<label for="starmus_file_type_<?php echo esc_attr($instance_id); ?>">
						<?php esc_html_e('Audio Format', 'starmus-audio-recorder'); ?>
					</label>
					<select
						id="starmus_file_type_<?php echo esc_attr($instance_id); ?>"
						name="audio_file_type"
						class="starmus-select">
						<?php foreach ($allowed_types_arr as $type) : ?>
							<option value="audio/<?php echo esc_attr($type); ?>">
								<?php echo esc_html(strtoupper($type)); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php else : ?>
				<input type="hidden" name="audio_file_type" value="audio/<?php echo esc_attr($allowed_types_arr[0]); ?>">
			<?php endif; ?>

			<!-- Recorder Container -->
			<div
				id="starmus_recorder_container_<?php echo esc_attr($instance_id); ?>"
				class="starmus-recorder-container"></div>

			<!-- Fallback for unsupported browsers -->
			<div
				id="starmus_fallback_container_<?php echo esc_attr($instance_id); ?>"
				class="starmus-fallback-container"
				style="display:none;">
				<p><?php esc_html_e('Live recording is not supported on this browser.', 'starmus-audio-recorder'); ?></p>
				<label for="starmus_fallback_input_<?php echo esc_attr($instance_id); ?>">
					<?php esc_html_e('Upload an audio file instead:', 'starmus-audio-recorder'); ?>
				</label>
				<input
					type="file"
					id="starmus_fallback_input_<?php echo esc_attr($instance_id); ?>"
					name="audio_file"
					accept="audio/*">
			</div>

			<!-- Upload loader -->
			<div
				id="starmus_loader_overlay_<?php echo esc_attr($instance_id); ?>"
				class="starmus-loader"
				style="display:none;">
				<?php esc_html_e('Uploading...', 'starmus-audio-recorder'); ?>
			</div>

			<!-- Submit Button -->
			<button
				type="submit"
				id="starmus_submit_btn_<?php echo esc_attr($instance_id); ?>"
				class="starmus-btn starmus-btn--primary"
				disabled>
				<?php esc_html_e('Submit Re-recording', 'starmus-audio-recorder'); ?>
			</button>

			<!-- Manual upload option for privileged users -->
			<?php if (current_user_can('author') || current_user_can('editor') || current_user_can('administrator')) : ?>
				<div class="starmus-upload-audio-link" style="margin-top:24px;text-align:right;">
					<button
						type="button"
						id="starmus_show_upload_<?php echo esc_attr($instance_id); ?>"
						class="starmus-btn starmus-btn--link"
						aria-controls="starmus_manual_upload_wrap_<?php echo esc_attr($instance_id); ?>"
						aria-expanded="false"
						style="font-size:0.95em;">
						<?php esc_html_e('Upload audio file', 'starmus-audio-recorder'); ?>
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
								toggle.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
							});
						}
					});
				</script>
			<?php endif; ?>

		</div>
	</form>
</div>