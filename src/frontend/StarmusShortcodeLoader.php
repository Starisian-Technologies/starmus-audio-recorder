<?php
declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\helpers\StarmusTemplateLoaderHelper;

/**
 * Registers shortcodes and routes rendering lazily to the correct UI classes.
 *
 * @since 0.7.7
 */
final class StarmusShortcodeLoader {

	private StarmusSettings $settings;
	private StarmusAudioRecorderDAL $dal;

	public function __construct( ?StarmusSettings $settings = null, ?StarmusAudioRecorderDAL $dal = null ) {
		$this->settings = $settings ?? new StarmusSettings();
		$this->dal      = $dal ?? new StarmusAudioRecorderDAL();
		add_action( 'init', array( $this, 'register_shortcodes' ) );
	}

	/**
	 * Register shortcodes — but don't instantiate heavy UI classes yet.
	 */
	public function register_shortcodes(): void {
		add_shortcode( 'starmus_audio_recorder', fn() => $this->safe_render( fn() => ( new StarmusAudioRecorderUI( $this->settings ) )->render_recorder_shortcode(), 'starmus_audio_recorder' ) );
		add_shortcode( 'starmus_audio_editor', fn() => $this->safe_render( fn() => ( new StarmusAudioEditorUI() )->render_audio_editor_shortcode(), 'starmus_audio_editor' ) );
		add_shortcode( 'starmus_my_recordings', array( $this, 'render_my_recordings_shortcode' ) );
		add_shortcode( 'starmus_recording_detail', array( $this, 'render_submission_detail_shortcode' ) );
		add_filter( 'the_content', array( $this, 'render_submission_detail_via_filter' ), 100 );
	}

	/**
	 * Safely render UI blocks with logging.
	 */
	private function safe_render( callable $renderer, string $context ): string {
		try {
			return $renderer();
		} catch ( \Throwable $e ) {
			StarmusLogger::log( "Shortcode:$context", $e );
			return '<p>' . esc_html__( 'Component unavailable.', 'starmus-audio-recorder' ) . '</p>';
		}
	}

	/**
	 * Render the "My Recordings" shortcode.
	 */
	public function render_my_recordings_shortcode( array $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You must be logged in to view your recordings.', 'starmus-audio-recorder' ) . '</p>';
		}

		try {
			$attributes     = shortcode_atts( array( 'posts_per_page' => 10 ), $atts );
			$posts_per_page = max( 1, absint( $attributes['posts_per_page'] ) );
			$paged          = get_query_var( 'paged' ) ? (int) get_query_var( 'paged' ) : 1;
			$cpt_slug       = $this->settings->get( 'cpt_slug', 'audio-recording' );
			$query          = $this->dal->get_user_recordings( get_current_user_id(), $cpt_slug, $posts_per_page, $paged );

			return StarmusTemplateLoaderHelper::render_template(
				'parts/starmus-my-recordings-list.php',
				array(
					'query'         => $query,
					'edit_page_url' => $this->dal->get_edit_page_url_admin( $cpt_slug ),
				)
			);
		} catch ( \Throwable $e ) {
			StarmusLogger::log( 'UI:render_my_recordings', $e );
			return '<p>' . esc_html__( 'Unable to load recordings.', 'starmus-audio-recorder' ) . '</p>';
		}
	}

	/**
	 * Render the single recording detail shortcode.
	 */
	public function render_submission_detail_shortcode( array $atts ): string {
		if ( ! is_singular( 'audio-recording' ) ) {
			return '<p><em>[starmus_recording_detail] can only be used on a single audio recording page.</em></p>';
		}

		$post_id          = get_the_ID();
		$template_to_load = '';

		if ( current_user_can( 'edit_others_posts', $post_id ) ) {
			$template_to_load = 'starmus-recording-detail-admin.php';
		} elseif ( is_user_logged_in() && get_current_user_id() === (int) get_post_field( 'post_author', $post_id ) ) {
			$template_to_load = 'starmus-recording-detail-user.php';
		}

		if ( $template_to_load ) {
			return StarmusTemplateLoaderHelper::render_template( $template_to_load );
		}

		return is_user_logged_in()
			? '<p>You do not have permission to view this recording detail.</p>'
			: '<p><em>You must be logged in to view this recording detail.</em></p>';
	}

	/**
	 * Automatically inject recording detail template into single view.
	 */
	public function render_submission_detail_via_filter( string $content ): string {
		if ( ! is_singular( 'audio-recording' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id          = get_the_ID();
		$template_to_load = '';

		if ( current_user_can( 'edit_others_posts', $post_id ) ) {
			$template_to_load = 'parts/starmus-recording-detail-admin.php';
		} elseif ( is_user_logged_in() && get_current_user_id() === (int) get_post_field( 'post_author', $post_id ) ) {
			$template_to_load = 'parts/starmus-recording-detail-user.php';
		}

		if ( $template_to_load ) {
			return StarmusTemplateLoaderHelper::render_template( $template_to_load );
		}

		return '<p>You do not have permission to view this recording detail.</p>';
	}
}
