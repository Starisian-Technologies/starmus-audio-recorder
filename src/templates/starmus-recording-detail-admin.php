<?php
// FILE: src/templates/parts/detail-admin.php

// Ensure StarmusSettings is available (assuming it's loaded by your plugin)
use Starisian\Starmus\core\StarmusSettings;

$post_id = get_the_ID();

// Correctly get the audio URL
$audio_attachment_id = get_post_meta( $post_id, '_audio_attachment_id', true );
$audio_url           = ! empty( $audio_attachment_id ) ? wp_get_attachment_url( (int) $audio_attachment_id ) : '';

// Get all the raw data
$author_id       = get_post_field( 'post_author', $post_id );
$author          = get_userdata( $author_id );
$language        = get_the_terms( $post_id, 'language' );
$recording_type  = get_the_terms( $post_id, 'recording-type' );
$transcript_json = get_post_meta( $post_id, 'first_pass_transcription', true );
$metadata_json   = get_post_meta( $post_id, 'recording_metadata', true );
$metadata_array  = json_decode( $metadata_json, true );
?>
<article class="starmus-admin-detail">
	<header class="starmus-detail__header">
		<!-- ... header meta ... -->
		<div class="starmus-detail__meta">
			<span class="starmus-meta__date">
				<?php esc_html_e( 'Recorded on', 'starmus-audio-recorder' ); ?>
				<time datetime="<?php echo esc_attr( get_the_date( 'c', $post_id ) ); ?>">
					<?php echo esc_html( get_the_date( 'F j, Y \a\t g:i A', $post_id ) ); ?>
				</time>
			</span>
			<?php if ( $recording_type && ! is_wp_error( $recording_type ) ) : ?>
				<span class="starmus-meta__type">
					<?php esc_html_e( 'Type:', 'starmus-audio-recorder' ); ?>
					<strong><?php echo esc_html( $recording_type[0]->name ); ?></strong>
				</span>
			<?php endif; ?>
			<?php if ( $language && ! is_wp_error( $language ) ) : ?>
				<span class="starmus-meta__language">
					<?php esc_html_e( 'Language:', 'starmus-audio-recorder' ); ?>
					<strong><?php echo esc_html( $language[0]->name ); ?></strong>
				</span>
			<?php endif; ?>
		</div>
	</header>

	<!-- 1. The Audio Player -->
	<section class="starmus-detail__section">
		<h2><?php esc_html_e( 'Audio Recording', 'starmus-audio-recorder' ); ?></h2>
		<?php if ( $audio_url ) : ?>
			<audio controls preload="metadata" style="width: 100%;">
				<source src="<?php echo esc_url( $audio_url ); ?>">
			</audio>
		<?php else : ?>
			<p><strong>Audio file could not be found.</strong></p>
		<?php endif; ?>
	</section>

	<!-- 2. Parsed & Mapped ACF Fields -->
	<section class="starmus-detail__section">
		<h2><?php esc_html_e( 'Archival Metadata', 'starmus-audio-recorder' ); ?></h2>
		<dl class="starmus-info-list">
			<?php
			// Display all the individual ACF fields you have saved.
			$fields_to_display = array(
				'session_date'          => 'Session Date',
				'session_start_time'    => 'Start Time',
				'session_end_time'      => 'End Time',
				'location'              => 'Location',
				'submission_ip'         => 'Submission IP',
				'recording_equipment'   => 'Recording Equipment',
				'media_condition_notes' => 'Media Condition Notes',
			);
			foreach ( $fields_to_display as $key => $label ) {
				$value = get_post_meta( $post_id, $key, true );
				if ( ! empty( $value ) ) {
					echo '<dt>' . esc_html( $label ) . '</dt>';
					echo '<dd>' . wp_kses_post( nl2br( $value ) ) . '</dd>';
				}
			}
			?>
		</dl>
	</section>

	<!-- 3. Raw Transcription Data -->
	<section class="starmus-detail__section">
		<h2><?php esc_html_e( 'Raw Transcription Data (JSON)', 'starmus-audio-recorder' ); ?></h2>
		<?php if ( ! empty( $transcript_json ) ) : ?>
			<pre
				class="starmus-raw-json"><code><?php echo esc_html( json_encode( json_decode( $transcript_json ), JSON_PRETTY_PRINT ) ); ?></code></pre>
		<?php else : ?>
			<p><em>No transcription data was saved.</em></p>
		<?php endif; ?>
	</section>

	<!-- 4. Raw Recording Metadata -->
	<section class="starmus-detail__section">
		<h2><?php esc_html_e( 'Raw Recording Metadata (JSON)', 'starmus-audio-recorder' ); ?></h2>
		<?php if ( ! empty( $metadata_json ) ) : ?>
			<pre
				class="starmus-raw-json"><code><?php echo esc_html( json_encode( json_decode( $metadata_json ), JSON_PRETTY_PRINT ) ); ?></code></pre>
		<?php else : ?>
			<p><em>No recording metadata was saved.</em></p>
		<?php endif; ?>
	</section>

	<!-- Footer actions: Add the Edit Audio Link here -->
	<footer class="starmus-detail__footer-actions">
		<?php
		// Retrieve the edit page URL from StarmusSettings
		$starmus_settings = new StarmusSettings(); // Instantiate settings
		$edit_page_id     = (int) $starmus_settings->get( 'edit_page_id', 0 );
		$edit_page_url    = '';

		if ( $edit_page_id > 0 ) {
			$edit_page_url = get_permalink( $edit_page_id );
			if ( false === $edit_page_url ) { // get_permalink can return false
				$edit_page_url = '';
			}
		}

		// Only show the edit link if the URL is valid and the user has permission
		if ( ! empty( $edit_page_url ) && current_user_can( 'edit_post', $post_id ) ) :
			$edit_link        = add_query_arg( 'post_id', $post_id, $edit_page_url );
			$secure_edit_link = wp_nonce_url( $edit_link, 'starmus_edit_audio', 'nonce' );
			?>
			<a href="<?php echo esc_url( $secure_edit_link ); ?>" class="starmus-btn starmus-btn--primary">
				<?php esc_html_e( 'Edit Audio Submission', 'starmus-audio-recorder' ); ?>
			</a>
		<?php endif; ?>
	</footer>
</article>