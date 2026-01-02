<?php

/**
 * @package \Starisian\Sparxstar\Starmus\core
 * @file STarusSubmissionHandler.php
 * @author
 * @version 1.0.0
 * @since 1.0.0
 */

declare(strict_types=1);

/**
 * Core submission service responsible for processing audio uploads using the DAL.
 *
 * Handles the complete workflow for audio file submissions including file validation,
 * upload processing, metadata extraction, post creation, and post-processing tasks.
 * Supports both chunked TUS uploads and traditional fallback uploads.
 *
 * @package   Starisian\Sparxstar\Starmus\core
 * @version   6.9.3-GOLDEN-MASTER
 * @since     1.0.0
 *
 * Features:
 * - TUS resumable upload processing via temporary file handling
 * - Traditional fallback upload support for Tier C browsers
 * - MIME type validation with security-conscious detection
 * - Rate limiting and file size validation
 * - Automatic metadata extraction and ACF field population
 * - Post-processing service integration with cron fallback
 * - Temporary file cleanup and path traversal protection
 *
 * Upload Flow:
 * 1. File validation (MIME type, size, extension)
 * 2. Secure file movement to WordPress uploads directory
 * 3. WordPress attachment creation via DAL
 * 4. Audio recording post creation or update
 * 5. Metadata extraction and ACF field population
 * 6. Taxonomy assignment (language, recording type)
 * 7. Post-processing trigger (audio optimization)
 * 8. Temporary file cleanup
 * @see IStarmusAudioDAL Data Access Layer interface
 * @see StarmusSettings Plugin configuration management
 * @see StarmusPostProcessingService Audio processing service
 */

namespace Starisian\Sparxstar\Starmus\core;

use Starisian\Sparxstar\Starmus\data\StarmusAudioDAL;
use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\helpers\StarmusSanitizer;
use Starisian\Sparxstar\Starmus\helpers\StarmusSchemaMapper;
use Starisian\Sparxstar\Starmus\services\StarmusPostProcessingService;
use Throwable;
use WP_Error;
use WP_REST_Request;
use function array_map;
use function file_exists;
use function filemtime;
use function filesize;
use function get_current_user_id;
use function get_post_meta;
use function get_post_type;
use function glob;
use function home_url;
use function is_dir;
use function is_wp_error;
use function json_decode;
use function mime_content_type;
use function pathinfo;
use function rmdir;
use function sanitize_key;
use function sanitize_text_field;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function sys_get_temp_dir;
use function time;
use function trailingslashit;
use function unlink;
use function wp_check_filetype;
use function wp_get_attachment_url;
use function wp_get_mime_types;
use function wp_next_scheduled;
use function wp_normalize_path;
use function wp_schedule_single_event;
use function wp_set_post_terms;
use function wp_unique_filename;
use function wp_unslash;
use function wp_upload_dir;

if (! \defined('ABSPATH')) {
	exit;
}
/**
 * StarmusSubmissionHandler Class
 *
 *
 *
 */

final class StarmusSubmissionHandler
{

	/**
	 * Class constructor dependencies.
	 *
	 * @var array<string>
	 *
	 * @phpstan-var list<string>
	 * @psalm-var list<string>
	 *
	 * Fallback file field names for multi-format support.
	 *
	 * @since 1.0.0
	 */
	private array $fallback_file_keys = array('audio_file', 'file', 'upload');

	/**
	 * Default allowed MIME types for audio uploads.
	 *
	 * Explicit MIME types for stricter validation when settings don't specify types.
	 * Includes common audio formats supported across browsers and platforms.
	 *
	 * @var array<string>
	 *
	 * @phpstan-var list<string>
	 * @psalm-var list<string>
	 *
	 * MIME type allowlist for uploads.
	 *
	 * @since 1.0.0
	 */
	private array $default_allowed_mimes = array(
		'audio/webm',
		'audio/ogg',
		'audio/mpeg',
		'audio/wav',
		'audio/x-wav',
		'audio/mp4',
	);

	/**
	 * Initializes the submission handler with required dependencies.
	 *
	 * Sets up WordPress action hooks for temporary file cleanup and logs
	 * successful construction. Throws exceptions on setup failures.
	 *
	 * @param StarmusAudioDAL $dal Data Access Layer implementation
	 * @param StarmusSettings                  $settings Plugin configuration service
	 *
	 * @throws Throwable If construction fails or hooks cannot be registered
	 *
	 * @since 1.0.0
	 */
	public function __construct(
		private readonly StarmusAudioDAL $dal,
		private readonly StarmusSettings $settings
	) {
		try {
			// PHP Runtime Error Trap
			set_error_handler(
				function ($severity, string $message, $file, $line): false {
					error_log(\sprintf('[STARMUS PHP] %s in %s:%s', $message, $file, $line));
					return false; // Continue normal error handling
				}
			);

			add_action('starmus_cleanup_temp_files', $this->cleanup_stale_temp_files(...));
			StarmusLogger::info(
				'Constructed successfully',
				array('component' => __CLASS__)
			);
		} catch (\Throwable $throwable) {
			StarmusLogger::log(
				$throwable,
				array(
					'component' => __CLASS__,
					'method'    => __METHOD__,
				)
			);
			throw $throwable;
		}
	}

	// --- UPLOAD HANDLERS ---

	/**
	 * Processes a completed file upload from disk (TUS or chunked upload completion).
	 *
	 * Main entry point for processing uploaded files that are already on disk,
	 * typically from TUS daemon webhook callbacks. Delegates to internal
	 * finalization method with proper error handling.
	 *
	 * @param string $file_path Absolute path to the uploaded file on disk
	 * @param array  $form_data Sanitized form submission data with metadata
	 *
	 * @since 1.0.0
	 * @see _finalize_from_local_disk() Internal finalization implementation
	 *
	 * Success Response:
	 * ```php
	 * [
	 *   'success' => true,
	 *   'attachment_id' => 123,
	 *   'post_id' => 456,
	 *   'url' => 'https://site.com/uploads/recording.wav'
	 * ]
	 * ```
	 *
	 * @throws WP_Error 400 If file is missing or invalid
	 * @throws WP_Error 413 If file exceeds size limits
	 * @throws WP_Error 415 If MIME type not allowed
	 * @throws WP_Error 500 If file operations or processing fail
	 *
	 * @return array|WP_Error Success data with attachment/post IDs or error object
	 */
	public function process_completed_file(string $file_path, array $form_data): array|WP_Error
	{
		return $this->_finalize_from_local_disk($file_path, $form_data);
	}

	/**
	 * Internal method to finalize file processing from local disk.
	 *
	 * Handles the complete workflow of moving uploaded files to their permanent
	 * location, creating WordPress attachments, and setting up audio recording posts.
	 * Includes security checks, MIME detection, and comprehensive error handling.
	 *
	 * @param string $file_path Path to temporary uploaded file
	 * @param array  $form_data Sanitized form submission data
	 *
	 * @since 1.0.0
	 *
	 * Process Flow:
	 * 1. **File Validation**: Existence, MIME detection with fallbacks
	 * 2. **Security Checks**: Path traversal protection, unique filename generation
	 * 3. **File Movement**: Secure move to WordPress uploads directory
	 * 4. **Attachment Creation**: WordPress attachment via DAL
	 * 5. **Post Management**: Create new or update existing audio recording post
	 * 6. **Metadata Linking**: Associate attachment with post, save metadata
	 * 7. **Cleanup**: Remove temporary files and chunk directories
	 *
	 * MIME Detection Priority:
	 * 1. mime_content_type() for actual file content analysis
	 * 2. wp_check_filetype() for extension-based detection
	 * 3. Form data filetype field as final fallback
	 *
	 * Security Features:
	 * - wp_normalize_path() to prevent path traversal
	 * - wp_unique_filename() to prevent conflicts
	 * - File existence verification before operations
	 * - Cleanup of temporary files on errors
	 *
	 * @throws WP_Error file_missing If file doesn't exist
	 * @throws WP_Error move_failed If file cannot be moved
	 * @throws WP_Error server_error If DAL operations fail
	 *
	 * @return array|WP_Error Success data or error object
	 */
	private function _finalize_from_local_disk(string $file_path, array $form_data): array|WP_Error
	{
		$attachment_id = 0;
		$cpt_post_id   = 0;
		$filename      = $form_data['filename'] ?? pathinfo($file_path, PATHINFO_BASENAME);

		try {
			if ($file_path === '' || $file_path === '0' || ! file_exists($file_path)) {
				return $this->err('file_missing', 'No file to process.', 400);
			}

			$upload_dir = wp_upload_dir();

			// SECURITY: Path Traversal Protection
			$base_path   = wp_normalize_path(trailingslashit($upload_dir['path']));
			$unique_name = wp_unique_filename($upload_dir['path'], $filename);
			$destination = $base_path . $unique_name;

			// MIME Detection with Fallback
			$detected_mime = mime_content_type($file_path);
			if (false === $detected_mime) {
				$wp_check = wp_check_filetype($filename);
				$mime     = $wp_check['type'] ?: 'application/octet-stream';
			} else {
				$mime = $detected_mime;
			}

			$final_mime = empty($mime) ? ($form_data['filetype'] ?? '') : $mime;
			$size       = file_exists($file_path) ? filesize($file_path) : 0;

			$valid = $this->validate_file_against_settings($final_mime, (int) $size);
			if (is_wp_error($valid)) {
				unlink($file_path);
				return $valid;
			}

			global $wp_filesystem;
			if (empty($wp_filesystem)) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				if (! WP_Filesystem()) {
					return $this->err('fs_init_failed', 'Could not initialize filesystem.', 500);
				}
			}

			if (! $wp_filesystem->move($file_path, $destination, true)) {
				unlink($file_path);
				return $this->err('move_failed', 'Failed to move upload.', 500);
			}

			// Cleanup Chunk Directory if applicable
			$parent_dir = \dirname($file_path);
			if (str_contains($parent_dir, 'starmus_tmp')) {
				$this->cleanup_chunks_dir($parent_dir);
			}

			error_log('[STARMUS PHP] Creating attachment from: ' . $destination);
			$attachment_id = $this->dal->create_attachment_from_file($destination, $filename);
			if (is_wp_error($attachment_id)) {
				error_log('[STARMUS PHP] Attachment creation failed: ' . $attachment_id->get_error_message());
				unlink($destination);
				return $attachment_id;
			}

			error_log('[STARMUS PHP] Attachment created: ' . $attachment_id);

			// Update vs Create Logic
			$existing_post_id = isset($form_data['post_id']) ? absint($form_data['post_id']) : (isset($form_data['recording_id']) ? absint($form_data['recording_id']) : 0);

			if ($existing_post_id > 0 && get_post_type($existing_post_id) === $this->get_cpt_slug()) {
				$cpt_post_id       = $existing_post_id;
				$old_attachment_id = (int) get_post_meta($cpt_post_id, '_audio_attachment_id', true);
				if ($old_attachment_id > 0) {
					$this->dal->delete_attachment($old_attachment_id);
				}
			} else {
				$user_id     = isset($form_data['user_id']) ? absint($form_data['user_id']) : get_current_user_id();
				$mapped_data = StarmusSchemaMapper::map_form_data($form_data);
				$title       = $mapped_data['dc_creator'] ?? pathinfo($filename, PATHINFO_FILENAME);
				$cpt_post_id = $this->dal->create_audio_post(
					$title,
					$this->get_cpt_slug(),
					$user_id
				);
				if (is_wp_error($cpt_post_id)) {
					$this->dal->delete_attachment((int) $attachment_id);
					return $cpt_post_id;
				}
			}

			$this->dal->save_post_meta((int) $cpt_post_id, '_audio_attachment_id', (int) $attachment_id);
			$this->dal->set_attachment_parent((int) $attachment_id, (int) $cpt_post_id);
			$this->save_all_metadata((int) $cpt_post_id, (int) $attachment_id, $form_data);

			/**
			 * @deprecated 6.9.3 Use the new 'starmus_recording_processed' hook for better data.
			 * This hook is fired for backward compatibility.
			 */
			do_action('starmus_after_audio_saved', (int) $cpt_post_id, $form_data);
			return array(
				'success'       => true,
				'attachment_id' => (int) $attachment_id,
				'post_id'       => (int) $cpt_post_id,
				'url'           => wp_get_attachment_url((int) $attachment_id),
			);
		} catch (Throwable $throwable) {
			StarmusLogger::log(
				$throwable,
				array(
					'component'     => __CLASS__,
					'method'        => __METHOD__,
					'attachment_id' => (int) $attachment_id,
					'post_id'       => (int) $cpt_post_id,
					'file_path'     => $file_path,
					'filename'      => $filename,
				)
			);
			return $this->err('server_error', 'File finalization failed.', 500);
		}
	}

	/**
	 * Handles REST API fallback uploads for Tier C browsers.
	 *
	 * Processes traditional file uploads via WordPress REST API when modern
	 * upload methods (TUS, chunked) are not supported. Includes rate limiting,
	 * enhanced MIME detection for iOS/Safari, and comprehensive validation.
	 *
	 * @param WP_REST_Request $request REST API request with file and form data
	 *
	 * @since 1.0.0
	 *
	 * Rate Limiting:
	 * - Checks user-based rate limits before processing
	 * - Returns 429 status for excessive requests
	 *
	 * MIME Detection (iOS/Safari Fix):
	 * 1. Check uploaded file type from browser
	 * 2. Use mime_content_type() if browser type is empty
	 * 3. Fallback to wp_check_filetype() for extension-based detection
	 *
	 * Supported File Keys (Priority Order):
	 * - audio_file (preferred)
	 * - file (generic)
	 * - upload (fallback)
	 * - Any array field with tmp_name
	 * @see process_fallback_upload() File processing implementation
	 * @see detect_file_key() File field detection logic
	 * @see validate_file_against_settings() MIME and size validation
	 *
	 * @throws WP_Error rate_limited If user exceeds rate limits
	 * @throws WP_Error missing_file If no valid file provided
	 * @throws WP_Error upload_error If browser upload failed
	 * @throws WP_Error server_error If processing fails
	 *
	 * @return array|WP_Error Success response or error object
	 */
	public function handle_fallback_upload_rest(WP_REST_Request $request): array|WP_Error
	{
		$file_key   = '';
		$form_data  = array();
		$files_data = array();
		$mime       = '';

		try {
			if ($this->is_rate_limited(get_current_user_id())) {
				return $this->err('rate_limited', 'Too frequent.', 429);
			}

			$form_data  = $this->sanitize_submission_data($request->get_params() ?? array());
			$files_data = $request->get_file_params() ?? array();

			$file_key = $this->detect_file_key($files_data);
			if (! $file_key) {
				return $this->err('missing_file', 'No audio file provided.', 400);
			}

			$file = $files_data[$file_key];
			if (! isset($file['error']) || (int) $file['error'] !== 0 || empty($file['tmp_name'])) {
				return $this->err('upload_error', 'Upload failed on client.', 400);
			}

			// CRITICAL FIX: Reliable MIME probing for REST fallback (handles iOS/Safari empty type)
			$mime = $file['type'] ?? '';

			if (empty($mime) && ! empty($file['tmp_name']) && file_exists($file['tmp_name'])) {
				$detected = mime_content_type($file['tmp_name']);
				if ($detected) {
					$mime = $detected;
				} else {
					// Fallback to extension check
					$check = wp_check_filetype((string) ($file['name'] ?? ''));
					$mime  = $check['type'] ?? '';
				}
			}

			$validation = $this->validate_file_against_settings($mime, (int) ($file['size'] ?? 0));
			if (is_wp_error($validation)) {
				return $validation;
			}

			error_log('[STARMUS PHP] Processing fallback upload for key: ' . $file_key);
			$result = $this->process_fallback_upload($files_data, $form_data, $file_key);

			if (is_wp_error($result)) {
				error_log('[STARMUS PHP] Fallback upload failed: ' . $result->get_error_message());
				return $result;
			}

			error_log('[STARMUS PHP] Fallback upload success');

			return array(
				'success' => true,
				'data'    => $result['data'],
			);
		} catch (\Throwable $throwable) {
			StarmusLogger::log(
				$throwable,
				array(
					'component' => __CLASS__,
					'method'    => __METHOD__,
					'file_key'  => $file_key,
					'mime'      => $mime,
				)
			);
			return $this->err('server_error', 'Fallback upload exception.', 500);
		}
	}

	/**
	 * Processes traditional file uploads using WordPress media functions.
	 *
	 * Handles sideloaded file uploads for fallback scenarios when TUS or
	 * chunked uploads are not available. Uses WordPress core media handling
	 * functions with DAL abstraction for consistency.
	 *
	 * @param array  $files_data $_FILES array data from request
	 * @param array  $form_data Sanitized form submission data
	 * @param string $file_key Detected file field key from files array
	 *
	 * @since 1.0.0
	 *
	 * Required WordPress Functions:
	 * - media_handle_sideload() for attachment creation
	 * - Includes image.php, file.php, media.php if not loaded
	 *
	 * Post Creation Logic:
	 * - Uses existing post if post_id provided and valid
	 * - Creates new audio recording post otherwise
	 * - Associates attachment as post parent
	 *
	 * Response Includes:
	 * - attachment_id: WordPress attachment post ID
	 * - post_id: Audio recording custom post ID
	 * - url: Direct attachment file URL
	 * - redirect_url: User-facing success page URL
	 *
	 * @throws WP_Error missing_file If file field is empty
	 * @throws WP_Error server_error If DAL operations fail
	 *
	 * @return array|WP_Error Success data with redirect URL or error
	 */
	public function process_fallback_upload(array $files_data, array $form_data, string $file_key): array|WP_Error
	{
		$attachment_id = 0;
		$cpt_post_id   = 0;

		try {
			if (! \function_exists('media_handle_sideload')) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';
			}

			if (empty($files_data[$file_key])) {
				return $this->err('missing_file', 'No audio file provided.', 400);
			}

			$attachment_id = $this->dal->create_attachment_from_sideload($files_data[$file_key]);
			if (is_wp_error($attachment_id)) {
				return $attachment_id;
			}

			$existing_id = isset($form_data['post_id']) ? absint($form_data['post_id']) : 0;

			if ($existing_id > 0 && get_post_type($existing_id) === $this->get_cpt_slug()) {
				$cpt_post_id = $existing_id;
			} else {
				$mapped_data = StarmusSchemaMapper::map_form_data($form_data);
				$title       = $mapped_data['dc_creator'] ?? pathinfo((string) $files_data[$file_key]['name'], PATHINFO_FILENAME);
				$cpt_post_id = $this->dal->create_audio_post($title, $this->get_cpt_slug(), get_current_user_id());
			}

			if (is_wp_error($cpt_post_id)) {
				$this->dal->delete_attachment((int) $attachment_id);
				return $cpt_post_id;
			}

			$this->dal->save_post_meta((int) $cpt_post_id, '_audio_attachment_id', (int) $attachment_id);
			$this->dal->set_attachment_parent((int) $attachment_id, (int) $cpt_post_id);

			$form_data['original_source'] = (int) $attachment_id;
			$this->save_all_metadata((int) $cpt_post_id, (int) $attachment_id, $form_data);

			return array(
				'success' => true,
				'data'    => array(
					'attachment_id' => (int) $attachment_id,
					'post_id'       => (int) $cpt_post_id,
					'url'           => wp_get_attachment_url((int) $attachment_id),
					'redirect_url'  => esc_url($this->get_redirect_url()),
				),
			);
		} catch (Throwable $e) {
			StarmusLogger::log(
				$e,
				array(
					'component'     => __CLASS__,
					'method'        => __METHOD__,
					'attachment_id' => (int) $attachment_id,
					'post_id'       => (int) $cpt_post_id,
					'file_key'      => $file_key,
				)
			);
			return $this->err('server_error', 'Fallback processing failed.', 500);
		}
	}

	// --- METADATA SAVING ---
	/**
	 * Saves comprehensive metadata for audio recording posts.
	 *
	 * Extracts and processes various types of metadata from form submissions,
	 * including environment data, calibration settings, transcripts, and device
	 * information. Updates ACF fields and taxonomies with proper sanitization.
	 *
	 * @param int   $audio_post_id Audio recording custom post ID
	 * @param int   $attachment_id WordPress attachment post ID
	 * @param array $form_data Complete sanitized form submission data
	 *
	 * @since 1.0.0
	 *
	 * Metadata Types Processed:
	 * 1. **Environment Data**: Browser, device, network information
	 * 2. **Calibration Data**: Microphone settings and audio levels
	 * 3. **Runtime Metadata**: Processing configuration and environment data
	 * 4. **Waveform Data**: Audio visualization information
	 * 5. **Submission Info**: IP address, timestamps, user agent
	 * 6. **Taxonomies**: Language and recording type classifications
	 * 7. **Linked Objects**: Connections to other custom post types
	 *
	 * JSON Field Processing:
	 * - _starmus_env: Environment/UEC data with device fingerprinting
	 * - _starmus_calibration: Microphone calibration and gain settings
	 * - waveform_json: Audio visualization data for editors
	 *
	 * ACF Field Mapping:
	 * - environment_data: Complete environment JSON
	 * - device_fingerprint: Extracted device identifiers
	 * - user_agent: Browser user agent string
	 * - runtime_metadata: Calibration settings JSON
	 * - mic_profile: Human-readable microphone settings
	 * - runtime_metadata: Processing environment and configuration
	 * - submission_ip: User IP address (GDPR/privacy considerations)
	 *
	 * WordPress Actions:
	 * - starmus_after_save_submission_metadata (deprecated 7.0.0)
	 * - starmus_recording_processed: New definitive integration hook
	 * @see update_acf_field() ACF field update wrapper
	 * @see trigger_post_processing() Post-processing service trigger
	 * @see StarmusSanitizer::get_user_ip() IP address extraction
	 */
	public function save_all_metadata(int $audio_post_id, int $attachment_id, array $form_data): void
	{
		try {
			// Map form data to new schema
			$mapped_data = StarmusSchemaMapper::map_form_data($form_data);
			error_log('[STARMUS PHP] Mapped form data: ' . json_encode(array_keys($mapped_data)));

			// Handle JavaScript-submitted environment data (_starmus_env)
			$env_json    = $form_data['_starmus_env'] ?? '';
			$decoded_env = null;
			if ($env_json) {
				$decoded_env = json_decode(wp_unslash($env_json), true);
				if ($decoded_env) {
					// Store complete environment data in environment_data (Group C)
					$this->update_acf_field('environment_data', json_encode($decoded_env), $audio_post_id);

					// Extract device fingerprint if available
					if (isset($decoded_env['fingerprint'])) {
						$this->update_acf_field('device_fingerprint', $decoded_env['fingerprint'], $audio_post_id);
					}
				}
			}

			// Handle JavaScript-submitted calibration data (_starmus_calibration)
			$cal_json = $form_data['_starmus_calibration'] ?? '';
			if ($cal_json) {
				$decoded_cal = json_decode(wp_unslash($cal_json), true);
				if ($decoded_cal) {
					// Store calibration data (gain, speechLevel) in transcriber field (Group C)
					$this->update_acf_field('transcriber', json_encode($decoded_cal), $audio_post_id);
				}
			}

			// Handle waveform JSON from JavaScript
			if (! empty($form_data['waveform_json'])) {
				$wf_value = \is_string($form_data['waveform_json']) ? $form_data['waveform_json'] : json_encode($form_data['waveform_json']);
				$this->update_acf_field('waveform_json', $wf_value, $audio_post_id);
			}

			// Handle recording metadata from JavaScript
			if (! empty($form_data['recording_metadata'])) {
				$metadata_value = \is_string($form_data['recording_metadata']) ? $form_data['recording_metadata'] : json_encode($form_data['recording_metadata']);
				$this->update_acf_field('recording_metadata', $metadata_value, $audio_post_id);
			}

			// New schema: User mappings (Groups A, B, D)
			$user_ids = StarmusSchemaMapper::extract_user_ids($form_data);
			foreach ($user_ids as $field => $value) {
				$this->update_acf_field($field, $value, $audio_post_id);
			}

			// Agreement to Terms (Group D) - Store in UTC
			$timestamp  = gmdate('Y-m-d H:i:s'); // UTC timestamp
			$user_ip    = StarmusSanitizer::get_user_ip();
			$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
			$this->update_acf_field('agreement_datetime', $timestamp, $audio_post_id);
			$this->update_acf_field('contributor_ip', $user_ip, $audio_post_id);
			$this->update_acf_field('contributor_user_agent', sanitize_text_field($user_agent), $audio_post_id);
			$this->update_acf_field('submission_ip', $user_ip, $audio_post_id);

			// Session metadata (Group B) - includes new fields
			$session_fields = array(
				'project_collection_id',
				'accession_number',
				'location',
				'session_date',
				'session_start_time',
				'gps_coordinates',
				'recording_equipment',
				'audio_files_originals',
				'media_condition_notes',
				'agreement_to_terms_toggle',
				'related_consent_agreement',
				'usage_restrictions_rights',
				'access_level',
				'first_pass_transcription',
				'audio_quality_score_tax',
			);
			foreach ($session_fields as $field) {
				if (isset($mapped_data[$field])) {
					if ($field === 'first_pass_transcription') {
						// Handle first pass transcription as readonly field
						$this->update_acf_field($field, $mapped_data[$field], $audio_post_id);
					} else {
						$this->update_acf_field($field, sanitize_text_field((string) $mapped_data[$field]), $audio_post_id);
					}
				}
			}

			// Processing fields (Group C) - JSON encoded
			foreach ($mapped_data as $field => $value) {
				if (StarmusSchemaMapper::is_json_field($field)) {
					$this->update_acf_field($field, $value, $audio_post_id);
				}
			}

			// Core archival fields (Group A)
			if (isset($mapped_data['dc_creator'])) {
				$this->update_acf_field('dc_creator', sanitize_text_field((string) $mapped_data['dc_creator']), $audio_post_id);
			}

			// File attachments (Group C)
			if ($attachment_id !== 0) {
				$this->update_acf_field('original_source', $attachment_id, $audio_post_id);
				// Also update audio_files_originals for backward compatibility
				$this->update_acf_field('audio_files_originals', array($attachment_id), $audio_post_id);
			}

			if (isset($mapped_data['mastered_mp3'])) {
				$this->update_acf_field('mastered_mp3', $mapped_data['mastered_mp3'], $audio_post_id);
			}

			if (isset($mapped_data['archival_wav'])) {
				$this->update_acf_field('archival_wav', $mapped_data['archival_wav'], $audio_post_id);
			}

			// Additional Group D fields
			if (isset($form_data['url'])) {
				$this->update_acf_field('url', $form_data['url'], $audio_post_id);
			}

			if (isset($form_data['submission_id'])) {
				$this->update_acf_field('submission_id', sanitize_text_field($form_data['submission_id']), $audio_post_id);
			}

			// Handle taxonomies through mapped data
			if (! empty($mapped_data['language'])) {
				wp_set_post_terms($audio_post_id, array((int) $mapped_data['language']), 'language');
			}

			if (! empty($mapped_data['recording_type'])) {
				wp_set_post_terms($audio_post_id, array((int) $mapped_data['recording_type']), 'recording-type');
			}

			// --- START: Links Starmus Audio to Other CPTs ---

			/**
			 * @deprecated 7.0.0 Use 'starmus_recording_processed' instead.
			 * Fires after submission metadata is saved.
			 *
			 * @param int $audio_post_id The Post ID of the audio recording.
			 * @param array $form_data The submitted form data.
			 * @param array $unused (Unused) For backward compatibility.
			 */
			do_action('starmus_after_save_submission_metadata', $audio_post_id, $form_data, array());

			// 'artifact_id' represents the ID of the primary object (e.g., Word or Artifact)
			// that this audio recording should be linked to. It is expected to be present in
			// the form data when a recording is associated with another object. If 'artifact_id'
			// is missing, we default to 0, meaning no link is established. For compatibility,
			// we also check for 'post_id' as a fallback field name.
			$linked_post_id = isset($form_data['artifact_id']) ? absint($form_data['artifact_id']) : 0;
			// If the linked ID might come from a different field, you can add a fallback:
			if ($linked_post_id === 0 && isset($form_data['post_id'])) {
				$linked_post_id = absint($form_data['post_id']);
			}

			/**
			 * Fires after a recording is fully processed and all metadata is saved.
			 * This is the definitive hook for integrations.
			 *
			 * @since 7.0.0
			 *
			 * @param int $audio_post_id The Post ID of the newly created 'Audio-Recording' post.
			 * @param int $linked_post_id The Post ID of the Word/Artifact this recording is linked to.
			 */
			do_action('starmus_recording_processed', $audio_post_id, $linked_post_id);

			// Extract session UUID from environment data if available
			$session_uuid = 'unknown';
			if (! empty($form_data['_starmus_env'])) {
				$decoded_env = json_decode(wp_unslash($form_data['_starmus_env']), true);
				if ($decoded_env && isset($decoded_env['identifiers']['sessionId'])) {
					$session_uuid = $decoded_env['identifiers']['sessionId'];
				}
			}

			$processing_params = array(
				'bitrate'      => '192k',
				'samplerate'   => 44100,
				'network_type' => '4g',
				'session_uuid' => $session_uuid,
			);
			$this->trigger_post_processing($audio_post_id, $attachment_id, $processing_params);
		} catch (Throwable $e) {
			StarmusLogger::log(
				$e,
				array(
					'component'     => __CLASS__,
					'method'        => __METHOD__,
					'post_id'       => $audio_post_id,
					'attachment_id' => $attachment_id,
				)
			);
		}
	}

	/**
	 * Triggers post-processing tasks for uploaded audio files.
	 *
	 * Attempts immediate post-processing via service, with automatic cron
	 * scheduling as fallback if processing fails or is unavailable.
	 *
	 * @param int   $post_id Audio recording custom post ID
	 * @param int   $attachment_id WordPress attachment post ID
	 * @param array $params Processing parameters for audio optimization
	 *
	 * @since 1.0.0
	 *
	 * Processing Parameters:
	 * - bitrate: Target audio bitrate (default: 192k)
	 * - samplerate: Target sample rate (default: 44100Hz)
	 * - network_type: User's network type for optimization
	 * - session_uuid: Unique session identifier for tracking
	 *
	 * Fallback Strategy:
	 * 1. Attempt immediate processing via StarmusPostProcessingService
	 * 2. If processing fails, schedule cron job for retry after 60 seconds
	 * 3. Prevents duplicate cron jobs with wp_next_scheduled() check
	 * @see StarmusPostProcessingService::process() Immediate processing
	 * @see wp_schedule_single_event() Cron job scheduling
	 */
	private function trigger_post_processing(int $post_id, int $attachment_id, array $params): void
	{
		try {
			error_log('[STARMUS PHP] Triggering post processing for post: ' . $post_id . ', attachment: ' . $attachment_id);
			$processor = new StarmusPostProcessingService();
			$result    = $processor->process($post_id, $attachment_id, $params);
			error_log('[STARMUS PHP] Post processing result: ' . ($result ? 'SUCCESS' : 'FAILED'));

			if (! $result && ! wp_next_scheduled('starmus_cron_process_pending_audio', array($post_id, $attachment_id))) {
				StarmusLogger::log(
					'[STARMUS PHP] Scheduling cron job for post processing retry',
					array(
						'component'     => __CLASS__,
						'post_id'       => $post_id,
						'attachment_id' => $attachment_id,
					)
				);
				wp_schedule_single_event(time() + 60, 'starmus_cron_process_pending_audio', array($post_id, $attachment_id));
			}
		} catch (Throwable $throwable) {
			error_log('[STARMUS PHP] Post processing trigger failed: ' . $throwable->getMessage());
			StarmusLogger::log(
				$throwable,
				array(
					'post_id'       => $post_id,
					'attachment_id' => $attachment_id,
				)
			);
		}
	}

	// --- HELPERS ---

	/**
	 * Gets the custom post type slug for audio recordings.
	 *
	 * Retrieves the configured CPT slug from settings with sanitization
	 * and fallback to default value.
	 *
	 * @return string Custom post type slug
	 *
	 * @since 1.0.0
	 */
	public function get_cpt_slug(): string
	{
		return ($this->settings instanceof \Starisian\Sparxstar\Starmus\core\StarmusSettings && $this->settings->get('cpt_slug')) ? sanitize_key((string) $this->settings->get('cpt_slug')) : 'audio-recording';
	}

	/**
	 * Updates ACF fields via DAL abstraction.
	 *
	 * Wrapper method for consistent ACF field updates through
	 * the Data Access Layer interface.
	 *
	 * @param string $key ACF field key
	 * @param mixed  $value Field value to save
	 * @param int    $id Post ID to update
	 *
	 * @since 1.0.0
	 */
	private function update_acf_field(string $key, $value, int $id): void
	{
		$this->dal->save_post_meta($id, $key, $value);
	}

	/**
	 * Sanitizes form submission data using central sanitizer.
	 *
	 * @param array $data Raw form submission data
	 *
	 * @return array Sanitized submission data
	 *
	 * @since 1.0.0
	 * @see StarmusSanitizer::sanitize_submission_data()
	 */
	public function sanitize_submission_data(array $data): array
	{
		return StarmusSanitizer::sanitize_submission_data($data);
	}

	/**
	 * Checks if user is rate limited for uploads.
	 *
	 * @param int $id User ID to check
	 *
	 * @return bool True if rate limited, false otherwise
	 *
	 * @since 1.0.0
	 */
	private function is_rate_limited(int $id): bool
	{
		return $this->dal->is_rate_limited($id);
	}

	/**
	 * Gets temporary directory path for upload processing.
	 *
	 * Creates path within WordPress uploads directory with fallback
	 * to system temporary directory.
	 *
	 * @return string Temporary directory path with trailing slash
	 *
	 * @since 1.0.0
	 */
	private function get_temp_dir(): string
	{
		return trailingslashit(wp_upload_dir()['basedir'] ?: sys_get_temp_dir()) . 'starmus_tmp/';
	}

	/**
	 * Gets redirect URL for successful submissions.
	 *
	 * @return string User-facing success page URL
	 *
	 * @since 1.0.0
	 */
	private function get_redirect_url(): string
	{
		return home_url('/my-submissions');
	}

	/**
	 * Detects the file field key from uploaded files array.
	 *
	 * Searches through predefined fallback keys and then all array keys
	 * to find a valid file upload field.
	 *
	 * @param array $files $_FILES array or similar file data
	 *
	 * @return string|null File key if found, null otherwise
	 *
	 * @since 1.0.0
	 *
	 * Search Priority:
	 * 1. Predefined fallback keys (audio_file, file, upload)
	 * 2. Any array key with valid tmp_name field
	 */
	private function detect_file_key(array $files): ?string
	{
		foreach ($this->fallback_file_keys as $key) {
			if (! empty($files[$key]) && \is_array($files[$key])) {
				return $key;
			}
		}

		foreach ($files as $key => $val) {
			if (\is_array($val) && ! empty($val['tmp_name'])) {
				return (string) $key;
			}
		}

		return null;
	}

	/**
	 * Cleans up stale temporary files via cron job.
	 *
	 * Removes temporary .part files older than 24 hours from the
	 * temporary directory. Registered as WordPress cron callback.
	 *
	 * @since 1.0.0
	 *
	 * @hook starmus_cleanup_temp_files
	 */
	public function cleanup_stale_temp_files(): void
	{
		$dir = $this->get_temp_dir();
		if (is_dir($dir)) {
			foreach (glob($dir . '*.part') as $file) {
				if (file_exists($file) && filemtime($file) < time() - DAY_IN_SECONDS) {
					unlink($file);
				}
			}
		}
	}

	/**
	 * Validates uploaded files against plugin settings and security policies.
	 *
	 * Performs comprehensive validation including file size limits and MIME type
	 * restrictions. Includes special handling for audio/webm MIME type which
	 * WordPress incorrectly maps to video/webm by default.
	 *
	 * @param string $mime Detected MIME type from file
	 * @param int    $size_bytes File size in bytes
	 *
	 * @since 1.0.0
	 *
	 * Validation Steps:
	 * 1. **Size Check**: Compare against configured MB limit
	 * 2. **MIME Check**: Validate against allowed types list
	 * 3. **Extension Mapping**: Handle WordPress MIME type limitations
	 *
	 * WordPress MIME Type Fix:
	 * - Explicitly allows audio/webm when 'webm' extension is configured
	 * - Works around WordPress defaulting webm to video/webm only
	 *
	 * @throws WP_Error file_too_large If file exceeds size limit (413)
	 * @throws WP_Error mime_not_allowed If MIME type not allowed (415)
	 *
	 * @return true|WP_Error True if valid, WP_Error if validation fails
	 */
	private function validate_file_against_settings(string $mime, int $size_bytes): true|WP_Error
	{
		$max_mb = $this->settings ? (int) $this->settings->get('file_size_limit', 10) : 10;
		if ($size_bytes > ($max_mb * 1024 * 1024)) {
			return $this->err('file_too_large', \sprintf('Max %dMB', $max_mb), 413);
		}

		$allowed_str = $this->settings ? $this->settings->get('allowed_file_types', '') : '';

		if (empty($allowed_str)) {
			$allowed_mimes = $this->default_allowed_mimes;
		} else {
			$exts          = array_map(trim(...), explode(',', (string) $allowed_str));
			$allowed_mimes = array();
			$wp_mimes      = wp_get_mime_types();

			foreach ($exts as $ext) {
				// FIX: Ensure audio/webm is allowed if 'webm' is listed.
				// WP defaults 'webm' to 'video/webm', causing 'audio/webm' uploads to fail validation.
				if ('webm' === $ext) {
					$allowed_mimes[] = 'audio/webm';
				}

				foreach ($wp_mimes as $ext_pattern => $mime_type) {
					if (str_contains($ext_pattern, $ext)) {
						$exploded = explode('|', $mime_type);
						foreach ($exploded as $m) {
							$allowed_mimes[] = $m;
						}
					}
				}
			}
		}

		if (! \in_array(strtolower($mime), $allowed_mimes, true)) {
			return $this->err('mime_not_allowed', 'Type not allowed: ' . $mime, 415);
		}

		return true;
	}

	/**
	 * Safely removes chunk directories after file processing.
	 *
	 * Validates that the path is within the temporary directory before
	 * performing cleanup operations to prevent path traversal attacks.
	 *
	 * @param string $path Directory path to clean up
	 *
	 * @since 1.0.0
	 *
	 * Security Features:
	 * - Path validation against temporary directory
	 * - Safe file removal with glob pattern matching
	 * - Directory removal only after emptying
	 */
	private function cleanup_chunks_dir(string $path): void
	{
		$temp_dir = $this->get_temp_dir();
		$path     = trailingslashit($path);

		if (str_starts_with($path, $temp_dir) && is_dir($path)) {
			$files = glob($path . '*');
			if ($files) {
				array_map(unlink(...), $files);
			}

			rmdir($path);
		}
	}

	/**
	 * Creates standardized error responses with logging.
	 *
	 * Generates WP_Error objects with consistent formatting and automatic
	 * logging for debugging and monitoring purposes.
	 *
	 * @param string $code Error code for identification
	 * @param string $message Human-readable error message
	 * @param int    $status HTTP status code (default: 400)
	 *
	 * @return WP_Error Formatted error object
	 *
	 * @since 1.0.0
	 */
	private function err(string $code, string $message, int $status = 400): WP_Error
	{
		StarmusLogger::info(
			\sprintf('%s: %s', $code, $message),
			array('component' => __CLASS__)
		);
		return new WP_Error($code, $message, array('status' => $status));
	}
}
