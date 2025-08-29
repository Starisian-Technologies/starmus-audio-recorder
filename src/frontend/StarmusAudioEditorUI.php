<?php
/**
 * Starmus Audio Editor UI
 *
 * This file is responsible for rendering and managing the front-end audio
 * editor interface, including handling all related scripts and REST API
 * endpoints for saving annotation data.
 *
 * @package Starmus\frontend
 * @since 0.1.0
 * @version 0.3.0
 */

// phpcs:ignore WordPress.Files.FileName
namespace Starmus\frontend;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the user interface and logic for the audio editor.
 */
class StarmusAudioEditorUI {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'starmus/v1';

	/**
	 * Shortcode tag.
	 *
	 * @var string
	 */
	const SHORTCODE = 'starmus_audio_editor';

	/**
	 * Constructor. Registers hooks for shortcodes, scripts, and REST API.
	 */
	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_audio_editor_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_endpoint' ) );
	}

	/**
	 * Renders the [starmus_audio_editor] shortcode.
	 *
	 * @return string The HTML for the audio editor interface or an error message.
	 */
	public function render_audio_editor_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You must be logged in to edit audio.', 'starmus_audio_recorder' ) . '</p>';
		}

		/**
		 * Allow integrators to run code before the editor renders (e.g., enqueue polyfills).
		 */
		do_action( 'starmus_before_editor_render' );

		$context = $this->get_editor_context();
		if ( is_wp_error( $context ) ) {
			return '<div class="notice notice-error"><p>' . esc_html( $context->get_error_message() ) . '</p></div>';
		}

		// Allow themes/plugins to override the template path.
		$default_template = trailingslashit( STARMUS_PATH ) . 'src/templates/starmus-audio-editor-ui.php';
		$template_path    = apply_filters( 'starmus_editor_template', $default_template, $context );

		if ( ! $template_path || ! file_exists( $template_path ) ) {
			return '<div class="notice notice-error"><p>' . esc_html__( 'Editor template not found.', 'starmus_audio_recorder' ) . '</p></div>';
		}

		ob_start();
		// Prefer load_template with $args where available (WP 5.5+).
		if ( function_exists( 'load_template' ) ) {
			load_template( $template_path, false, array( 'args' => $context ) );
		} else {
			// Fallback: make $args available explicitly in the scope of the included file.
			$args = $context; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			include $template_path;
		}
		return (string) ob_get_clean();
	}

	/**
	 * Enqueues scripts and styles for the audio editor, only when the shortcode is present.
	 */
	public function enqueue_scripts(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post    = get_queried_object();
		$content = is_object( $post ) ? ( $post->post_content ?? '' ) : '';

		// Detect shortcode in classic content or as raw text (builder fallback).
		$has_shortcode = ( is_string( $content ) && has_shortcode( $content, self::SHORTCODE ) )
			|| ( is_string( $content ) && false !== strpos( $content, '[' . self::SHORTCODE ) );

		if ( ! $has_shortcode ) {
			return;
		}

		// Ensure context is valid before loading heavy assets.
		$ctx = $this->get_editor_context();
		if ( is_wp_error( $ctx ) ) {
			return;
		}

		wp_enqueue_style(
			'starmus-audio-editor-style',
			STARMUS_URL . 'assets/css/starmus-audio-editor-style.min.css',
			array(),
			STARMUS_VERSION
		);

		wp_enqueue_script(
			'peaks-js',
			STARMUS_URL . 'assets/js/vendor/peaks.min.js',
			array(),
			'2.0.0',
			true
		);

		wp_enqueue_script(
			'starmus-audio-editor',
			STARMUS_URL . 'assets/js/starmus-audio-editor.min.js',
			array( 'peaks-js', 'wp-api-fetch' ),
			STARMUS_VERSION,
			true
		);

		$annotations_data = json_decode( (string) $ctx['annotations_json'], true );
		if ( ! is_array( $annotations_data ) ) {
			$annotations_data = array();
		}

		wp_localize_script(
			'starmus-audio-editor',
			'STARMUS_EDITOR_DATA',
			array(
				'restUrl'         => esc_url_raw( rest_url( self::REST_NAMESPACE . '/annotations' ) ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'postId'          => (int) $ctx['post_id'],
				'audioUrl'        => esc_url( (string) $ctx['audio_url'] ),
				'waveformDataUrl' => esc_url( (string) $ctx['waveform_url'] ),
				'annotations'     => $annotations_data,
			)
		);
	}

	/**
	 * Build and validate the editor context (nonce, permissions, assets).
	 *
	 * @return array|WP_Error
	 */
	private function get_editor_context() {
		// Validate nonce passed via query: ?nonce=... (e.g., link from dashboard).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce_raw = isset( $_GET['nonce'] ) ? wp_unslash( $_GET['nonce'] ) : '';
		$nonce     = sanitize_key( (string) $nonce_raw );

		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'starmus_edit_audio' ) ) {
			return new WP_Error( 'invalid_nonce', __( 'Security check failed. Please go back and try again.', 'starmus_audio_recorder' ) );
		}

		$post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id ) {
			return new WP_Error( 'invalid_id', __( 'Invalid submission ID.', 'starmus_audio_recorder' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'permission_denied', __( 'You do not have permission to edit this item.', 'starmus_audio_recorder' ) );
		}

		$attachment_id = (int) get_post_meta( $post_id, '_audio_attachment_id', true );
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'no_audio', __( 'No audio file is attached to this submission.', 'starmus_audio_recorder' ) );
		}

		$audio_url = wp_get_attachment_url( $attachment_id );
		if ( ! $audio_url ) {
			return new WP_Error( 'no_audio_url', __( 'Audio file URL not available.', 'starmus_audio_recorder' ) );
		}

		$uploads        = wp_get_upload_dir();
		$wave_json_path = (string) get_post_meta( $attachment_id, '_waveform_json_path', true );
		$waveform_url   = '';
		if ( $wave_json_path && file_exists( $wave_json_path ) ) {
			$waveform_url = str_replace( (string) $uploads['basedir'], (string) $uploads['baseurl'], $wave_json_path );
		}

		$annotations_json = get_post_meta( $post_id, 'starmus_annotations_json', true );
		$annotations_json = is_string( $annotations_json ) && '' !== $annotations_json ? $annotations_json : '[]';

		return array(
			'post_id'          => $post_id,
			'attachment_id'    => $attachment_id,
			'audio_url'        => $audio_url,
			'waveform_url'     => $waveform_url,
			'annotations_json' => $annotations_json,
		);
	}

	/**
	 * Registers the REST endpoint for saving annotations.
	 */
	public function register_rest_endpoint(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/annotations',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_save_annotations' ),
				'permission_callback' => array( $this, 'can_save_annotations' ),
				'args'                => array(
					'postId'      => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $val ) {
							return ( is_numeric( $val ) && (int) $val > 0 );
						},
					),
					'annotations' => array(
						'required'          => true,
						'validate_callback' => function ( $val ) {
							if ( ! is_array( $val ) ) {
								return false;
							}
							// Cap segments to prevent payload abuse.
							return count( $val ) <= 1000;
						},
					),
				),
			)
		);
	}

	/**
	 * Permission + CSRF checks for saving annotations.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_save_annotations( WP_REST_Request $request ): bool {
		// Validate REST nonce from header.
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return false;
		}

		$post_id = (int) $request->get_param( 'postId' );
		if ( ! $post_id ) {
			return false;
		}

		// Require capability to edit this post.
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Handles saving the annotations from the editor via REST API.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response The REST response.
	 */
	public function handle_save_annotations( WP_REST_Request $request ): WP_REST_Response {
		$post_id     = (int) $request->get_param( 'postId' );
		$annotations = $request->get_param( 'annotations' );

		// Simple per-user rate limit: 1 write / 2s per post.
		$key = 'starmus_ann_rl_' . get_current_user_id() . '_' . $post_id;
		if ( get_transient( $key ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Please wait before saving again.', 'starmus_audio_recorder' ) ),
				429
			);
		}
		set_transient( $key, 1, 2 );

		$sanitized = array();
		foreach ( (array) $annotations as $seg ) {
			if ( ! is_array( $seg ) || empty( $seg['id'] ) || ! isset( $seg['startTime'], $seg['endTime'] ) ) {
				continue;
			}
			$start = (float) $seg['startTime'];
			$end   = (float) $seg['endTime'];
			if ( $start < 0 || $end <= $start ) {
				continue;
			}
			$label = isset( $seg['label'] ) ? wp_strip_all_tags( (string) $seg['label'] ) : '';
			if ( strlen( $label ) > 200 ) {
				$label = substr( $label, 0, 200 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.mb_strlen
			}
			$sanitized[] = array(
				'id'        => sanitize_key( (string) $seg['id'] ),
				'startTime' => $start,
				'endTime'   => $end,
				'label'     => $label,
			);
		}

		// Sort by start time and prevent overlaps.
		usort(
			$sanitized,
			static function ( $a, $b ) {
				return $a['startTime'] <=> $b['startTime'];
			}
		);

		$sc = count( $sanitized );
		for ( $i = 1; $i < $sc; $i++ ) {
			if ( $sanitized[ $i ]['startTime'] < $sanitized[ $i - 1 ]['endTime'] ) {
				return new WP_REST_Response(
					array( 'message' => __( 'Overlapping annotations are not allowed.', 'starmus_audio_recorder' ) ),
					400
				);
			}
		}

		/**
		 * Allow custom validation or transformation of annotations before saving.
		 *
		 * @param array $sanitized
		 * @param int   $post_id
		 */
		$sanitized = (array) apply_filters( 'starmus_before_annotations_save', $sanitized, $post_id );

		update_post_meta(
			$post_id,
			'starmus_annotations_json',
			wp_json_encode( $sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);

		/**
		 * Fires after annotations are saved.
		 *
		 * @param int   $post_id
		 * @param array $sanitized
		 */
		do_action( 'starmus_after_annotations_save', $post_id, $sanitized );

		return new WP_REST_Response(
			array(
				'message'     => __( 'Annotations saved.', 'starmus_audio_recorder' ),
				'annotations' => $sanitized,
			),
			200
		);
	}
}
