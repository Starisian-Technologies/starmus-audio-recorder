<?php
/**
 * Starmus Audio Recorder UI Template - Final, Secure, and Accessible
 *
 * This template provides the HTML structure for the audio recorder. It is designed
 * to be controlled by the JavaScript modules, with containers for the dynamic UI
 * and the Tier C fallback for legacy browsers.
 *
 * @package Starmus\templates
 * @version 0.6.2
 * @since   0.4.5
 * @var string $form_id         Base ID for the form, passed from the shortcode.
 * @var string $consent_message The user consent message.
 * @var string $data_policy_url The URL to the data policy.
 * @var array  $recording_types An array of recording type terms.
 * @var array  $languages       An array of language terms.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// ** CRITICAL **
// A unique instance ID is generated to ensure multiple forms on one page do not conflict.
// All JavaScript and CSS selectors will use this unique ID.
$instance_id = 'starmus_form_' . sanitize_key( $form_id . '_' . wp_generate_uuid4() );
?>

<!-- Main wrapper with the class our unified CSS is targeting -->
<div class="starmus-recorder-form">

	<!-- The form ID is the UNIQUE instance ID that all JS modules will reference -->
	<form id="<?php echo esc_attr( $instance_id ); ?>" class="starmus-audio-form" method="post" enctype="multipart/form-data" novalidate>

		<?php wp_nonce_field( 'starmus_audio_form', 'starmus_nonce_' . $instance_id ); ?>

		<!-- ==================================================================== -->
		<!-- Step 1: Form Details                                                 -->
		<!-- ==================================================================== -->
		<div id="starmus_step1_<?php echo esc_attr( $instance_id ); ?>" class="starmus-step starmus-step-1">
			<h2><?php esc_html_e( 'Recording Details', 'starmus-audio-recorder' ); ?></h2>

			<!-- This single message area is used for all validation and status messages in Step 1 -->
			<div id="starmus_step1_usermsg_<?php echo esc_attr( $instance_id ); ?>" class="starmus-user-message" style="display:none;" role="alert" aria-live="polite"></div>

			<div class="starmus-field-group">
				<label for="starmus_title_<?php echo esc_attr( $instance_id ); ?>"><?php esc_html_e( 'Title', 'starmus-audio-recorder' ); ?> <span class="starmus-required">*</span></label>
				<input type="text" id="starmus_title_<?php echo esc_attr( $instance_id ); ?>" name="audio_title" maxlength="200" required>
			</div>

			<div class="starmus-field-group">
				<label for="starmus_language_<?php echo esc_attr( $instance_id ); ?>"><?php esc_html_e( 'Language', 'starmus-audio-recorder' ); ?> <span class="starmus-required">*</span></label>
				<select id="starmus_language_<?php echo esc_attr( $instance_id ); ?>" name="language" required>
					<option value=""><?php esc_html_e( 'Select Language', 'starmus-audio-recorder' ); ?></option>
					<?php if ( ! empty( $languages ) && is_array( $languages ) ) : ?>
						<?php foreach ( $languages as $lang ) : ?>
							<option value="<?php echo esc_attr( $lang->term_id ); ?>"><?php echo esc_html( $lang->name ); ?></option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
			</div>

			<div class="starmus-field-group">
				<label for="starmus_recording_type_<?php echo esc_attr( $instance_id ); ?>"><?php esc_html_e( 'Recording Type', 'starmus-audio-recorder' ); ?> <span class="starmus-required">*</span></label>
				<select id="starmus_recording_type_<?php echo esc_attr( $instance_id ); ?>" name="recording_type" required>
					<option value=""><?php esc_html_e( 'Select Type', 'starmus-audio-recorder' ); ?></option>
					<?php if ( ! empty( $recording_types ) && is_array( $recording_types ) ) : ?>
						<?php foreach ( $recording_types as $type ) : ?>
							<option value="<?php echo esc_attr( $type->term_id ); ?>"><?php echo esc_html( $type->name ); ?></option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
			</div>

			<!-- ==================================================================== -->
			<!-- REFACTORED: Formal and Accessible Consent Section                    -->
			<!-- ==================================================================== -->
			<fieldset class="starmus-consent-fieldset">
				<legend class="starmus-fieldset-legend"><?php esc_html_e( 'Data Usage Consent', 'starmus-audio-recorder' ); ?></legend>
				<div class="starmus-consent-field">
					<input type="checkbox" id="starmus_consent_<?php echo esc_attr( $instance_id ); ?>" name="audio_consent" value="1" required>
					<label for="starmus_consent_<?php echo esc_attr( $instance_id ); ?>">
						<?php echo wp_kses_post( $consent_message ); ?>
						<?php if ( ! empty( $data_policy_url ) ) : ?>
							<a href="<?php echo esc_url( $data_policy_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy Policy', 'starmus-audio-recorder' ); ?></a>
						<?php endif; ?>
					</label>
				</div>
			</fieldset>

			<!-- Hidden fields for metadata capture, populated by JS -->
			<input type="hidden" name="gps_latitude" value="">
			<input type="hidden" name="gps_longitude" value="">
			<input type="hidden" name="first-pass-transcription" value="">
			<input type="hidden" name="recording_metadata" value="">

			<button type="button" id="starmus_continue_btn_<?php echo esc_attr( $instance_id ); ?>" class="starmus-btn starmus-btn--primary">
				<?php esc_html_e( 'Continue to Recording', 'starmus-audio-recorder' ); ?>
			</button>
		</div>

		<!-- ==================================================================== -->
		<!-- Step 2: Audio Recording                                              -->
		<!-- ==================================================================== -->
		<div id="starmus_step2_<?php echo esc_attr( $instance_id ); ?>" class="starmus-step starmus-step-2" style="display:none;">
			<h2 id="starmus_audioRecorderHeading_<?php echo esc_attr( $instance_id ); ?>" tabindex="-1"><?php esc_html_e( 'Record Your Audio', 'starmus-audio-recorder' ); ?></h2>

			<!-- The UI Controller will build the live recorder interface inside this container -->
			<div id="starmus_recorder_container_<?php echo esc_attr( $instance_id ); ?>" class="starmus-recorder-container">
				<!-- JS builds the UI here to keep logic out of the template -->
			</div>

			<!-- ==================================================================== -->
			<!-- NEW: Tier C Fallback for Legacy Browsers (e.g., in Gambia)         -->
			<!-- ==================================================================== -->
			<div id="starmus_fallback_container_<?php echo esc_attr( $instance_id ); ?>" class="starmus-fallback-container" style="display:none;">
				<p><?php esc_html_e( 'Live recording is not supported on this browser.', 'starmus-audio-recorder' ); ?></p>
				<label for="starmus_fallback_input_<?php echo esc_attr( $instance_id ); ?>"><?php esc_html_e( 'Upload an audio file instead:', 'starmus-audio-recorder' ); ?></label>
				<input type="file" id="starmus_fallback_input_<?php echo esc_attr( $instance_id ); ?>" name="audio_file" accept="audio/*">
			</div>

			<!-- The loading spinner overlay for submissions -->
			<div id="starmus_loader_overlay_<?php echo esc_attr( $instance_id ); ?>" class="starmus-loader" style="display:none;">
				<?php esc_html_e( 'Uploading...', 'starmus-audio-recorder' ); ?>
			</div>

			<!-- The final submit button. It is disabled until a recording is complete. -->
			<button type="submit" id="starmus_submit_btn_<?php echo esc_attr( $instance_id ); ?>" class="starmus-btn starmus-btn--primary" disabled>
				<?php esc_html_e( 'Submit Recording', 'starmus-audio-recorder' ); ?>
			</button>
		</div>
	</form>
</div>
