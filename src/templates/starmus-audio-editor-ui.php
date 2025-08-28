<?php
/**
 * Starmus Audio Editor UI Template
 *
 * This template provides the HTML structure for the Peaks.js audio editor.
 *
 * @package Starmus\templates
 */

namespace Starmus\templates;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- In templates/audio-editor.php -->
<div class="starmus-editor" role="region" aria-label="<?php esc_attr_e( 'Audio editor', 'starmus_audio_recorder' ); ?>">
	<div class="starmus-editor__head">
	<h1 class="starmus-editor__title"><?php esc_html_e( 'Audio Editor', 'starmus_audio_recorder' ); ?></h1>
	<div class="starmus-editor__time" aria-live="polite">
		<span class="screen-reader-text"><?php esc_html_e( 'Current time', 'starmus_audio_recorder' ); ?></span>
		<span id="starmus-time-cur">0:00</span> /
		<span class="screen-reader-text"><?php esc_html_e( 'Duration', 'starmus_audio_recorder' ); ?></span>
		<span id="starmus-time-dur">0:00</span>
	</div>
	</div>

	<div id="peaks-container" class="starmus-editor__wave">
	<div id="zoomview" class="starmus-editor__zoom" aria-label="<?php esc_attr_e( 'Zoomed waveform', 'starmus_audio_recorder' ); ?>"></div>
	<div id="overview" class="starmus-editor__overview" aria-label="<?php esc_attr_e( 'Overview waveform', 'starmus_audio_recorder' ); ?>"></div>
	</div>

	<div class="starmus-editor__controls" role="toolbar" aria-label="<?php esc_attr_e( 'Transport and edit', 'starmus_audio_recorder' ); ?>">
	<button id="back5" type="button" class="button">−5s</button>
	<button id="play"  type="button" class="button button-primary" aria-pressed="false"><?php esc_html_e( 'Play', 'starmus_audio_recorder' ); ?></button>
	<button id="fwd5"  type="button" class="button">+5s</button>
	<span class="starmus-editor__spacer"></span>
	<button id="zoom-out" type="button" class="button">Zoom −</button>
	<button id="zoom-in"  type="button" class="button">Zoom +</button>
	<button id="zoom-fit" type="button" class="button"><?php esc_html_e( 'Fit', 'starmus_audio_recorder' ); ?></button>
	<span class="starmus-editor__spacer"></span>
	<label class="starmus-editor__loop"><input id="loop" type="checkbox"> <?php esc_html_e( 'Loop selection', 'starmus_audio_recorder' ); ?></label>
	<button id="add-region" type="button" class="button"><?php esc_html_e( 'Add Region', 'starmus_audio_recorder' ); ?></button>
	<button id="save" type="button" class="button button-primary" disabled><?php esc_html_e( 'Save', 'starmus_audio_recorder' ); ?></button>
	</div>

	<div id="starmus-editor-notice" class="starmus-editor__notice" hidden></div>

	<div class="starmus-editor__list">
	<table class="wp-list-table widefat fixed striped" aria-describedby="starmus-editor-notice">
		<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Label', 'starmus_audio_recorder' ); ?></th>
			<th scope="col" style="width:110px"><?php esc_html_e( 'Start', 'starmus_audio_recorder' ); ?></th>
			<th scope="col" style="width:110px"><?php esc_html_e( 'End', 'starmus_audio_recorder' ); ?></th>
			<th scope="col" style="width:110px"><?php esc_html_e( 'Dur', 'starmus_audio_recorder' ); ?></th>
			<th scope="col" style="width:160px"><?php esc_html_e( 'Actions', 'starmus_audio_recorder' ); ?></th>
		</tr>
		</thead>
		<tbody id="regions-list"></tbody>
	</table>
	</div>
</div>
