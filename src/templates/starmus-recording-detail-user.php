<?php
/**
 * User Detail Template - Simplified View (starmus-recording-detail-user.php)
 *
 * Loaded by StarmusShortcodeLoader::render_submission_detail_shortcode()
 * when the current user is the author of the recording.
 *
 * @package Starisian\Starmus\templates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\services\StarmusFileService;

// === 1. INITIALIZATION & DATA RESOLUTION ===

try {
	// 1. Get the current Post ID (Works inside Shortcode and Filter contexts)
	$post_id = get_the_ID();

	// Fallback if specific ID passed via args (future proofing)
	if ( ! $post_id && isset( $args['post_id'] ) ) {
		$post_id = intval( $args['post_id'] );
	}

	if ( ! $post_id ) {
		// If loaded outside a loop context, return empty or error
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Starmus Detail] No Post ID found in context.' );
		}
		return;
	}

	// 2. Instantiate Services (Since Loader doesn't pass them down)
	$settings     = new StarmusSettings();
	
	// Check if FileService exists before instantiating to prevent fatal errors
	$file_service = class_exists( \Starisian\Sparxstar\Starmus\services\StarmusFileService::class ) 
		? new StarmusFileService() 
		: null;

	// --- Resolve Audio Logic (Based on CLI Keys) ---
	$mastered_mp3_id = (int) get_post_meta( $post_id, 'mastered_mp3', true );
	$original_id     = (int) get_post_meta( $post_id, 'audio_files_originals', true );
	$legacy_id       = (int) get_post_meta( $post_id, '_audio_attachment_id', true );

	// Priority: Mastered > Original > Legacy
	$playback_id = 0;
	if ( $mastered_mp3_id > 0 ) {
		$playback_id = $mastered_mp3_id;
	} elseif ( $original_id > 0 ) {
		$playback_id = $original_id;
	} elseif ( $legacy_id > 0 ) {
		$playback_id = $legacy_id;
	}
	
	// 3. Resolve Public URL
	$playback_url = '';
	if ( $playback_id > 0 ) {
		try {
			if ( $file_service instanceof \Starisian\Sparxstar\Starmus\services\StarmusFileService ) {
				$playback_url = $file_service->star_get_public_url( $playback_id );
			} else {
				throw new Exception( 'Service unavailable' );
			}
		} catch ( \Throwable ) {
			$playback_url = wp_get_attachment_url( $playback_id );
		}
	}

	// --- Resolve Duration ---
	$duration_formatted = '';
	$duration_sec = get_post_meta( $post_id, 'audio_duration', true );

	if ( $duration_sec ) {
		$duration_formatted = gmdate( 'i:s', intval( $duration_sec ) );
	} elseif ( $playback_id > 0 ) {
		$att_meta = wp_get_attachment_metadata( $playback_id );
		if ( isset( $att_meta['length_formatted'] ) ) {
			$duration_formatted = $att_meta['length_formatted'];
		} elseif ( isset( $att_meta['length'] ) ) {
			$duration_formatted = gmdate( 'i:s', intval( $att_meta['length'] ) );
		}
	}

	// --- Fetch Metadata ---
	$accession_number = get_post_meta( $post_id, 'accession_number', true );
	$location_data    = get_post_meta( $post_id, 'location', true );
	
	// --- Taxonomies ---
	$languages = get_the_terms( $post_id, 'language' );
	$rec_types = get_the_terms( $post_id, 'recording_type' );

	// --- Action URLs ---
	$edit_page_slug     = $settings->get( 'edit_page_id', '' );
	$recorder_page_slug = $settings->get( 'recorder_page_id', '' );
	$edit_page_url      = $edit_page_slug ? get_permalink( get_page_by_path( $edit_page_slug ) ) : '';
	$recorder_page_url  = $recorder_page_slug ? get_permalink( get_page_by_path( $recorder_page_slug ) ) : '';

} catch ( \Throwable $throwable ) {
	// Fail silently in production, log in debug
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[Starmus Detail Error] ' . $throwable->getMessage() );
	}
	echo '<div class="starmus-alert starmus-alert--error"><p>' . esc_html__( 'Unable to load recording details.', 'starmus-audio-recorder' ) . '</p></div>';
	return;
}
?>

<main class="starmus-user-detail" id="starmus-record-<?php echo esc_attr( $post_id ); ?>">
	
	<!-- Header: Title & Badges -->
	<header class="starmus-header-clean">
		<h1 class="starmus-title"><?php echo esc_html( get_the_title( $post_id ) ); ?></h1>
		
		<div class="starmus-meta-row">
			<span class="starmus-meta-item">
				<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
				<?php echo esc_html( get_the_date( 'F j, Y', $post_id ) ); ?>
			</span>
			
			<?php if ( ! empty( $languages ) && ! is_wp_error( $languages ) ) : ?>
				<span class="starmus-tag starmus-tag--lang">
					<?php echo esc_html( $languages[0]->name ); ?>
				</span>
			<?php endif; ?>

			<?php if ( ! empty( $rec_types ) && ! is_wp_error( $rec_types ) ) : ?>
				<span class="starmus-tag starmus-tag--type">
					<?php echo esc_html( $rec_types[0]->name ); ?>
				</span>
			<?php endif; ?>
		</div>
	</header>

	<div class="starmus-layout-split">
		
		<!-- Left: Player & Info -->
		<div class="starmus-content-main">
			
			<!-- Audio Player -->
			<section class="starmus-player-card sparxstar-glass-card">
				<?php if ( $playback_url ) : ?>
					<audio controls preload="metadata" class="starmus-audio-full">
						<source src="<?php echo esc_url( $playback_url ); ?>" type="<?php echo str_contains($playback_url, '.mp3') ? 'audio/mpeg' : 'audio/webm'; ?>">
						<?php esc_html_e( 'Your browser does not support the audio player.', 'starmus-audio-recorder' ); ?>
					</audio>
				<?php else : ?>
					<p class="starmus-empty-msg">
						<?php esc_html_e( 'Audio is currently processing or unavailable.', 'starmus-audio-recorder' ); ?>
					</p>
				<?php endif; ?>
			</section>

			<!-- Public Metadata -->
			<section class="starmus-info-card sparxstar-glass-card">
				<h3><?php esc_html_e( 'About this Recording', 'starmus-audio-recorder' ); ?></h3>
				<dl class="starmus-dl-list">
					
					<?php if ( $location_data ) : ?>
						<div class="starmus-dl-item">
							<dt><?php esc_html_e( 'Location', 'starmus-audio-recorder' ); ?></dt>
							<dd><?php echo esc_html( $location_data ); ?></dd>
						</div>
					<?php endif; ?>

					<?php if ( $accession_number ) : ?>
						<div class="starmus-dl-item">
							<dt><?php esc_html_e( 'Accession ID', 'starmus-audio-recorder' ); ?></dt>
							<dd><?php echo esc_html( $accession_number ); ?></dd>
						</div>
					<?php endif; ?>

					<?php if ( $duration_formatted ) : ?>
						<div class="starmus-dl-item">
							<dt><?php esc_html_e( 'Duration', 'starmus-audio-recorder' ); ?></dt>
							<dd><?php echo esc_html( $duration_formatted ); ?></dd>
						</div>
					<?php endif; ?>

				</dl>
			</section>

		</div>

		<!-- Right: Actions (Permissions Checked in Loader, but re-checked here for safety) -->
		<aside class="starmus-content-sidebar">
			<?php if ( current_user_can( 'edit_post', $post_id ) ) : ?>
				<section class="starmus-actions-card sparxstar-glass-card">
					<h3><?php esc_html_e( 'Actions', 'starmus-audio-recorder' ); ?></h3>
					
					<div class="starmus-btn-stack">
						<!-- Re-Record -->
						<?php if ( $recorder_page_url ) : ?>
							<a href="<?php echo esc_url( add_query_arg( 'recording_id', $post_id, $recorder_page_url ) ); ?>" class="starmus-btn starmus-btn--action">
								<span class="dashicons dashicons-microphone" aria-hidden="true"></span>
								<?php esc_html_e( 'Re-Record Audio', 'starmus-audio-recorder' ); ?>
							</a>
						<?php endif; ?>

						<!-- Edit -->
						<?php if ( $edit_page_url ) : ?>
							<a href="<?php echo esc_url( add_query_arg( 'recording_id', $post_id, $edit_page_url ) ); ?>" class="starmus-btn starmus-btn--outline">
								<span class="dashicons dashicons-edit" aria-hidden="true"></span>
								<?php esc_html_e( 'Open Editor', 'starmus-audio-recorder' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</section>
			<?php endif; ?>
		</aside>

	</div>

</main>