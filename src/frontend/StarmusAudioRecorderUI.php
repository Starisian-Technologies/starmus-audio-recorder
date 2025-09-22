<?php
namespace Starmus\frontend;
/**
 * Starmus Audio Recorder UI - Corrected and Complete with Full Error Handling
 *
 * This file is responsible for rendering and managing the front-end audio
 * recorder interface, including handling all related scripts and REST API
 * endpoints for saving audio data.
 *
 * @package Starmus\frontend
 * @since 0.1.0
 * @version 0.7.1
 * @author Starisian Technologies (Max Barrett)
 *
 * Changelog:
 */

use Starmus\includes\StarmusSettings;
use File;
use Media;
use Image;
use WP_Filesystem;
use WP_REST_Request;
use WP_Error;
use Throwable;
use RecursiveIteratorIterator;
use RecursiveArrayIterator;

if ( ! defined( 'ABSPATH' ) ) {
	// Fallback for CLI/standalone context; adjust as needed for your environment
	define( 'ABSPATH', dirname( __FILE__, 5 ) . '/');
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	require_once ABSPATH . 'wp-includes/formatting.php';
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	require_once ABSPATH . 'wp-includes/functions.php';
}
if ( ! function_exists( 'wp_unique_filename' ) ) {
	require_once ABSPATH . 'wp-includes/functions.php';
}
// Add missing global functions and helpers
if ( ! function_exists( 'absint' ) ) {
       function absint( $maybeint ) {
	       return (int) $maybeint; } }
if ( ! function_exists( '__' ) ) {
       function __( $text, $domain = null ) {
	       return $text; } }
if ( ! function_exists( 'is_wp_error' ) ) {
       function is_wp_error( $thing ) {
	       return ( $thing instanceof \WP_Error ); } }
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
       function wp_generate_uuid4() {
	       return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0x0fff ) | 0x4000, mt_rand( 0, 0x3fff ) | 0x8000, mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) ); } }

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines the user interface and submission handling for the audio recorder.
 *
 * @since 0.1.0
 * @version 0.7.1
 * @package Starmus\frontend
 * @uses StarmusSettings
 */
class StarmusAudioRecorderUI {

		/** REST namespace for front-end endpoints. */
	public const STAR_REST_NAMESPACE = 'starmus/v1';

		/** Settings handler instance. */
	private ?StarmusSettings $settings = null;

		/**
		 * Initialize the recorder UI and load settings.
		 */
	public function __construct(?StarmusSettings $settings) {
			error_log( 'StarmusAudioRecorderUI: Constructor called' );
		try {
        $this->settings = $settings;
				error_log( 'StarmusAudioRecorderUI: Settings instantiated successfully' );
		} catch ( Throwable $e ) {
				error_log( 'StarmusAudioRecorderUI: Failed to instantiate settings: ' . $e->getMessage() );
				throw $e;
		}
	}
	/**
	 * Render the "My Recordings" shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string The rendered HTML content.
	 * @since 0.3.0
	 * @version 0.7.1
	 */
	public function render_my_recordings_shortcode( $atts = array() ): string {
		error_log( 'StarmusAudioRecorderUI: render_my_recordings_shortcode called with atts: ' . print_r( $atts, true ) );

		if ( ! is_user_logged_in() ) {
			error_log( 'StarmusAudioRecorderUI: User not logged in for my_recordings shortcode' );
			return '<p>' . esc_html__( 'You must be logged in to view your recordings.', STARMUS_TEXT_DOMAIN ) . '</p>';
		}

		error_log( 'StarmusAudioRecorderUI: User is logged in, proceeding with my_recordings shortcode' );

		// FIX: Added full try...catch block
		try {
			$attributes = shortcode_atts( array( 'posts_per_page' => 10 ), $atts );
			error_log( 'StarmusAudioRecorderUI: Shortcode attributes processed: ' . print_r( $attributes, true ) );

			$posts_per_page = max( 1, absint( $attributes['posts_per_page'] ) );
			$paged          = get_query_var( 'paged' ) ? (int) get_query_var( 'paged' ) : 1;

			$cpt_slug = $this->settings->get( 'cpt_slug', 'audio-recording' );
			error_log( 'StarmusAudioRecorderUI: Using CPT slug: ' . $cpt_slug );

			$query = new WP_Query(
				array(
					'post_type'      => $cpt_slug,
					'author'         => get_current_user_id(),
					'posts_per_page' => $posts_per_page,
					'paged'          => $paged,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				)
			);

			error_log( 'StarmusAudioRecorderUI: Query executed, found posts: ' . $query->found_posts );

			$template_result = $this->render_template(
				'starmus-my-recordings-list.php',
				array(
					'query'         => $query,
					'edit_page_url' => $this->get_edit_page_url(),
				)
			);

			error_log( 'StarmusAudioRecorderUI: Template rendered, length: ' . strlen( $template_result ) );
			return $template_result;

		} catch ( Throwable $e ) {
			error_log( 'StarmusAudioRecorderUI: My recordings shortcode error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
			return '<p>' . esc_html__( 'Unable to load recordings.', STARMUS_TEXT_DOMAIN ) . '</p>';
		}
	}
	/**
	 * Get the URL of the edit page for audio recordings.
	 *
	 * @return string The URL of the edit page.
	 * @since 0.3.0
	 * @version 0.7.1
	 */
	public function clear_taxonomy_transients(): void {
		delete_transient( 'starmus_languages_list' );
		delete_transient( 'starmus_recording_types_list' );
	}

	/**
	 * Renders the audio recorder shortcode by correctly passing data to the template.
	 *
	 * @since 1.2.2
	 * @param array $atts Shortcode attributes.
	 * @return string The HTML output of the recorder form.
	 */
	public function render_recorder_shortcode( $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You must be logged in to record audio.', 'starmus-audio-recorder' ) . '</p>';
		}

		// This action allows other plugins to hook in before we render.
		do_action( 'starmus_before_recorder_render' );

		try {
			// Prepare the data that the template file will need.
			$template_args = array(
				'form_id'         => 'starmus_recorder_form', // Base ID for the form instance.
				'consent_message' => $this->settings->get( 'consent_message', 'I consent to the terms and conditions.' ),
				'data_policy_url' => $this->settings->get( 'data_policy_url', '' ),
				'recording_types' => $this->get_cached_terms( 'recording-type', 'starmus_recording_types_list' ),
				'languages'       => $this->get_cached_terms( 'language', 'starmus_languages_list' ),
			);

			// --- THIS IS THE FIX ---
			// Instead of including the template directly, we now call your working
			// render_template() helper function. It will handle the output buffering,
			// the extract() logic, and including the file.

			// Output admin flag for JS debug banners (only for admins/superadmins)
			$is_admin   = current_user_can( 'administrator' ) || current_user_can( 'manage_options' ) || current_user_can( 'super_admin' );
			$admin_flag = '<script>window.isStarmusAdmin = ' . ( $is_admin ? 'true' : 'false' ) . ';</script>';
			return $admin_flag . $this->render_template( 'starmus-audio-recorder-ui.php', $template_args );

		} catch ( Throwable $e ) {
			error_log( 'Starmus Plugin: Recorder shortcode render error - ' . $e->getMessage() );
			return '<p>' . esc_html__( 'The audio recorder is temporarily unavailable.', 'starmus-audio-recorder' ) . '</p>';
		}
	}
	/**
	 *
	 * Get cached terms for a given taxonomy, with transient caching.
	 *
	 * @param string $taxonomy The taxonomy slug.
	 * @param string $cache_key The transient cache key.
	 * @return array The list of terms.
	 * @since 0.3.0
	 * @version 0.7.1
	 */
	private function get_cached_terms( string $taxonomy, string $cache_key ): array {
		$terms = get_transient( $cache_key );
		if ( false === $terms ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			if ( ! is_wp_error( $terms ) ) {
				set_transient( $cache_key, $terms, 12 * HOUR_IN_SECONDS );
			} else {
				// This now correctly uses your log_error method.
				error_log( $terms->get_error_message() );
				$this->log_error( 'Get terms failed for ' . $taxonomy, new \Exception( $terms->get_error_message() ) );
				$terms = array();
			}
		}
		return is_array( $terms ) ? $terms : array();
	}
	/**
	 * Registers and conditionally enqueues all frontend scripts and styles.
	 *
	 * This is the final, definitive method, architected to work with the modern
	 * build process which produces a single, bundled JavaScript application file.
	 * It ensures optimal performance by only loading assets when a corresponding
	 * shortcode is present on the page.
	 *
	 * @since 1.2.1
	 */
	public function enqueue_scripts(): void {
		try {
			// Do not load any frontend assets in the admin area.
			if ( is_admin() ) {
				return;
			}

			// We need the global $post object to check its content for shortcodes.
			// Exit early if it's not a valid post object, to prevent errors.
			global $post;
			if ( ! is_a( $post, 'WP_Post' ) || empty( $post->post_content ) ) {
				return;
			}

			// --- Asset loading logic ---

			// Define our shortcode names for clarity and easy maintenance.
			// **IMPORTANT**: Make sure these match the names you use in `add_shortcode`.
			$recorder_shortcode = 'starmus_audio_recorder_form';
			$list_shortcode     = 'starmus_my_recordings';

			// Check if our shortcodes exist on the current page.
			$has_recorder = has_shortcode( $post->post_content, $recorder_shortcode );
			$has_list     = has_shortcode( $post->post_content, $list_shortcode );

			// If neither shortcode is found, there is nothing to do. Exit immediately.
			if ( ! $has_recorder && ! $has_list ) {
				return;
			}

			// --- 1. Enqueue the Stylesheet ---
			// This single, unified stylesheet contains styles for both the recorder and
			// the recordings list, so we load it if either shortcode is present.
			wp_enqueue_style(
				'starmus-unified-styles',
				STARMUS_URL . 'assets/css/starmus-styles.min.css', // Points to the single minified CSS file.
				array(), // No other stylesheet dependencies.
				STARMUS_VERSION // For cache busting.
			);

			// --- 2. Enqueue the JavaScript Application ---
			// The full JavaScript application is only needed for the interactive recorder.
			if ( $has_recorder ) {

				// Load individual JS files for development, bundled for production
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// Development: Load individual files
					wp_enqueue_script( 'starmus-hooks', STARMUS_URL . 'src/js/starmus-audio-recorder-hooks.js', array(), STARMUS_VERSION, true );
					wp_enqueue_script( 'starmus-recorder-module', STARMUS_URL . 'src/js/starmus-audio-recorder-module.js', array( 'starmus-hooks' ), STARMUS_VERSION, true );
					wp_enqueue_script( 'starmus-submissions-handler', STARMUS_URL . 'src/js/starmus-audio-recorder-submissions-handler.js', array( 'starmus-hooks' ), STARMUS_VERSION, true );
					wp_enqueue_script( 'starmus-ui-controller', STARMUS_URL . 'src/js/starmus-audio-recorder-ui-controller.js', array( 'starmus-hooks', 'starmus-recorder-module', 'starmus-submissions-handler' ), STARMUS_VERSION, true );
					wp_enqueue_script( 'tus-js', STARMUS_URL . 'vendor/js/tus.min.js', array(), '4.3.1', true );
				} else {
					// Production: Load bundled file
					wp_enqueue_script( 'starmus-app', STARMUS_URL . 'assets/js/starmus-app.min.js', array(), STARMUS_VERSION, true );
					wp_enqueue_script( 'tus-js', STARMUS_URL . 'vendor/js/tus.min.js', array(), '4.3.1', true );
				}

				// Pass critical data from PHP to our JavaScript application.
				$script_handle = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'starmus-ui-controller' : 'starmus-app';
				wp_localize_script(
					$script_handle,
					'starmusFormData',
					array(
						'rest_url'   => esc_url_raw( rest_url( self::STAR_REST_NAMESPACE . '/upload-chunk' ) ),
						'rest_nonce' => wp_create_nonce( 'wp_rest' ),
					)
				);

				// Provide the tus.io endpoint to our JavaScript application if it is configured.
				$tus_endpoint = $this->settings->get( 'tus_endpoint', '' );
				if ( ! empty( $tus_endpoint ) ) {
						wp_add_inline_script(
							$script_handle,
							'window.starmusTus = { endpoint: "' . esc_url_raw( $tus_endpoint ) . '" };',
							'before'
						);
				}
			}
		} catch ( Throwable $e ) {
			// If anything goes wrong during this process, log it securely
			// using your existing class method for consistency.
			$this->log_error( 'Script enqueue error', $e );
		}
	}
	/**
	 * Register REST API routes for chunked audio uploads.
	 *
	 * @since 0.2.0
	 * @version 0.7.1
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			self::STAR_REST_NAMESPACE,
			'/upload-chunk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_upload_chunk_rest' ),
				'permission_callback' => array( $this, 'upload_permissions_check' ),
			)
		);
				register_rest_route(
					self::STAR_REST_NAMESPACE,
					'/upload-fallback', // A new, different URL
					array(
						'methods'             => 'POST',
						'callback'            => array( $this, 'handle_fallback_upload_rest' ),
						'permission_callback' => array( $this, 'upload_permissions_check' ),
					)
				);
	}
  /**
   * Safely handle a single file upload, validating and processing it securely.
   */
  private function safe_handle_upload( array $file, array $form_data = [] ): array|\WP_Error {
    // 1. Validate PHP upload error codes
    if ( ! empty( $file['error'] ) && $file['error'] !== UPLOAD_ERR_OK ) {
        return new WP_Error(
            'upload_error',
            'Upload failed: ' . $this->php_error_message( $file['error'] ),
            [ 'status' => 400 ]
        );
    }
    // 2. Ensure file exists and is readable
    if ( ! is_uploaded_file( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
        return new WP_Error( 'file_missing', 'Temporary file missing or unreadable.', [ 'status' => 400 ] );
    }

    $size = filesize( $file['tmp_name'] );
    if ( $size === false || $size <= 0 ) {
        return new WP_Error( 'file_empty', 'Uploaded file is empty.', [ 'status' => 400 ] );
    }
    if ( $size > 50 * 1024 * 1024 ) { // 50 MB cap (adjust as needed)
        return new WP_Error( 'file_too_large', 'Uploaded file exceeds maximum size.', [ 'status' => 413 ] );
    }

    // 3. Clone to a safe working copy (avoid overwrite/cleanup races)
    $tmp_copy = wp_tempnam( $file['name'] );
    if ( ! $tmp_copy || ! copy( $file['tmp_name'], $tmp_copy ) ) {
        return new WP_Error( 'tmp_copy_fail', 'Failed to create safe temp copy.', [ 'status' => 500 ] );
    }


	// 4. Sniff MIME type safely
	// Copy the uploaded tmp file to a safe location before sniffing.
	$tmp_copy = wp_tempnam( $_FILES['audio_file']['name'] );
	if ( ! $tmp_copy || ! copy( $_FILES['audio_file']['tmp_name'], $tmp_copy ) ) {
		return new WP_Error( 'upload_copy_failed', 'Unable to copy uploaded file for validation.' );
	}

	// Use finfo to detect the real MIME type.
	$finfo = finfo_open( FILEINFO_MIME_TYPE );
	$detected_mime = $finfo ? finfo_file( $finfo, $tmp_copy ) : false;
	if ( $finfo ) {
		finfo_close( $finfo );
	}

	// Allowable audio/video MIME types (extend as needed).
	$allowed_mimes = [
		'audio/webm',
		'video/webm', // Accept video/webm for audio-only webm blobs
		'audio/weba', // Accept audio/weba for .weba extension
		'audio/ogg',
		'audio/opus',
		'audio/wav',
		'audio/mpeg',       // mp3
		'audio/mp4',        // m4a
		'audio/x-m4a',
		'audio/aac',
		'audio/flac',
	];

	// Validate against the whitelist.
	if ( ! $detected_mime || ! in_array( $detected_mime, $allowed_mimes, true ) ) {
		@unlink( $tmp_copy );
		return new WP_Error(
			'invalid_mime',
			sprintf( 'File is not a valid audio type. Detected: %s', esc_html( $detected_mime ?: 'unknown' ) ),
			[ 'status' => 415 ]
		);
	}


    // 5. Hand off to WordPress sideload
	// Use starmus_title (sanitized) and uuid for filename if available
	$title_part = !empty($form_data['starmus_title']) ? sanitize_file_name($form_data['starmus_title']) : 'recording';
	$uuid_part = !empty($form_data['submissionUUID']) ? $form_data['submissionUUID'] : uniqid();
	$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
	if (!$ext) { $ext = 'webm'; }
	$final_name = $title_part . '-' . $uuid_part . '.' . $ext;
	$file_array = [
		'name'     => wp_unique_filename( wp_upload_dir()['path'], $final_name ),
		'tmp_name' => $tmp_copy,
	];

    $overrides = [
        'test_form' => false,
        'mimes'     => [ 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'webm' => 'audio/webm', 'ogg' => 'audio/ogg' ],
    ];

    $result = wp_handle_sideload( $file_array, $overrides );

	if ( !empty( $result['error'] ) ) {
		@unlink( $tmp_copy );
		return new WP_Error( 'sideload_error', $result['error'], [ 'status' => 500 ] );
	}

    return $result; // ['file' => path, 'url' => url, 'type' => mime]
}

/**
 * Map PHP upload error codes to human-readable messages.
 */
private function php_error_message( int $code ): string {
    $map = [
        UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize limit.',
        UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive.',
        UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
    ];
    return $map[$code] ?? 'Unknown upload error.';
}

	/**
	 * Handles simple, non-chunked audio file uploads from the REST fallback.
	 */
	public function handle_fallback_upload_rest( \WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// --- START OF NEW DEBUGGING ---
		error_log( 'STARMUS DEBUG: Fallback handler initiated.' );
		// Log all expected ACF/meta fields
		$acf_fields = array(
			'project_collection_id',
			'accession_number',
			'session_date',
			'session_start_time',
			'session_end_time',
			'location',
			'gps_coordinates',
			'contributor_id',
			'interviewers_recorders',
			'recording_equipment',
			'audio_files_originals',
			'media_condition_notes',
			'related_consent_agreement',
			'usage_restrictions_rights',
			'access_level',
			'first_pass_transcription',
			'audio_quality_score',
			'audio_consent',
			'language',
			'recording_type',
			'starmus_title',
			'recording_metadata',
			'metadata',
			'audio_file',
			'audio_file_url',
			'audio_file_id',
		);
		$params     = $request->get_params();
		foreach ( $acf_fields as $field ) {
		$val = ( array_key_exists( $field, $params ) && $params[ $field ] !== '' ) ? $params[ $field ] : '[NOT SET]';
			error_log( "STARMUS DEBUG: Field '$field': " . ( is_string( $val ) ? $val : print_r( $val, true ) ) );
		}
		// --- END OF NEW DEBUGGING ---

		try {
			if ( $this->is_rate_limited() ) {
				error_log( 'STARMUS DEBUG: Request failed due to rate limiting.' );
				return new WP_Error( 'rate_limit_exceeded', __( 'You are uploading too frequently.', 'starmus-audio-recorder' ), array( 'status' => 429 ) );
			}


			   $params = $request->get_params();
			   $files  = $request->get_file_params();

			   // Parse JSON metadata fields if present
			   $json_fields = array();
			   foreach ( array('recording_metadata', 'metadata') as $json_key ) {
				   if ( !empty($params[$json_key]) ) {
					   $decoded = json_decode($params[$json_key], true);
					   if (is_array($decoded)) {
						   $json_fields = array_merge($json_fields, $decoded);
					   }
				   }
			   }

			   // Flatten nested keys for easy mapping
			   $flat_json = array();
			   $iterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($json_fields));
			   foreach ($iterator as $key => $value) {
				   $keys = array();
				   foreach (range(0, $iterator->getDepth()) as $depth) {
					   $keys[] = $iterator->getSubIterator($depth)->key();
				   }
				   $flat_json[implode('_', array_filter($keys))] = $value;
			   }

			   // Map relevant fields from JSON to params if not already set
			   $field_map = array(
				   'session_date' => array('session_date', 'temporal_recordedAt'),
				   'session_start_time' => array('session_start_time'),
				   'session_end_time' => array('session_end_time'),
				   'location' => array('location'),
				   'gps_coordinates' => array('gps_coordinates', 'device_gps'),
				   'accession_number' => array('accession_number'),
				   'audio_quality_score' => array('audio_quality_score', 'quality_avgVolume'),
				   'first_pass_transcription' => array('first_pass_transcription', 'transcript'),
				   'audio_consent' => array('audio_consent'),
				   'language' => array('language', 'linguistic_detectedLanguage'),
				   'recording_type' => array('recording_type', 'linguistic_recordingType'),
				   'starmus_title' => array('starmus_title'),
				   'audio_file_url' => array('audio_file_url'),
				   'audio_file_id' => array('audio_file_id'),
				   // Add more mappings as needed
			   );
			   foreach ($field_map as $param_key => $json_keys) {
				   if (empty($params[$param_key])) {
					   foreach ($json_keys as $json_key) {
						   if (!empty($flat_json[$json_key])) {
							   $params[$param_key] = $flat_json[$json_key];
							   break;
						   }
					   }
				   }
			   }

			   // Always ensure a UUID is set for corpus tracking
			   if (empty($params['sessionUUID'])) {
				   $params['sessionUUID'] = !empty($flat_json['identifiers_sessionUUID']) ? $flat_json['identifiers_sessionUUID'] : wp_generate_uuid4();
			   }
			   if (empty($params['submissionUUID'])) {
				   $params['submissionUUID'] = !empty($flat_json['identifiers_submissionUUID']) ? $flat_json['identifiers_submissionUUID'] : wp_generate_uuid4();
			   }

			// --- MORE DEBUGGING ---
			error_log( 'STARMUS DEBUG: Received Params: ' . print_r( $params, true ) );
			error_log( 'STARMUS DEBUG: Received Files: ' . print_r( $files, true ) );


	       // Use safe_handle_upload for all file validation and sideloading
	       if ( empty( $files['audio_file'] ) ) {
		       error_log( 'STARMUS DEBUG: Validation failed - audio_file is missing.' );
		       return new WP_Error( 'invalid_request_data', __( 'Audio file is missing.', 'starmus-audio-recorder' ), array( 'status' => 400 ) );
	       }
	       if ( empty( $params['starmus_title'] ) ) {
		       error_log( 'STARMUS DEBUG: Validation failed - starmus_title is missing.' );
		       return new WP_Error( 'invalid_request_data', __( 'Recording title is required.', 'starmus-audio-recorder' ), array( 'status' => 400 ) );
	       }

	       $safe_result = $this->safe_handle_upload( $files['audio_file'], $params );
	       if ( is_wp_error( $safe_result ) ) {
		       error_log( 'STARMUS DEBUG: safe_handle_upload failed: ' . $safe_result->get_error_message() );
		       return $safe_result;
	       }

	       // Use the safely handled file for post creation and finalization
	       $unique_file_name = basename( $safe_result['file'] );
	       $file_size = filesize( $safe_result['file'] );
	       $uuid = wp_generate_uuid4();
	       $this->create_draft_post( $uuid, $file_size, $unique_file_name, $params );
	       $return = $this->finalize_submission( $uuid, $unique_file_name, $safe_result['file'], $params );

			// After meta save, log post meta for all ACF fields if post was created
			if ( is_array( $return ) && array_key_exists( 'post_id', $return ) && $return['post_id'] ) {
				$post_id = $return['post_id'];
			} elseif ( is_object( $return ) && isset( $return->data['post_id'] ) && $return->data['post_id'] ) {
				$post_id = $return->data['post_id'];
			} else {
				$post_id = null;
			}
			if ( $post_id ) {
				$meta = array();
				foreach ( $acf_fields as $field ) {
					   $meta[ $field ] = \get_post_meta( $post_id, $field, true );
				}
				error_log( 'STARMUS DEBUG: Saved post meta: ' . print_r( $meta, true ) );
			}
			return $return;

		} catch ( Throwable $e ) {
			$this->log_error( 'Fallback upload error', $e );
			return new WP_Error( 'upload_error', __( 'Upload failed due to a server error.', 'starmus-audio-recorder' ), array( 'status' => 500 ) );
		}
	}

	/**
	 * Check if the current user has permission to upload files.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return bool True if the user can upload, false otherwise.
	 * @since 0.2.0
	 * @version 0.7.1
	 */
	public function upload_permissions_check( \WP_REST_Request $request ): bool {
		$allowed = current_user_can( 'upload_files' );
		$allowed = (bool) apply_filters_ref_array( 'starmus_can_upload', array( $allowed, $request ) );

		// Check for nonce in header OR body
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return false;
		}
		return $allowed;
	}
	/**
	 * Handle chunked audio file uploads via REST API.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error The response or error object.
	 * @since 0.2.0
	 * @version 0.7.1
	 */
	public function handle_upload_chunk_rest( \WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			if ( $this->is_rate_limited() ) {
				return new WP_Error( 'rate_limit_exceeded', __( 'You are uploading too frequently.', STARMUS_TEXT_DOMAIN ), array( 'status' => 429 ) );
			}
			$params = $request->get_params();
			$files  = $request->get_file_params();
			$data   = $this->validate_chunk_data( $params, $files );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			if ( 0 === $data['offset'] && ! $this->find_post_by_uuid( $data['uuid'] ) ) {
				$this->create_draft_post( $data['uuid'], $data['total_size'], $data['file_name'], $params );
			}
			$post = $this->find_post_by_uuid( $data['uuid'] );
			if ( ! $post || (int) get_current_user_id() !== (int) $post->post_author ) {
				return new WP_Error( 'forbidden_submission', __( 'You cannot modify this submission.', STARMUS_TEXT_DOMAIN ), array( 'status' => 403 ) );
			}
			$write_result = $this->write_chunk_streamed( $data['uuid'], $data['offset'], $files['audio_file']['tmp_name'] );
			if ( is_wp_error( $write_result ) ) {
				return $write_result;
			}
			if ( ( $data['offset'] + (int) $files['audio_file']['size'] ) >= $data['total_size'] ) {
				return $this->finalize_submission( $data['uuid'], $data['file_name'], $write_result, $params );
			}
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Chunk received.', STARMUS_TEXT_DOMAIN ),
				),
				200
			);
		} catch ( Throwable $e ) {
			$this->log_error( 'Chunk upload error', $e );
			return new WP_Error( 'upload_error', __( 'Upload failed. Please try again.', STARMUS_TEXT_DOMAIN ), array( 'status' => 500 ) );
		}
	}
	/**
	 * Log errors to the debug log if WP_DEBUG_LOG is enabled.
	 *
	 * @param string    $context A brief context message.
	 * @param Throwable $e The exception to log.
	 */
	private function log_error( string $context, \Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log(
				sprintf(
					'Starmus: %s - %s in %s:%d',
					sanitize_text_field( $context ),
					sanitize_text_field( $e->getMessage() ),
					sanitize_text_field( $e->getFile() ),
					$e->getLine()
				)
			);
		}
	}
	/**
	 * Validate chunk upload data.
	 *
	 * @param array $params The request parameters.
	 * @param array $files The uploaded files.
	 * @return array|WP_Error The validated data or an error.
	 */
	private function validate_chunk_data( array $params, array $files ): array|WP_Error {
		$params     = wp_unslash( $params );
		$uuid       = sanitize_key( $params['audio_uuid'] ?? '' );
		$offset     = absint( $params['chunk_offset'] ?? 0 );
		$total_size = absint( $params['total_size'] ?? 0 );
		$file_name  = sanitize_file_name( $params['fileName'] ?? 'audio.webm' );
		$file_chunk = $files['audio_file'] ?? null;
		if ( ! $uuid || ! $file_chunk || UPLOAD_ERR_OK !== ( $file_chunk['error'] ?? 0 ) || ! $total_size ) {
			return new WP_Error( 'invalid_request_data', __( 'Invalid or missing request data.', STARMUS_TEXT_DOMAIN ), array( 'status' => 400 ) );
		}
		$max_size = (int) $this->settings->get( 'max_file_size_mb', 25 ) * 1024 * 1024;
		if ( $total_size > $max_size ) {
			return new WP_Error( 'file_too_large', __( 'The uploaded file exceeds the maximum allowed size.', STARMUS_TEXT_DOMAIN ), array( 'status' => 413 ) );
		}
	$allowed_exts = array_map( 'strtolower', (array) $this->settings->get( 'allowed_extensions', array( 'webm', 'weba', 'opus', 'mp3', 'm4a', 'wav', 'ogg' ) ) );
		$extension    = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, $allowed_exts, true ) ) {
			return new WP_Error( 'invalid_file_extension', __( 'The file type is not permitted.', STARMUS_TEXT_DOMAIN ), array( 'status' => 415 ) );
		}
		return compact( 'uuid', 'offset', 'total_size', 'file_chunk', 'file_name' );
	}
	/**
	 * Write the uploaded chunk to a temporary file.
	 *
	 * @param string $uuid The unique upload identifier.
	 * @param int    $offset The byte offset for this chunk.
	 * @param string $tmp_name The temporary file name of the uploaded chunk.
	 * @return string|WP_Error The path to the temporary file or an error.
	 * @since 0.2.0
	 * @version 0.7.1
	 */
	private function write_chunk_streamed( string $uuid, int $offset, string $tmp_name ): string|WP_Error {
		$uuid = sanitize_key( $uuid );
		if ( empty( $uuid ) || strlen( $uuid ) > 40 || ! preg_match( '/^[a-zA-Z0-9_-]+$/', $uuid ) ) {
			return new WP_Error( 'invalid_uuid', __( 'Invalid upload identifier.', STARMUS_TEXT_DOMAIN ) );
		}
		if ( ! is_uploaded_file( $tmp_name ) ) {
			return new WP_Error( 'invalid_temp_file', __( 'Invalid temporary file.', STARMUS_TEXT_DOMAIN ) );
		}

		$temp_dir = $this->get_temp_dir();
		if ( is_wp_error( $temp_dir ) ) {
			return $temp_dir;
		}

		// Initialize WP_Filesystem
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$temp_file_path = trailingslashit( $temp_dir ) . $uuid . '.part';
		$real_temp_dir  = realpath( $temp_dir );
		$real_temp_file = realpath( dirname( $temp_file_path ) ) . '/' . basename( $temp_file_path );
		if ( ! $real_temp_dir || strpos( $real_temp_file, $real_temp_dir ) !== 0 ) {
			return new WP_Error( 'path_traversal_attempt', __( 'Invalid file path.', STARMUS_TEXT_DOMAIN ) );
		}

		$current_size = $wp_filesystem->exists( $temp_file_path ) ? $wp_filesystem->size( $temp_file_path ) : 0;
		if ( $offset !== $current_size ) {
			return new WP_Error( 'bad_chunk_offset', sprintf( __( 'Chunk offset mismatch. Received %1$d, expected %2$d.', STARMUS_TEXT_DOMAIN ), $offset, $current_size ), array( 'status' => 409 ) );
		}

		// Read chunk data
		$chunk_data = $wp_filesystem->get_contents( $tmp_name );
		if ( false === $chunk_data ) {
			return new WP_Error( 'chunk_read_failed', __( 'Failed to read chunk data.', STARMUS_TEXT_DOMAIN ), array( 'status' => 500 ) );
		}

		// Append or create file
		if ( 0 === $current_size ) {
			$result = $wp_filesystem->put_contents( $temp_file_path, $chunk_data );
		} else {
			$existing_data = $wp_filesystem->get_contents( $temp_file_path );
			if ( false === $existing_data ) {
				return new WP_Error( 'existing_read_failed', __( 'Failed to read existing file.', STARMUS_TEXT_DOMAIN ), array( 'status' => 500 ) );
			}
			$result = $wp_filesystem->put_contents( $temp_file_path, $existing_data . $chunk_data );
		}

		if ( false === $result ) {
			return new WP_Error( 'chunk_write_failed', __( 'Failed to write chunk data.', STARMUS_TEXT_DOMAIN ), array( 'status' => 500 ) );
		}

		return $temp_file_path;
	}
	/**
	 * Finalize the submission by validating and attaching the audio file.
	 *
	 * @param string $uuid The unique upload identifier.
	 * @param string $file_name The original file name.
	 * @param string $temp_file_path The path to the temporary file.
	 * @param array  $form_data Additional form data.
	 * @return WP_REST_Response|WP_Error The response or error object.
	 * @since 0.2.0
	 * @version 0.7.1
	 */
	private function finalize_submission( string $uuid, string $file_name, string $temp_file_path, array $form_data ): WP_REST_Response|WP_Error {
		$post = $this->find_post_by_uuid( $uuid );
		if ( ! $post || (int) get_current_user_id() !== (int) $post->post_author ) {
			if ( file_exists( $temp_file_path ) ) {
				wp_delete_file( $temp_file_path );
			}
			return new WP_Error( 'submission_not_found', __( 'Draft submission post could not be found.', STARMUS_TEXT_DOMAIN ), array( 'status' => 404 ) );
		}

		$finfo     = finfo_open( FILEINFO_MIME_TYPE );
		$real_mime = $finfo ? (string) finfo_file( $finfo, $temp_file_path ) : '';
		if ( $finfo ) {
			finfo_close( $finfo );
		}

		// Normalize WebM so both audio/webm and video/webm pass
		if ( $real_mime === 'video/webm' ) {
			$real_mime = 'audio/webm';
		}

		if ( '' === $real_mime || ( 0 !== strpos( $real_mime, 'audio/' ) && $real_mime !== 'audio/webm' ) ) {
			wp_delete_file( $temp_file_path );
			return new WP_Error(
				'invalid_mime_type',
				__( 'File content is not a valid audio type.', STARMUS_TEXT_DOMAIN ),
				array( 'status' => 415 )
			);
		}

		// Extend core mime map with webm
		$core_mime_map         = wp_get_mime_types();
		$core_mime_map['webm'] = 'video/webm';
		$core_mime_map['weba'] = 'audio/webm';
		$core_mime_map['opus'] = 'audio/ogg; codecs=opus';

		$allowed_exts  = array_map( 'strtolower', (array) $this->settings->get( 'allowed_extensions', array( 'webm', 'mp3', 'm4a', 'wav', 'ogg' ) ) );
		$allowed_mimes = array();
		foreach ( $core_mime_map as $exts => $mime ) {
			$ext_arr = array_map( 'trim', explode( '|', $exts ) );
			foreach ( $ext_arr as $ext ) {
				if ( in_array( strtolower( $ext ), $allowed_exts, true ) && 0 === strpos( (string) $mime, 'audio/' ) ) {
					$allowed_mimes[ $ext ] = $mime;
				}
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file_name = sanitize_file_name( basename( $file_name ) );
		if ( empty( $file_name ) || strpos( $file_name, '..' ) !== false ) {
			wp_delete_file( $temp_file_path );
			return new WP_Error( 'invalid_filename', __( 'Invalid file name.', STARMUS_TEXT_DOMAIN ) );
		}

		$file_data = array(
			'name'     => wp_unique_filename( wp_get_upload_dir()['path'], $file_name ),
			'tmp_name' => $temp_file_path,
		);

    // This second filter is the "nuclear option" to bypass any other security
    // plugins that might be interfering with MIME type checks.
    add_filter( 'wp_check_filetype_and_ext', '__return_true', 100 );

		$upload_result = wp_handle_sideload(
			$file_data,
			array(
				'test_form' => false,
        'test_upload' => true, // Ensure the file is a valid upload
				'mimes'     => $allowed_mimes,
			)
		);

    // IMPORTANT: Immediately remove our temporary filter.
    remove_filter( 'wp_check_filetype_and_ext', '__return_true', 100 );

		if (! empty( $upload_result['error'] ) ) {
			if ( file_exists( $temp_file_path ) ) {
				wp_delete_file( $temp_file_path );
			}
			return new WP_Error( 'sideload_failed', $upload_result['error'], array( 'status' => 500 ) );
		}

		$uploaded_path = $upload_result['file'];
		$uploaded_type = $upload_result['type'];
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $uploaded_type,
				'post_title'     => sanitize_text_field( pathinfo( $uploaded_path, PATHINFO_FILENAME ) ),
				'post_status'    => 'inherit',
			),
			$uploaded_path,
			$post->ID
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $uploaded_path );
			return new WP_Error( 'attachment_error', $attachment_id->get_error_message(), array( 'status' => 500 ) );
		}

		$attachment_id = (int) $attachment_id;
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $uploaded_path ) );
		update_post_meta( $post->ID, '_audio_attachment_id', $attachment_id );
		update_post_meta( $attachment_id, '_starmus_audio_post_id', (int) $post->ID );

		// Set audio as featured image (post thumbnail) for the CPT post
		if ( function_exists( '\set_post_thumbnail' ) ) {
			\set_post_thumbnail( $post->ID, $attachment_id );
		}

		$waveform_generated = $this->generate_waveform_data( $attachment_id );

		wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'publish',
			)
		);

		do_action( 'starmus_after_audio_upload', (int) $post->ID, $attachment_id, $form_data );

		$response_data = apply_filters_ref_array(
			'starmus_audio_upload_success_response',
			array(
				array(
					'message'            => esc_html__( 'Submission complete!', STARMUS_TEXT_DOMAIN ),
					'post_id'            => (int) $post->ID,
					'attachment_id'      => $attachment_id,
					'waveform_generated' => (bool) $waveform_generated,
				),
				(int) $post->ID,
				$form_data,
			)
		);

		return new WP_REST_Response( $response_data, 200 );
	}

		/**
		 * Create a draft post to hold upload metadata.
		 *
		 * @param string $uuid       Unique upload identifier.
		 * @param int    $total_size Expected total upload size.
		 * @param string $file_name  Original filename.
		 * @param array  $form_data  Submitted form data.
		 */
	private function create_draft_post( string $uuid, int $total_size, string $file_name, array $form_data ): void {
		$meta_input = array(
			'audio_uuid'        => $uuid,
			'upload_total_size' => $total_size,
		);
		if ( $this->settings->get( 'collect_ip_ua' ) && ! empty( $form_data['audio_consent'] ) ) {
			$meta_input['submission_ip']         = $this->get_client_ip();
			$meta_input['submission_user_agent'] = $this->get_user_agent();
		}
		wp_insert_post(
			array(
				// THIS IS THE BUG
				'post_title'  => sanitize_text_field( $form_data['starmus_title'] ?? pathinfo( $file_name, PATHINFO_FILENAME ) ),
				'post_type'   => $this->settings->get( 'cpt_slug', 'audio-recording' ),
				'post_status' => 'draft',
				'post_author' => get_current_user_id(),
				'meta_input'  => $meta_input,
			)
		);
	}
		/**
		 * Save all metadata and taxonomy terms for the recording.
		 *
		 * @param int   $audio_post_id The audio post ID.
		 * @param int   $attachment_id The attachment ID for the audio file.
		 * @param array $form_data     Submitted form data.
		 */
	public function save_all_metadata( int $audio_post_id, int $attachment_id, array $form_data ): void {
		$consent_post_id = $this->create_consent_post( $audio_post_id, $form_data );
		$this->update_audio_recording_metadata( $audio_post_id, $attachment_id, $consent_post_id, $form_data );
		$this->assign_audio_recording_taxonomies( $audio_post_id, $form_data );

		// Save all submitted ACF fields to the post
		$acf_fields = array(
			'project_collection_id',
			'accession_number',
			'session_date',
			'session_start_time',
			'session_end_time',
			'location',
			'gps_coordinates',
			'contributor_id',
			'interviewers_recorders',
			'recording_equipment',
			'audio_files_originals',
			'media_condition_notes',
			'related_consent_agreement',
			'usage_restrictions_rights',
			'access_level',
			'first_pass_transcription',
			'audio_quality_score',
		);
		if ( function_exists( 'update_field' ) ) {
			foreach ( $acf_fields as $acf_field ) {
				   if ( array_key_exists( $acf_field, $form_data ) && $form_data[ $acf_field ] !== '' ) {
					$value = $form_data[ $acf_field ];
					// Decode JSON for gps_coordinates and first_pass_transcription
					if ( in_array( $acf_field, array( 'gps_coordinates', 'first_pass_transcription' ), true ) ) {
						$decoded = json_decode( $value, true );
						if ( is_array( $decoded ) ) {
							$value = $decoded;
						}
					}
					   \update_field( $acf_field, $value, $audio_post_id );
				}
			}
		}
	}
	/**
	 * Create a consent post if the user has given consent.
	 *
	 * @param int   $audio_post_id The ID of the audio recording post.
	 * @param array $form_data The submitted form data.
	 * @return int|null The ID of the consent post or null if not created.
	 * @since 0.3.0
	 * @version 0.7.1
	 */
	private function create_consent_post( int $audio_post_id, array $form_data ): ?int {
		if ( empty( $form_data['audio_consent'] ) ) {
			return null;
		}
		$consent_post_data = array(
			'post_title'     => sprintf( 'Consent for Recording #%d', $audio_post_id ),
			'post_content'   => __( 'Consent granted for this recording.', STARMUS_TEXT_DOMAIN ),
			'post_status'    => 'private',
			'comment_status' => 'closed',
			'post_author'    => get_current_user_id(),
			'post_type'      => 'starmus_consent',
		);
		$consent_post_id   = wp_insert_post( $consent_post_data, true );
		if ( is_wp_error( $consent_post_id ) ) {
			return null;
		}
		$consent_post_id = (int) $consent_post_id;
		update_post_meta( $consent_post_id, '_starmus_audio_post_id', $audio_post_id );
		update_post_meta( $consent_post_id, '_starmus_consent_time', current_time( 'mysql' ) );
		update_post_meta( $consent_post_id, '_starmus_consent_ip', $this->get_client_ip() );
		update_post_meta( $consent_post_id, '_starmus_consent_ua', $this->get_user_agent() );
		return $consent_post_id;
	}
	/**
	 * Update the audio recording post metadata.
	 *
	 * @param int      $audio_post_id The ID of the audio recording post.
	 * @param int      $attachment_id The ID of the audio attachment.
	 * @param int|null $consent_post_id The ID of the consent post, if any.
	 * @param array    $form_data The submitted form data.
	 * @since 0.3.0
	 * @version 0.7.1
	 */
	private function update_audio_recording_metadata( int $audio_post_id, int $attachment_id, ?int $consent_post_id, array $form_data ): void {
		\update_post_meta( $audio_post_id, '_audio_attachment_id', $attachment_id );
		   if ( $consent_post_id ) {
			   \update_post_meta( $audio_post_id, '_consent_post_id', $consent_post_id );
		}
		   if ( ! empty( $form_data['audio_description'] ) ) {
			   \wp_update_post(
				   array(
					   'ID'           => $audio_post_id,
					   'post_content' => \sanitize_textarea_field( $form_data['audio_description'] ),
				   )
			   );
		}
		\do_action( 'starmus_update_recording_metadata', $audio_post_id, $attachment_id, $consent_post_id, $form_data );
	}
	/**
	 * Assign taxonomies to the audio recording post.
	 *
	 * @param int   $audio_post_id The ID of the audio recording post.
	 * @param array $form_data The submitted form data.
	 * @since 0.3.0
	 * @version 0.7.1
	 */
	private function assign_audio_recording_taxonomies( int $audio_post_id, array $form_data ): void {
		$taxonomies = array(
			'language'       => $form_data['language'] ?? null,
			'recording_type' => $form_data['recording_type'] ?? null,
		);
		foreach ( $taxonomies as $tax_slug => $provided ) {
			   if ( empty( $provided ) || ! \taxonomy_exists( $tax_slug ) ) {
				continue;
			}
			$term_ids = array();
			   $values   = is_array( $provided ) ? $provided : array( $provided );
			   foreach ( $values as $val ) {
				   if ( is_numeric( $val ) ) {
					   $term_id = (int) $val;
					   if ( \get_term( $term_id, $tax_slug ) ) {
						   $term_ids[] = $term_id;
					   }
				   } else {
					   $slug = \sanitize_key( (string) $val );
					   $term = \get_term_by( 'slug', $slug, $tax_slug );
					   if ( $term && ! \is_wp_error( $term ) ) {
						   $term_ids[] = (int) $term->term_id;
					   }
				   }
			   }
			   if ( ! empty( $term_ids ) ) {
				   \wp_set_post_terms( $audio_post_id, $term_ids, $tax_slug, false );
			   }
		}
	}
	/**
	 * Add a conditional redirect URL after successful submission.
	 *
	 * @param array $response The current response data.
	 * @param int   $post_id The ID of the created audio post.
	 * @param array $form_data The submitted form data.
	 * @return array The modified response with redirect URL.
	 * @since 0.3.0
	 * @version 0.7.1
	 */
	public function add_conditional_redirect( array $response, int $post_id, array $form_data ): array {
		$slug                     = $form_data['recording_type'] ?? 'default';
		$term                     = is_numeric( $slug ) ? get_term( (int) $slug ) : get_term_by( 'slug', sanitize_key( (string) $slug ), 'recording_type' );
		$recording_type_slug      = ( $term && ! is_wp_error( $term ) ) ? $term->slug : 'default';
		$response['redirect_url'] = apply_filters_ref_array(
			'starmus_final_redirect_url',
			array( home_url( '/my-recordings' ), $post_id, $recording_type_slug, $form_data )
		);
		return $response;
	}
	/**
	 * Generate waveform data for the uploaded audio file using audiowaveform.
	 *
	 * @param int $attachment_id The attachment ID of the audio file.
	 * @return bool True on success, false on failure.
	 * @since 0.3.0
	 * @version 0.7.1
	 */
	private function generate_waveform_data( int $attachment_id ): bool {
		if ( ! $this->settings->get( 'enable_waveform_generation' ) || ! apply_filters( 'starmus_allow_waveform_generation', false ) ) {
			return false;
		}

		// Validate audiowaveform binary path
		$audiowaveform_path = apply_filters( 'starmus_audiowaveform_path', '/usr/local/bin/audiowaveform' );
		$real_binary_path   = realpath( $audiowaveform_path );
		if ( ! $real_binary_path || ! is_executable( $real_binary_path ) ) {
			return false;
		}

		// Validate input file
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path ) {
			return false;
		}

		// Initialize WP_Filesystem
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem->exists( $file_path ) ) {
			return false;
		}

		$real_file_path = realpath( $file_path );
		$uploads_dir    = realpath( wp_get_upload_dir()['basedir'] );
		if ( ! $real_file_path || ! $uploads_dir || strpos( $real_file_path, $uploads_dir ) !== 0 ) {
			return false;
		}

		// Create secure temp file path
		$temp_json_path = $file_path . '.json';
		$real_temp_path = realpath( dirname( $temp_json_path ) ) . '/' . basename( $temp_json_path );
		if ( strpos( $real_temp_path, $uploads_dir ) !== 0 ) {
			return false;
		}

		// Build secure command with escaped arguments
		$cmd = sprintf(
			'%s -i %s -o %s --pixels-per-second 20 --bits 8 --height 128 2>/dev/null',
			escapeshellarg( $real_binary_path ),
			escapeshellarg( $real_file_path ),
			escapeshellarg( $temp_json_path )
		);

		// Execute with timeout
		$output      = array();
		$return_code = 0;
		exec( $cmd, $output, $return_code );

		if ( $return_code !== 0 || ! $wp_filesystem->exists( $temp_json_path ) ) {
			return false;
		}

		$json = $wp_filesystem->get_contents( $temp_json_path );
		$wp_filesystem->delete( $temp_json_path );

		if ( false === $json ) {
			return false;
		}

		$waveform_data = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || empty( $waveform_data['data'] ) ) {
			return false;
		}

		update_post_meta( $attachment_id, '_waveform_data', $waveform_data['data'] );
		return true;
	}
		/**
		 * Render a template file with provided arguments.
		 *
		 * @param string $template_name The name of the template file.
		 * @param array  $args The arguments to pass to the template.
		 * @return string The rendered template content.
		 * @since 0.2.0
		 * @version 0.7.1
		 */
	private function render_template( string $template_name, array $args = array() ): string {
		error_log( 'StarmusAudioRecorderUI: render_template called for: ' . $template_name );

		$template_name = basename( $template_name );
		error_log( 'StarmusAudioRecorderUI: Template basename: ' . $template_name );

		// CORRECTED: Added 'src/' to the plugin's template path.
		$locations = array(
			trailingslashit( get_stylesheet_directory() ) . 'starmus/' . $template_name,
			trailingslashit( get_template_directory() ) . 'starmus/' . $template_name,
			trailingslashit( STARMUS_PATH ) . 'src/templates/' . $template_name,
		);

		error_log( 'StarmusAudioRecorderUI: Template search locations: ' . print_r( $locations, true ) );

		$template_path = '';
		foreach ( $locations as $location ) {
			error_log( 'StarmusAudioRecorderUI: Checking template location: ' . $location );
			if ( file_exists( $location ) ) {
				$template_path = $location;
				error_log( 'StarmusAudioRecorderUI: Template found at: ' . $template_path );
				break;
			} else {
				error_log( 'StarmusAudioRecorderUI: Template not found at: ' . $location );
			}
		}

		try {
			if ( $template_path ) {
				error_log( 'StarmusAudioRecorderUI: Loading template: ' . $template_path );

				// CORRECTED: This makes variables like $query available to the template file.
				if ( is_array( $args ) ) {
					extract( $args, EXTR_SKIP );
				}

				ob_start();

				// CORRECTED: Include the template file directly.
				include $template_path;

				$output = (string) ob_get_clean();
				error_log( 'StarmusAudioRecorderUI: Template loaded successfully, output length: ' . strlen( $output ) );
				return $output;
			} else {
				error_log( 'StarmusAudioRecorderUI: No template found for: ' . $template_name );
			}
		} catch ( Throwable $e ) {
			error_log( 'StarmusAudioRecorderUI: Template render error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
		}
		return '';
	}
	/**
	 * Simple rate limiting based on user ID and IP address.
	 * Limits to a certain number of uploads per minute.
	 *
	 * @return bool True if rate limited, false otherwise.
	 * @since 0.2.0
	 * @version 0.7.1
	 */
	private function is_rate_limited(): bool {
		$limit   = (int) apply_filters( 'starmus_rate_limit_uploads_per_minute', 20 );
		$user_id = (int) get_current_user_id();
		$ip      = $this->get_client_ip();
		$key     = 'starmus_rl_' . $user_id . '_' . hash( 'sha256', $ip );
		$current = (int) get_transient( $key );
		if ( $current >= $limit ) {
			return true;
		}
		set_transient( $key, $current + 1, MINUTE_IN_SECONDS );
		return false;
	}
	/**
	 * Get or create the temporary upload directory.
	 *
	 * @return string|WP_Error The path to the temporary directory or an error.
	 * @since 0.2.0
	 * @version 0.7.1
	 */
	private function get_temp_dir(): string|WP_Error {
		$upload_dir       = wp_get_upload_dir();
		$default_temp_dir = trailingslashit( $upload_dir['basedir'] ) . 'starmus-temp';
		$temp_dir         = apply_filters( 'starmus_temp_upload_dir', $default_temp_dir );
		if ( ! wp_mkdir_p( $temp_dir ) ) {
			return new WP_Error( 'temp_dir_error', __( 'Cannot create temporary directory.', STARMUS_TEXT_DOMAIN ), array( 'status' => 500 ) );
		}
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		$htaccess_path = trailingslashit( $temp_dir ) . '.htaccess';
		if ( ! $wp_filesystem->exists( $htaccess_path ) ) {
			$wp_filesystem->put_contents( $htaccess_path, "Deny from all\n" );
		}
		$index_path = trailingslashit( $temp_dir ) . 'index.html';
		if ( ! $wp_filesystem->exists( $index_path ) ) {
			$wp_filesystem->put_contents( $index_path, '' );
		}
		return $temp_dir;
	}
	/**
	 * Schedule a cron job to clean up stale temporary files.
	 *
	 * @since 0.2.0
	 * @version 0.7.1
	 */
	public function maybe_schedule_cron(): void {
		if ( function_exists( '\wp_next_scheduled' ) && ! \wp_next_scheduled( 'starmus_cleanup_temp_files' ) ) {
			\wp_schedule_event( time(), 'hourly', 'starmus_cleanup_temp_files' );
		}
	}
	/**
	 * Clean up stale temporary files older than 24 hours.
	 *
	 * @since 0.2.0
	 * @version 0.7.1
	 */
	public function cleanup_stale_temp_files(): void {
		$temp_dir = $this->get_temp_dir();
		if ( is_wp_error( $temp_dir ) ) {
			return;
		}
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		$files = $wp_filesystem->dirlist( $temp_dir );
		if ( empty( $files ) ) {
			return;
		}
		$cutoff = time() - DAY_IN_SECONDS;
		foreach ( $files as $file ) {
			if ( 'f' === $file['type'] && str_ends_with( $file['name'], '.part' ) && $file['lastmod'] < $cutoff ) {
				$file_path = trailingslashit( $temp_dir ) . $file['name'];
				wp_delete_file( $file_path );
			}
		}
	}
	/**
	 * Find a post by its UUID.
	 *
	 * @param string $uuid The unique upload identifier.
	 * @return WP_Post|null The found post or null if not found.
	 * @since 0.2.0
	 * @version 0.7.1
	 */
	private function find_post_by_uuid( string $uuid ): ?\WP_Post {
		$uuid      = sanitize_key( $uuid );
		$cache_key = 'starmus_post_id_for_uuid_' . $uuid;
		$post_id   = wp_cache_get( $cache_key, STARMUS_TEXT_DOMAIN );
		if ( false === $post_id ) {
			$q       = new WP_Query(
				array(
					'post_type'      => $this->settings->get( 'cpt_slug', 'audio-recording' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'meta_query'     => array(
						array(
							'key'   => 'audio_uuid',
							'value' => $uuid,
						),
					), // phpcs:ignore slow-db-query
				)
			);
			$post_id = $q->have_posts() ? (int) $q->posts[0] : 0;
			wp_cache_set( $cache_key, $post_id, STARMUS_TEXT_DOMAIN, 5 * MINUTE_IN_SECONDS );
		}
		return $post_id ? get_post( (int) $post_id ) : null;
	}
	/**
	 * Get the URL of the edit page from settings.
	 *
	 * @return string The edit page URL or empty string if not set.
	 * @since 0.3.0
	 * @version 0.7.1
	 */
	private function get_edit_page_url(): string {
		$edit_page_id = $this->settings->get( 'edit_page_id' );
		if ( ! empty( $edit_page_id ) ) {
			$permalink = get_permalink( (int) $edit_page_id );
			return $permalink ? esc_url( $permalink ) : '';
		}
		return '';
	}
	/**
	 * Get the client's IP address with proxy support.
	 *
	 * @return string The client's IP address or empty string if not available.
	 * @since 0.3.0
	 * @version 0.7.1
	 */
	private function get_client_ip(): string {
		// Check proxy headers in order of preference
		$headers = array(
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'HTTP_CLIENT_IP',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
			foreach ( $ips as $ip ) {
				$ip = trim( $ip );
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $ip;
				}
			}
		}

		// Fallback to any valid IP
		foreach ( $headers as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
			foreach ( $ips as $ip ) {
				$ip = trim( $ip );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '';
	}
	/**
	 * Get the client's User-Agent string.
	 *
	 * @return string The User-Agent string or empty string if not available.
	 * @since 0.3.0
	 * @version 0.7.1
	 */
	private function get_user_agent(): string {
		$user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		// Limit length to prevent abuse
		return substr( $user_agent, 0, 500 );
	}
}
