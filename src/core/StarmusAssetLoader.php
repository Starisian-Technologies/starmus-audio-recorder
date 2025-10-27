<?php
/**
 * Unified asset loader for Starmus Audio.
 *
 * @package Starmus
 * @version 0.8.5
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
			// Ensure you have tus.min.js in vendor/js/
			wp_enqueue_script(
				'tus-js',
				trailingslashit( STARMUS_URL ) . 'vendor/js/tus.min.js',
				array(),
				'4.3.1', // Use the version you have downloaded
				true
			);

			// If the editor is present, load its deps BEFORE the core app so the app can use them.
			$starmus_app_deps = array( 'tus-js' );

			if ( $has_editor ) {
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
            
            // ====================================================================
            // ADDED THIS BLOCK: Pass tusd configuration to the frontend.
            // ====================================================================
            $tus_config = array(
                // This creates the public URL your JavaScript needs to talk to Apache.
                // The '/files/' path MUST match your Apache reverse proxy <Location> block.
                'endpoint'    => trailingslashit( home_url( '/files/' ) ),
                
                // You can define other tus-js-client options here.
                'chunkSize'   => 5 * 1024 * 1024, // 5 MB
                'retryDelays' => array( 0, 3000, 5000, 10000, 20000 ),
                'headers'     => array(), // If using a shared secret, add it here. e.g., 'X-Starmus-Secret' => 'your-token'
            );

            // This makes the $tus_config array available in JavaScript as the global `window.starmusTus` object.
            wp_localize_script(
                'starmus-app',       // Attach the data to your main app script.
                'starmusTus',        // The name of the JavaScript object.
                $tus_config          // The data itself.
            );
            // ====================================================================
            // END OF ADDED BLOCK
            // ====================================================================


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

		} catch ( \Throwable $e ) {
			StarmusLogger::log( 'Assets:enqueue_frontend_assets', $e );
		}
	}

	private function resolve_version(): string {
		return ( defined( 'STARMUS_VERSION' ) && STARMUS_VERSION ) ? STARMUS_VERSION : '0.7.5';
	}
}
