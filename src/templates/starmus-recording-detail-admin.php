<?php
/**
 * Admin Detail Template (starmus-recording-detail-admin.php)
 *
 * PURPOSE: Full telemetry view for admins.
 * INCLUDES: IP, Logs, Waveforms, Raw File Access, Technical Metadata.
 *
 * @package Starisian\Starmus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\services\StarmusFileService;

// === 1. INITIALIZATION & DATA RESOLUTION ===

try {
	$post_id = get_the_ID();

	// Fallback for AJAX/Context
	if ( ! $post_id && isset( $args['post_id'] ) ) {
		$post_id = intval( $args['post_id'] );
	}

	if ( ! $post_id ) {
		throw new \Exception( 'No post ID found.' );
	}

	$settings     = new StarmusSettings();
	
	// Safe Service Instantiation
	$file_service = class_exists( 'Starisian\Sparxstar\Starmus\services\StarmusFileService' ) 
		? new StarmusFileService() 
		: null;

	// --- 1. Resolve Audio IDs (Using Verified Keys) ---
	$mastered_mp3_id = (int) get_post_meta( $post_id, 'mastered_mp3', true );
	$archival_wav_id = (int) get_post_meta( $post_id, 'archival_wav', true );
	$original_id     = (int) get_post_meta( $post_id, 'audio_files_originals', true );
	$legacy_id       = (int) get_post_meta( $post_id, '_audio_attachment_id', true );

	// Helper to get URL (Try Service -> Fallback WP)
	$get_url = function( int $att_id ) use ( $file_service ) {
		if ( $att_id <= 0 ) return '';
		try {
			if ( $file_service ) return $file_service->star_get_public_url( $att_id );
		} catch ( \Throwable $e ) {}
		return wp_get_attachment_url( $att_id ) ?: '';
	};

	$mp3_url      = $get_url( $mastered_mp3_id );
	$wav_url      = $get_url( $archival_wav_id );
	$original_url = $get_url( $original_id ?: $legacy_id );

	// Playback Priority: MP3 > Original > Legacy
	$playback_url = $mp3_url ?: $original_url;

	// --- 2. Admin Specific Metadata (Telemetry) ---
	$submission_ip     = get_post_meta( $post_id, 'submission_ip', true );
	$processing_log    = get_post_meta( $post_id, 'processing_log', true );
	$user_agent        = get_post_meta( $post_id, 'user_agent', true );
	
	// --- 3. Waveform Data ---
	$waveform_json     = get_post_meta( $post_id, 'waveform_json', true );
	$waveform_data     = [];
	if ( ! empty( $waveform_json ) ) {
		$decoded = is_string( $waveform_json ) ? json_decode( $waveform_json, true ) : $waveform_json;
		$waveform_data = is_array( $decoded ) ? $decoded : [];
	}

	// --- 4. Standard Metadata ---
	$accession_number = get_post_meta( $post_id, 'accession_number', true );
	$location_data    = get_post_meta( $post_id, 'location', true );
	$project_id       = get_post_meta( $post_id, 'project_collection_id', true );
	
	$languages = get_the_terms( $post_id, 'language' );
	$rec_types = get_the_terms( $post_id, 'recording_type' );

	// --- 5. Action URLs ---
	$edit_page_slug     = $settings->get( 'edit_page_id', '' );
	$recorder_page_slug = $settings->get( 'recorder_page_id', '' );
	$edit_page_url      = $edit_page_slug ? get_permalink( get_page_by_path( $edit_page_slug ) ) : '';
	$recorder_page_url  = $recorder_page_slug ? get_permalink( get_page_by_path( $recorder_page_slug ) ) : '';

} catch ( \Throwable $e ) {
	echo '<div class="starmus-alert starmus-alert--error"><p>Error: ' . esc_html( $e->getMessage() ) . '</p></div>';
	return;
}
?>

<main class="starmus-admin-detail" id="starmus-detail-<?php echo esc_attr( $post_id ); ?>">
	
	<!-- Header -->
	<header class="starmus-detail__header">
		<h1>
			<span class="screen-reader-text"><?php esc_html_e( 'Recording Title:', 'starmus-audio-recorder' ); ?></span>
			<?php echo esc_html( get_the_title( $post_id ) ); ?>
		</h1>
		
		<div class="starmus-detail__meta-badges">
			<span class="starmus-badge">
				<strong>ID:</strong> <?php echo intval( $post_id ); ?>
			</span>
			<span class="starmus-badge">
				<strong>Date:</strong> <?php echo esc_html( get_the_date( 'Y-m-d H:i', $post_id ) ); ?>
			</span>
			<?php if ( ! empty( $languages ) && ! is_wp_error( $languages ) ) : ?>
				<span class="starmus-badge">
					<strong>Lang:</strong> <?php echo esc_html( $languages[0]->name ); ?>
				</span>
			<?php endif; ?>
			<?php if ( ! empty( $rec_types ) && ! is_wp_error( $rec_types ) ) : ?>
				<span class="starmus-badge">
					<strong>Type:</strong> <?php echo esc_html( $rec_types[0]->name ); ?>
				</span>
			<?php endif; ?>
		</div>
	</header>

	<!-- Audio Player & File Access (Admin View) -->
	<section class="starmus-detail__section sparxstar-glass-card">
		<h2 id="starmus-audio-heading"><?php esc_html_e( 'Audio Assets', 'starmus-audio-recorder' ); ?></h2>

		<?php if ( $playback_url ) : ?>
			<figure class="starmus-player-wrap" style="margin-bottom: 20px;">
				<audio controls preload="metadata" style="width: 100%;" class="starmus-audio-full">
					<source src="<?php echo esc_url( $playback_url ); ?>" type="<?php echo strpos($playback_url, '.mp3') !== false ? 'audio/mpeg' : 'audio/webm'; ?>">
					<?php esc_html_e( 'Browser does not support audio.', 'starmus-audio-recorder' ); ?>
				</audio>
			</figure>
		<?php else : ?>
			<div class="starmus-alert starmus-alert--warning">
				<p><?php esc_html_e( 'No audio files attached.', 'starmus-audio-recorder' ); ?></p>
			</div>
		<?php endif; ?>

		<!-- Admin File Table -->
		<table class="starmus-info-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Asset Type', 'starmus-audio-recorder' ); ?></th>
					<th><?php esc_html_e( 'Status', 'starmus-audio-recorder' ); ?></th>
					<th><?php esc_html_e( 'Action', 'starmus-audio-recorder' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong>Mastered MP3</strong></td>
					<td>
						<?php if ( $mastered_mp3_id ) : ?>
							<span style="color:var(--starmus-success);">✔ Available (ID: <?php echo intval( $mastered_mp3_id ); ?>)</span>
						<?php else : ?>
							<span style="color:var(--starmus-warning);">Processing...</span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $mp3_url ) : ?>
							<a href="<?php echo esc_url( $mp3_url ); ?>" target="_blank" download class="starmus-btn starmus-btn--outline" style="padding: 5px 10px; font-size: 0.8rem;">Download MP3</a>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><strong>Archival WAV</strong></td>
					<td>
						<?php if ( $archival_wav_id ) : ?>
							<span style="color:var(--starmus-success);">✔ Available (ID: <?php echo intval( $archival_wav_id ); ?>)</span>
						<?php else : ?>
							<span style="color:var(--starmus-text-muted);">Not generated</span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $wav_url ) : ?>
							<a href="<?php echo esc_url( $wav_url ); ?>" target="_blank" download class="starmus-btn starmus-btn--outline" style="padding: 5px 10px; font-size: 0.8rem;">Download WAV</a>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><strong>Original Source</strong></td>
					<td>
						<?php if ( $original_id || $legacy_id ) : ?>
							<span style="color:var(--starmus-success);">✔ Available (ID: <?php echo intval( $original_id ?: $legacy_id ); ?>)</span>
						<?php else : ?>
							<span style="color:var(--starmus-danger);">MISSING</span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $original_url ) : ?>
							<a href="<?php echo esc_url( $original_url ); ?>" target="_blank" download class="starmus-btn starmus-btn--outline" style="padding: 5px 10px; font-size: 0.8rem;">Download Source</a>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
	</section>

	<!-- Waveform (Admin Only Visualization) -->
	<?php if ( ! empty( $waveform_data ) ) : 
		$width = 800; $height = 100;
		$count = count( $waveform_data );
		$step = max( 1, floor( $count / 800 ) ); 
		$points = [];
		$max_val = max( array_map( 'abs', $waveform_data ) ) ?: 1;

		for ( $i = 0; $i < $count; $i += $step ) {
			$val = (float) $waveform_data[$i];
			$x = ( $i / $count ) * $width;
			$y = $height - ( ( $val / $max_val ) * $height );
			$points[] = "$x,$y";
		}
	?>
	<section class="starmus-detail__section sparxstar-glass-card">
		<h2><?php esc_html_e( 'Waveform Data', 'starmus-audio-recorder' ); ?></h2>
		<figure class="starmus-waveform-container" style="background:#f0f0f1; border:1px solid #ddd; padding:10px; border-radius: 8px;">
			<svg viewBox="0 0 <?php echo $width; ?> <?php echo $height; ?>" preserveAspectRatio="none" width="100%" height="<?php echo $height; ?>">
				<polyline fill="none" stroke="#2271b1" stroke-width="1" points="<?php echo esc_attr( implode( ' ', $points ) ); ?>" />
			</svg>
		</figure>
	</section>
	<?php endif; ?>

	<div class="starmus-grid-layout">
		
		<!-- LEFT: Metadata & Telemetry -->
		<div class="starmus-col-main">
			
			<section class="starmus-detail__section sparxstar-glass-card">
				<h2><?php esc_html_e( 'Archive Metadata', 'starmus-audio-recorder' ); ?></h2>
				<table class="starmus-info-table">
					<tbody>
						<tr><th scope="row">Collection ID</th><td><?php echo esc_html( $project_id ?: '-' ); ?></td></tr>
						<tr><th scope="row">Accession #</th><td><?php echo esc_html( $accession_number ?: '-' ); ?></td></tr>
						<tr><th scope="row">Location</th><td><?php echo esc_html( $location_data ?: '-' ); ?></td></tr>
					</tbody>
				</table>
			</section>

			<!-- Technical / Telemetry (Admin Only) -->
			<section class="starmus-detail__section sparxstar-glass-card">
				<h2><?php esc_html_e( 'Technical Telemetry', 'starmus-audio-recorder' ); ?></h2>
				<table class="starmus-info-table">
					<tbody>
						<tr>
							<th scope="row">Submission IP</th>
							<td><code><?php echo esc_html( $submission_ip ?: 'Unknown' ); ?></code></td>
						</tr>
						<tr>
							<th scope="row">User Agent</th>
							<td style="word-break: break-all; font-size: 0.85em;"><?php echo esc_html( $user_agent ?: '-' ); ?></td>
						</tr>
					</tbody>
				</table>

				<?php if ( $processing_log ) : ?>
				<details class="starmus-logs" style="margin-top: 1rem; border-top: 1px solid var(--starmus-glass-border-color); padding-top: 1rem;">
					<summary style="cursor: pointer; font-weight: 600; color: var(--starmus-primary);">View FFmpeg Processing Log</summary>
					<div style="margin-top: 10px;">
						<pre class="starmus-processing-log" style="max-height: 300px; overflow-y: auto;"><?php echo esc_html( $processing_log ); ?></pre>
					</div>
				</details>
				<?php endif; ?>
			</section>

		</div>

		<!-- RIGHT: Actions -->
		<aside class="starmus-col-sidebar">
			<section class="starmus-detail__section sparxstar-glass-card">
				<h2><?php esc_html_e( 'Admin Actions', 'starmus-audio-recorder' ); ?></h2>
				
				<div class="starmus-btn-stack">
					<?php if ( current_user_can( 'edit_post', $post_id ) ) : ?>
						<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>" class="starmus-btn starmus-btn--primary" style="justify-content: center;">
							Edit Post Meta
						</a>
					<?php endif; ?>

					<?php if ( $recorder_page_url ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'recording_id', $post_id, $recorder_page_url ) ); ?>" class="starmus-btn starmus-btn--outline" style="justify-content: center;">
							Launch Re-Recorder
						</a>
					<?php endif; ?>

					<?php if ( $edit_page_url ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'recording_id', $post_id, $edit_page_url ) ); ?>" class="starmus-btn starmus-btn--outline" style="justify-content: center;">
							Open Audio Editor
						</a>
					<?php endif; ?>
				</div>
			</section>
		</aside>

	</div>

</main>