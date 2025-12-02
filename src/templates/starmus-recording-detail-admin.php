<?php
/**
 * Admin Detail Template (starmus-recording-detail-admin.php) - FINAL ROBUST VERSION
 *
 * FIXED: Prioritizes parsing telemetry from the saved JSON blobs 
 * (environment_data, runtime_metadata) for reliable display.
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
	$file_service = class_exists( 'Starisian\Sparxstar\Starmus\services\StarmusFileService' ) 
		? new StarmusFileService() 
		: null;

	// --- 1. Audio Assets ---
	$mastered_mp3_id = (int) get_post_meta( $post_id, 'mastered_mp3', true );
	$archival_wav_id = (int) get_post_meta( $post_id, 'archival_wav', true ); 
	$original_id     = (int) get_post_meta( $post_id, 'audio_files_originals', true );

	$get_url = function( int $att_id ) use ( $file_service ) {
		if ( $att_id <= 0 ) return '';
		try {
			if ( $file_service ) return $file_service->star_get_public_url( $att_id );
		} catch ( \Throwable $e ) {}
		return wp_get_attachment_url( $att_id ) ?: '';
	};

	$mp3_url      = $get_url( $mastered_mp3_id );
	$wav_url      = $get_url( $archival_wav_id );
	$original_url = $get_url( $original_id ); // Assuming _audio_attachment_id is covered by audio_files_originals
	$playback_url = $mp3_url ?: $original_url;

	// --- 2. Telemetry & Logs ---
	$processing_log    = get_post_meta( $post_id, 'processing_log', true );
	$transcript_raw    = get_post_meta( $post_id, 'first_pass_transcription', true );
    $runtime_raw       = get_post_meta( $post_id, 'runtime_metadata', true );

	// --- 3. Robust Data Parsing (Reads Saved Blobs) ---
	$env_json_raw      = get_post_meta( $post_id, 'environment_data', true );
	$env_data          = ! empty( $env_json_raw ) ? json_decode( $env_json_raw, true ) : [];
    
    // Fallback to post_meta if UEC parsing failed to save individual fields
    $fingerprint_display = get_post_meta( $post_id, 'device_fingerprint', true ) ?: ( $env_data['identifiers']['visitorId'] ?? 'N/A' );
    $submission_ip_display = get_post_meta( $post_id, 'submission_ip', true ) ?: ( $env_data['identifiers']['ipAddress'] ?? 'Unknown' );
    $mic_profile_display = get_post_meta( $post_id, 'mic_profile', true ) ?: ( $env_data['technical']['profile']['overallProfile'] ?? 'N/A' );
    
    // Construct User Agent from saved environment JSON (Robust Fallback)
    $ua_construct = ($env_data['technical']['raw']['browser']['name'] ?? '') . ' ' . ($env_data['technical']['raw']['browser']['version'] ?? '') . ' (' . ($env_data['deviceDetails']['os']['name'] ?? '') . ')';
    $user_agent_display = trim($ua_construct) ?: (get_post_meta( $post_id, 'user_agent', true ) ?: 'N/A');

	// Parse Transcript
	$transcript_text = '';
	if ( ! empty( $transcript_raw ) ) {
		$decoded = is_string( $transcript_raw ) ? json_decode( $transcript_raw, true ) : $transcript_raw;
		if ( is_array( $decoded ) && isset( $decoded['transcript'] ) ) {
			$transcript_text = $decoded['transcript'];
		} else {
			$transcript_text = $transcript_raw;
		}
	}
    
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
					<source src="<?php echo esc_url( $playback_url ); ?>" type="<?php echo strpos($playback_url, '.mp3') !== false ? 'audio/mpeg' : 'audio/webm'; ?>">
					<?php esc_html_e( 'Browser does not support audio.', 'starmus-audio-recorder' ); ?>
				</audio>
			</figure>
		<?php else : ?>
			<div class="starmus-alert starmus-alert--warning">
				<p><?php esc_html_e( 'No audio files attached.', 'starmus-audio-recorder' ); ?></p>
			</div>
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
					<td><?php echo $mastered_mp3_id ? '<span style="color:var(--starmus-success);">✔ Available</span>' : '<span style="color:var(--starmus-warning);">Processing...</span>'; ?></td>
					<td><?php if ( $mp3_url ) : ?><a href="<?php echo esc_url( $mp3_url ); ?>" target="_blank" download class="starmus-btn starmus-btn--outline" style="padding:4px 8px;font-size:0.8em;">Download</a><?php endif; ?></td>
				</tr>
				<tr>
					<td><strong>Archival WAV</strong></td>
					<td><?php echo $archival_wav_id ? '<span style="color:var(--starmus-success);">✔ Available</span>' : '<span style="color:var(--starmus-text-muted);">Not generated</span>'; ?></td>
					<td><?php if ( $wav_url ) : ?><a href="<?php echo esc_url( $wav_url ); ?>" target="_blank" download class="starmus-btn starmus-btn--outline" style="padding:4px 8px;font-size:0.8em;">Download</a><?php endif; ?></td>
				</tr>
				<tr>
					<td><strong>Original Source</strong></td>
					<td><?php echo ( $original_id ) ? '<span style="color:var(--starmus-success);">✔ Available</span>' : '<span style="color:var(--starmus-danger);">MISSING</span>'; ?></td>
					<td><?php if ( $original_url ) : ?><a href="<?php echo esc_url( $original_url ); ?>" target="_blank" download class="starmus-btn starmus-btn--outline" style="padding:4px 8px;font-size:0.8em;">Download</a><?php endif; ?></td>
				</tr>
			</tbody>
		</table>
	</section>

	<!-- Waveform -->
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
						<tr><th>IP Address</th><td><code><?php echo esc_html( $submission_ip_display ); ?></code></td></tr>
						<tr><th>Fingerprint</th><td><code><?php echo esc_html( $fingerprint_display ); ?></code></td></tr>
						<tr><th>Browser/OS</th><td><?php echo esc_html( $user_agent_display ); ?></td></tr>
						<tr><th>Mic Profile</th><td><?php echo esc_html( $mic_profile_display ); ?></td></tr>
						<?php if ( ! empty( $runtime_raw ) ) : ?>
						<tr><th>Raw Runtime</th><td><details><summary>View JSON</summary><pre style="font-size:0.8em; white-space:pre-wrap;"><?php echo esc_html( $runtime_raw ); ?></pre></details></td></tr>
						<?php endif; ?>
						<?php if ( ! empty( $env_json_raw ) ) : ?>
						<tr><th>Raw Environment</th><td><details><summary>View JSON</summary><pre style="font-size:0.8em; white-space:pre-wrap;"><?php echo esc_html( $env_json_raw ); ?></pre></details></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</section>

			<!-- Logs -->
			<?php if ( $processing_log ) : ?>
			<section class="starmus-detail__section sparxstar-glass-card">
				<details class="starmus-logs">
					<summary style="cursor: pointer; font-weight: 600; color: var(--starmus-primary);">View Technical Processing Log</summary>
					<div style="margin-top: 10px;">
						<pre class="starmus-processing-log" style="max-height: 300px; overflow-y: auto;"><?php echo esc_html( $processing_log ); ?></pre>
					</div>
				</details>
			</section>
			<?php endif; ?>

		</div>

		<!-- RIGHT: Admin Actions -->
		<aside class="starmus-col-sidebar">
			<section class="starmus-detail__section sparxstar-glass-card">
				<h2><?php esc_html_e( 'Admin Actions', 'starmus-audio-recorder' ); ?></h2>
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