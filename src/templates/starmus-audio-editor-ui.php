<?php
/**
 * Starmus Audio Editor UI Template
 *
 * This template provides the HTML structure for the Peaks.js audio editor.
 *
 * @package Starmus\templates
 * @version 0.5.7
 * @since 0.3.0
 */

namespace Starmus\templates;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- In templates/audio-editor.php -->
<div class="starmus-editor" role="region" aria-label="<?php esc_attr_e( 'Audio editor', STARMUS_TEXT_DOMAIN ); ?>">
	<div class="starmus-editor__head">
	<h1 class="starmus-editor__title"><?php esc_html_e( 'Audio Editor', STARMUS_TEXT_DOMAIN ); ?></h1>
	<div class="starmus-editor__time" aria-live="polite">
		<span class="screen-reader-text"><?php esc_html_e( 'Current time', STARMUS_TEXT_DOMAIN ); ?></span>
		<span id="starmus-time-cur">0:00</span> /
		<span class="screen-reader-text"><?php esc_html_e( 'Duration', STARMUS_TEXT_DOMAIN ); ?></span>
		<span id="starmus-time-dur">0:00</span>
	</div>
	</div>

	<div id="peaks-container" class="starmus-editor__wave">
	<div id="zoomview" class="starmus-editor__zoom" aria-label="<?php esc_attr_e( 'Zoomed waveform', STARMUS_TEXT_DOMAIN ); ?>"></div>
	<div id="overview" class="starmus-editor__overview" aria-label="<?php esc_attr_e( 'Overview waveform', STARMUS_TEXT_DOMAIN ); ?>"></div>
	</div>

	<div class="starmus-editor__controls" role="toolbar" aria-label="<?php esc_attr_e( 'Transport and edit', STARMUS_TEXT_DOMAIN ); ?>">
	<button id="back5" type="button" class="button">−5s</button>
	<button id="play"  type="button" class="button button-primary" aria-pressed="false"><?php esc_html_e( 'Play', STARMUS_TEXT_DOMAIN ); ?></button>
	<button id="fwd5"  type="button" class="button">+5s</button>
	<span class="starmus-editor__spacer"></span>
	<button id="zoom-out" type="button" class="button">Zoom −</button>
	<button id="zoom-in"  type="button" class="button">Zoom +</button>
	<button id="zoom-fit" type="button" class="button"><?php esc_html_e( 'Fit', STARMUS_TEXT_DOMAIN ); ?></button>
	<span class="starmus-editor__spacer"></span>
	<label class="starmus-editor__loop"><input id="loop" type="checkbox"> <?php esc_html_e( 'Loop selection', STARMUS_TEXT_DOMAIN ); ?></label>
	<button id="add-region" type="button" class="button"><?php esc_html_e( 'Add Region', STARMUS_TEXT_DOMAIN ); ?></button>
	<button id="save" type="button" class="button button-primary" disabled><?php esc_html_e( 'Save', STARMUS_TEXT_DOMAIN ); ?></button>
	</div>

	<div id="starmus-editor-notice" class="starmus-editor__notice" hidden></div>

	<div class="starmus-editor__list">
	<table class="wp-list-table widefat fixed striped" aria-describedby="starmus-editor-notice">
		<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Label', STARMUS_TEXT_DOMAIN ); ?></th>
			<th scope="col" style="width:110px"><?php esc_html_e( 'Start', STARMUS_TEXT_DOMAIN ); ?></th>
			<th scope="col" style="width:110px"><?php esc_html_e( 'End', STARMUS_TEXT_DOMAIN ); ?></th>
			<th scope="col" style="width:110px"><?php esc_html_e( 'Dur', STARMUS_TEXT_DOMAIN ); ?></th>
			<th scope="col" style="width:160px"><?php esc_html_e( 'Actions', STARMUS_TEXT_DOMAIN ); ?></th>
		</tr>
		</thead>
		<tbody id="regions-list"></tbody>
	</table>
	</div>
</div>
