<?php

/**
 * Starmus Audio Editor UI Template
 *
 * FIXED: Prioritizes Context ID > URL Parameter > Current Page ID.
 * This prevents the editor from trying to load audio from the Page (ID 21) instead of the Recording (ID 573).
 *
 * @package Starisian\Sparxstar\Starmus\templates
 */
namespace Starisian\Sparxstar\Starmus\templates;

if (! \defined('ABSPATH')) {
    exit;
}

// === 1. ROBUST ID RESOLUTION ===

$current_post_id = 0;

// Priority 1: Context passed from Shortcode Loader (This contains the correct ID 573)
if (! empty($context['post_id'])) {
    $current_post_id = \intval($context['post_id']);
}
// Priority 2: Direct variable if extracted
elseif (isset($post_id)) {
    $current_post_id = \intval($post_id);
}
// Priority 3: URL Parameter (e.g. ?recording_id=573)
elseif (isset($_GET['recording_id'])) {
    $current_post_id = \intval($_GET['recording_id']);
}
// Priority 4: Global ID (Only if it's actually an audio-recording post type)
elseif ('audio-recording' === get_post_type()) {
    $current_post_id = get_the_ID();
}

// === 2. FETCH DATA BASED ON RESOLVED ID ===

$audio_url   = '';
$editor_data = [];

if ($current_post_id) {
    // Resolve Audio URL (Master > Original > Legacy)
    $master_id   = (int) get_post_meta($current_post_id, 'mastered_mp3', true);
    $original_id = (int) get_post_meta($current_post_id, 'audio_files_originals', true);
    $legacy_id   = (int) get_post_meta($current_post_id, '_audio_attachment_id', true);

    $audio_att_id = $master_id ?: ($original_id ?: $legacy_id);
    $audio_url    = $audio_att_id !== 0 ? wp_get_attachment_url($audio_att_id) : '';

    // Resolve Metadata
    $transcript_json  = get_post_meta($current_post_id, 'first_pass_transcription', true);
    $annotations_json = get_post_meta($current_post_id, 'starmus_annotations', true);
    $waveform_json    = get_post_meta($current_post_id, 'waveform_json', true);

    // Decode JSONs safely
    $transcript_data = [];
    if ($transcript_json) {
        $decoded         = json_decode($transcript_json, true);
        $transcript_data = \is_array($decoded) ? ($decoded['segments'] ?? $decoded) : [];
    }

    $annotations_data = [];
    if ($annotations_json) {
        $decoded          = json_decode($annotations_json, true);
        $annotations_data = \is_array($decoded) ? $decoded : [];
    }

    $waveform_data = null;
    if ($waveform_json) {
        $waveform_data = \is_string($waveform_json) ? json_decode($waveform_json, true) : $waveform_json;
    }

    // Construct JS Data Object
    $editor_data = [
        'postId'        => $current_post_id,
        'audioUrl'      => $audio_url,
        'restUrl'       => esc_url_raw(rest_url('star_uec/v1/annotations')),
        'nonce'         => wp_create_nonce('wp_rest'),
        'transcript'    => $transcript_data,
        'annotations'   => $annotations_data,
        'waveform_data' => $waveform_data,
        'canCommit'     => current_user_can('edit_post', $current_post_id),
        'pageType'      => 'editor',
    ];
}
?>

<!-- OUTPUT DATA IMMEDIATELY -->
<script>
	window.STARMUS_EDITOR_DATA = <?php echo wp_json_encode($editor_data); ?>;
</script>

<div
	id="starmus-editor-root"
	class="starmus-editor sparxstar-glass-card"
	role="region"
	aria-label="<?php esc_attr_e('Audio editor', 'starmus-audio-recorder'); ?>"
	style="margin-top: 20px;">

	<div class="starmus-editor__head">
		<h1 class="starmus-editor__title">
			<?php esc_html_e('Audio Editor', 'starmus-audio-recorder'); ?>
			<span class="starmus-editor__id-badge">
				<?php
                if ($current_post_id) {
                    /* translators: %d: Recording ID number */
                    printf(esc_html__('ID: %d', 'starmus-audio-recorder'), \intval($current_post_id));
                } else {
                    esc_html_e('No Recording Selected', 'starmus-audio-recorder');
                }
?>
			</span>
		</h1>
		<div class="starmus-editor__time">
			<span id="starmus-time-cur">0:00</span> / <span id="starmus-time-dur">0:00</span>
		</div>
	</div>

	<?php if (empty($current_post_id)) { ?>
		<div class="starmus-alert starmus-alert--warning">
			<p><?php esc_html_e('Please select a recording to edit.', 'starmus-audio-recorder'); ?></p>
		</div>
	<?php } elseif (empty($audio_url)) { ?>
		<div class="starmus-alert starmus-alert--error">
			<p>
				<strong><?php esc_html_e('Error:', 'starmus-audio-recorder'); ?></strong>
				<?php
/* translators: %d: Recording ID number */
printf(esc_html__('Audio file missing for recording #%d.', 'starmus-audio-recorder'), $current_post_id); ?>
			</p>
		</div>
	<?php } else { ?>

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
						<?php if (empty($transcript_data)) { ?>
							<p class="starmus-empty-state">No transcription data available.</p>
						<?php } ?>
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
	<?php } ?>
</div>