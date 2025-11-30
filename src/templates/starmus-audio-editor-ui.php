<?php

/**
 * Starmus Audio Editor UI Template
 *
 * This template provides the HTML structure for the Peaks.js audio editor
 * with integrated transcript panel for bidirectional audio-text sync.
 *
 * @package Starisian\Sparxstar\Starmus\templates
 *
 * @version 0.9.0
 *
 * @since 0.3.0
 */

namespace Starisian\Sparxstar\Starmus\templates;

// Exit if accessed directly.
if (! \defined('ABSPATH')) {
	exit;
}
/** @var array $context */

// Debug logging to verify context is received
if (\defined('WP_DEBUG') && WP_DEBUG) {
	error_log('Editor template loaded with context: ' . print_r($context, true));
}

?>
<div
	id="starmus-editor-root"
	class="starmus-editor"
	data-post-id="<?php echo esc_attr($context['post_id']); ?>"
	data-audio-url="<?php echo esc_attr($context['audio_url']); ?>"
	data-waveform-url="<?php echo esc_attr($context['waveform_url']); ?>"
	role="region"
	aria-label="<?php esc_attr_e('Audio editor', 'starmus-audio-recorder'); ?>">
	<div class="starmus-editor__head">
		<h1 class="starmus-editor__title">
			<?php esc_html_e('Audio Editor', 'starmus-audio-recorder'); ?>
			<small style="opacity:0.6;font-size:0.6em;margin-left:1rem;">Post ID: <?php echo esc_html($context['post_id']); ?></small>
		</h1>
		<div class="starmus-editor__time" aria-live="polite">
			<span class="screen-reader-text"><?php esc_html_e('Current time', 'starmus-audio-recorder'); ?></span>
			<span id="starmus-time-cur">0:00</span>
			<span aria-hidden="true"> / </span>
			<span class="screen-reader-text"><?php esc_html_e('Duration', 'starmus-audio-recorder'); ?></span>
			<span id="starmus-time-dur">0:00</span>
		</div>
	</div>

	<div class="starmus-editor__layout">
		<!-- LEFT: Waveform + transport -->
		<div class="starmus-editor__wave">
			<div id="peaks-container" class="starmus-editor__wave-inner">
				<div
					id="zoomview"
					class="starmus-editor__zoom"
					aria-label="<?php esc_attr_e('Zoomed waveform', 'starmus-audio-recorder'); ?>">
				</div>
				<div
					id="overview"
					class="starmus-editor__overview"
					aria-label="<?php esc_attr_e('Overview waveform', 'starmus-audio-recorder'); ?>">
				</div>
			</div>

			<div class="starmus-editor__controls" role="toolbar" aria-label="<?php esc_attr_e('Transport and edit', 'starmus-audio-recorder'); ?>">
				<button id="back5" type="button" class="button">−5s</button>
				<button id="play" type="button" class="button button-primary" aria-pressed="false">
					<?php esc_html_e('Play', 'starmus-audio-recorder'); ?>
				</button>
				<button id="fwd5" type="button" class="button">+5s</button>

				<span class="starmus-editor__spacer"></span>

				<button id="zoom-out" type="button" class="button">Zoom −</button>
				<button id="zoom-in" type="button" class="button">Zoom +</button>
				<button id="zoom-fit" type="button" class="button">
					<?php esc_html_e('Fit', 'starmus-audio-recorder'); ?>
				</button>

				<span class="starmus-editor__spacer"></span>

				<label class="starmus-editor__loop">
					<input id="loop" type="checkbox">
					<?php esc_html_e('Loop selection', 'starmus-audio-recorder'); ?>
				</label>

				<button id="add-region" type="button" class="button">
					<?php esc_html_e('Add Region', 'starmus-audio-recorder'); ?>
				</button>

				<button id="save" type="button" class="button button-primary" disabled>
					<?php esc_html_e('Save', 'starmus-audio-recorder'); ?>
				</button>
			</div>

			<div id="starmus-editor-notice" class="starmus-editor__notice" hidden></div>
		</div>

		<!-- RIGHT: Transcript + region list -->
		<aside class="starmus-editor__side" aria-label="<?php esc_attr_e('Transcript and regions', 'starmus-audio-recorder'); ?>">
			<section class="starmus-editor__transcript" aria-label="<?php esc_attr_e('Transcript', 'starmus-audio-recorder'); ?>">
				<h2 class="starmus-editor__subheading">
					<?php esc_html_e('Transcript', 'starmus-audio-recorder'); ?>
				</h2>
				<div
					id="starmus-transcript-panel"
					class="starmus-transcript-panel"
					data-starmus-transcript-panel
					role="list"
					aria-live="polite">
					<!-- JS will inject span.starmus-word[data-start][data-end] here -->
				</div>
			</section>

			<section class="starmus-editor__list" aria-label="<?php esc_attr_e('Regions', 'starmus-audio-recorder'); ?>">
				<h2 class="starmus-editor__subheading">
					<?php esc_html_e('Regions', 'starmus-audio-recorder'); ?>
				</h2>
				<table class="wp-list-table widefat fixed striped" aria-describedby="starmus-editor-notice">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e('Label', 'starmus-audio-recorder'); ?></th>
							<th scope="col" style="width:110px"><?php esc_html_e('Start', 'starmus-audio-recorder'); ?></th>
							<th scope="col" style="width:110px"><?php esc_html_e('End', 'starmus-audio-recorder'); ?></th>
							<th scope="col" style="width:110px"><?php esc_html_e('Dur', 'starmus-audio-recorder'); ?></th>
							<th scope="col" style="width:160px"><?php esc_html_e('Actions', 'starmus-audio-recorder'); ?></th>
						</tr>
					</thead>
					<tbody id="regions-list"></tbody>
				</table>
			</section>
		</aside>
	</div>
</div>