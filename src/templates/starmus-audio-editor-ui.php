<?php

/**
 * Starmus Audio Editor UI Template
 *
 * @package Starisian\Sparxstar\Starmus\templates
 *
 * @version 1.1.0-ROBUST-TEMPLATE
 */

if (! defined('ABSPATH')) {
	exit;
}

// Data is prepared by StarmusAudioEditorUI and passed in $context
$current_post_id = $context['post_id'] ?? 0;
$audio_url       = $context['audio_url'] ?? '';
$editor_data     = [
	'postId'        => $current_post_id,
	'audioUrl'      => $audio_url,
	'restUrl'       => esc_url_raw(rest_url('star_uec/v1/annotations')),
	'nonce'         => wp_create_nonce('wp_rest'),
	'annotations'   => isset($context['annotations_json']) ? json_decode($context['annotations_json'], true) : [],
	'transcript'    => $context['transcript_data'] ?? [],
	'waveform_data' => isset($context['starmus_waveform_json']) ? json_decode($context['starmus_waveform_json'], true) : null,
	'canCommit'     => current_user_can('edit_post', $current_post_id),
];
?>

<!-- JS Bootstrap Data -->
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
					/* translators: %d: Recording ID */
					printf(esc_html__('ID: %d', 'starmus-audio-recorder'), (int) $current_post_id);
				} else {
					esc_html_e('No Recording', 'starmus-audio-recorder');
				}
				?>
			</span>
		</h1>
		<div class="starmus-editor__time">
			<span id="starmus-time-cur">0:00</span> / <span id="starmus-time-dur">0:00</span>
		</div>
	</div>

	<?php if (empty($audio_url)) { ?>
		<div class="starmus-alert starmus-alert--error">
			<p><strong><?php esc_html_e('Error:', 'starmus-audio-recorder'); ?></strong>
				<?php esc_html_e('Audio file missing for this recording.', 'starmus-audio-recorder'); ?></p>
		</div>
	<?php } else { ?>
		<div class="starmus-editor__layout">
			<!-- WAVEFORM COLUMN -->
			<div class="starmus-editor__wave">
				<div id="peaks-container" class="starmus-editor__wave-inner">
					<div id="zoomview" class="starmus-editor__zoom"></div>
					<div id="overview" class="starmus-editor__overview"></div>
				</div>

				<div class="starmus-editor__controls">
					<div class="starmus-btn-group">
						<button id="back5" class="starmus-btn starmus-btn--outline" type="button" aria-label="<?php esc_attr_e('Rewind 5 seconds', 'starmus-audio-recorder'); ?>">-5s</button>
						<button id="play" class="starmus-btn starmus-btn--primary" type="button" aria-label="<?php esc_attr_e('Play', 'starmus-audio-recorder'); ?>">Play</button>
						<button id="fwd5" class="starmus-btn starmus-btn--outline" type="button" aria-label="<?php esc_attr_e('Fast forward 5 seconds', 'starmus-audio-recorder'); ?>">+5s</button>
					</div>
					<div class="starmus-btn-group">
						<button id="zoom-out" class="starmus-btn starmus-btn--outline" type="button" aria-label="<?php esc_attr_e('Zoom Out', 'starmus-audio-recorder'); ?>">-</button>
						<button id="zoom-fit" class="starmus-btn starmus-btn--outline" type="button" aria-label="<?php esc_attr_e('Fit to Screen', 'starmus-audio-recorder'); ?>">Fit</button>
						<button id="zoom-in" class="starmus-btn starmus-btn--outline" type="button" aria-label="<?php esc_attr_e('Zoom In', 'starmus-audio-recorder'); ?>">+</button>
					</div>
					<label class="starmus-editor__loop"><input id="loop" type="checkbox"> Loop</label>
					<button id="add-region" class="starmus-btn starmus-btn--secondary" type="button">Add Region</button>
					<button id="save" class="starmus-btn starmus-btn--primary" type="button" disabled>Save Changes</button>
				</div>
				<div id="starmus-editor-notice" class="starmus-alert" hidden></div>
			</div>

			<!-- SIDEBAR COLUMN -->
			<aside class="starmus-editor__side">
				<section class="starmus-editor__transcript">
					<h3><?php esc_html_e('Transcript', 'starmus-audio-recorder'); ?></h3>
					<div id="starmus-transcript-panel" class="starmus-transcript-panel">
						<?php if (empty($editor_data['transcript'])) { ?>
							<p class="starmus-empty-state"><?php esc_html_e('No transcription data.', 'starmus-audio-recorder'); ?></p>
						<?php } ?>
					</div>
				</section>
				<section class="starmus-editor__list">
					<h3><?php esc_html_e('Regions', 'starmus-audio-recorder'); ?></h3>
					<table class="starmus-info-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e('Label', 'starmus-audio-recorder'); ?></th>
								<th scope="col" style="width:60px"><?php esc_html_e('Start', 'starmus-audio-recorder'); ?></th>
								<th scope="col" style="width:60px"><?php esc_html_e('End', 'starmus-audio-recorder'); ?></th>
								<th scope="col" style="width:50px"><?php esc_html_e('Dur', 'starmus-audio-recorder'); ?></th>
								<th scope="col" style="width:100px"><?php esc_html_e('Actions', 'starmus-audio-recorder'); ?></th>
							</tr>
						</thead>
						<tbody id="regions-list"></tbody>
					</table>
				</section>
			</aside>
		</div>
	<?php } ?>
</div>
