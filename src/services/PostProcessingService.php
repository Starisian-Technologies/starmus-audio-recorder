<?php
/**
 * Service class for post-save audio transcoding, mastering, and archival using ffmpeg.
 * Produces both MP3 (distribution) and WAV (archival) and triggers metadata writing.
 *
 * @package Starmus\services
 * @version 0.7.5
 * @since  0.7.2
 */

namespace Starmus\services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PostProcessingService {

	private $audio_processing_service;

	public function __construct() {

		// This service now depends on the metadata service to write tags.
		$this->audio_processing_service = new AudioProcessingService();
	}

	public function is_tool_available(): bool {
		$path = shell_exec( 'command -v ffmpeg' );
		return ! empty( trim( $path ) );
	}

	/**
	 * Main entry point to transcode, master, and archive an audio attachment.
	 *
	 * @param int   $attachment_id The WordPress attachment ID.
	 * @param array $options Configuration options.
	 * @return bool True on success, false on failure.
	 */
	public function process_and_archive_audio( int $attachment_id, array $options = array() ): bool {
		if ( ! $this->is_tool_available() ) {

			return false;
		}

		$original_path = get_attached_file( $attachment_id );
		if ( ! $original_path || ! file_exists( $original_path ) ) {

			return false;
		}
		$options['']   = $original_path;
		$upload_dir    = wp_get_upload_dir();
		$base_filename = pathinfo( $original_path, PATHINFO_FILENAME );

		$backup_path = $original_path . '.bak';
		if ( ! rename( $original_path, $backup_path ) ) {
			/* ... error handling ... */ return false; }
		// --- 1. Backup the original file before any changes ---
		$backup_path = $original_path . '.bak';
		if ( ! rename( $original_path, $backup_path ) ) {

			return false;
		}

		// --- 2. Generate Lossless Archival WAV Copy ---
		$archival_path = $upload_dir['path'] . '/' . $base_filename . '-archive.wav';
		$cmd_wav       = sprintf(
			'ffmpeg -i %s -c:a pcm_s16le -ar 44100 -y %s',
			escapeshellarg( $backup_path ),
			escapeshellarg( $archival_path )
		);
		exec( $cmd_wav . ' 2>&1', $out_wav, $ret_wav );

		if ( $ret_wav !== 0 || ! file_exists( $archival_path ) ) {
			rename( $backup_path, $original_path ); // Restore backup on failure
			return false;
		}

		// --- 3. Build and Generate Mastered Distribution MP3 ---
		$filters      = array( 'loudnorm=I=-16:TP=-1.5:LRA=11', 'silenceremove=start_periods=1:start_threshold=-50dB' );
		$filters      = apply_filters( 'starmus_ffmpeg_filters', $filters, $attachment_id );
		$filter_chain = implode( ',', array_filter( $filters ) );

		$mp3_path = $upload_dir['path'] . '/' . $base_filename . '.mp3';
		$cmd_mp3  = sprintf(
			'ffmpeg -i %s -af "%s" -c:a libmp3lame -b:a 192k -y %s',
			escapeshellarg( $backup_path ),
			$filter_chain,
			escapeshellarg( $mp3_path )
		);
		exec( $cmd_mp3 . ' 2>&1', $out_mp3, $ret_mp3 );

		if ( $ret_mp3 !== 0 || ! file_exists( $mp3_path ) ) {
			rename( $backup_path, $original_path ); // Restore backup
			if ( file_exists( $archival_path ) ) {
				wp_delete_file( $archival_path ); // Clean up partial archive
			}
			return false;
		}

		// --- 4. Update WordPress to use the new MP3 as the main attachment ---
		update_attached_file( $attachment_id, $mp3_path );
		wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => 'audio/mpeg',
			)
		);
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $mp3_pah ) );

		// --- 5. Call the Metadata Service to write ID3 tags to the new MP3 ---
		$this->audio_processing_service->process_attachment( $attachment_id );

		// --- 6. Store archival path and clean up ---
		update_post_meta( $attachment_id, '_starmus_archival_path', $archival_path );
		wp_delete_file( $backup_path ); // Success, so remove the backup file.

			/**
			* Fires after an audio file has been successfully transcoded and archived.
			*
			* @hook starmus_audio_postprocessed
			* @param int   $attachment_id The ID of the master MP3 attachment.
			* @param array $processed_files An array of paths to the generated files.
			*/
			do_action(
				'starmus_audio_postprocessed',
				$attachment_id,
				array(
					'mp3' => $mp3_path,
					'wav' => $archival_path,
				)
			);

		return true;
	}
}
