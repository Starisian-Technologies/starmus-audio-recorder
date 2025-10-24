<?php
/**
 * Core submission service responsible for processing uploads.
 *
 * @package   Starisian\Sparxstar\Starmus\includes
 */

namespace Starisian\Sparxstar\Starmus\includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Throwable;
use WP_Error;
use WP_REST_Request;

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\helpers\StarmusSanitizer;
use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\services\PostProcessingService;

use function is_wp_error;
use function wp_upload_dir;
use function wp_insert_attachment;
use function wp_update_attachment_metadata;
use function wp_generate_attachment_metadata;
use function wp_delete_attachment;
use function update_post_meta;
use function wp_update_post;
use function wp_get_attachment_url;
use function media_handle_upload;
use function get_current_user_id;
use function sanitize_key;
use function get_transient;
use function set_transient;
use function wp_mkdir_p;
use function trailingslashit;
use function pathinfo;
use function preg_match;
use function file_put_contents;
use function file_exists;
use function uniqid;
use function glob;
use function filemtime;
use function unlink;
use const MINUTE_IN_SECONDS;
use const DAY_IN_SECONDS;

/**
 * Handles validation and persistence for audio submissions.
 *
 * The class exposes helpers that can be wired into REST callbacks or
 * traditional form submissions. It purposely avoids registering REST
 * routes directly so the caller controls exposure and permissions.
 */
class StarmusSubmissionHandler {

	/** Namespaced identifier used for REST endpoints. */
	public const STARMUS_REST_NAMESPACE = 'star-starmus-audio-recorder/v1';

	/** Lazily injected plugin settings service. */
	private ?StarmusSettings $settings;

	/**
	 * Build the submission handler and wire scheduled maintenance hooks.
	 *
	 * @param StarmusSettings|null $settings Optional plugin settings adapter.
	 */
	public function __construct( ?StarmusSettings $settings ) {
		$this->settings = $settings;
		add_action( 'starmus_cleanup_temp_files', array( $this, 'cleanup_stale_temp_files' ) );
	}

	/**
	 * Process a chunked upload request coming from the REST API.
	 *
	 * @param WP_REST_Request $request Incoming WordPress REST request.
	 * @return array|WP_Error Response payload on success or error object.
	 */
	public function handle_upload_chunk_rest( WP_REST_Request $request ): array|WP_Error {
		StarmusLogger::setCorrelationId();
		StarmusLogger::info(
			'SubmissionHandler',
			'Chunk request received',
			array(
				'user_id' => get_current_user_id(),
				'ip'      => StarmusSanitizer::get_user_ip(),
			)
		);

		try {
			if ( $this->is_rate_limited( get_current_user_id() ) ) {
				StarmusLogger::warning(
					'SubmissionHandler',
					'Rate limited chunk',
					array(
						'user_id' => get_current_user_id(),
					)
				);
				return new WP_Error( 'rate_limited', 'You are uploading too frequently.', array( 'status' => 429 ) );
			}

			$params = $this->sanitize_submission_data( $request->get_json_params() ?? array() );
			StarmusLogger::debug(
				'SubmissionHandler',
				'Chunk params sanitized',
				array(
					'upload_id'   => $params['upload_id'] ?? null,
					'chunk_index' => $params['chunk_index'] ?? null,
					'is_last'     => ! empty( $params['is_last_chunk'] ),
				)
			);

			$validation = $this->validate_chunk_data( $params );
			if ( is_wp_error( $validation ) ) {
				StarmusLogger::warning(
					'SubmissionHandler',
					'Chunk validation failed',
					array(
						'upload_id' => $params['upload_id'] ?? null,
						'error'     => $validation->get_error_message(),
					)
				);
				return $validation;
			}

			$tmp_file = $this->write_chunk_streamed( $params );
			if ( is_wp_error( $tmp_file ) ) {
				StarmusLogger::error(
					'SubmissionHandler',
					'Chunk write failed',
					array(
						'upload_id' => $params['upload_id'] ?? null,
						'error'     => $tmp_file->get_error_message(),
					)
				);
				return $tmp_file;
			}

			if ( ! empty( $params['is_last_chunk'] ) ) {
				StarmusLogger::info(
					'SubmissionHandler',
					'Last chunk received, finalizing',
					array(
						'upload_id' => $params['upload_id'] ?? null,
						'path'      => $tmp_file,
					)
				);
				return $this->finalize_submission( $tmp_file, $params );
			}

			return array(
				'success' => true,
				'data'    => array(
					'status' => 'chunk_received',
					'file'   => $tmp_file,
				),
			);
		} catch ( Throwable $e ) {
			StarmusLogger::error( 'SubmissionHandler', $e );
			return new WP_Error( 'server_error', 'Failed to process chunk.', array( 'status' => 500 ) );
		}
	}

	/**
	 * Handle the REST fallback upload that uses multipart/form-data.
	 *
	 * @param WP_REST_Request $request Incoming WordPress REST request.
	 * @return array|WP_Error Response payload on success or error object.
	 */
	public function handle_fallback_upload_rest( WP_REST_Request $request ): array|WP_Error {
		StarmusLogger::setCorrelationId();

		try {
			$form_data  = $this->sanitize_submission_data( $request->get_params() ?? array() );
			$files_data = $request->get_file_params();

			if ( empty( $files_data['audio_file'] ) ) {
				StarmusLogger::warning( 'SubmissionHandler', 'Fallback missing audio_file payload' );
				return new WP_Error( 'missing_file', 'No audio file provided.' );
			}

			StarmusLogger::info(
				'SubmissionHandler',
				'Processing fallback upload',
				array(
					'user_id' => get_current_user_id(),
					'name'    => $files_data['audio_file']['name'] ?? null,
					'type'    => $files_data['audio_file']['type'] ?? null,
					'size'    => $files_data['audio_file']['size'] ?? null,
				)
			);

			return $this->process_fallback_upload( $files_data, $form_data );
		} catch ( Throwable $e ) {
			StarmusLogger::error( 'SubmissionHandler', $e );
			return new WP_Error( 'server_error', 'Failed to process fallback upload.', array( 'status' => 500 ) );
		}
	}

	/**
	 * Validate the minimal payload required for chunk persistence.
	 *
	 * @param array $params Sanitized request parameters.
	 * @return true|WP_Error True when valid or WP_Error on failure.
	 */
	private function validate_chunk_data( array $params ): true|WP_Error {
		if ( empty( $params['upload_id'] ) || empty( $params['chunk_index'] ) ) {
			return new WP_Error( 'invalid_params', 'Missing upload_id or chunk_index.' );
		}
		if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $params['upload_id'] ) ) {
			return new WP_Error( 'invalid_id', 'Invalid upload_id format.' );
		}
		return true;
	}

	/**
	 * Append a decoded chunk to the temporary upload file on disk.
	 *
	 * @param array $params Sanitized request parameters.
	 * @return string|WP_Error File path of the temporary upload or error.
	 */
	private function write_chunk_streamed( array $params ): string|WP_Error {
		$temp_dir = $this->get_temp_dir();
		if ( ! is_dir( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		$file_path = $temp_dir . $params['upload_id'] . '.part';
		$chunk     = base64_decode( $params['data'] ?? '', true );

		if ( $chunk === false ) {
			return new WP_Error( 'invalid_chunk', 'Chunk data not valid base64.' );
		}

		$result = file_put_contents( $file_path, $chunk, FILE_APPEND );
		if ( $result === false ) {
			return new WP_Error( 'write_failed', 'Failed to write chunk.' );
		}

		StarmusLogger::debug(
			'SubmissionHandler',
			'Chunk appended',
			array(
				'path'        => $file_path,
				'chunk_index' => $params['chunk_index'],
			)
		);

		return $file_path;
	}

	/**
	 * Finalize a chunked upload by promoting the temporary file to media.
	 *
	 * @param string $file_path Temporary file path.
	 * @param array  $form_data Sanitized submission context.
	 * @return array|WP_Error Finalized payload or error object.
	 */
	private function finalize_submission( string $file_path, array $form_data ): array|WP_Error {
		StarmusLogger::info(
			'SubmissionHandler',
			'Finalizing submission',
			array(
				'temp_path' => $file_path,
			)
		);

		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_missing', 'No file to finalize.' );
		}

		$filename    = $form_data['filename'] ?? uniqid( 'starmus_', true ) . '.webm';
		$upload_dir  = wp_upload_dir();
		$destination = $upload_dir['path'] . '/' . $filename;

		if ( ! @rename( $file_path, $destination ) ) {
			StarmusLogger::error(
				'SubmissionHandler',
				'Move to destination failed',
				array(
					'from' => $file_path,
					'to'   => $destination,
				)
			);
			if ( ! @unlink( $file_path ) ) {
				StarmusLogger::warning(
					'SubmissionHandler',
					'Failed to delete temp after move failure',
					array(
						'path' => $file_path,
					)
				);
			}
			return new WP_Error( 'move_failed', 'Failed to move upload.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$file_check = wp_check_filetype_and_ext( $destination, $filename, wp_get_mime_types() );
		$mime_type  = $file_check['type'] ?? '';
		$ext        = $file_check['ext'] ?? '';
		if ( empty( $mime_type ) || empty( $ext ) || ! preg_match( '#^audio/([a-z0-9.+-]+)$#i', $mime_type ) ) {
			StarmusLogger::warning(
				'SubmissionHandler',
				'Invalid audio type after finalize',
				array(
					'path' => $destination,
					'type' => $mime_type,
					'ext'  => $ext,
				)
			);
			if ( ! @unlink( $destination ) ) {
				StarmusLogger::warning(
					'SubmissionHandler',
					'Failed to delete invalid audio file',
					array(
						'path' => $destination,
					)
				);
			}
			return new WP_Error( 'invalid_type', 'Uploaded file must be an audio format.' );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime_type,
				'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$destination
		);

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $destination )
		);

		$cpt_post_id = $this->create_recording_post( (int) $attachment_id, $form_data, $filename );
		if ( is_wp_error( $cpt_post_id ) ) {
			StarmusLogger::error(
				'SubmissionHandler',
				'CPT creation failed; deleting attachment',
				array(
					'attachment_id' => $attachment_id,
					'error'         => $cpt_post_id->get_error_message(),
				)
			);
			wp_delete_attachment( $attachment_id, true );
			return $cpt_post_id;
		}

		update_post_meta( $cpt_post_id, '_audio_attachment_id', $attachment_id );
		wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_parent' => $cpt_post_id,
			)
		);

		$this->save_all_metadata( $cpt_post_id, $attachment_id, $form_data );

		StarmusLogger::info(
			'SubmissionHandler',
			'Finalize complete',
			array(
				'post_id'       => $cpt_post_id,
				'attachment_id' => $attachment_id,
				'url'           => wp_get_attachment_url( $attachment_id ),
			)
		);

		return array(
			'success' => true,
			'data'    => array(
				'attachment_id' => $attachment_id,
				'post_id'       => $cpt_post_id,
				'url'           => wp_get_attachment_url( $attachment_id ),
				'redirect_url'  => esc_url( $this->get_redirect_url() ),
			),
		);
	}

	/**
	 * Process classic form uploads submitted through multipart endpoints.
	 *
	 * @param array $files_data Normalized $_FILES array from the request.
	 * @param array $form_data  Sanitized form parameters.
	 * @return array|WP_Error Result payload or WP_Error when validation fails.
	 */
	public function process_fallback_upload( array $files_data, array $form_data ): array|WP_Error {
		StarmusLogger::setCorrelationId();

		try {
			if ( ! isset( $files_data['audio_file'] ) ) {
				StarmusLogger::warning( 'SubmissionHandler', 'No audio file in fallback' );
				return new WP_Error( 'missing_file', 'No audio file data in request.' );
			}

			$submitted_file = $files_data['audio_file'];

			// Clean browser-provided MIME type (strip codec suffixes)
			$original_mime_type     = $submitted_file['type'] ?? '';
			$clean_mime_type        = strtok( $original_mime_type, ';' );
			$submitted_file['type'] = $clean_mime_type;

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';

			$attachment_id = media_handle_sideload( $submitted_file, 0, null, array( 'test_form' => false ) );
			if ( is_wp_error( $attachment_id ) ) {
				StarmusLogger::error(
					'SubmissionHandler',
					'media_handle_sideload failed',
					array(
						'error' => $attachment_id->get_error_message(),
					)
				);
				return $attachment_id;
			}

			$attachment_path = get_attached_file( $attachment_id );
			if ( $attachment_path && 0 === strpos( get_post_mime_type( $attachment_id ), 'audio/' ) ) {
				$metadata = wp_read_audio_metadata( $attachment_path );
				wp_update_attachment_metadata( $attachment_id, $metadata );
			} elseif ( $attachment_path ) {
				wp_update_attachment_metadata(
					$attachment_id,
					wp_generate_attachment_metadata( $attachment_id, $attachment_path )
				);
			}

			$cpt_post_id = $this->create_recording_post( (int) $attachment_id, $form_data, $submitted_file['name'] );
			if ( is_wp_error( $cpt_post_id ) ) {
				StarmusLogger::error(
					'SubmissionHandler',
					'CPT creation failed (fallback); deleting attachment',
					array(
						'attachment_id' => $attachment_id,
						'error'         => $cpt_post_id->get_error_message(),
					)
				);
				wp_delete_attachment( $attachment_id, true );
				return $cpt_post_id;
			}

			update_post_meta( $cpt_post_id, '_audio_attachment_id', $attachment_id );
			wp_update_post(
				array(
					'ID'          => $attachment_id,
					'post_parent' => $cpt_post_id,
				)
			);

			$this->save_all_metadata( $cpt_post_id, $attachment_id, $form_data );

			return array(
				'success' => true,
				'data'    => array(
					'attachment_id' => $attachment_id,
					'post_id'       => $cpt_post_id,
					'url'           => wp_get_attachment_url( $attachment_id ),
					'redirect_url'  => esc_url( $this->get_redirect_url() ),
				),
			);
		} catch ( Throwable $e ) {
			StarmusLogger::error( 'SubmissionHandler', $e );
			return new WP_Error( 'server_error', 'Failed to process fallback.', array( 'status' => 500 ) );
		}
	}

	/**
	 * Persist the custom post type entry that pairs with the attachment.
	 *
	 * @param int    $attachment_id WordPress media attachment identifier.
	 * @param array  $form_data     Sanitized submission metadata.
	 * @param string $original_filename Original filename for title fallback.
	 * @return int|WP_Error Post ID on success or WP_Error on failure.
	 */
	private function create_recording_post( int $attachment_id, array $form_data, string $original_filename ): int|WP_Error {
		$post_id = wp_insert_post(
			array(
				'post_title'  => $form_data['starmus_title'] ?? pathinfo( $original_filename, PATHINFO_FILENAME ),
				'post_type'   => $this->get_cpt_slug(),
				'post_status' => 'publish',
				'post_author' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		StarmusLogger::info(
			'SubmissionHandler',
			'CPT created',
			array(
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
			)
		);

		return (int) $post_id;
	}

	/**
	 * Store all submitted metadata; then invoke post-processing (MP3/WAV/ID3/waveform).
	 *
	 * @param int   $audio_post_id  Identifier of the CPT post.
	 * @param int   $attachment_id  Identifier of the attachment.
	 * @param array $form_data      Raw submission data from the request.
	 * @return void
	 */
	public function save_all_metadata( int $audio_post_id, int $attachment_id, array $form_data ): void {
		StarmusLogger::debug(
			'SubmissionHandler',
			'Saving metadata',
			array(
				'post_id'       => $audio_post_id,
				'attachment_id' => $attachment_id,
			)
		);

		// --- STEP 1: SAVE THE RAW JSON ARCHIVES ---
		if ( isset( $form_data['first_pass_transcription'] ) ) {
			$this->update_acf_field( 'first_pass_transcription', wp_kses_post( wp_unslash( $form_data['first_pass_transcription'] ) ), $audio_post_id );
		}
		if ( isset( $form_data['recording_metadata'] ) ) {
			$this->update_acf_field( 'recording_metadata', wp_kses_post( wp_unslash( $form_data['recording_metadata'] ) ), $audio_post_id );
		}

		// --- STEP 2: PARSE METADATA AND MAP TO SPECIFIC ACF FIELDS ---
		$metadata = isset( $form_data['recording_metadata'] )
			? json_decode( wp_unslash( $form_data['recording_metadata'] ), true )
			: array();

		if ( is_array( $metadata ) && ! empty( $metadata ) ) {
			// Temporal
			if ( isset( $metadata['temporal']['recordedAt'] ) ) {
				try {
					$date = new \DateTime( $metadata['temporal']['recordedAt'] );
					$this->update_acf_field( 'session_date', $date->format( 'Ymd' ), $audio_post_id );
					$this->update_acf_field( 'session_start_time', $date->format( 'H:i:s' ), $audio_post_id );
				} catch ( \Exception $e ) {
					/* ignore */ }
			}
			if ( isset( $metadata['temporal']['submittedAt'] ) ) {
				try {
					$date = new \DateTime( $metadata['temporal']['submittedAt'] );
					$this->update_acf_field( 'session_end_time', $date->format( 'H:i:s' ), $audio_post_id );
				} catch ( \Exception $e ) {
					/* ignore */ }
			}

			// Technical & Device Notes
			$equipment_notes = array();
			if ( isset( $metadata['technical']['codec'] ) ) {
				$equipment_notes[] = 'Codec: ' . sanitize_text_field( $metadata['technical']['codec'] );
			}
			if ( ! empty( $equipment_notes ) ) {
				$this->update_acf_field( 'recording_equipment', implode( "\n", $equipment_notes ), $audio_post_id );
			}

			$condition_notes = array();
			if ( isset( $metadata['device']['platform'] ) ) {
				$condition_notes[] = 'Platform: ' . sanitize_text_field( $metadata['device']['platform'] );
			}
			if ( ! empty( $condition_notes ) ) {
				$this->update_acf_field( 'media_condition_notes', implode( "\n", $condition_notes ), $audio_post_id );
			}
		}

		// --- STEP 3: SAVE SERVER & DIRECT FORM DATA ---
		$this->update_acf_field( 'submission_ip', StarmusSanitizer::get_user_ip(), $audio_post_id );

		$direct_fields = array(
			'project_collection_id',
			'accession_number',
			'location',
			'usage_restrictions_rights',
			'access_level',
			'agreement_to_terms',
		);
		foreach ( $direct_fields as $field_name ) {
			if ( isset( $form_data[ $field_name ] ) ) {
				$this->update_acf_field( $field_name, sanitize_text_field( wp_unslash( $form_data[ $field_name ] ) ), $audio_post_id );
			}
		}

		// --- STEP 4: SAVE ATTACHMENT & TAXONOMIES ---
		if ( $attachment_id ) {
			$this->update_acf_field( 'audio_files_originals', $attachment_id, $audio_post_id );
		}
		if ( ! empty( $form_data['language'] ) ) {
			wp_set_post_terms( $audio_post_id, (int) $form_data['language'], 'language', false );
		}
		if ( ! empty( $form_data['recording_type'] ) ) {
			wp_set_post_terms( $audio_post_id, (int) $form_data['recording_type'], 'recording-type', false );
		}

		// --- STEP 5: FIRE THE EXTENSIBILITY HOOK ---
		do_action( 'starmus_after_save_submission_metadata', $audio_post_id, $form_data, $metadata, $attachment_id );

		// --- STEP 6: INVOKE POST-PROCESSING (MP3/WAV/ID3/Waveform) ---
		try {
			StarmusLogger::info(
				'SubmissionHandler',
				'Starting post-processing',
				array(
					'post_id'       => $audio_post_id,
					'attachment_id' => $attachment_id,
				)
			);

			$post_processing = new PostProcessingService();
			$ok              = $post_processing->process_and_archive_audio(
				$audio_post_id,
				$attachment_id,
				array(
					'preserve_silence' => true,   // keep timing aligned with early transcripts
					'bitrate'          => '192k', // sane default for distribution
					'samplerate'       => 44100,  // archival WAV target
				)
			);

			if ( ! $ok ) {
				StarmusLogger::warning(
					'SubmissionHandler',
					'Immediate post-processing failed; scheduling retry',
					array(
						'post_id'       => $audio_post_id,
						'attachment_id' => $attachment_id,
					)
				);

				// Defer a retry via cron to improve resilience in low-resource environments.
				if ( ! wp_next_scheduled( 'starmus_cron_process_pending_audio', array( $audio_post_id, $attachment_id ) ) ) {
					wp_schedule_single_event( time() + 300, 'starmus_cron_process_pending_audio', array( $audio_post_id, $attachment_id ) ); // 5 min
				}
			} else {
				StarmusLogger::info(
					'SubmissionHandler',
					'Post-processing complete',
					array(
						'post_id'       => $audio_post_id,
						'attachment_id' => $attachment_id,
					)
				);
			}
		} catch ( Throwable $e ) {
			StarmusLogger::error( 'SubmissionHandler', $e );

			// Always schedule a backup retry if immediate processing throws
			wp_schedule_single_event( time() + 600, 'starmus_cron_process_pending_audio', array( $audio_post_id, $attachment_id ) ); // 10 min
		}
	}

	/**
	 * Resolve the CPT slug even when configuration is unavailable.
	 *
	 * @return string Sanitized custom post type slug.
	 */
	public function get_cpt_slug(): string {
		if ( $this->settings instanceof StarmusSettings ) {
			$slug = $this->settings->get( 'cpt_slug', 'audio-recording' );
			if ( is_string( $slug ) && $slug !== '' ) {
				return sanitize_key( $slug );
			}
		}
		return 'audio-recording';
	}

	/**
	 * Update an ACF field when ACF is loaded, otherwise fall back to post meta.
	 *
	 * @param string $field_key Field identifier or meta key to persist.
	 * @param mixed  $value     Sanitized value to save.
	 * @param int    $post_id   Target post identifier.
	 * @return void
	 */
	private function update_acf_field( string $field_key, $value, int $post_id ): void {
		if ( function_exists( 'update_field' ) ) {
			update_field( $field_key, $value, $post_id );
			return;
		}
		update_post_meta( $post_id, $field_key, $value );
	}

	/**
	 * Proxy helper to sanitize request data using the shared sanitizer.
	 *
	 * @param array $data Raw request payload.
	 * @return array Sanitized data.
	 */
	public function sanitize_submission_data( array $data ): array {
		return StarmusSanitizer::sanitize_submission_data( $data );
	}

	/**
	 * Determine whether the current user has exceeded rate limits.
	 *
	 * @param int $user_id WordPress user identifier.
	 * @return bool True when throttled.
	 */
	private function is_rate_limited( int $user_id ): bool {
		$key   = 'starmus_rate_' . $user_id;
		$count = (int) get_transient( $key );
		if ( $count > 10 ) {
			return true;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * Compute the path used to store temporary upload chunks.
	 *
	 * @return string Absolute path inside the uploads directory.
	 */
	private function get_temp_dir(): string {
		return trailingslashit( wp_upload_dir()['basedir'] ) . 'starmus_tmp/';
	}

	/**
	 * Remove stale chunk files older than one day to reclaim disk space.
	 *
	 * @return void
	 */
	public function cleanup_stale_temp_files(): void {
		$dir = $this->get_temp_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( glob( $dir . '*.part' ) as $file ) {
			if ( filemtime( $file ) < time() - DAY_IN_SECONDS ) {
				if ( ! @unlink( $file ) ) {
					StarmusLogger::warning( 'SubmissionHandler', 'Failed to delete stale chunk', array( 'path' => $file ) );
				}
			}
		}
		StarmusLogger::info( 'SubmissionHandler', 'Stale temp cleanup complete', array( 'dir' => $dir ) );
	}

	/**
	 * Resolve redirect location after a successful submission.
	 *
	 * @return string Absolute URL pointing to the submissions list.
	 */
	private function get_redirect_url(): string {
		$redirect_page_id = $this->settings ? $this->settings->get( 'redirect_page_id', 0 ) : 0;
		return $redirect_page_id ? get_permalink( (int) $redirect_page_id ) : home_url( '/my-submissions' );
	}
}
