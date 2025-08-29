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
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Variables passed from the render_recorder_shortcode() method in the main class.
$form_id         = $form_id ?? 'starmus_default_form';
$data_policy_url = $data_policy_url ?? '';
$consent_message = $consent_message ?? 'I consent to the recording of my voice.';
?>

<form id="<?php echo esc_attr( $form_id ); ?>" class="starmus-recorder-form" novalidate>
	<div id="starmus_audioWrapper_<?php echo esc_attr( $form_id ); ?>" class="sparxstar-audioWrapper" data-enabled-recorder>

		<!-- =================================================================== -->
		<!-- STEP 1: DETAILS SECTION (Visible by default)					  -->
		<!-- =================================================================== -->
		<div id="starmus_step_1_<?php echo esc_attr( $form_id ); ?>" class="starmus-form-step">
			<h2 class="sparxstar-h2"><?php esc_html_e( 'Step 1: Tell Us About Your Recording', 'starmus_audio_recorder' ); ?></h2>

			<div class="starmus-form-field">
				<label for="audio_title_<?php echo esc_attr( $form_id ); ?>"><?php esc_html_e( 'Title of Recording', 'starmus_audio_recorder' ); ?></label>
				<input type="text" id="audio_title_<?php echo esc_attr( $form_id ); ?>" name="audio_title" required>
				<p class="sparxstar-description">Enter either the word you are going to add or a description of the audio you are recording.</p>
			</div>

			<div class="starmus-form-field">
				<label for="language_<?php echo esc_attr( $form_id ); ?>"><?php esc_html_e( 'Language', 'starmus_audio_recorder' ); ?></label>
				<select name="language" id="language_<?php echo esc_attr( $form_id ); ?>" required>
					<option value="" disabled selected><?php esc_html_e( '-- Select a language --', 'starmus_audio_recorder' ); ?></option>
					<?php if ( ! empty( $languages ) && ! is_wp_error( $languages ) ) : ?>
						<?php foreach ( $languages as $term ) : ?>
							<option value="<?php echo esc_attr( $term->slug ); ?>"><?php echo esc_html( $term->name ); ?></option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
			</div>

			<div class="starmus-form-field">
				<label for="recording_type_<?php echo esc_attr( $form_id ); ?>"><?php esc_html_e( 'Type of Recording', 'starmus_audio_recorder' ); ?></label>
				<select name="recording_type" id="recording_type_<?php echo esc_attr( $form_id ); ?>" required>
					<option value="" disabled selected><?php esc_html_e( '-- Select a type --', 'starmus_audio_recorder' ); ?></option>
					<?php if ( ! empty( $recording_types ) && ! is_wp_error( $recording_types ) ) : ?>
						<?php foreach ( $recording_types as $term ) : ?>
							<option value="<?php echo esc_attr( $term->slug ); ?>"><?php echo esc_html( $term->name ); ?></option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
			</div>

			<div class="starmus-form-field starmus-consent-field">
				<label for="audio_consent_<?php echo esc_attr( $form_id ); ?>">
					<input type="checkbox" id="audio_consent_<?php echo esc_attr( $form_id ); ?>" name="audio_consent" required>
					<?php echo wp_kses_post( $consent_message ); ?>
				</label>
				<?php if ( ! empty( $data_policy_url ) ) : ?>
					<a href="<?php echo esc_url( $data_policy_url ); ?>" target="_blank" rel="noopener" class="starmus-data-policy-link"><?php esc_html_e( '(View data policy)', 'starmus_audio_recorder' ); ?></a>
				<?php endif; ?>
			</div>

			<div id="starmus_step_1_error_<?php echo esc_attr( $form_id ); ?>" class="starmus-error-message" style="display: none;" role="alert"></div>

			<button type="button" id="starmus_continue_btn_<?php echo esc_attr( $form_id ); ?>" class="sparxstar_button">
				<?php esc_html_e( 'Continue to Recording', 'starmus_audio_recorder' ); ?>
			</button>
			
			<!-- FIX: New status message for the transition between Step 1 and 2 -->
			<div id="starmus_step_1_status_<?php echo esc_attr( $form_id ); ?>" class="starmus-status-message" style="display: none;" role="status">
				<?php esc_html_e( 'Preparing recorder...', 'starmus_audio_recorder' ); ?>
			</div>
		</div>

		<!-- Step 2 and the rest of the form remains exactly the same... -->
		<div id="starmus_step_2_<?php echo esc_attr( $form_id ); ?>" class="starmus-form-step" style="display: none;">
			<!-- ... -->
		</div>
		<div id="sparxstar_loader_overlay_<?php echo esc_attr( $form_id ); ?>" class="sparxstar_loader_overlay sparxstar_visually_hidden" role="alert" aria-live="assertive">
			<!-- ... -->
		</div>

	</div>
</form>
