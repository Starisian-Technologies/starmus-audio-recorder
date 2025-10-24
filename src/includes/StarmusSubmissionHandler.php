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
use function uniqid;
use function glob;
use function filemtime;
use function unlink;
use const MINUTE_IN_SECONDS;
use const DAY_IN_SECONDS;

/**
 * Handles validation and persistence for audio submissions (DAL integrated).
 */
final class StarmusSubmissionHandler {

	public const STARMUS_REST_NAMESPACE = 'star-starmus-audio-recorder/v1';

	private ?StarmusSettings $settings;
	private StarmusAudioRecorderDAL $dal;

	public function __construct( StarmusAudioRecorderDAL $DAL, StarmusSettings $settings ) {
		$this->settings = $settings;
		$this->dal      = $DAL;
		add_action( 'starmus_cleanup_temp_files', array( $this, 'cleanup_stale_temp_files' ) );
	}

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
				return new WP_Error( 'rate_limited', 'You are uploading too frequently.', array( 'status' => 429 ) );
			}

			$params     = $this->sanitize_submission_data( $request->get_json_params() ?? array() );
			$validation = $this->validate_chunk_data( $params );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			$tmp_file = $this->write_chunk_streamed( $params );
			if ( is_wp_error( $tmp_file ) ) {
				return $tmp_file;
			}

			if ( ! empty( $params['is_last_chunk'] ) ) {
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

	public function handle_fallback_upload_rest( WP_REST_Request $request ): array|WP_Error {
		StarmusLogger::setCorrelationId();
		try {
			$form_data  = $this->sanitize_submission_data( $request->get_params() ?? array() );
			$files_data = $request->get_file_params();
			if ( empty( $files_data['audio_file'] ) ) {
				return new WP_Error( 'missing_file', 'No audio file provided.' );
			}
			return $this->process_fallback_upload( $files_data, $form_data );
		} catch ( Throwable $e ) {
			StarmusLogger::error( 'SubmissionHandler', $e );
			return new WP_Error( 'server_error', 'Failed to process fallback upload.', array( 'status' => 500 ) );
		}
	}

	private function validate_chunk_data( array $params ): true|WP_Error {
		if ( empty( $params['upload_id'] ) || empty( $params['chunk_index'] ) ) {
			return new WP_Error( 'invalid_params', 'Missing upload_id or chunk_index.' );
		}
		if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $params['upload_id'] ) ) {
			return new WP_Error( 'invalid_id', 'Invalid upload_id format.' );
		}
		return true;
	}

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
		return $file_path;
	}

	private function finalize_submission( string $file_path, array $form_data ): array|WP_Error {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_missing', 'No file to finalize.' );
		}

		$filename    = $form_data['filename'] ?? uniqid( 'starmus_', true ) . '.webm';
		$upload_dir  = wp_upload_dir();
		$destination = $upload_dir['path'] . '/' . $filename;

		if ( ! @rename( $file_path, $destination ) ) {
			@unlink( $file_path );
			return new WP_Error( 'move_failed', 'Failed to move upload.' );
		}

		$attachment_id = $this->dal->create_attachment_from_file( $destination, $filename );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$cpt_post_id = $this->dal->create_audio_post(
			$form_data['starmus_title'] ?? pathinfo( $filename, PATHINFO_FILENAME ),
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

	public function process_fallback_upload( array $files_data, array $form_data ): array|WP_Error {
		try {
			if ( empty( $files_data['audio_file'] ) ) {
				return new WP_Error( 'missing_file', 'No audio file provided.' );
			}

			$attachment_id = $this->dal->create_attachment_from_sideload( $files_data['audio_file'] );
			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			$cpt_post_id = $this->dal->create_audio_post(
				$form_data['starmus_title'] ?? pathinfo( $files_data['audio_file']['name'], PATHINFO_FILENAME ),
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
			return new WP_Error( 'server_error', 'Failed to process fallback upload.', array( 'status' => 500 ) );
		}
	}

	public function save_all_metadata( int $audio_post_id, int $attachment_id, array $form_data ): void {
		StarmusLogger::debug(
			'SubmissionHandler',
			'Saving metadata',
			array(
				'post_id'       => $audio_post_id,
				'attachment_id' => $attachment_id,
			)
		);

		if ( isset( $form_data['first_pass_transcription'] ) ) {
			$this->update_acf_field( 'first_pass_transcription', wp_kses_post( wp_unslash( $form_data['first_pass_transcription'] ) ), $audio_post_id );
		}
		if ( isset( $form_data['recording_metadata'] ) ) {
			$this->update_acf_field( 'recording_metadata', wp_kses_post( wp_unslash( $form_data['recording_metadata'] ) ), $audio_post_id );
		}

		$metadata = isset( $form_data['recording_metadata'] )
			? json_decode( wp_unslash( $form_data['recording_metadata'] ), true )
			: array();

		if ( is_array( $metadata ) && ! empty( $metadata ) ) {
			if ( isset( $metadata['temporal']['recordedAt'] ) ) {
				try {
					$date = new \DateTime( $metadata['temporal']['recordedAt'] );
					$this->update_acf_field( 'session_date', $date->format( 'Ymd' ), $audio_post_id );
					$this->update_acf_field( 'session_start_time', $date->format( 'H:i:s' ), $audio_post_id );
				} catch ( \Exception $e ) {
				}
			}
			if ( isset( $metadata['temporal']['submittedAt'] ) ) {
				try {
					$date = new \DateTime( $metadata['temporal']['submittedAt'] );
					$this->update_acf_field( 'session_end_time', $date->format( 'H:i:s' ), $audio_post_id );
				} catch ( \Exception $e ) {
				}
			}

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
			$ok              = $post_processing->process_and_archive_audio(
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
			}
		} catch ( Throwable $e ) {
			StarmusLogger::error( 'SubmissionHandler', $e );
			wp_schedule_single_event( time() + 600, 'starmus_cron_process_pending_audio', array( $audio_post_id, $attachment_id ) );
		}
	}

	public function get_cpt_slug(): string {
		if ( $this->settings instanceof StarmusSettings ) {
			$slug = $this->settings->get( 'cpt_slug', 'audio-recording' );
			if ( is_string( $slug ) && $slug !== '' ) {
				return sanitize_key( $slug );
			}
		}
		return 'audio-recording';
	}

	private function update_acf_field( string $field_key, $value, int $post_id ): void {
		$this->dal->save_post_meta( $post_id, $field_key, $value );
	}

	public function sanitize_submission_data( array $data ): array {
		return StarmusSanitizer::sanitize_submission_data( $data );
	}

	private function is_rate_limited( int $user_id ): bool {
		return $this->dal->is_rate_limited( $user_id );
	}

	private function get_temp_dir(): string {
		return trailingslashit( wp_upload_dir()['basedir'] ) . 'starmus_tmp/';
	}

	public function cleanup_stale_temp_files(): void {
		$dir = $this->get_temp_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( glob( $dir . '*.part' ) as $file ) {
			if ( filemtime( $file ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $file );
			}
		}
		StarmusLogger::info( 'SubmissionHandler', 'Stale temp cleanup complete', array( 'dir' => $dir ) );
	}

	private function get_redirect_url(): string {
		$redirect_page_id = $this->settings ? $this->settings->get( 'redirect_page_id', 0 ) : 0;
		return $redirect_page_id ? get_permalink( (int) $redirect_page_id ) : home_url( '/my-submissions' );
	}
}
