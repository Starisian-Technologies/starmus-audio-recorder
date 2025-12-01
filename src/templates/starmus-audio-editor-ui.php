<?php
/**
 * Starmus Audio Editor UI Template
 *
 * FIXED: 
 * 1. Rebuilds the data array ($editor_data) locally to prevent empty JS variables.
 * 2. Outputs JS data immediately to prevent "Editor data not found" race conditions.
 *
 * @package Starisian\Sparxstar\Starmus\templates
 */

namespace Starisian\Sparxstar\Starmus\templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 1. Resolve Post ID (Handle passed args or global fallback)
$current_post_id = isset( $post_id ) ? intval( $post_id ) : get_the_ID();

// If we still don't have an ID (rare), check the $context array if it exists
if ( ! $current_post_id && isset( $context['post_id'] ) ) {
	$current_post_id = intval( $context['post_id'] );
}

$audio_url = '';
$editor_data = []; // We will build this object for JS

if ( $current_post_id ) {
	// 2. Resolve Audio URL (Master > Original > Legacy)
	$master_id   = (int) get_post_meta( $current_post_id, 'mastered_mp3', true );
	$original_id = (int) get_post_meta( $current_post_id, 'audio_files_originals', true );
	$legacy_id   = (int) get_post_meta( $current_post_id, '_audio_attachment_id', true );

	$audio_att_id = $master_id ?: ( $original_id ?: $legacy_id );
	$audio_url    = $audio_att_id ? wp_get_attachment_url( $audio_att_id ) : '';

	// 3. Resolve Metadata (Transcript & Annotations)
	$transcript_json  = get_post_meta( $current_post_id, 'first_pass_transcription', true );
	$annotations_json = get_post_meta( $current_post_id, 'starmus_annotations', true );
	$waveform_json    = get_post_meta( $current_post_id, 'waveform_json', true );

	// Decode JSONs safely
	$transcript_data  = [];
	if ( $transcript_json ) {
		$decoded = json_decode( $transcript_json, true );
		$transcript_data = is_array( $decoded ) ? ( $decoded['segments'] ?? $decoded ) : [];
	}

	$annotations_data = [];
	if ( $annotations_json ) {
		$decoded = json_decode( $annotations_json, true );
		$annotations_data = is_array( $decoded ) ? $decoded : [];
	}

	$waveform_data = null;
	if ( $waveform_json ) {
		$waveform_data = is_string( $waveform_json ) ? json_decode( $waveform_json, true ) : $waveform_json;
	}

	// 4. Construct the Data Object for JavaScript
	// This ensures keys exactly match what starmus-audio-recorder-script.bundle.js expects
	$editor_data = [
		'postId'          => $current_post_id, // JS expects camelCase 'postId'
		'post_id'         => $current_post_id, // Keep snake_case just in case
		'audioUrl'        => $audio_url,       // JS expects 'audioUrl'
		'audio_url'       => $audio_url,
		'restUrl'         => esc_url_raw( rest_url( 'star_uec/v1/annotations' ) ),
		'nonce'           => wp_create_nonce( 'wp_rest' ),
		'transcript'      => $transcript_data,
		'annotations'     => $annotations_data,
		'waveform_data'   => $waveform_data,
		'canCommit'       => current_user_can( 'edit_post', $current_post_id ),
		'pageType'        => 'editor'
	];
}
?>

<!-- 1. OUTPUT DATA FIRST (This fixes the "Editor data not found" error) -->
<script>
	window.STARMUS_EDITOR_DATA = <?php echo wp_json_encode( $editor_data ); ?>;
	// Helper for debugging
	console.log("[Starmus Editor] Data Loaded:", window.STARMUS_EDITOR_DATA);
</script>

<!-- 2. EDITOR CONTAINER -->
<div 
	id="starmus-editor-root" 
	class="starmus-editor sparxstar-glass-card" 
	role="region" 
	aria-label="<?php esc_attr_e( 'Audio editor', 'starmus-audio-recorder' ); ?>"
	style="margin-top: 20px;">

	<div class="starmus-editor__head">
		<h1 class="starmus-editor__title">
			<?php esc_html_e( 'Audio Editor', 'starmus-audio-recorder' ); ?>
			<span class="starmus-editor__id-badge">ID: <?php echo intval( $current_post_id ); ?></span>
		</h1>
		<div class="starmus-editor__time">
			<span id="starmus-time-cur">0:00</span> / <span id="starmus-time-dur">0:00</span>
		</div>
	</div>

	<?php if ( empty( $audio_url ) ) : ?>
		<div class="starmus-alert starmus-alert--error">
			<p><strong>Error:</strong> Audio file not found (ID: <?php echo intval( $current_post_id ); ?>). Cannot initialize editor.</p>
		</div>
	<?php else : ?>

		<div class="starmus-editor__layout">
			
			<!-- WAVEFORM COLUMN -->
			<div class="starmus-editor__wave">
				<!-- Peaks Container -->
				<div id="peaks-container" class="starmus-editor__wave-inner">
					<div id="zoomview" class="starmus-editor__zoom"></div>
					<div id="overview" class="starmus-editor__overview"></div>
				</div>

				<!-- Controls -->
				<div class="starmus-editor__controls">
					<div class="starmus-btn-group">
						<button id="back5" class="starmus-btn starmus-btn--outline" type="button">-5s</button>
						<button id="play" class="starmus-btn starmus-btn--primary" type="button">Play</button>
						<button id="fwd5" class="starmus-btn starmus-btn--outline" type="button">+5s</button>
					</div>
					<div class="starmus-btn-group">
						<button id="zoom-out" class="starmus-btn starmus-btn--outline" type="button">-</button>
						<button id="zoom-fit" class="starmus-btn starmus-btn--outline" type="button">Fit</button>
						<button id="zoom-in" class="starmus-btn starmus-btn--outline" type="button">+</button>
					</div>
					<label class="starmus-editor__loop">
						<input id="loop" type="checkbox"> Loop
					</label>
					<button id="add-region" class="starmus-btn starmus-btn--secondary" type="button">Add Region</button>
					<button id="save" class="starmus-btn starmus-btn--primary" type="button" disabled>Save Changes</button>
				</div>
				
				<div id="starmus-editor-notice" class="starmus-alert" hidden></div>
			</div>

			<!-- SIDEBAR COLUMN -->
			<aside class="starmus-editor__side">
				<section class="starmus-editor__transcript">
					<h3>Transcript</h3>
					<div id="starmus-transcript-panel" class="starmus-transcript-panel">
						<?php if ( empty( $transcript_data ) ) : ?>
							<p class="starmus-empty-state">No transcription data available.</p>
						<?php endif; ?>
					</div>
				</section>

				<section class="starmus-editor__list">
					<h3>Regions</h3>
					<table class="starmus-info-table">
						<thead>
							<tr>
								<th>Label</th>
								<th style="width:60px">Start</th>
								<th style="width:60px">End</th>
								<th style="width:50px">Dur</th>
								<th style="width:100px">Actions</th>
							</tr>
						</thead>
						<tbody id="regions-list"></tbody>
					</table>
				</section>
			</aside>

		</div>
	<?php endif; ?>
</div>