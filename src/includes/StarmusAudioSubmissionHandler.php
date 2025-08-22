<?php/**
 * Starmus Submission Manager
 *
 * Handles the audio submission process, including AJAX requests and shortcode rendering.
 *
 * @package Starmus\src\includes
 */

namespace Starisian\src\includes;

// This class now depends on StarmusCustomPostType for the post type slug.
// Ensure it's loaded before this one.

// Import WordPress functions into the namespace... (same as your original file)
use function wp_doing_ajax;
use function wp_send_json_error;
// ... (all other 'use function' statements from your original file)

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StarmusSubmissionManager
 *
 * Manages the front-end audio submission form and AJAX handling.
 */

class StarmusSubmissionManager {
	const ACTION_HOOK   = 'starmus_submit_audio';
	const NONCE_ACTION  = 'starmus_submit_audio_action';
	const NONCE_FIELD   = 'starmus_audio_nonce_field';
	const SHORTCODE_TAG = 'starmus_audio_recorder';

	private static ?self $instance = null;
	public array $settings = [];
	private string $plugin_path;
	private string $plugin_url;
	private string $version;

	/**
	 * Constructor is now private to prevent direct instantiation.
	 */
	private function __construct() {
		$this->plugin_path = defined('STARMUS_PATH') ? STARMUS_PATH : plugin_dir_path(__FILE__);
		$this->plugin_url  = defined('STARMUS_URL') ? STARMUS_URL : plugin_dir_url(__FILE__);
		$this->version     = defined('STARMUS_VERSION') ? STARMUS_VERSION : '1.0.0';
		if ( ! defined( 'STARMUS_DEBUG' ) ) {
			define( 'STARMUS_DEBUG', false );
		}
		$this->load_settings();
		$this->register_hooks();
	}

	/**
	 * The single point of access to get the instance.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Loads or re-loads settings from the database into the instance property.
	 */
	public function load_settings(): void {
		if (class_exists('Starisian\\src\\admin\\StarmusAdminSettings')) {
			$settingsClass = '\\Starisian\\src\\admin\\StarmusAdminSettings';
			$this->settings = get_option($settingsClass::OPTION_NAME, []);
		} else {
			$this->settings = [
				'file_size_limit' => 10,
				'recording_time_limit' => 300,
				'allowed_file_types' => 'mp3,wav,webm,m4a,ogg,opus',
				'consent_message' => 'I consent to having this audio recording stored and used.',
				'cpt_slug' => 'starmus_submission',
			];
		}
	}
	/**
	 * Undocumented function
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// AJAX hooks
		add_action( 'wp_ajax_nopriv_' . self::ACTION_HOOK, [ $this, 'handle_submission' ] );
		add_action( 'wp_ajax_' . self::ACTION_HOOK, [ $this, 'handle_submission' ] );		

		// File type handling filters
		add_filter( 'upload_mimes', [ $this, 'add_custom_mime_types' ] );
		add_filter( 'wp_check_filetype_and_ext', [ $this, 'force_allowed_audio_filetypes' ], 10, 4 );
	}
	

	/**
	 * Handles the audio submission AJAX request.
	 * Validates data, uploads the audio file, and creates a new submission post.
	 */
	public function handle_submission(): void {
		if ( ! \wp_doing_ajax() ) {
			$this->log_critical_error( 'Invalid request method.' );
			\wp_send_json_error( [ 'message' => 'Invalid request method.' ], 400 );
		}

		if ( STARMUS_DEBUG ) {
			\error_log( '--- STARMUS SUBMISSION HANDLER ---' );
			\error_log( 'POST Data Keys: ' . \wp_json_encode( array_map( '\sanitize_text_field', array_keys( $_POST ) ) ) );
			\error_log( 'FILES Data Keys: ' . \wp_json_encode( array_map( '\sanitize_text_field', array_keys( $_FILES ) ) ) );
		}

		// --- Rate Limiting: 1 submission per minute per IP ---
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? \sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : 'unknown';
		$rate_limit_key = 'starmus_rate_limit_' . md5( $ip );
		$last_submit = \get_transient( $rate_limit_key );
		if ( $last_submit && ( time() - (int)$last_submit < 60 ) ) {
			$this->log_critical_error( 'Rate limit exceeded for IP: ' . $ip );
			\wp_send_json_error( [ 'message' => 'You are submitting too quickly. Please wait a minute before trying again.' ], 429 );
		}

		// Security: Uncomment the following block to require users to be logged in.
		/*
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'You must be logged in to submit a recording.' ], 403 );
		}
		*/

		// 1. Security & Validation Checks
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			$this->log_critical_error( 'Security check failed for IP: ' . $ip );
			\wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page and try again.' ], 403 );
		}

		if ( empty( $_POST['audio_consent'] ) || 'on' !== \sanitize_text_field( $_POST['audio_consent'] ) ) {
			\wp_send_json_error( [ 'message' => 'You must provide consent to submit your recording.' ], 400 );
		}

		$uuid = isset( $_POST['audio_uuid'] ) ? \sanitize_text_field( $_POST['audio_uuid'] ) : '';
		if ( ! $this->is_valid_uuid( $uuid ) ) {
			\wp_send_json_error( [ 'message' => 'A valid submission identifier is missing.' ], 400 );
		}

		if ( empty( $_FILES['audio_file'] ) || UPLOAD_ERR_OK !== $_FILES['audio_file']['error'] ) {
			$error_code = $_FILES['audio_file']['error'] ?? UPLOAD_ERR_NO_FILE;
			\wp_send_json_error( [ 'message' => $this->get_upload_error_message( $error_code ) ], 400 );
		}

	$file         = $_FILES['audio_file'];
	$file['name'] = \sanitize_file_name( $file['name'] ); // Sanitize filename before further checks.

		// --- File Size Limit: Use settings array ---
		$file_size_limit = $this->settings['file_size_limit'] ?? 10;
		$max_size_bytes = $file_size_limit * 1024 * 1024;
		if ( $file['size'] > $max_size_bytes ) {
			\wp_send_json_error( [ 'message' => sprintf( 'The uploaded file exceeds the maximum allowed size of %dMB.', $file_size_limit ) ], 400 );
		}

		if ( ! $this->is_allowed_file_type( $file['type'], $file['name'] ) ) {
			\wp_send_json_error( [ 'message' => 'The uploaded audio format is not supported.' ], 400 );
		}

		// 2. Upload File to Media Library
		$attachment_id = $this->upload_file_to_media_library( 'audio_file' );
		if ( \is_wp_error( $attachment_id ) ) {
			$this->log_critical_error( 'Failed to save the audio file: ' . $attachment_id->get_error_message() );
			\wp_send_json_error( [ 'message' => 'Failed to save the audio file. ' . $attachment_id->get_error_message() ], 500 );
		}

		// 3. Gather Submission Data
	$current_user_id = \get_current_user_id();
	$post_title      = 'Audio Submission - ' . $uuid;
	$audio_url       = \wp_get_attachment_url( $attachment_id );
	$post_content    = $audio_url ? sprintf( '[audio src="%s"]', \esc_url( $audio_url ) ) : '';

		// 4. Create and Insert Post
		$post_data = [
			'post_title'   => $post_title,
			'post_type'    => $this->settings['cpt_slug'] ?? 'starmus_submission',
			'post_status'  => 'publish',
			'post_author'  => $current_user_id,
			'post_content' => $post_content,
			'meta_input'   => $this->prepare_post_metadata( $attachment_id, $file, $uuid ),
		];

		$post_id = \wp_insert_post( $post_data, true );

		if ( \is_wp_error( $post_id ) ) {
			\wp_delete_attachment( $attachment_id, true ); // Clean up orphaned media file.
			$this->log_critical_error( 'Failed to create submission entry: ' . $post_id->get_error_message() );
			\wp_send_json_error( [ 'message' => 'Failed to create submission entry: ' . $post_id->get_error_message() ], 500 );
		}

		// --- Set Rate Limit Transient ---
	\set_transient( $rate_limit_key, time(), 60 );

		// 5. Success
		\wp_send_json_success(
			[
				'message'       => 'Submission successful!',
				'attachment_id' => $attachment_id,
				'post_id'       => $post_id,
			],
			200
		);
	}
	/**
	 * Logs critical errors to a custom log file if debug is off, or to error_log if debug is on.
	 * @param string $message
	 */
	protected function log_critical_error( string $message ): void {
		if ( defined( 'STARMUS_DEBUG' ) && STARMUS_DEBUG ) {
			\error_log( '[STARMUS CRITICAL] ' . $message );
		} else {
			$log_file = WP_CONTENT_DIR . '/starmus_critical.log';
			$date = date( 'Y-m-d H:i:s' );
			@file_put_contents( $log_file, "[$date] $message\n", FILE_APPEND );
		}
	}

	/**
	 * Prepares a structured array of metadata for the submission post.
	 *
	 * @param int    $attachment_id The ID of the uploaded media attachment.
	 * @param array  $file The sanitized $_FILES array for the uploaded audio.
	 * @param string $uuid The unique identifier from the client-side.
	 * @return array The metadata array.
	 */
	private function prepare_post_metadata( int $attachment_id, array $file, string $uuid ): array {
		$current_user_id = get_current_user_id();
		$user_data       = $current_user_id > 0 ? get_userdata( $current_user_id ) : null;

		$metadata = [
			// Submission Context
			'submission_ip_address'     => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : 'unknown',
			'submission_user_agent'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : 'unknown',
			'submission_datetime'       => current_time( 'mysql' ),
			'audio_consent_given'       => 'yes',
			'linked_form_submission_id' => isset( $_POST['submission_id'] ) ? sanitize_text_field( $_POST['submission_id'] ) : '',

			// Audio File Details
			'_audio_attachment_id'      => $attachment_id,
			'audio_uuid'                => $uuid,
			'audio_file_type'           => sanitize_text_field( $file['type'] ),
			'audio_file_size'           => (int) $file['size'],
			'audio_original_filename'   => sanitize_text_field( $file['name'] ),

			// User Details
			'submission_user_id'        => $current_user_id,
			'submission_user_email'     => $user_data ? sanitize_email( $user_data->user_email ) : '',
			'anonymous_user_id'         => $this->get_or_set_anonymous_id(),
		];

		return $metadata;
	}

	/**
	 * Gets an anonymous tracking ID from a cookie or sets a new one.
	 * Can be disabled via the 'starmus_enable_anonymous_tracking' filter.
	 *
	 * @return string The anonymous ID, or an empty string if disabled/not applicable.
	 */
	private function get_or_set_anonymous_id(): string {
		if ( get_current_user_id() > 0 || ! apply_filters( 'starmus_enable_anonymous_tracking', true ) ) {
			return '';
		}

		$cookie_name = 'starmus_anon_id';
		if ( isset( $_COOKIE[ $cookie_name ] ) && preg_match( '/^[a-f0-9]{32}$/', $_COOKIE[ $cookie_name ] ) ) {
			return sanitize_text_field( $_COOKIE[ $cookie_name ] );
		}

		$anon_id = bin2hex( random_bytes( 16 ) );
		setcookie( $cookie_name, $anon_id, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		$_COOKIE[ $cookie_name ] = $anon_id; // Make it available on the current request.

		return $anon_id;
	}

	/**
	 * Adds custom audio MIME types to the list of allowed uploads.
	 *
	 * @param array $mimes Existing MIME types.
	 * @return array Modified MIME types.
	 */
	public function add_custom_mime_types( array $mimes ): array {
		$mimes['webm'] = 'audio/webm';
		$mimes['wav']  = 'audio/wav';
		$mimes['mp3']  = 'audio/mpeg';
		$mimes['ogg']  = 'audio/ogg'; // Also covers opus
		$mimes['opus'] = 'audio/ogg';
		$mimes['m4a']  = 'audio/mp4';
		$mimes['mp4']  = 'audio/mp4'; // iOS can mislabel audio-only recordings
		return $mimes;
	}

	/**
	 * Forces WordPress to correctly identify the file type for our allowed audio formats.
	 * This helps bypass strict server checks that might not recognize modern audio types.
	 *
	 * @param array  $data File data array.
	 * @param string $file Full path to the file.
	 * @param string $filename The file's name.
	 * @param array  $mimes Mime types.
	 * @return array Modified file data.
	 */
	public function force_allowed_audio_filetypes( array $data, string $file, string $filename, array $mimes ): array {
		if ( empty( $filename ) || ! is_string( $filename ) ) {
			return $data;
		}
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		$type_map = [
			'webm' => 'audio/webm',
			'ogg'  => 'audio/ogg',
			'opus' => 'audio/ogg',
			'm4a'  => 'audio/mp4',
			'mp4'  => 'audio/mp4',
			'wav'  => 'audio/wav',
			'mp3'  => 'audio/mpeg',
		];

		if ( isset( $type_map[ $ext ] ) ) {
			$data['ext']  = $ext;
			$data['type'] = $type_map[ $ext ];
		}

		return $data;
	}

	/**
	 * Validates a UUID format. Can be disabled via a filter for less strict validation.
	 *
	 * @param string $uuid The UUID string to validate.
	 * @return bool True if valid, false otherwise.
	 */
	protected function is_valid_uuid( string $uuid ): bool {
		if ( ! apply_filters( 'starmus_uuid_strict', true ) ) {
			return ! empty( $uuid );
		}
		// Standard UUID v4 format regex.
		return 1 === preg_match( '/^[a-f\d]{8}-([a-f\d]{4}-){3}[a-f\d]{12}$/i', $uuid );
	}

	/**
	 * Determines whether a file's MIME type is in our allowed list.
	 *
	 * @param string $file_type The MIME type of the uploaded file.
	 * @return bool True if allowed, false otherwise.
	 */
	       /**
		* Determines if a file is an allowed type based on settings.
		* Now checks extension as a fallback.
		*
		* @param string $file_type The MIME type of the uploaded file.
		* @param string $filename The original filename.
		* @return bool True if allowed, false otherwise.
		*/
	       protected function is_allowed_file_type( string $file_type, string $filename ): bool {
			// Get allowed extensions from settings array
			$extensions_str = $this->settings['allowed_file_types'] ?? 'mp3,wav,webm,m4a,ogg,opus';
			$allowed_extensions = explode( ',', $extensions_str );

		       // Create a map of extensions to MIME types
		       $type_map = [
			       'mp3'  => 'audio/mpeg',
			       'wav'  => 'audio/wav',
			       'webm' => 'audio/webm',
			       'm4a'  => 'audio/mp4',
			       'ogg'  => 'audio/ogg',
			       'opus' => 'audio/ogg',
			       'mp4'  => 'video/mp4', // Fallback for iOS
		       ];

		       $allowed_mimes = [];
		       foreach ($allowed_extensions as $ext) {
			       $ext = trim($ext);
			       if (isset($type_map[$ext])) {
				       $allowed_mimes[] = $type_map[$ext];
			       }
		       }
		       // Add some common variations
		       $allowed_mimes[] = 'audio/wave';
		       $allowed_mimes[] = 'audio/m4a';
		       $allowed_mimes = array_unique($allowed_mimes);

		       // Check against MIME type first
		       if ( in_array( strtolower( $file_type ), $allowed_mimes, true ) ) {
			       return true;
		       }

		       // As a fallback, check the file extension
		       $file_ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		       if ( in_array( $file_ext, $allowed_extensions, true ) ) {
			       return true;
		       }

		       return false;
	       }

	/**
	 * Handles moving an uploaded file from `$_FILES` to the WordPress media library.
	 *
	 * @param string $file_key The key in the $_FILES array.
	 * @param int    $post_id Optional post ID to attach the media to.
	 * @return int|\WP_Error The attachment ID on success, or a WP_Error object on failure.
	 */
	protected function upload_file_to_media_library( string $file_key, int $post_id = 0 ) {
		if ( ! function_exists( 'media_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		return media_handle_upload( $file_key, $post_id );
	}

	/**
	 * Gets the post type where the audio entry should be stored.
	 *
	 * @return string The post type slug.
	 */
	protected function get_target_post_type(): string {
		return apply_filters( 'starmus_audio_submission_post_type', self::POST_TYPE );
	}

	/**
	 * Provides user-friendly error messages for file upload failures.
	 *
	 * @param int $error_code The PHP UPLOAD_ERR_* constant.
	 * @return string A readable error message.
	 */
	protected function get_upload_error_message( int $error_code ): string {
		if ( STARMUS_DEBUG ) {
			error_log( 'STARMUS Upload Error Code: ' . intval( $error_code ) );
		}
		switch ( $error_code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return 'The uploaded file is too large.';
			case UPLOAD_ERR_PARTIAL:
				return 'The file was only partially uploaded. Please try again.';
			case UPLOAD_ERR_NO_FILE:
				return 'No file was sent with the submission.';
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
			case UPLOAD_ERR_EXTENSION:
				return 'A server error prevented the file upload. Please contact support.';
			default:
				return 'An unknown error occurred during file upload.';
		}
	}
}