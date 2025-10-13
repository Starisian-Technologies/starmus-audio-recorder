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
			<!-- Primary player (attachment URL) -->
			<audio controls preload="metadata" style="width: 100%;">
				<source src="<?php echo esc_url( $audio_url ); ?>">
				<?php esc_html_e( 'Your browser does not support the audio element.', 'starmus-audio-recorder' ); ?>
			</audio>

			<?php
			// Show explicit info about the attached file and generated files (MP3/WAV).
			$attached_file_path = ( $audio_attachment_id ) ? get_attached_file( (int) $audio_attachment_id ) : '';
			$archival_fs_path = ( $audio_attachment_id ) ? get_post_meta( (int) $audio_attachment_id, '_starmus_archival_path', true ) : '';
			$archival_url = '';
			$uploads = wp_get_upload_dir();
			if ( is_string( $archival_fs_path ) && ! empty( $archival_fs_path ) ) {
				$real_arch = realpath( $archival_fs_path );
				$real_base = realpath( $uploads['basedir'] );
				if ( $real_arch && $real_base && str_starts_with( $real_arch, $real_base ) ) {
					$archival_url = str_replace( $uploads['basedir'], $uploads['baseurl'], $archival_fs_path );
				}
			}
			?>

			<ul class="starmus-detail__files">
				<?php if ( ! empty( $attached_file_path ) ) : ?>
					<li><strong><?php esc_html_e( 'Attached file (filesystem):', 'starmus-audio-recorder' ); ?></strong> <?php echo esc_html( $attached_file_path ); ?></li>
				<?php endif; ?>
				<?php if ( ! empty( $audio_url ) ) : ?>
					<li><strong><?php esc_html_e( 'Attachment URL:', 'starmus-audio-recorder' ); ?></strong> <a href="<?php echo esc_url( $audio_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( wp_basename( $audio_url ) ); ?></a></li>
				<?php endif; ?>
				<?php if ( ! empty( $archival_url ) ) : ?>
					<li><strong><?php esc_html_e( 'Archival WAV:', 'starmus-audio-recorder' ); ?></strong> <a href="<?php echo esc_url( $archival_url ); ?>" download><?php echo esc_html( wp_basename( $archival_url ) ); ?></a>
						<!-- Optional WAV player -->
						<div style="margin-top:.5rem;"><audio controls preload="metadata" style="width:100%;"><source src="<?php echo esc_url( $archival_url ); ?>" type="audio/wav"><?php esc_html_e( 'Your browser does not support the audio element.', 'starmus-audio-recorder' ); ?></audio></div>
					</li>
				<?php elseif ( ! empty( $archival_fs_path ) ) : ?>
					<li><strong><?php esc_html_e( 'Archival WAV (path):', 'starmus-audio-recorder' ); ?></strong> <?php echo esc_html( $archival_fs_path ); ?> <em>(not publicly available)</em></li>
				<?php endif; ?>
			</ul>
		<?php else : ?>
			<p><strong>Audio file could not be found.</strong></p>
		<?php endif; ?>
	</section>

	<!-- Waveform visualization (uses waveform peaks stored on the attachment post) -->
	<?php
	if ( $audio_attachment_id ) :
		$waveform_peaks = get_post_meta( (int) $audio_attachment_id, '_waveform_data', true );
		if ( ! empty( $waveform_peaks ) && is_array( $waveform_peaks ) ) :
			// Prepare an SVG polyline representation. Limit points for performance.
			$width = 800;
			$height = 120;
			$count = count( $waveform_peaks );
			$max_points = 600;
			$step = max( 1, (int) floor( $count / $max_points ) );
			$max_val = max( array_map( 'abs', $waveform_peaks ) );
			if ( $max_val <= 0 ) { $max_val = 1; }
			$points = array();
			for ( $i = 0; $i < $count; $i += $step ) {
				$v = (float) $waveform_peaks[ $i ];
				$norm = $v / $max_val; // 0..1-ish
				$x = ( $i / max(1, $count - 1) ) * $width;
				$y = $height - ( $norm * $height );
				$points[] = $x . ',' . $y;
			}
			$points_str = implode( ' ', $points );
			?>
		<section class="starmus-detail__section">
			<h2><?php esc_html_e( 'Waveform Preview', 'starmus-audio-recorder' ); ?></h2>
			<div class="starmus-waveform">
				<svg viewBox="0 0 <?php echo esc_attr( $width ); ?> <?php echo esc_attr( $height ); ?>" preserveAspectRatio="none" width="100%" height="<?php echo esc_attr( $height ); ?>" role="img" aria-label="<?php esc_attr_e( 'Audio waveform preview', 'starmus-audio-recorder' ); ?>">
					<polyline fill="none" stroke="#0073aa" stroke-width="1" points="<?php echo esc_attr( $points_str ); ?>" />
				</svg>
				<details style="margin-top:.5rem;"><summary><?php esc_html_e( 'Show raw waveform data', 'starmus-audio-recorder' ); ?></summary>
					<pre class="starmus-raw-json"><code><?php echo esc_html( json_encode( $waveform_peaks, JSON_PRETTY_PRINT ) ); ?></code></pre>
				</details>
			</div>
		</section>
		<?php
		endif;
	endif;

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