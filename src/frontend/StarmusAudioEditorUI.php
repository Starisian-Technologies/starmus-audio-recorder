<?php

/**
 * Starmus Audio Editor UI - Refactored for Security & Performance
 *
 * @package Starmus\frontend
 * @version 0.6.4
 * @since 0.3.1
 */

namespace Starmus\frontend;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use Throwable;
use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Secure and optimized audio editor UI class.
 *
 * @since 0.3.1
 * @version 0.6.4
 * @package Starmus\frontend
 */
class StarmusAudioEditorUI {


	const STAR_REST_NAMESPACE = 'starmus/v1';

	const STAR_MAX_ANNOTATIONS    = 1000;
	const STAR_RATE_LIMIT_SECONDS = 2;

	private ?array $cached_context = null;

	public function __construct() {
		// Initialization if needed
	}

	public function render_audio_editor_shortcode(): string {
		try {
			if ( ! is_user_logged_in() ) {
				return '<p>' . esc_html__( 'You must be logged in to edit audio.', STARMUS_TEXT_DOMAIN ) . '</p>';
			}
			do_action( 'starmus_before_editor_render' );
			$context = $this->get_editor_context();
			if ( is_wp_error( $context ) ) {
				return '<div class="notice notice-error"><p>' . esc_html( $context->get_error_message() ) . '</p></div>';
			}
			return $this->render_template_secure( $context );
		} catch ( Throwable $e ) {
			$this->log_error( 'Editor shortcode error', $e );
			return '<div class="notice notice-error"><p>' . esc_html__( 'Audio editor unavailable.', STARMUS_TEXT_DOMAIN ) . '</p></div>';
		}
	}

	private function render_template_secure( array $context ): string {
		$default_template = STARMUS_PATH . 'src/templates/starmus-audio-editor-ui.php';
		$template_path    = apply_filters( 'starmus_editor_template', $default_template, $context );
		if ( ! $this->is_valid_template_path( $template_path ) ) {
			return '<div class="notice notice-error"><p>' . esc_html__( 'Invalid template path.', STARMUS_TEXT_DOMAIN ) . '</p></div>';
		}
		if ( ! file_exists( $template_path ) ) {
			return '<div class="notice notice-error"><p>' . esc_html__( 'Editor template not found.', STARMUS_TEXT_DOMAIN ) . '</p></div>';
		}
		try {
			ob_start();
			$args = $context;
			include $template_path;
			$output = ob_get_clean();
			return $output !== false ? $output : '';
		} catch ( Throwable $e ) {
			$this->log_error( 'Editor template error', $e );
			return '<div class="notice notice-error"><p>' . esc_html__( 'Template loading failed.', STARMUS_TEXT_DOMAIN ) . '</p></div>';
		}
	}

	private function is_valid_template_path( string $path ): bool {
		$real_path = realpath( $path );
		if ( ! $real_path ) {
			return false;
		}
		$allowed_dirs = array_filter(
			array(
				realpath( STARMUS_PATH ),
				realpath( get_template_directory() ),
				realpath( get_stylesheet_directory() ),
			)
		);
		foreach ( $allowed_dirs as $allowed_dir ) {
			if ( $allowed_dir && str_starts_with( $real_path, $allowed_dir ) ) {
				return true;
			}
		}
		return false;
	}

	public function enqueue_scripts(): void {
		try {
			if ( ! is_singular() ) {
				return;
			}
			global $post;
			if ( ! $post || ! has_shortcode( $post->post_content, 'starmus_audio_editor' ) ) {
				return;
			}
			$context = $this->get_editor_context();
			if ( is_wp_error( $context ) ) {
				// Don't enqueue scripts if there's an error loading the context.
				return;
			}
			wp_enqueue_style( 'starmus-unified-styles', STARMUS_URL . 'assets/css/starmus-styles.min.css', array(), STARMUS_VERSION );
			wp_enqueue_script( 'peaks-js', STARMUS_URL . 'vendor/js/peaks.min.js', array(), '4.0.0', true );
			wp_enqueue_script(
				'starmus-audio-editor',
				STARMUS_URL . 'src/js/starmus-audio-editor.js',
				array( 'jquery', 'peaks-js' ),
				STARMUS_VERSION,
				true
			);
			$annotations_data = $this->parse_annotations_json( $context['annotations_json'] );
			wp_localize_script(
				'starmus-audio-editor',
				'STARMUS_EDITOR_DATA',
				array(
					'restUrl'         => esc_url_raw( rest_url( self::STAR_REST_NAMESPACE . '/annotations' ) ),
					'nonce'           => wp_create_nonce( 'wp_rest' ),
					'postId'          => absint( $context['post_id'] ),
					'audioUrl'        => esc_url( $context['audio_url'] ),
					'waveformDataUrl' => esc_url( $context['waveform_url'] ),
					'annotations'     => $annotations_data,
				)
			);
		} catch ( Throwable $e ) {
			$this->log_error( 'Editor script enqueue error', $e );
		}
	}

	private function parse_annotations_json( string $json ): array {
		try {
			if ( empty( $json ) ) {
				return array();
			}
			$data = json_decode( $json, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				throw new Exception( json_last_error_msg() );
			}
			return is_array( $data ) ? $data : array();
		} catch ( Throwable $e ) {
			$this->log_error( 'JSON parsing error', $e );
			return array();
		}
	}

	private function get_editor_context(): array|WP_Error {
		try {
			if ( $this->cached_context !== null ) {
				return $this->cached_context;
			}
			$nonce = sanitize_key( $_GET['nonce'] ?? '' );
			if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'starmus_edit_audio' ) ) {
				return new WP_Error( 'invalid_nonce', __( 'Security check failed.', STARMUS_TEXT_DOMAIN ) );
			}
			$post_id = absint( $_GET['post_id'] ?? 0 );
			if ( ! $post_id || ! get_post( $post_id ) ) {
				return new WP_Error( 'invalid_id', __( 'Invalid submission ID.', STARMUS_TEXT_DOMAIN ) );
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'permission_denied', __( 'Permission denied.', STARMUS_TEXT_DOMAIN ) );
			}
			$attachment_id = absint( get_post_meta( $post_id, '_audio_attachment_id', true ) );
			if ( ! $attachment_id || get_post_type( $attachment_id ) !== 'attachment' ) {
				return new WP_Error( 'no_audio', __( 'No audio file attached.', STARMUS_TEXT_DOMAIN ) );
			}
			$audio_url = wp_get_attachment_url( $attachment_id );
			if ( ! $audio_url ) {
				return new WP_Error( 'no_audio_url', __( 'Audio file URL not available.', STARMUS_TEXT_DOMAIN ) );
			}
			$waveform_url         = $this->get_secure_waveform_url( $attachment_id );
			$annotations_json     = get_post_meta( $post_id, 'starmus_annotations_json', true );
			$annotations_json     = is_string( $annotations_json ) ? $annotations_json : '[]';
			$this->cached_context = array(
				'post_id'          => $post_id,
				'attachment_id'    => $attachment_id,
				'audio_url'        => $audio_url,
				'waveform_url'     => $waveform_url,
				'annotations_json' => $annotations_json,
			);
			return $this->cached_context;
		} catch ( Throwable $e ) {
			$this->log_error( 'Context retrieval error', $e );
			return new WP_Error( 'context_error', __( 'Unable to load editor context.', STARMUS_TEXT_DOMAIN ) );
		}
	}

	private function get_secure_waveform_url( int $attachment_id ): string {
		$wave_json_path = get_post_meta( $attachment_id, '_waveform_json_path', true );
		if ( ! is_string( $wave_json_path ) || empty( $wave_json_path ) ) {
			return '';
		}
		$uploads          = wp_get_upload_dir();
		$real_wave_path   = realpath( $wave_json_path );
		$real_uploads_dir = realpath( $uploads['basedir'] );
		if ( ! $real_wave_path || ! $real_uploads_dir || ! str_starts_with( $real_wave_path, $real_uploads_dir ) ) {
			return '';
		}
		if ( ! file_exists( $wave_json_path ) ) {
			return '';
		}
		return str_replace( $uploads['basedir'], $uploads['baseurl'], $wave_json_path );
	}

	public function register_rest_endpoint(): void {
		register_rest_route(
			self::STAR_REST_NAMESPACE,
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
						'validate_callback' => array( $this, 'validate_post_id' ),
					),
					'annotations' => array(
						'required'          => true,
						'sanitize_callback' => array( $this, 'sanitize_annotations' ),
						'validate_callback' => array( $this, 'validate_annotations' ),
					),
				),
			)
		);
	}

	public function validate_post_id( $value ): bool {
		return is_numeric( $value ) && $value > 0 && get_post( absint( $value ) ) !== null;
	}

	public function sanitize_annotations( $value ): array {
		try {
			if ( ! is_array( $value ) ) {
				return array();
			}
			$sanitized = array();
			foreach ( $value as $annotation ) {
				if ( ! is_array( $annotation ) ) {
					continue;
				}
				$sanitized[] = array(
					'id'        => sanitize_key( $annotation['id'] ?? '' ),
					'startTime' => floatval( $annotation['startTime'] ?? 0 ),
					'endTime'   => floatval( $annotation['endTime'] ?? 0 ),
					'labelText' => wp_kses_post( $annotation['labelText'] ?? '' ),
					'color'     => sanitize_hex_color( $annotation['color'] ?? '#000000' ),
				);
			}
			return $sanitized;
		} catch ( Throwable $e ) {
			$this->log_error( 'Annotation sanitization error', $e );
			return array();
		}
	}


	public function validate_annotations( $value ): bool {
		if ( ! is_array( $value ) || count( $value ) > self::STAR_MAX_ANNOTATIONS ) {
			return false;
		}
		foreach ( $value as $annotation ) {
			if ( ! is_array( $annotation ) ) {
				return false;
			}
			if ( ! isset( $annotation['startTime'], $annotation['endTime'] ) ) {
				return false;
			}
			$start = floatval( $annotation['startTime'] );
			$end   = floatval( $annotation['endTime'] );
			if ( $start < 0 || $end < 0 || $start >= $end ) {
				return false;
			}
		}
		return true;
	}

	public function can_save_annotations( WP_REST_Request $request ): bool {
		try {
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return false;
			}
			$post_id = absint( $request->get_param( 'postId' ) );
			if ( ! $post_id || ! get_post( $post_id ) ) {
				return false;
			}
			return current_user_can( 'edit_post', $post_id );
		} catch ( Throwable $e ) {
			$this->log_error( 'Permission check error', $e );
			return false;
		}
	}

	public function handle_save_annotations( WP_REST_Request $request ): WP_REST_Response {
		try {
			$post_id     = absint( $request->get_param( 'postId' ) );
			$annotations = $request->get_param( 'annotations' );
			if ( $this->is_rate_limited( $post_id ) ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Too many requests. Please wait.', STARMUS_TEXT_DOMAIN ),
					),
					429
				);
			}
			$validation_result = $this->validate_annotation_consistency( $annotations );
			if ( is_wp_error( $validation_result ) ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => $validation_result->get_error_message(),
					),
					400
				);
			}
			$json_data = wp_json_encode( $annotations );
			if ( $json_data === false ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Failed to encode annotations.', STARMUS_TEXT_DOMAIN ),
					),
					500
				);
			}
			do_action( 'starmus_before_annotations_save', $post_id, $annotations );
			update_post_meta( $post_id, 'starmus_annotations_json', $json_data );
			do_action( 'starmus_after_annotations_save', $post_id, $annotations );
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Annotations saved successfully.', STARMUS_TEXT_DOMAIN ),
					'count'   => count( $annotations ),
				),
				200
			);
		} catch ( Throwable $e ) {
			$this->log_error( 'Save annotations error', $e );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Internal server error.', STARMUS_TEXT_DOMAIN ),
				),
				500
			);
		}
	}

	private function is_rate_limited( int $post_id ): bool {
		$user_id = get_current_user_id();
		$key     = "starmus_ann_rl_{$user_id}_{$post_id}";
		if ( get_transient( $key ) ) {
			return true;
		}
		set_transient( $key, true, self::STAR_RATE_LIMIT_SECONDS );
		return false;
	}

	private function validate_annotation_consistency( array $annotations ) {
		if ( empty( $annotations ) ) {
			return true;
		}
		usort(
			$annotations,
			function ( $a, $b ) {
				return $a['startTime'] <=> $b['startTime'];
			}
		);
		for ( $i = 0; $i < count( $annotations ) - 1; $i++ ) {
			$current = $annotations[ $i ];
			$next    = $annotations[ $i + 1 ];
			if ( $current['endTime'] > $next['startTime'] ) {
				return new WP_Error( 'overlap_detected', __( 'Overlapping annotations detected.', STARMUS_TEXT_DOMAIN ) );
			}
		}
		return true;
	}

	private function log_error( string $context, Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log(
				sprintf(
					'Starmus Editor: %s - %s in %s:%d',
					sanitize_text_field( $context ),
					sanitize_text_field( $e->getMessage() ),
					$e->getFile(),
					$e->getLine()
				)
			);
		}
	}
}
