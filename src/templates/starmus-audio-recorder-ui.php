<?php
/**
 * Starmus Audio Recorder UI Template - Secure Version
 *
 * @package Starmus\templates
 * @version 0.4.0
 * @since 0.2.0
 * @var string $form_id
 * @var string $consent_message
 * @var string $data_policy_url
 * @var array $recording_types
 * @var array $languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

$nonce            = wp_create_nonce( 'starmus_audio_form' );
$form_instance_id = sanitize_key( $form_id . '_' . wp_generate_uuid4() );
?>

<div id="starmus_audioWrapper_<?php echo esc_attr( $form_instance_id ); ?>" data-enabled-recorder="true" class="starmus-audio-wrapper starmus-recorder-form">
	<form id="<?php echo esc_attr( $form_instance_id ); ?>" class="starmus-audio-form" method="post" enctype="multipart/form-data">
		
		<?php wp_nonce_field( 'starmus_audio_form', 'starmus_nonce_' . $form_instance_id ); ?>
		
		<!-- Step 1: Form Details -->
		<div id="starmus_step1_<?php echo esc_attr( $form_instance_id ); ?>" class="starmus-step starmus-step-1">
			<h2><?php esc_html_e( 'Recording Details', STARMUS_TEXT_DOMAIN ); ?></h2>
			
			<div id="starmus_step1_usermsg_<?php echo esc_attr( $form_instance_id ); ?>" class="starmus-user-message" style="display:none;" role="alert" aria-live="polite"></div>
			
			<div class="starmus-field-group">
				<label for="audio_title_<?php echo esc_attr( $form_instance_id ); ?>"><?php esc_html_e( 'Title', STARMUS_TEXT_DOMAIN ); ?> *</label>
				<input type="text" id="audio_title_<?php echo esc_attr( $form_instance_id ); ?>" name="audio_title" maxlength="200" required>
			</div>
			
			<div class="starmus-field-group">
				<label for="language_<?php echo esc_attr( $form_instance_id ); ?>"><?php esc_html_e( 'Language', STARMUS_TEXT_DOMAIN ); ?> *</label>
				<select id="language_<?php echo esc_attr( $form_instance_id ); ?>" name="language" required>
					<option value=""><?php esc_html_e( 'Select Language', STARMUS_TEXT_DOMAIN ); ?></option>
					<?php if ( ! empty( $languages ) && ! is_wp_error( $languages ) ) : ?>
						<?php foreach ( $languages as $lang ) : ?>
							<option value="<?php echo esc_attr( $lang->term_id ); ?>"><?php echo esc_html( $lang->name ); ?></option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
			</div>
			
			<div class="starmus-field-group">
				<label for="recording_type_<?php echo esc_attr( $form_instance_id ); ?>"><?php esc_html_e( 'Recording Type', STARMUS_TEXT_DOMAIN ); ?> *</label>
				<select id="recording_type_<?php echo esc_attr( $form_instance_id ); ?>" name="recording_type" required>
					<option value=""><?php esc_html_e( 'Select Type', STARMUS_TEXT_DOMAIN ); ?></option>
					<?php if ( ! empty( $recording_types ) && ! is_wp_error( $recording_types ) ) : ?>
						<?php foreach ( $recording_types as $type ) : ?>
							<option value="<?php echo esc_attr( $type->term_id ); ?>"><?php echo esc_html( $type->name ); ?></option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
			</div>
			
			<div class="starmus-field-group">
				<label>
					<input type="checkbox" id="audio_consent_<?php echo esc_attr( $form_instance_id ); ?>" name="audio_consent" value="1" required>
					<?php echo wp_kses_post( $consent_message ); ?>
					<?php if ( $data_policy_url ) : ?>
						<a href="<?php echo esc_url( $data_policy_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy Policy', STARMUS_TEXT_DOMAIN ); ?></a>
					<?php endif; ?>
				</label>
			</div>
			
			<!-- Hidden fields for Geolocation data -->
			<input type="hidden" name="gps_latitude" value="">
			<input type="hidden" name="gps_longitude" value="">
			
			<button type="button" id="starmus_continue_btn_<?php echo esc_attr( $form_instance_id ); ?>" class="starmus-btn starmus-btn-primary">
				<?php esc_html_e( 'Continue to Recording', STARMUS_TEXT_DOMAIN ); ?>
			</button>
		</div>
		
		<!-- Step 2: Audio Recording -->
		<div id="starmus_step2_<?php echo esc_attr( $form_instance_id ); ?>" class="starmus-step starmus-step-2" style="display:none;">
			<h2 id="starmus_audioRecorderHeading_<?php echo esc_attr( $form_instance_id ); ?>"><?php esc_html_e( 'Record Your Audio', STARMUS_TEXT_DOMAIN ); ?></h2>
			
			<div id="starmus_recorder_container_<?php echo esc_attr( $form_instance_id ); ?>" class="starmus-recorder-container">
				<!-- Audio recorder will be injected here by JavaScript -->
			</div>
			
			<div id="starmus_loader_overlay_<?php echo esc_attr( $form_instance_id ); ?>" class="starmus_visually_hidden starmus-loader">
				<?php esc_html_e( 'Uploading...', STARMUS_TEXT_DOMAIN ); ?>
			</div>
			
			<button type="submit" id="submit_button_<?php echo esc_attr( $form_instance_id ); ?>" class="starmus-btn starmus-btn-success" disabled>
				<?php esc_html_e( 'Submit Recording', STARMUS_TEXT_DOMAIN ); ?>
			</button>
		</div>
	</form>
</div>