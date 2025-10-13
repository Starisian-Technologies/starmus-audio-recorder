<?php
namespace Starisian\Starmus\frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\Starmus\core\StarmusSettings;
use Starisian\Starmus\frontend\StarmusAudioRecorderUI;
use Starisian\Starmus\frontend\StarmusAudioEditorUI;
use Starisian\Starmus\helpers\StarmusLogger;
use Starisian\Starmus\helpers\StarmusTemplateLoaderHelper;

/**
 * Registers shortcodes and routes rendering lazily to the correct UI classes.
 *
 * @since 0.7.7
 */
class StarmusShortcodeLoader {

	private StarmusSettings $settings;

	public function __construct( ?StarmusSettings $settings ) {
		$this->settings = $settings ?? new StarmusSettings();
		add_action( 'init', [ $this, 'register_shortcodes' ] );
	}

	/**
	 * Register shortcodes — but don't instantiate heavy UI classes yet.
	 */
	public function register_shortcodes(): void {

		add_shortcode( 'starmus_audio_recorder', function() {
			try {
				$recorder = new StarmusAudioRecorderUI( $this->settings );
				return $recorder->render_recorder_shortcode();
			} catch ( \Throwable $e ) {
				StarmusLogger::log( 'Shortcode:starmus_audio_recorder', $e );
				return '<p>' . esc_html__( 'Recorder unavailable.', 'starmus-audio-recorder' ) . '</p>';
			}
		} );

		add_shortcode( 'starmus_audio_editor', function() {
			try {
				$editor = new StarmusAudioEditorUI();
				return $editor->render_audio_editor_shortcode();
			} catch ( \Throwable $e ) {
				StarmusLogger::log( 'Shortcode:starmus_audio_editor', $e );
				return '<p>' . esc_html__( 'Editor unavailable.', 'starmus-audio-recorder' ) . '</p>';
			}
		} );

		add_shortcode( 'starmus_my_recordings', [ $this, 'render_my_recordings_shortcode' ] );
		add_shortcode( 'starmus_recording_detail', [ $this, 'render_submission_detail_shortcode' ] );
		add_filter( 'the_content', [ $this, 'render_submission_detail_via_filter' ], 100 );
	}

	/**
	 * Render the "My Recordings" shortcode.
	 */
	public function render_my_recordings_shortcode( $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You must be logged in to view your recordings.', 'starmus-audio-recorder' ) . '</p>';
		}

		try {
			$attributes     = shortcode_atts( [ 'posts_per_page' => 10 ], $atts );
			$posts_per_page = max( 1, absint( $attributes['posts_per_page'] ) );
			$paged          = get_query_var( 'paged' ) ? (int) get_query_var( 'paged' ) : 1;
			$cpt_slug       = $this->settings->get( 'cpt_slug', 'audio-recording' );

			$query = new \WP_Query( [
				'post_type'      => $cpt_slug,
				'author'         => get_current_user_id(),
				'posts_per_page' => $posts_per_page,
				'paged'          => $paged,
				'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
			] );

			return StarmusTemplateLoaderHelper::render_template(
				'parts/starmus-my-recordings-list.php',
				[
					'query'         => $query,
					'edit_page_url' => $this->get_edit_page_url_admin(),
				]
			);
		} catch ( \Throwable $e ) {
			StarmusLogger::log( 'UI:render_my_recordings', $e );
			return '<p>' . esc_html__( 'Unable to load recordings.', 'starmus-audio-recorder' ) . '</p>';
		}
	}

	public function render_submission_detail_shortcode( $atts ) {
		if ( ! is_singular( 'audio-recording' ) ) {
			return '<p><em>[starmus_recording_detail] can only be used on a single audio recording page.</em></p>';
		}

		$post_id          = get_the_ID();
		$template_to_load = '';

		if ( current_user_can( 'edit_others_posts', $post_id ) ) {
			$template_to_load = 'starmus-recording-detail-admin.php';
		} elseif ( is_user_logged_in() && get_current_user_id() == get_post_field( 'post_author', $post_id ) ) {
			$template_to_load = 'starmus-recording-detail-user.php';
		}

		if ( $template_to_load ) {
			return StarmusTemplateLoaderHelper::render_template( $template_to_load );
		}

		return is_user_logged_in()
			? '<p>You do not have permission to view this recording detail.</p>'
			: '<p><em>You must be logged in to view this recording detail.</em></p>';
	}

	public function render_submission_detail_via_filter( string $content ): string {
		if ( ! is_singular( 'audio-recording' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id          = get_the_ID();
		$template_to_load = '';

		if ( current_user_can( 'edit_others_posts', $post_id ) ) {
			$template_to_load = 'parts/starmus-recording-detail-admin.php';
		} elseif ( is_user_logged_in() && get_current_user_id() == get_post_field( 'post_author', $post_id ) ) {
			$template_to_load = 'parts/starmus-recording-detail-user.php';
		}

		if ( $template_to_load ) {
			return StarmusTemplateLoaderHelper::render_template( $template_to_load );
		}

		return '<p>You do not have permission to view this recording detail.</p>';
	}

	private function get_edit_page_url_admin(): string {
		$cpt = $this->settings->get( 'cpt_slug', 'audio-recording' );
		return admin_url( 'edit.php?post_type=' . $cpt );
	}
}
