<?php
/**
 * Unified asset loader for Starmus Audio.
 *
 * @package Starmus
 * @version 0.7.6
 * @since  0.7.5
 */

namespace Starmus\includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starmus\helpers\StarmusLogger;
use Starmus\frontend\StarmusSubmissionHandler;

class StarmusAssetLoader {

	/**
	 * Boot hooks.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Enqueue styles/scripts on frontend conditionally.
	 */
	public function enqueue_frontend_assets(): void {
		try {
			if ( is_admin() ) {
				return;
			}

			global $post;
			if ( ! is_a( $post, 'WP_Post' ) || empty( $post->post_content ) ) {
				return;
			}

			$has_recorder = has_shortcode( $post->post_content, 'starmus_audio_recorder_form' );
			$has_list     = has_shortcode( $post->post_content, 'starmus_my_recordings' );

			if ( ! $has_recorder && ! $has_list ) {
				return;
			}

			// ... your wp_enqueue_style calls ...
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				wp_enqueue_style(
					'starmus-audio-styles',
					trailingslashit( STARMUS_URL ) . 'src/css/starmus-audio-recorder-style.css',
					array(),
					defined( 'STARMUS_VERSION' ) ? STARMUS_VERSION : '1.2.0'
				);
			} else {
				wp_enqueue_style(
					'starmus-audio-styles',
					trailingslashit( STARMUS_URL ) . 'starmus.styles.min.css',
					array(),
					defined( 'STARMUS_VERSION' ) ? STARMUS_VERSION : '1.2.0'
				);
			}

			// --- SCRIPTS (Recorder Only) ---
			if ( $has_recorder ) {
				// --- YOUR EXISTING SCRIPT ENQUEUEING (This is correct) ---
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					wp_enqueue_script( 'starmus-hooks', trailingslashit( STARMUS_URL ) . 'src/js/starmus-audio-recorder-hooks.js', array(), STARMUS_VERSION, true );
					wp_enqueue_script( 'starmus-recorder-module', trailingslashit( STARMUS_URL ) . 'src/js/starmus-audio-recorder-module.js', array( 'starmus-hooks' ), STARMUS_VERSION, true );
					wp_enqueue_script( 'starmus-submissions-handler', trailingslashit( STARMUS_URL ) . 'src/js/starmus-audio-recorder-submissions-handler.js', array( 'starmus-hooks' ), STARMUS_VERSION, true );
					wp_enqueue_script( 'starmus-ui-controller', trailingslashit( STARMUS_URL ) . 'src/js/starmus-audio-recorder-ui-controller.js', array( 'starmus-hooks', 'starmus-recorder-module', 'starmus-submissions-handler' ), STARMUS_VERSION, true );
				} else {
					// In production, your main app script should be the one to localize to.
					wp_enqueue_script( 'starmus-app', trailingslashit( STARMUS_URL ) . 'assets/js/starmus-app.min.js', array(), STARMUS_VERSION, true );
				}

				wp_enqueue_script( 'tus-js', trailingslashit( STARMUS_URL ) . 'vendor/js/tus.min.js', array(), '4.3.1', true );

				// --- THIS IS THE CRITICAL FIX ---
				// Determine which script handle to attach the data to.
				$handler_script_handle = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'starmus-submissions-handler' : 'starmus-app';

				// This block creates the `window.starmusFormData` object that your JS is missing.
				wp_localize_script(
					$handler_script_handle,
					'starmusFormData',
					array(
						'rest_url'   => esc_url_raw( rest_url( StarmusSubmissionHandler::STAR_REST_NAMESPACE . '/upload-fallback' ) ),
						'rest_nonce' => wp_create_nonce( 'wp_rest' ),
						'user_id'    => get_current_user_id(),
					)
				);
			}
		} catch ( \Throwable $e ) {
			StarmusLogger::log( 'Assets:enqueue_frontend_assets', $e );
		}
	}

	/**
	 * Enqueue admin styles for detail/editor screens.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		try {
			// Only load on CPT edit + single view.
			global $post_type;
			if ( $post_type !== 'audio-recording' ) {
				return;
			}

			wp_enqueue_style(
				'starmus-audio-styles',
				trailingslashit( STARMUS_URL ) . 'assets/css/starmus-audio-plugin.css',
				array(),
				defined( 'STARMUS_VERSION' ) ? STARMUS_VERSION : '1.2.0'
			);
		} catch ( \Throwable $e ) {
			StarmusLogger::log( 'Assets:enqueue_admin_assets', $e );
		}
	}
}
