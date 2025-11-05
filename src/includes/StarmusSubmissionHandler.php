<?php
declare(strict_types=1);

/**
 * Core submission service responsible for processing uploads using the DAL.
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

use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;
use Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\helpers\StarmusSanitizer;
use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\services\PostProcessingService;

use function is_wp_error;
use function wp_upload_dir;
use function wp_mkdir_p;
use function trailingslashit;
use function pathinfo;
use function preg_match;
use function file_put_contents;
use function file_exists;
use function is_dir;
use function is_writable;
use function uniqid;
use function glob;
use function filemtime;
use function unlink;
use function sanitize_text_field;
use function wp_unslash;
use function get_permalink;
use function home_url;
use function get_current_user_id;
use function wp_get_attachment_url;
use function wp_kses_post;
use function wp_next_scheduled;
use function wp_schedule_single_event;

use const DAY_IN_SECONDS;

/**
 * Handles validation and persistence for audio submissions (DAL integrated).
 */
final class StarmusSubmissionHandler {

	public const STARMUS_REST_NAMESPACE = 'star-starmus-audio-recorder/v1';

	private ?StarmusSettings $settings;
	private StarmusAudioRecorderDAL $dal;

	/** Allow a small list of safe upload keys the client might send. */
	private array $fallback_file_keys = array( 'audio_file', 'file', 'upload' );

	/** Default allowed mime types if settings are empty. */
	private array $default_allowed_mimes = array(
		'audio/webm',
		'audio/webm;codecs=opus',
		'audio/ogg',
		'audio/ogg;codecs=opus',
		'audio/mpeg',
		'audio/wav',
	);

	public function __construct( StarmusAudioRecorderDALInterface $DAL, StarmusSettings $settings ) {
	    try {

			$this->dal      = $DAL;
			$this->settings = $settings;

			// Scheduled cleanup of temp chunk files.
			add_action( 'starmus_cleanup_temp_files', array( $this, 'cleanup_stale_temp_files' ) );

			StarmusLogger::info( 'SubmissionHandler', 'Constructed successfully' );
		} catch ( Throwable $e ) {
			StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => '__construct' ) );
			// Let constructor throw in truly fatal cases:
			throw $e;
		}
	}

	/*
	======================================================================
	 * CHUNKED UPLOAD HANDLER
	 * ==================================================================== */

	/**
	 * Processes a file that is already fully present on the local filesystem.
	 * This is the new entry point for post-upload processing (e.g., from tusd).
	 *
	 * @param string $file_path   Absolute path to the completed file.
	 * @param array  $form_data   Sanitized metadata from the client.
	 * @return array|WP_Error     Success array with IDs or WP_Error.
	 */
public function process_completed_file( string $file_path, array $form_data ): array|WP_Error {
	try {
		StarmusLogger::timeStart( 'process_completed_file' );

		// This logic is adapted directly from your finalize_submission() method.
		if ( ! file_exists( $file_path ) ) {
			return $this->err( 'file_missing', 'No file to process.', 400, array( 'path' => $file_path ) );
		}

		$filename    = $form_data['filename'] ?? pathinfo( $file_path, PATHINFO_BASENAME );
		$upload_dir  = wp_upload_dir();
		$destination = trailingslashit( $upload_dir['path'] ) . wp_unique_filename( $upload_dir['path'], $filename );

		// Validate the file before moving it
		$mime  = (string) ( $form_data['filetype'] ?? mime_content_type( $file_path ) );
		$size  = (int) @filesize( $file_path );
		$valid = $this->validate_file_against_settings( $mime, $size );
		if ( is_wp_error( $valid ) ) {
			@unlink( $file_path );
			return $valid;
		}

		if ( ! @rename( $file_path, $destination ) ) {
			@unlink( $file_path );
			return $this->err( 'move_failed', 'Failed to move upload into uploads path.', 500, array( 'dest' => $destination ) );
		}

		// Now, the rest is nearly identical to your existing logic...
		$attachment_id = $this->dal->create_attachment_from_file( $destination, $filename );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $destination );
			return $attachment_id;
		}

		// Extract user ID from form_data metadata, fallback to 0 (anonymous) if not present or invalid.
		$user_id = isset( $form_data['user_id'] ) ? absint( $form_data['user_id'] ) : 0;
		if ( $user_id && ! get_userdata( $user_id ) ) {
			$user_id = 0; // Invalid user ID, fallback to anonymous.
		}
		// For tusd uploads, user_id should be passed in metadata. Do not use get_current_user_id() as uploads may be processed asynchronously.
		$cpt_post_id = $this->dal->create_audio_post(
			$form_data['starmus_title'] ?? pathinfo( $filename, PATHINFO_FILENAME ),
			$this->get_cpt_slug(),
			$user_id
		);
		if ( is_wp_error( $cpt_post_id ) ) {
			$this->dal->delete_attachment( (int) $attachment_id );
			return $cpt_post_id;
		}

		$this->dal->save_post_meta( (int) $cpt_post_id, '_audio_attachment_id', (int) $attachment_id );
		$this->dal->set_attachment_parent( (int) $attachment_id, (int) $cpt_post_id );
		$this->save_all_metadata( (int) $cpt_post_id, (int) $attachment_id, $form_data );

		StarmusLogger::timeEnd( 'process_completed_file', 'SubmissionHandler' );

		return array(
			'success'       => true,
			'attachment_id' => (int) $attachment_id,
			'post_id'       => (int) $cpt_post_id,
			'url'           => wp_get_attachment_url( (int) $attachment_id ),
		);

	} catch ( Throwable $e ) {
		StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => 'process_completed_file' ) );
		return $this->err( 'server_error', 'Failed to process completed file.', 500 );
	}
}

public function handle_upload_chunk_rest( WP_REST_Request $request ): array|WP_Error {
	try {
		StarmusLogger::setCorrelationId();
		StarmusLogger::timeStart( 'chunk_upload' );
		StarmusLogger::info(
			'SubmissionHandler',
			'Chunk request received',
			array(
				'user_id' => get_current_user_id(),
				'ip'      => StarmusSanitizer::get_user_ip(),
			)
		);

		if ( $this->is_rate_limited( get_current_user_id() ) ) {
			return $this->err( 'rate_limited', 'You are uploading too frequently.', 429 );
		}

		$params_raw = $request->get_json_params() ?? array();
		$params     = $this->sanitize_submission_data( $params_raw );

		$valid = $this->validate_chunk_data( $params );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$writable_check = $this->ensure_uploads_writable();
		if ( is_wp_error( $writable_check ) ) {
			return $writable_check;
		}

		$tmp_file = $this->write_chunk_streamed( $params );
		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		if ( ! empty( $params['is_last_chunk'] ) ) {
			$final = $this->finalize_submission( $tmp_file, $params );
			StarmusLogger::timeEnd( 'chunk_upload', 'SubmissionHandler' );
			return $final;
		}

		StarmusLogger::info(
			'SubmissionHandler',
			'Chunk stored',
			array(
				'upload_id'   => $params['upload_id'] ?? null,
				'chunk_index' => $params['chunk_index'] ?? null,
				'bytes'       => isset( $params['data'] ) ? strlen( (string) $params['data'] ) : 0,
			)
		);

		StarmusLogger::timeEnd( 'chunk_upload', 'SubmissionHandler' );
		return array(
			'success' => true,
			'data'    => array(
				'status'      => 'chunk_received',
				'upload_id'   => $params['upload_id'] ?? null,
				'chunk_index' => $params['chunk_index'] ?? null,
			),
		);
	} catch ( Throwable $e ) {
		StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => 'chunk' ) );
		return $this->err( 'server_error', 'Failed to process chunk.', 500 );
	}
}

	/*
	======================================================================
	 * FALLBACK FORM UPLOAD HANDLER (multipart/form-data)
	 * ==================================================================== */

public function handle_fallback_upload_rest( WP_REST_Request $request ): array|WP_Error {
	try {
		StarmusLogger::setCorrelationId();
		StarmusLogger::timeStart( 'fallback_upload' );
		StarmusLogger::info(
			'SubmissionHandler',
			'Fallback upload request received',
			array(
				'user_id' => get_current_user_id(),
				'ip'      => StarmusSanitizer::get_user_ip(),
			)
		);

		if ( $this->is_rate_limited( get_current_user_id() ) ) {
			return $this->err( 'rate_limited', 'You are uploading too frequently.', 429 );
		}

		$form_data  = $this->sanitize_submission_data( $request->get_params() ?? array() );
		$files_data = $request->get_file_params() ?? array();

		$file_key = $this->detect_file_key( $files_data );
		if ( ! $file_key ) {
			StarmusLogger::warning( 'SubmissionHandler', 'No file found in request', array( 'keys_present' => array_keys( $files_data ) ) );
			return $this->err( 'missing_file', 'No audio file provided.', 400 );
		}

		$file = $files_data[ $file_key ];

		if ( ! isset( $file['error'] ) || (int) $file['error'] !== 0 || empty( $file['tmp_name'] ) ) {
			return $this->err( 'upload_error', 'Upload failed or missing tmp file.', 400, array( 'file_meta' => $file ) );
		}

		$mime       = (string) ( $file['type'] ?? '' );
		$size_bytes = (int) ( $file['size'] ?? 0 );
		$validation = $this->validate_file_against_settings( $mime, $size_bytes );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		StarmusLogger::debug(
			'SubmissionHandler',
			'Fallback upload file accepted',
			array(
				'file_key' => $file_key,
				'name'     => $file['name'] ?? '',
				'mime'     => $mime,
				'size'     => $size_bytes,
			)
		);

		$result = $this->process_fallback_upload( $files_data, $form_data, $file_key );

		StarmusLogger::timeEnd( 'fallback_upload', 'SubmissionHandler' );
		return $result;
	} catch ( Throwable $e ) {
		StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => 'fallback' ) );
		return $this->err( 'server_error', 'Failed to process fallback upload.', 500 );
	}
}

	/*
	======================================================================
	 * VALIDATION / IO HELPERS
	 * ==================================================================== */

private function validate_chunk_data( array $params ): true|WP_Error {
	try {
		if ( empty( $params['upload_id'] ) || ! isset( $params['chunk_index'] ) ) {
			return $this->err( 'invalid_params', 'Missing upload_id or chunk_index.', 400 );
		}
		if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', (string) $params['upload_id'] ) ) {
			return $this->err( 'invalid_id', 'Invalid upload_id format.', 400 );
		}
		if ( ! isset( $params['data'] ) || $params['data'] === '' ) {
			return $this->err( 'invalid_chunk', 'Missing chunk data.', 400 );
		}
		return true;
	} catch ( Throwable $e ) {
		StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => 'validate_chunk_data' ) );
		return $this->err( 'server_error', 'Validation failed.', 500 );
	}
}

private function write_chunk_streamed( array $params ): string|WP_Error {
	try {
		$temp_dir = $this->get_temp_dir();
		if ( ! is_dir( $temp_dir ) && ! @wp_mkdir_p( $temp_dir ) ) {
			return $this->err( 'temp_dir_unwritable', 'Temp directory is not writable.', 500, array( 'dir' => $temp_dir ) );
		}

		$file_path = $temp_dir . $params['upload_id'] . '.part';
		$chunk     = base64_decode( (string) $params['data'], true );

		if ( $chunk === false ) {
			return $this->err( 'invalid_chunk', 'Chunk data not valid base64.', 400 );
		}

		$written = @file_put_contents( $file_path, $chunk, FILE_APPEND );
		if ( $written === false ) {
			return $this->err( 'write_failed', 'Failed to write chunk to disk.', 500, array( 'path' => $file_path ) );
		}

		return $file_path;
	} catch ( Throwable $e ) {
		StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => 'write_chunk_streamed' ) );
		return $this->err( 'server_error', 'Could not persist chunk.', 500 );
	}
}

private function finalize_submission( string $file_path, array $form_data ): array|WP_Error {
	try {
		StarmusLogger::timeStart( 'finalize_submission' );

		if ( ! file_exists( $file_path ) ) {
			return $this->err( 'file_missing', 'No file to finalize.', 400, array( 'path' => $file_path ) );
		}

		$filename   = $form_data['filename'] ?? uniqid( 'starmus_', true ) . '.webm';
		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['path'] ) || ! is_dir( $upload_dir['path'] ) ) {
			return $this->err( 'uploads_unavailable', 'Uploads directory not available.', 500, $upload_dir );
		}
		$destination = trailingslashit( $upload_dir['path'] ) . $filename;

		$mime  = (string) ( $form_data['mime'] ?? 'audio/webm' );
		$size  = (int) @filesize( $file_path );
		$valid = $this->validate_file_against_settings( $mime, $size );
		if ( is_wp_error( $valid ) ) {
			@unlink( $file_path );
			return $valid;
		}

		if ( ! @rename( $file_path, $destination ) ) {
			@unlink( $file_path );
			return $this->err( 'move_failed', 'Failed to move upload into uploads path.', 500, array( 'dest' => $destination ) );
		}

		try {
			$attachment_id = $this->dal->create_attachment_from_file( $destination, $filename );
		} catch ( Throwable $e ) {
			StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => 'create_attachment' ) );
			@unlink( $destination );
			return $this->err( 'attachment_create_failed', 'Could not create attachment.', 500 );
		}
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $destination );
			return $attachment_id;
		}

		try {
			$cpt_post_id = $this->dal->create_audio_post(
				$form_data['starmus_title'] ?? pathinfo( $filename, PATHINFO_FILENAME ),
				$this->get_cpt_slug(),
				get_current_user_id()
			);
		} catch ( Throwable $e ) {
			StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => 'create_post' ) );
			$this->dal->delete_attachment( (int) $attachment_id );
			return $this->err( 'post_create_failed', 'Could not create audio post.', 500 );
		}
		if ( is_wp_error( $cpt_post_id ) ) {
			$this->dal->delete_attachment( (int) $attachment_id );
			return $cpt_post_id;
		}

		try {
			$this->dal->save_post_meta( (int) $cpt_post_id, '_audio_attachment_id', (int) $attachment_id );
			$this->dal->set_attachment_parent( (int) $attachment_id, (int) $cpt_post_id );
			$this->save_all_metadata( (int) $cpt_post_id, (int) $attachment_id, $form_data );
		} catch ( Throwable $e ) {
			StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => 'save_meta' ) );
			// Intentionally do not delete post or attachment; admins can reconcile if needed.
		}

		StarmusLogger::timeEnd( 'finalize_submission', 'SubmissionHandler' );

		return array(
			'success' => true,
			'data'    => array(
				'attachment_id' => (int) $attachment_id,
				'post_id'       => (int) $cpt_post_id,
				'url'           => wp_get_attachment_url( (int) $attachment_id ),
				'redirect_url'  => esc_url( $this->get_redirect_url() ),
			),
		);
	} catch ( Throwable $e ) {
		StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => 'finalize_submission' ) );
		return $this->err( 'server_error', 'Finalize failed.', 500 );
	}
}

	/**
	 * Process standard multipart upload via media sideload.
	 *
	 * @param array  $files_data Full $_FILES-like array from request.
	 * @param array  $form_data  Sanitized form params.
	 * @param string $file_key   Which key contained the uploaded file.
	 */
public function process_fallback_upload( array $files_data, array $form_data, string $file_key = 'audio_file' ): array|WP_Error {
	try {
		StarmusLogger::timeStart( 'fallback_pipeline' );

		if ( empty( $files_data[ $file_key ] ) ) {
			return $this->err( 'missing_file', 'No audio file provided.', 400 );
		}

		$attachment_id = $this->dal->create_attachment_from_sideload( $files_data[ $file_key ] );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$title       = $form_data['starmus_title'] ?? pathinfo( $files_data[ $file_key ]['name'], PATHINFO_FILENAME );
		$cpt_post_id = $this->dal->create_audio_post(
			$title,
			$this->get_cpt_slug(),
			get_current_user_id()
		);

		if ( is_wp_error( $cpt_post_id ) ) {
			$this->dal->delete_attachment( (int) $attachment_id );
			return $cpt_post_id;
		}

		$this->dal->save_post_meta( (int) $cpt_post_id, '_audio_attachment_id', (int) $attachment_id );
		$this->dal->set_attachment_parent( (int) $attachment_id, (int) $cpt_post_id );

		$this->save_all_metadata( (int) $cpt_post_id, (int) $attachment_id, $form_data );

		StarmusLogger::timeEnd( 'fallback_pipeline', 'SubmissionHandler' );

		return array(
			'success' => true,
			'data'    => array(
				'attachment_id' => (int) $attachment_id,
				'post_id'       => (int) $cpt_post_id,
				'url'           => wp_get_attachment_url( (int) $attachment_id ),
				'redirect_url'  => esc_url( $this->get_redirect_url() ),
			),
		);
	} catch ( Throwable $e ) {
		StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => 'fallback_pipeline' ) );
		return $this->err( 'server_error', 'Failed to process fallback upload.', 500 );
	}
}

	/*
	======================================================================
	 * METADATA + POST-PROCESSING
	 * ==================================================================== */

public function save_all_metadata( int $audio_post_id, int $attachment_id, array $form_data ): void {
	try {
		StarmusLogger::debug(
			'SubmissionHandler',
			'Saving metadata',
			array(
				'post_id'       => $audio_post_id,
				'attachment_id' => $attachment_id,
			)
		);

		if ( isset( $form_data['first_pass_transcription'] ) ) {
			$this->update_acf_field( 'first_pass_transcription', wp_kses_post( (string) wp_unslash( $form_data['first_pass_transcription'] ) ), $audio_post_id );
		}
		if ( isset( $form_data['recording_metadata'] ) ) {
			$this->update_acf_field( 'recording_metadata', wp_kses_post( (string) wp_unslash( $form_data['recording_metadata'] ) ), $audio_post_id );
		}

		$metadata = array();
		if ( isset( $form_data['recording_metadata'] ) ) {
			$decoded = json_decode( (string) wp_unslash( $form_data['recording_metadata'] ), true );
			if ( is_array( $decoded ) ) {
				$metadata = $decoded;
			}
		}

		if ( ! empty( $metadata['temporal']['recordedAt'] ) ) {
			try {
				$date = new \DateTime( (string) $metadata['temporal']['recordedAt'] );
				$this->update_acf_field( 'session_date', $date->format( 'Ymd' ), $audio_post_id );
				$this->update_acf_field( 'session_start_time', $date->format( 'H:i:s' ), $audio_post_id );
			} catch ( \Exception $e ) {
				StarmusLogger::warning( 'SubmissionHandler', 'Invalid recordedAt format' );
			}
		}
		if ( ! empty( $metadata['temporal']['submittedAt'] ) ) {
			try {
				$date = new \DateTime( (string) $metadata['temporal']['submittedAt'] );
				$this->update_acf_field( 'session_end_time', $date->format( 'H:i:s' ), $audio_post_id );
			} catch ( \Exception $e ) {
				StarmusLogger::warning( 'SubmissionHandler', 'Invalid submittedAt format' );
			}
		}

		$equipment_notes = array();
		if ( ! empty( $metadata['technical']['codec'] ) ) {
			$equipment_notes[] = 'Codec: ' . sanitize_text_field( (string) $metadata['technical']['codec'] );
		}
		if ( $equipment_notes ) {
			$this->update_acf_field( 'recording_equipment', implode( "\n", $equipment_notes ), $audio_post_id );
		}

		$condition_notes = array();
		if ( ! empty( $metadata['device']['platform'] ) ) {
			$condition_notes[] = 'Platform: ' . sanitize_text_field( (string) $metadata['device']['platform'] );
		}
		if ( $condition_notes ) {
			$this->update_acf_field( 'media_condition_notes', implode( "\n", $condition_notes ), $audio_post_id );
		}

		$this->update_acf_field( 'submission_ip', StarmusSanitizer::get_user_ip(), $audio_post_id );

		foreach ( array( 'project_collection_id', 'accession_number', 'location', 'usage_restrictions_rights', 'access_level', 'agreement_to_terms' ) as $field ) {
			if ( isset( $form_data[ $field ] ) ) {
				$this->update_acf_field( $field, sanitize_text_field( (string) wp_unslash( $form_data[ $field ] ) ), $audio_post_id );
			}
		}

		if ( $attachment_id ) {
			$this->update_acf_field( 'audio_files_originals', $attachment_id, $audio_post_id );
		}

		if ( ! empty( $form_data['language'] ) ) {
			$this->dal->set_post_term( $audio_post_id, (int) $form_data['language'], 'language' );
		}
		if ( ! empty( $form_data['recording_type'] ) ) {
			$this->dal->set_post_term( $audio_post_id, (int) $form_data['recording_type'], 'recording-type' );
		}

		do_action( 'starmus_after_save_submission_metadata', $audio_post_id, $form_data, $metadata, $attachment_id );

		try {
			$post_processing = new PostProcessingService();
			$ok              = $post_processing->process(
				$audio_post_id,
				$attachment_id,
				array(
					'preserve_silence' => true,
					'bitrate'          => '192k',
					'samplerate'       => 44100,
				)
			);
			if ( ! $ok ) {
				if ( ! wp_next_scheduled( 'starmus_cron_process_pending_audio', array( $audio_post_id, $attachment_id ) ) ) {
					wp_schedule_single_event( time() + 300, 'starmus_cron_process_pending_audio', array( $audio_post_id, $attachment_id ) );
				}
				StarmusLogger::notice(
					'SubmissionHandler',
					'Post-processing deferred to cron',
					array(
						'post_id'       => $audio_post_id,
						'attachment_id' => $attachment_id,
					)
				);
			}
		} catch ( Throwable $e ) {
			StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => 'post_processing' ) );
			wp_schedule_single_event( time() + 600, 'starmus_cron_process_pending_audio', array( $audio_post_id, $attachment_id ) );
		}
	} catch ( Throwable $e ) {
		StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => 'save_all_metadata' ) );
		// void method; swallow after logging
	}
}

	/*
	======================================================================
	 * SETTINGS / STATE HELPERS
	 * ==================================================================== */

public function get_cpt_slug(): string {
	try {
		if ( $this->settings instanceof StarmusSettings ) {
			$slug = $this->settings->get( 'cpt_slug', 'audio-recording' );
			if ( is_string( $slug ) && $slug !== '' ) {
				return sanitize_key( $slug );
			}
		}
		return 'audio-recording';
	} catch ( Throwable $e ) {
		StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => 'get_cpt_slug' ) );
		return 'audio-recording';
	}
}

private function update_acf_field( string $field_key, $value, int $post_id ): void {
	try {
		$this->dal->save_post_meta( $post_id, $field_key, $value );
	} catch ( Throwable $e ) {
		StarmusLogger::error(
			'SubmissionHandler',
			$e,
			array(
				'phase'   => 'update_acf_field',
				'field'   => $field_key,
				'post_id' => $post_id,
			)
		);
	}
}

public function sanitize_submission_data( array $data ): array {
	try {
		return StarmusSanitizer::sanitize_submission_data( $data );
	} catch ( Throwable $e ) {
		StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => 'sanitize_submission_data' ) );
		// Fall back to shallow sanitization
		foreach ( $data as $k => $v ) {
			if ( is_string( $v ) ) {
				$data[ $k ] = sanitize_text_field( $v );
			}
		}
		return $data;
	}
}

private function is_rate_limited( int $user_id ): bool {
	try {
		return $this->dal->is_rate_limited( $user_id );
	} catch ( Throwable $e ) {
		StarmusLogger::error(
			'SubmissionHandler',
			$e,
			array(
				'phase'   => 'is_rate_limited',
				'user_id' => $user_id,
			)
		);
		return false; // Fail-open to avoid blocking users due to a DAL fault.
	}
}

private function get_temp_dir(): string {
	try {
		$base = (string) ( wp_upload_dir()['basedir'] ?? '' );
		if ( $base === '' ) {
			return trailingslashit( sys_get_temp_dir() ) . 'starmus_tmp/';
		}
		return trailingslashit( $base ) . 'starmus_tmp/';
	} catch ( Throwable $e ) {
		StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => 'get_temp_dir' ) );
		return trailingslashit( sys_get_temp_dir() ) . 'starmus_tmp/';
	}
}

public function cleanup_stale_temp_files(): void {
	try {
		$dir = $this->get_temp_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( glob( $dir . '*.part' ) as $file ) {
			if ( @filemtime( $file ) < time() - DAY_IN_SECONDS ) {
				@unlink( $file );
			}
		}
		StarmusLogger::info( 'SubmissionHandler', 'Stale temp cleanup complete', array( 'dir' => $dir ) );
	} catch ( Throwable $e ) {
		StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => 'cleanup_stale_temp_files' ) );
	}
}

private function get_redirect_url(): string {
	try {
		$redirect_page_id = $this->settings ? (int) $this->settings->get( 'redirect_page_id', 0 ) : 0;
		return $redirect_page_id ? (string) get_permalink( $redirect_page_id ) : (string) home_url( '/my-submissions' );
	} catch ( Throwable $e ) {
		StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => 'get_redirect_url' ) );
		return (string) home_url( '/my-submissions' );
	}
}

	/*
	======================================================================
	 * INTERNAL UTILITIES
	 * ==================================================================== */

	/**
	 * Consistent logger + WP_Error creator with correlation id in data.
	 */
private function err( string $code, string $message, int $status = 400, array $context = array() ): WP_Error {
	try {
		$cid = StarmusLogger::getCorrelationId();
		StarmusLogger::warning(
			'SubmissionHandler',
			"{$code}: {$message}",
			array_merge(
				$context,
				array(
					'status'         => $status,
					'correlation_id' => $cid,
				)
			)
		);
		return new WP_Error(
			$code,
			$message,
			array(
				'status'         => $status,
				'correlation_id' => $cid,
			)
		);
	} catch ( Throwable $e ) {
		// As a last resort, ensure a WP_Error still returns even if logging fails.
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}

	/**
	 * Ensure uploads base dir is writable; returns WP_Error on failure.
	 */
private function ensure_uploads_writable(): true|WP_Error {
	try {
		$uploads = wp_upload_dir();
		$base    = (string) ( $uploads['basedir'] ?? '' );
		if ( $base === '' ) {
			return $this->err( 'uploads_unavailable', 'Uploads directory not available.', 500, $uploads );
		}
		if ( ! is_dir( $base ) && ! @wp_mkdir_p( $base ) ) {
			return $this->err( 'uploads_unwritable', 'Failed to create uploads directory.', 500, array( 'basedir' => $base ) );
		}
		if ( ! is_writable( $base ) ) {
			return $this->err( 'uploads_unwritable', 'Uploads directory not writable.', 500, array( 'basedir' => $base ) );
		}
		return true;
	} catch ( Throwable $e ) {
		StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => 'ensure_uploads_writable' ) );
		return $this->err( 'server_error', 'Uploads not writable (internal error).', 500 );
	}
}

	/**
	 * Detect the uploaded file key from a set of common alternatives.
	 */
private function detect_file_key( array $files ): ?string {
	try {
		foreach ( $this->fallback_file_keys as $key ) {
			if ( ! empty( $files[ $key ] ) && is_array( $files[ $key ] ) ) {
				return $key;
			}
		}
		foreach ( $files as $key => $val ) {
			if ( is_array( $val ) && ! empty( $val['tmp_name'] ) ) {
				return (string) $key;
			}
		}
		return null;
	} catch ( Throwable $e ) {
		StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => 'detect_file_key' ) );
		return null;
	}
}

	/**
	 * Validates file against size/mime settings.
	 */
private function validate_file_against_settings( string $mime, int $size_bytes ): true|WP_Error {
	try {
		$max_mb = (int) ( $this->settings ? ( $this->settings->get( 'file_size_limit', 10 ) ) : 10 );
		if ( $max_mb > 0 && $size_bytes > ( $max_mb * 1024 * 1024 ) ) {
			return $this->err( 'file_too_large', "File exceeds maximum size of {$max_mb}MB.", 413, array( 'size_bytes' => $size_bytes ) );
		}

		$allowed = $this->settings ? $this->settings->get( 'allowed_file_types', array() ) : array();
		if ( is_string( $allowed ) && $allowed !== '' ) {
			$allowed = array_map( 'trim', explode( ',', $allowed ) );
		}
		if ( ! is_array( $allowed ) || count( $allowed ) === 0 ) {
			$allowed = $this->default_allowed_mimes;
		}

		$mime_lc = strtolower( $mime );
		$ok      = false;
		foreach ( $allowed as $allowed_type ) {
			$allowed_type = strtolower( (string) $allowed_type );
			if ( $allowed_type === $mime_lc ) {
				$ok = true;
				break;
			}
			if ( str_starts_with( $mime_lc, $allowed_type ) ) {
				$ok = true;
				break;
			}
		}
		if ( ! $ok ) {
			return $this->err(
				'mime_not_allowed',
				'This file type is not allowed.',
				415,
				array(
					'mime'    => $mime_lc,
					'allowed' => $allowed,
				)
			);
		}

		return true;
	} catch ( Throwable $e ) {
		StarmusLogger::error( 'SubmissionHandler', $e, array( 'phase' => 'validate_file_against_settings' ) );
		return $this->err( 'server_error', 'File validation failed.', 500 );
	}
}
}
