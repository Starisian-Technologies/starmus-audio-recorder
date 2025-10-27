<?php
/**
 * Unified asset loader for Starmus Audio.
 *
 * @package Starmus
 * @version 0.8.4
 * @since  0.7.5
 */

namespace Starisian\Sparxstar\Starmus\core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\includes\StarmusSubmissionHandler;

class StarmusAssetLoader {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	public function enqueue_frontend_assets(): void {
		try {
			if ( is_admin() ) {
				return;
			}

			global $post;
			$has_content  = ( $post instanceof \WP_Post ) && ! empty( $post->post_content );
			$has_recorder = $has_content && has_shortcode( $post->post_content, 'starmus_audio_recorder' );
			$has_list     = $has_content && has_shortcode( $post->post_content, 'starmus_my_recordings' );
			$has_editor   = $has_content && has_shortcode( $post->post_content, 'starmus_audio_editor' );

			// --- Always-needed dependency for offline uploads + recorder/editor ---
			wp_enqueue_script(
				'tus-js',
				trailingslashit( STARMUS_URL ) . 'vendor/js/tus.min.js',
				array(),
				'4.3.1',
				true
			);

			// If the editor is present, load its deps BEFORE the core app so the app can use them.
			$starmus_app_deps = array( 'tus-js' );

			if ( $has_editor ) {
				// If your build uses these vendor files, load them here.
				// (If your Peaks bundle already includes them, you can remove the first two enqueues.)
				wp_enqueue_script(
					'wavesurfer',
					trailingslashit( STARMUS_URL ) . 'vendor/js/wavesurfer.min.js',
					array(),
					$this->resolve_version(),
					true
				);
				wp_enqueue_script(
					'webaudio-peaks',
					trailingslashit( STARMUS_URL ) . 'vendor/js/webaudio-peaks.min.js',
					array( 'wavesurfer' ),
					$this->resolve_version(),
					true
				);
				wp_enqueue_script(
					'peaks-js',
					trailingslashit( STARMUS_URL ) . 'vendor/js/peaks.min.js',
					array( 'webaudio-peaks' ),
					$this->resolve_version(),
					true
				);

				$starmus_app_deps[] = 'peaks-js';
			}

			// --- Core app bundle (includes Offline Sync + global logic). Loads on EVERY page.
			wp_enqueue_script(
				'starmus-app',
				trailingslashit( STARMUS_URL ) . 'assets/js/starmus-app.min.js',
				$starmus_app_deps,
				$this->resolve_version(),
				true
			);

			// Global data for uploads / REST use (available to app + recorder + editor)
			wp_localize_script(
				'starmus-app',
				'starmusFormData',
				array(
					'rest_url'   => esc_url_raw( rest_url( StarmusSubmissionHandler::STARMUS_REST_NAMESPACE . '/upload-fallback' ) ),
					'rest_nonce' => wp_create_nonce( 'wp_rest' ),
					'user_id'    => get_current_user_id(),
				)
			);

			// --- CSS only when a Starmus UI is present (recorder, list, or editor)
			if ( $has_recorder || $has_list || $has_editor ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					wp_enqueue_style(
						'starmus-audio-styles',
						trailingslashit( STARMUS_URL ) . 'src/css/starmus-audio-recorder-style.css',
						array(),
						$this->resolve_version()
					);
				} else {
					wp_enqueue_style(
						'starmus-audio-styles',
						trailingslashit( STARMUS_URL ) . 'starmus.styles.min.css',
						array(),
						$this->resolve_version()
					);
				}
			}

			// Recorder: if you still have dev-time separates, enqueue them here (optional).
			// In production, recorder/editor logic should already be inside starmus-app.min.js.

		} catch ( \Throwable $e ) {
			StarmusLogger::log( 'Assets:enqueue_frontend_assets', $e );
		}
	}

	private function resolve_version(): string {
		return ( defined( 'STARMUS_VERSION' ) && STARMUS_VERSION ) ? STARMUS_VERSION : '0.7.5';
	}
}
