<?php
/**
 * Starmus Audio Editor UI Template
 *
 * UPDATED:
 * - Includes REST API Nonce & URL (Required for Saving).
 * - Fetches existing Annotations (Regions).
 * - Uses verified production keys (`mastered_mp3`, `first_pass_transcription`).
 *
 * @package Starisian\Sparxstar\Starmus\templates
 */

namespace Starisian\Sparxstar\Starmus\templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// === DATA RESOLUTION & SECURITY LOGIC ===

$post_id = isset( $context['post_id'] ) ? intval( $context['post_id'] ) : get_the_ID();

if ( $post_id ) {
	// 1. Resolve Audio URL (Priority: Mastered > Original)
	$master_id   = (int) get_post_meta( $post_id, 'mastered_mp3', true );
	$original_id = (int) get_post_meta( $post_id, 'audio_files_originals', true );
	
	// Fallback to legacy
	if ( ! $original_id ) {
		$original_id = (int) get_post_meta( $post_id, '_audio_attachment_id', true );
	}

	$audio_url = '';
	if ( $master_id > 0 ) {
		$audio_url = wp_get_attachment_url( $master_id );
	} elseif ( $original_id > 0 ) {
		$audio_url = wp_get_attachment_url( $original_id );
	}

	// 2. Resolve Transcript
	$transcript_json = get_post_meta( $post_id, 'first_pass_transcription', true );
	$transcript_data = [];
	if ( $transcript_json ) {
		$decoded = json_decode( $transcript_json, true );
		if ( is_array( $decoded ) ) {
			$transcript_data = $decoded['segments'] ?? $decoded; 
		}
	}

	// 3. Resolve Waveform Data
	$waveform_json = get_post_meta( $post_id, 'waveform_json', true );
	$waveform_data = null;
	if ( $waveform_json ) {
		$waveform_data = is_string( $waveform_json ) ? json_decode( $waveform_json, true ) : $waveform_json;
	}

	// 4. Resolve Existing Annotations (Regions) - NEW
	$annotations_json = get_post_meta( $post_id, 'starmus_annotations', true );
	$annotations_data = [];
	if ( $annotations_json ) {
		$decoded = json_decode( $annotations_json, true );
		$annotations_data = is_array( $decoded ) ? $decoded : [];
	}

	// 5. Build Full Context for JS
	$context['post_id']       = $post_id;
	$context['audio_url']     = $audio_url;
	$context['transcript']    = $transcript_data;
	$context['annotations']   = $annotations_data; // Pass regions to JS
	$context['waveform_data'] = $waveform_data;
	
	// 6. Security & API Endpoints (CRITICAL FOR SAVE BUTTON)
	if ( empty( $context['restUrl'] ) ) {
		$context['restUrl'] = esc_url_raw( rest_url( 'star_uec/v1/annotations' ) );
	}
	if ( empty( $context['nonce'] ) ) {
		$context['nonce'] = wp_create_nonce( 'wp_rest' );
	}
}
?>

<!-- Pass hydrated context to Window object -->
<script>
	window.STARMUS_EDITOR_DATA = <?php echo wp_json_encode( $context ); ?>;
</script>

<div
	id="starmus-editor-root"
	class="starmus-editor"
	data-post-id="<?php echo esc_attr( $context['post_id'] ); ?>"
	data-audio-url="<?php echo esc_attr( $context['audio_url'] ); ?>"
	role="region"
	aria-label="<?php esc_attr_e( 'Audio editor', 'starmus-audio-recorder' ); ?>">
	
	<div class="starmus-editor__head">
		<h1 class="starmus-editor__title">
			<?php esc_html_e( 'Audio Editor', 'starmus-audio-recorder' ); ?>
			<span class="starmus-editor__id-badge">
				<?php printf( esc_html__( 'ID: %d', 'starmus-audio-recorder' ), intval( $context['post_id'] ) ); ?>
			</span>
		</h1>
		
		<div class="starmus-editor__time" aria-live="polite">
			<span class="screen-reader-text"><?php esc_html_e( 'Current time', 'starmus-audio-recorder' ); ?></span>
			<span id="starmus-time-cur">0:00</span>
			<span aria-hidden="true"> / </span>
			<span class="screen-reader-text"><?php esc_html_e( 'Duration', 'starmus-audio-recorder' ); ?></span>
			<span id="starmus-time-dur">0:00</span>
		</div>
	</div>

	<?php if ( empty( $context['audio_url'] ) ) : ?>
		<div class="notice notice-error inline">
			<p>
				<strong><?php esc_html_e( 'Error:', 'starmus-audio-recorder' ); ?></strong>
				<?php esc_html_e( 'No audio file found. Cannot load editor.', 'starmus-audio-recorder' ); ?>
			</p>
		</div>
	<?php else : ?>

	<div class="starmus-editor__layout">
		<!-- LEFT: Waveform + transport -->
		<div class="starmus-editor__wave">
			<div id="peaks-container" class="starmus-editor__wave-inner">
				<div id="zoomview" class="starmus-editor__zoom" aria-label="<?php esc_attr_e( 'Zoomed waveform', 'starmus-audio-recorder' ); ?>"></div>
				<div id="overview" class="starmus-editor__overview" aria-label="<?php esc_attr_e( 'Overview waveform', 'starmus-audio-recorder' ); ?>"></div>
			</div>

			<div class="starmus-editor__controls" role="toolbar" aria-label="<?php esc_attr_e( 'Transport and edit', 'starmus-audio-recorder' ); ?>">
				<!-- Navigation -->
				<div class="starmus-btn-group">
					<button id="back5" type="button" class="button" title="<?php esc_attr_e( 'Rewind 5 seconds', 'starmus-audio-recorder' ); ?>">−5s</button>
					<button id="play" type="button" class="button button-primary starmus-btn-play" aria-pressed="false">
						<?php esc_html_e( 'Play', 'starmus-audio-recorder' ); ?>
					</button>
					<button id="fwd5" type="button" class="button" title="<?php esc_attr_e( 'Forward 5 seconds', 'starmus-audio-recorder' ); ?>">+5s</button>
				</div>

				<span class="starmus-editor__spacer"></span>

				<!-- Zoom -->
				<div class="starmus-btn-group">
					<button id="zoom-out" type="button" class="button" aria-label="<?php esc_attr_e( 'Zoom Out', 'starmus-audio-recorder' ); ?>">
						<span aria-hidden="true">−</span>
					</button>
					<button id="zoom-fit" type="button" class="button">
						<?php esc_html_e( 'Fit', 'starmus-audio-recorder' ); ?>
					</button>
					<button id="zoom-in" type="button" class="button" aria-label="<?php esc_attr_e( 'Zoom In', 'starmus-audio-recorder' ); ?>">
						<span aria-hidden="true">+</span>
					</button>
				</div>

				<span class="starmus-editor__spacer"></span>

				<!-- Regions / Logic -->
				<label class="starmus-editor__loop button">
					<input id="loop" type="checkbox">
					<?php esc_html_e( 'Loop', 'starmus-audio-recorder' ); ?>
				</label>

				<button id="add-region" type="button" class="button">
					<?php esc_html_e( 'Add Region', 'starmus-audio-recorder' ); ?>
				</button>

				<button id="save" type="button" class="button button-primary" disabled>
					<?php esc_html_e( 'Save Changes', 'starmus-audio-recorder' ); ?>
				</button>
			</div>

			<div id="starmus-editor-notice" class="starmus-editor__notice" hidden></div>
		</div>

		<!-- RIGHT: Transcript + region list -->
		<aside class="starmus-editor__side" aria-label="<?php esc_attr_e( 'Transcript and regions', 'starmus-audio-recorder' ); ?>">
			
			<section class="starmus-editor__transcript" aria-label="<?php esc_attr_e( 'Transcript', 'starmus-audio-recorder' ); ?>">
				<header class="starmus-side-header">
					<h2 class="starmus-editor__subheading"><?php esc_html_e( 'Transcript', 'starmus-audio-recorder' ); ?></h2>
				</header>
				
				<div
					id="starmus-transcript-panel"
					class="starmus-transcript-panel"
					data-starmus-transcript-panel
					role="list"
					aria-live="polite">
					<?php 
					if ( ! empty( $context['transcript'] ) ) {
						echo '<p style="padding:10px; color:#666;">' . esc_html__( 'Loading transcript sync...', 'starmus-audio-recorder' ) . '</p>';
					} else {
						echo '<p class="starmus-empty-state">' . esc_html__( 'No transcription data available.', 'starmus-audio-recorder' ) . '</p>';
					}
					?>
				</div>
			</section>

			<section class="starmus-editor__list" aria-label="<?php esc_attr_e( 'Regions', 'starmus-audio-recorder' ); ?>">
				<header class="starmus-side-header">
					<h2 class="starmus-editor__subheading"><?php esc_html_e( 'Regions / Segments', 'starmus-audio-recorder' ); ?></h2>
				</header>
				
				<div class="starmus-table-wrap">
					<table class="wp-list-table widefat fixed striped" aria-describedby="starmus-editor-notice">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Label', 'starmus-audio-recorder' ); ?></th>
								<th scope="col" style="width:70px"><?php esc_html_e( 'Start', 'starmus-audio-recorder' ); ?></th>
								<th scope="col" style="width:70px"><?php esc_html_e( 'End', 'starmus-audio-recorder' ); ?></th>
								<th scope="col" style="width:60px" class="screen-reader-text"><?php esc_html_e( 'Actions', 'starmus-audio-recorder' ); ?></th>
							</tr>
						</thead>
						<tbody id="regions-list">
							<!-- Populated by Peaks.js -->
						</tbody>
					</table>
				</div>
			</section>
		</aside>
	</div>
	<?php endif; ?>
</div>