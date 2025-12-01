<?php
/**
 * Admin Detail Template (starmus-recording-detail-admin.php)
 *
 * PURPOSE: Full telemetry view for admins.
 * INCLUDES: Audio, Waveform, Transcript, Environment Data, Logs.
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

	if ( ! $post_id && isset( $args['post_id'] ) ) {
		$post_id = intval( $args['post_id'] );
	}

	if ( ! $post_id ) {
		throw new \Exception( 'No post ID found.' );
	}

	$settings     = new StarmusSettings();
	
	// Safe Service Instantiation
	$file_service = class_exists( \Starisian\Sparxstar\Starmus\services\StarmusFileService::class ) 
		? new StarmusFileService() 
		: null;

	// --- 1. Audio Assets ---
	$mastered_mp3_id = (int) get_post_meta( $post_id, 'mastered_mp3', true );
	$archival_wav_id = (int) get_post_meta( $post_id, 'archival_wav', true ); 
	$original_id     = (int) get_post_meta( $post_id, 'audio_files_originals', true );
	$legacy_id       = (int) get_post_meta( $post_id, '_audio_attachment_id', true );

	$get_url = function( int $att_id ) use ( $file_service ) {
		if ($att_id <= 0) {
            return '';
        }
		try {
			if ($file_service instanceof \Starisian\Sparxstar\Starmus\services\StarmusFileService) {
                return $file_service->star_get_public_url( $att_id );
            }
		} catch ( \Throwable ) {}
		return wp_get_attachment_url( $att_id ) ?: '';
	};

	$mp3_url      = $get_url( $mastered_mp3_id );
	$wav_url      = $get_url( $archival_wav_id );
	$original_url = $get_url( $original_id ?: $legacy_id );
	$playback_url = $mp3_url ?: $original_url;

	// --- 2. Telemetry & Logs ---
	$submission_ip     = get_post_meta( $post_id, 'submission_ip', true );
	$processing_log    = get_post_meta( $post_id, 'processing_log', true );
	$user_agent        = get_post_meta( $post_id, 'user_agent', true );
	$device_fp         = get_post_meta( $post_id, 'device_fingerprint', true );
	
	// --- 3. Complex Data Blobs ---
	$waveform_json     = get_post_meta( $post_id, 'waveform_json', true );
	$transcript_raw    = get_post_meta( $post_id, 'first_pass_transcription', true );
	$env_json          = get_post_meta( $post_id, 'environment_data', true );
	$annotations_json  = get_post_meta( $post_id, 'starmus_annotations', true );

	// Parse Waveform
	$waveform_data = [];
	if ( ! empty( $waveform_json ) ) {
		$decoded = is_string( $waveform_json ) ? json_decode( $waveform_json, true ) : $waveform_json;
		$waveform_data = is_array( $decoded ) ? $decoded : [];
	}

	// Parse Transcript
	$transcript_text = '';
	if ( ! empty( $transcript_raw ) ) {
		$decoded = json_decode( $transcript_raw, true );
		// If it's a JSON object with 'transcript' key, use that. If array, it might be segments.
		if ( is_array( $decoded ) || is_object( $decoded ) ) {
			$transcript_text = $decoded['transcript'] ?? ( is_string( $decoded ) ? $decoded : wp_json_encode( $decoded ) );
		} else {
			$transcript_text = $transcript_raw;
		}
	}

	// Parse Environment
	$env_data = empty( $env_json ) ? [] : json_decode( $env_json, true );

	// Parse Annotations
	$annotations = empty( $annotations_json ) ? [] : json_decode( $annotations_json, true );

	// --- 4. Standard Metadata ---
	$accession_number = get_post_meta( $post_id, 'accession_number', true );
	$location_data    = get_post_meta( $post_id, 'location', true );
	$project_id       = get_post_meta( $post_id, 'project_collection_id', true );
	
	$languages = get_the_terms( $post_id, 'language' );
	$rec_types = get_the_terms( $post_id, 'recording_type' );

	// --- 5. URLs ---
	$edit_page_slug     = $settings->get( 'edit_page_id', '' );
	$recorder_page_slug = $settings->get( 'recorder_page_id', '' );
	$edit_page_url      = $edit_page_slug ? get_permalink( get_page_by_path( $edit_page_slug ) ) : '';
	$recorder_page_url  = $recorder_page_slug ? get_permalink( get_page_by_path( $recorder_page_slug ) ) : '';

} catch ( \Throwable $throwable ) {
	echo '<div class="starmus-alert starmus-alert--error"><p>Error: ' . esc_html( $throwable->getMessage() ) . '</p></div>';
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
			<span class="starmus-badge"><strong>ID:</strong> <?php echo intval( $post_id ); ?></span>
			<span class="starmus-badge"><strong>Date:</strong> <?php echo esc_html( get_the_date( 'Y-m-d H:i', $post_id ) ); ?></span>
			<?php if ( ! empty( $languages ) && ! is_wp_error( $languages ) ) : ?>
				<span class="starmus-badge"><strong>Lang:</strong> <?php echo esc_html( $languages[0]->name ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $rec_types ) && ! is_wp_error( $rec_types ) ) : ?>
				<span class="starmus-badge"><strong>Type:</strong> <?php echo esc_html( $rec_types[0]->name ); ?></span>
			<?php endif; ?>
		</div>
	</header>

	<!-- Audio Player & Assets -->
	<section class="starmus-detail__section sparxstar-glass-card">
		<h2 id="starmus-audio-heading"><?php esc_html_e( 'Audio Assets', 'starmus-audio-recorder' ); ?></h2>

		<?php if ( $playback_url ) : ?>
			<figure class="starmus-player-wrap" style="margin-bottom: 20px;">
				<audio controls preload="metadata" style="width: 100%;" class="starmus-audio-full">
					<source src="<?php echo esc_url( $playback_url ); ?>" type="<?php echo str_contains($playback_url, '.mp3') ? 'audio/mpeg' : 'audio/webm'; ?>">
					<?php esc_html_e( 'Browser does not support audio.', 'starmus-audio-recorder' ); ?>
				</audio>
			</figure>
		<?php endif; ?>

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
					<td><?php echo $mastered_mp3_id !== 0 ? '<span style="color:var(--starmus-success);">✔ Available</span>' : '<span style="color:var(--starmus-warning);">Processing...</span>'; ?></td>
					<td><?php if ( $mp3_url ) : ?><a href="<?php echo esc_url( $mp3_url ); ?>" target="_blank" download class="starmus-btn starmus-btn--outline" style="padding:4px 8px;font-size:0.8em;">Download</a><?php endif; ?></td>
				</tr>
				<tr>
					<td><strong>Archival WAV</strong></td>
					<td><?php echo $archival_wav_id !== 0 ? '<span style="color:var(--starmus-success);">✔ Available</span>' : '<span style="color:var(--starmus-text-muted);">Not generated</span>'; ?></td>
					<td><?php if ( $wav_url ) : ?><a href="<?php echo esc_url( $wav_url ); ?>" target="_blank" download class="starmus-btn starmus-btn--outline" style="padding:4px 8px;font-size:0.8em;">Download</a><?php endif; ?></td>
				</tr>
				<tr>
					<td><strong>Original Source</strong></td>
					<td><?php echo ( $original_id || $legacy_id ) ? '<span style="color:var(--starmus-success);">✔ Available</span>' : '<span style="color:var(--starmus-danger);">MISSING</span>'; ?></td>
					<td><?php if ( $original_url ) : ?><a href="<?php echo esc_url( $original_url ); ?>" target="_blank" download class="starmus-btn starmus-btn--outline" style="padding:4px 8px;font-size:0.8em;">Download</a><?php endif; ?></td>
				</tr>
			</tbody>
		</table>
	</section>

	<!-- Waveform -->
	<?php if ( $waveform_data !== [] ) : 
		$width = 800; $height = 100;
		$count = count( $waveform_data );
		$step = max( 1, floor( $count / 800 ) ); 
		$points = [];
		$max_val = max( array_map( abs(...), $waveform_data ) ) ?: 1;
		for ( $i = 0; $i < $count; $i += $step ) {
			$val = (float) $waveform_data[$i];
			$x = ( $i / $count ) * $width;
			$y = $height - ( ( $val / $max_val ) * $height );
			$points[] = sprintf('%s,%s', $x, $y);
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
		
		<!-- LEFT: Metadata -->
		<div class="starmus-col-main">
			
			<!-- Transcription -->
			<section class="starmus-detail__section sparxstar-glass-card">
				<h2><?php esc_html_e( 'Transcription', 'starmus-audio-recorder' ); ?></h2>
				<?php if ( $transcript_text ) : ?>
					<div class="starmus-transcript-box" style="background:#f9f9f9; padding:15px; border:1px solid #eee; border-radius:4px; max-height:200px; overflow-y:auto;">
						<?php echo wp_kses_post( nl2br( $transcript_text ) ); ?>
					</div>
				<?php else : ?>
					<p class="description">No transcription data available.</p>
				<?php endif; ?>
			</section>

			<!-- Environment Data (Parsed) -->
			<section class="starmus-detail__section sparxstar-glass-card">
				<h2><?php esc_html_e( 'Environment & Device', 'starmus-audio-recorder' ); ?></h2>
				<table class="starmus-info-table">
					<tbody>
						<?php if ( ! empty( $env_data['browser'] ) ) : ?>
							<tr><th>Browser</th><td><?php echo esc_html( $env_data['browser']['name'] ?? 'Unknown' ); ?> <?php echo esc_html( $env_data['browser']['version'] ?? '' ); ?></td></tr>
						<?php endif; ?>
						<?php if ( ! empty( $env_data['os'] ) ) : ?>
							<tr><th>OS</th><td><?php echo esc_html( $env_data['os']['name'] ?? 'Unknown' ); ?> <?php echo esc_html( $env_data['os']['version'] ?? '' ); ?></td></tr>
						<?php endif; ?>
						<?php if ( ! empty( $env_data['device'] ) ) : ?>
							<tr><th>Device Type</th><td><?php echo esc_html( $env_data['device']['type'] ?? 'Desktop' ); ?></td></tr>
						<?php endif; ?>
						<tr><th>Fingerprint</th><td><code><?php echo esc_html( $device_fp ?: 'N/A' ); ?></code></td></tr>
						<tr><th>IP Address</th><td><code><?php echo esc_html( $submission_ip ?: 'Unknown' ); ?></code></td></tr>
					</tbody>
				</table>
			</section>

			<!-- Annotations -->
			<?php if ( ! empty( $annotations ) ) : ?>
			<section class="starmus-detail__section sparxstar-glass-card">
				<h2><?php esc_html_e( 'Regions / Annotations', 'starmus-audio-recorder' ); ?></h2>
				<table class="starmus-info-table">
					<thead>
						<tr><th>Label</th><th>Start</th><th>End</th></tr>
					</thead>
					<tbody>
						<?php foreach ( $annotations as $region ) : ?>
						<tr>
							<td><?php echo esc_html( $region['label'] ?? 'Region' ); ?></td>
							<td><?php echo number_format( $region['startTime'], 2 ); ?>s</td>
							<td><?php echo number_format( $region['endTime'], 2 ); ?>s</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</section>
			<?php endif; ?>

			<!-- Logs -->
			<?php if ( $processing_log ) : ?>
			<section class="starmus-detail__section sparxstar-glass-card">
				<details class="starmus-logs">
					<summary style="cursor: pointer; font-weight: 600; color: var(--starmus-primary);">View Technical Processing Log</summary>
					<pre style="background: #1e1e1e; color: #ccc; padding: 10px; overflow-x: auto; font-size: 11px; margin-top: 10px; max-height: 300px;"><?php echo esc_html( $processing_log ); ?></pre>
				</details>
			</section>
			<?php endif; ?>

		</div>

		<!-- RIGHT: Admin Actions -->
		<aside class="starmus-col-sidebar">
			<section class="starmus-detail__section sparxstar-glass-card">
				<h2><?php esc_html_e( 'Actions', 'starmus-audio-recorder' ); ?></h2>
				<div class="starmus-btn-stack">
					<?php if ( current_user_can( 'edit_post', $post_id ) ) : ?>
						<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>" class="starmus-btn starmus-btn--primary" style="justify-content:center;">Edit Metadata</a>
					<?php endif; ?>
					<?php if ( $recorder_page_url ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'recording_id', $post_id, $recorder_page_url ) ); ?>" class="starmus-btn starmus-btn--outline" style="justify-content:center;">Re-Record</a>
					<?php endif; ?>
					<?php if ( $edit_page_url ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'recording_id', $post_id, $edit_page_url ) ); ?>" class="starmus-btn starmus-btn--outline" style="justify-content:center;">Open Editor</a>
					<?php endif; ?>
				</div>
			</section>

			<!-- Archive Info -->
			<section class="starmus-detail__section sparxstar-glass-card">
				<h3>Archive Info</h3>
				<ul style="list-style:none; padding:0; margin:0; font-size:0.9em;">
					<li><strong>Collection ID:</strong> <?php echo esc_html( $project_id ?: '-' ); ?></li>
					<li><strong>Accession #:</strong> <?php echo esc_html( $accession_number ?: '-' ); ?></li>
					<li><strong>Location:</strong> <?php echo esc_html( $location_data ?: '-' ); ?></li>
				</ul>
			</section>
		</aside>

	</div>

</main>