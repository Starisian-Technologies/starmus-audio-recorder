<?php
/**
 * Full post-save transcoding, mastering, archival, ID3, waveform generation.
 *
 * @package Starisian\Sparxstar\Starmus\services
 * @version 1.3.0-dal
 */

namespace Starisian\Sparxstar\Starmus\services;

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StarmusAudioProcessingHandler {

	private AudioProcessingService $audio_processing_service;
	private FileService $file_service;
	private WaveformService $waveform_service;
	private StarmusAudioRecorderDAL $dal;

	public function __construct() {
		$this->dal                      = new StarmusAudioRecorderDAL();
		$this->audio_processing_service = new AudioProcessingService( $this->dal );
		$this->file_service             = new FileService( $this->dal );
		$this->waveform_service         = new WaveformService();
	}

	public function star_is_tool_available(): bool {
		$path = shell_exec( 'command -v ffmpeg' );
		return ! empty( trim( (string) $path ) );
	}

	public function process_and_archive_audio( int $audio_post_id, int $attachment_id, array $options = array() ): bool {
		return $this->star_process_and_archive_audio( $audio_post_id, $attachment_id, $options );
	}

	/**
	 * Transcode to WAV (archival) + MP3 (distribution), ID3, waveform, and metadata persistence.
	 */
	public function star_process_and_archive_audio( int $audio_post_id, int $attachment_id, array $options = array() ): bool {
		StarmusLogger::setCorrelationId();
		StarmusLogger::timeStart( 'audio_postprocess' );

		if ( ! $this->star_is_tool_available() ) {
			StarmusLogger::error( 'PostProcessingService', 'FFmpeg not available' );
			return false;
		}

		$defaults = array(
			'preserve_silence' => true,
			'bitrate'          => '192k',
			'samplerate'       => 44100,
		);
		$options  = array_replace( $defaults, $options );

		$original_path = $this->file_service->get_local_copy( $attachment_id );
		if ( ! $original_path || ! file_exists( $original_path ) ) {
			StarmusLogger::error( 'PostProcessingService', 'Missing local source', array( 'attachment_id' => $attachment_id ) );
			return false;
		}

		$is_temp_source = ( strpos( $original_path, sys_get_temp_dir() ) === 0 );
		$upload_dir     = wp_get_upload_dir();
		$base_filename  = pathinfo( $original_path, PATHINFO_FILENAME );
		$backup_path    = $original_path . '.bak';

		if ( ! @rename( $original_path, $backup_path ) ) {
			StarmusLogger::error( 'PostProcessingService', 'Backup creation failed' );
			return false;
		}

		do_action(
			'starmus_audio_preprocess',
			array(
				'post_id'       => $audio_post_id,
				'attachment_id' => $attachment_id,
				'backup_path'   => $backup_path,
				'options'       => $options,
			)
		);

		// --- 1) WAV archival ---
		$archival_path = trailingslashit( $upload_dir['path'] ) . "{$base_filename}-archive.wav";
		$cmd_wav       = sprintf(
			'ffmpeg -y -i %s -c:a pcm_s16le -ar %d %s',
			escapeshellarg( $backup_path ),
			(int) $options['samplerate'],
			escapeshellarg( $archival_path )
		);
		exec( $cmd_wav . ' 2>&1', $out_wav, $ret_wav );
		if ( $ret_wav !== 0 || ! file_exists( $archival_path ) ) {
			@rename( $backup_path, $original_path );
			StarmusLogger::error( 'PostProcessingService', 'WAV generation failed', array( 'cmd' => $cmd_wav ) );
			return false;
		}

		// --- 2) MP3 distribution ---
		$filters = array( 'loudnorm=I=-16:TP=-1.5:LRA=11' );
		if ( ! $options['preserve_silence'] ) {
			$filters[] = 'silenceremove=start_periods=1:start_threshold=-50dB';
		}
		$filter_chain = implode( ',', array_filter( $filters ) );

		$mp3_path = trailingslashit( $upload_dir['path'] ) . "{$base_filename}.mp3";
		$cmd_mp3  = sprintf(
			'ffmpeg -y -i %s -af %s -c:a libmp3lame -b:a %s %s',
			escapeshellarg( $backup_path ),
			escapeshellarg( $filter_chain ),
			escapeshellarg( (string) $options['bitrate'] ),
			escapeshellarg( $mp3_path )
		);

		exec( $cmd_mp3 . ' 2>&1', $out_mp3, $ret_mp3 );
		if ( $ret_mp3 !== 0 || ! file_exists( $mp3_path ) ) {
			@rename( $backup_path, $original_path );
			if ( file_exists( $archival_path ) ) {
				wp_delete_file( $archival_path );
			}
			StarmusLogger::error( 'PostProcessingService', 'MP3 generation failed', array( 'cmd' => $cmd_mp3 ) );
			return false;
		}

		// --- 3) DAL-managed attachment + recording post update ---
		try {
			// Update attachment stored file path and metadata
			$this->dal->update_attachment_file_path( $attachment_id, $mp3_path );
			$this->dal->update_attachment_metadata( $attachment_id, $mp3_path );

			// Persist attachment-level output paths (attachment meta)
			if ( method_exists( $this->dal, 'persist_audio_outputs' ) ) {
				$this->dal->persist_audio_outputs( $attachment_id, $mp3_path, $archival_path );
			} else {
				// Fallback to direct meta updates
				$this->dal->update_audio_post_meta( $attachment_id, '_audio_mp3_path', (string) $mp3_path );
				$this->dal->update_audio_post_meta( $attachment_id, '_audio_wav_path', (string) $archival_path );
				$this->dal->update_audio_post_meta( $attachment_id, '_starmus_archival_path', (string) $archival_path );
			}

			// Persist recording-level (parent post) fields using DAL helper
			if ( method_exists( $this->dal, 'save_audio_outputs' ) ) {
				// save_audio_outputs(post_id, waveform_json|null, mp3_url|null, wav_url|null)
				$this->dal->save_audio_outputs( $audio_post_id, null, $mp3_path, $archival_path );
			} else {
				// Fallback: preserve previous behaviour using update_audio_post_fields
				$this->dal->update_audio_post_fields(
					$audio_post_id,
					array(
						'archival_wav' => $archival_path,
						'mastered_mp3' => $mp3_path,
					)
				);
			}
		} catch ( \Throwable $e ) {
			StarmusLogger::error( 'PostProcessingService', $e, array( 'phase' => 'persist_outputs', 'attachment_id' => $attachment_id, 'post_id' => $audio_post_id ) );
		}

		// --- 4) ID3 tagging ---
		$this->audio_processing_service->process_attachment( $attachment_id );
		do_action( 'starmus_audio_id3_written', $attachment_id, $mp3_path, $audio_post_id );

		// --- 5) Waveform generation ---
		$this->waveform_service->generate_waveform_data( $attachment_id );

		// --- 6) Optional cloud offload ---
		$this->file_service->upload_and_replace_attachment( $attachment_id, $mp3_path );

		// --- 7) Cleanup ---
		wp_delete_file( $backup_path );
		if ( $is_temp_source && file_exists( $original_path ) ) {
			@unlink( $original_path );
		}

		do_action(
			'starmus_audio_postprocessed',
			$attachment_id,
			array(
				'post_id' => $audio_post_id,
				'mp3'     => $mp3_path,
				'wav'     => $archival_path,
				'ffmpeg'  => array(
					'wav' => $out_wav ?? array(),
					'mp3' => $out_mp3 ?? array(),
				),
				'options' => $options,
			)
		);

		StarmusLogger::timeEnd( 'audio_postprocess', 'PostProcessingService' );
		StarmusLogger::info(
			'PostProcessingService',
			'Post-processing complete',
			array(
				'attachment_id' => $attachment_id,
				'mp3'           => $mp3_path,
				'wav'           => $archival_path,
			)
		);

		return true;
	}
}
