<?php
/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * Post-save transcoding, mastering, archival, ID3, waveform generation.
 *
 * @package   Starisian\Sparxstar\Starmus\services
 * @version 0.8.5-dal
 */

namespace Starisian\Sparxstar\Starmus\services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

/**
 * PostProcessingService
 *
 * Responsibilities:
 *  - Resolve ffmpeg path (via DAL → filters)
 *  - Transcode original → WAV (archival), MP3 (distribution)
 *  - Apply ID3 via AudioProcessingService
 *  - Generate waveform via WaveformService
 *  - Persist paths & state via DAL
 *  - Optional cloud offload via FileService
 */
class PostProcessingService {

	/** Status/state codes written for observability */
	public const STATE_IDLE           = 'idle';
	public const STATE_PROCESSING     = 'processing';
	public const STATE_CONVERTING_WAV = 'converting_wav';
	public const STATE_CONVERTING_MP3 = 'converting_mp3';
	public const STATE_ID3_WRITING    = 'id3_writing';
	public const STATE_WAVEFORM       = 'waveform';
	public const STATE_COMPLETED      = 'completed';

	public const STATE_ERR_FFMPEG_MISSING = 'error_ffmpeg_missing';
	public const STATE_ERR_SOURCE_MISSING = 'error_source_missing';
	public const STATE_ERR_BACKUP_FAILED  = 'error_backup_failed';
	public const STATE_ERR_WAV_FAILED     = 'error_wav_failed';
	public const STATE_ERR_MP3_FAILED     = 'error_mp3_failed';
	public const STATE_ERR_ID3_FAILED     = 'error_id3_failed';
	public const STATE_ERR_UNKNOWN        = 'error_unknown';

	/** Meta keys (attachment) kept for limited internal use */
	private const META_ORIGINAL_SOURCE = '_audio_original_source';

	/** ACF keys (used only if ACF is present, via DAL method) */
	private const ACF_ARCHIVAL_WAV = 'archival_wav';
	private const ACF_MASTERED_MP3 = 'mastered_mp3';

	/** Filters */
	private const FILTER_FFMPEG_PATH     = 'starmus_ffmpeg_path';
	private const FILTER_PROCESS_OPTIONS = 'starmus_postprocess_options';

	private AudioProcessingService $id3;
	private FileService $files;
	private WaveformService $waveform;
	private StarmusAudioRecorderDAL $dal;

	/** DI-friendly constructor (falls back to internal instantiation if omitted) */
	public function __construct(
		?AudioProcessingService $audio_processing_service = null,
		?FileService $file_service = null,
		?WaveformService $waveform_service = null,
		?StarmusAudioRecorderDAL $dal = null
	) {
		$this->id3      = $audio_processing_service ?: new AudioProcessingService();
		$this->files    = $file_service ?: new FileService();
		$this->waveform = $waveform_service ?: new WaveformService();
		$this->dal      = $dal ?: new StarmusAudioRecorderDAL();
	}

	/** Back-compat alias */
	public function process_and_archive_audio( int $audio_post_id, int $attachment_id, array $options = array() ): bool {
		return $this->process( $audio_post_id, $attachment_id, $options );
	}

	/** Back-compat alias (kept to avoid touching older callers) */
	public function star_process_and_archive_audio( int $audio_post_id, int $attachment_id, array $options = array() ): bool {
		return $this->process( $audio_post_id, $attachment_id, $options );
	}

	/**
	 * Main entry point (strict)
	 */
	public function process( int $audio_post_id, int $attachment_id, array $options = array() ): bool {
		StarmusLogger::setCorrelationId();
		StarmusLogger::timeStart( 'audio_postprocess' );

		try {
			$ffmpeg = $this->resolveFfmpegPath();
			if ( ! $ffmpeg ) {
				$this->setState( $attachment_id, self::STATE_ERR_FFMPEG_MISSING );
				StarmusLogger::error( 'PostProcessingService', 'FFmpeg binary not found' );
				return false;
			}

			$options = $this->resolveOptions( $audio_post_id, $attachment_id, $options );

			$original = $this->files->get_local_copy( $attachment_id );
			if ( ! $original || ! file_exists( $original ) ) {
				$this->setState( $attachment_id, self::STATE_ERR_SOURCE_MISSING );
				StarmusLogger::error(
					'PostProcessingService',
					'Missing local source',
					array(
						'attachment_id' => $attachment_id,
						'path'          => $original,
					)
				);
				return false;
			}

			$this->setState( $attachment_id, self::STATE_PROCESSING );
			// Persist original source via DAL (keeps writes centralized).
			try {
				$this->dal->save_post_meta( $attachment_id, self::META_ORIGINAL_SOURCE, $original );
			} catch ( \Throwable $e ) {
				StarmusLogger::warning( 'PostProcessingService', 'Failed to persist original source via DAL; falling back to update_post_meta', array( 'attachment_id' => $attachment_id ) );
				update_post_meta( $attachment_id, self::META_ORIGINAL_SOURCE, $original );
			}

			$is_temp_source = ( strpos( $original, sys_get_temp_dir() ) === 0 );
			$upload_dir     = wp_get_upload_dir();
			$base_filename  = pathinfo( $original, PATHINFO_FILENAME );
			$backup         = $original . '.bak';

			if ( ! @rename( $original, $backup ) ) {
				$this->setState( $attachment_id, self::STATE_ERR_BACKUP_FAILED );
				StarmusLogger::error( 'PostProcessingService', 'Backup creation failed', array( 'original' => $original ) );
				return false;
			}

			do_action(
				'starmus_audio_preprocess',
				array(
					'post_id'       => $audio_post_id,
					'attachment_id' => $attachment_id,
					'backup_path'   => $backup,
					'options'       => $options,
				)
			);

			// 1) WAV archival
			$this->setState( $attachment_id, self::STATE_CONVERTING_WAV );
			$wav_path         = trailingslashit( $upload_dir['path'] ) . "{$base_filename}-archive.wav";
			[$okWav, $wavLog] = $this->execFfmpeg(
				$ffmpeg,
				sprintf(
					'-y -i %s -c:a pcm_s16le -ar %d %s',
					escapeshellarg( $backup ),
					(int) $options['samplerate'],
					escapeshellarg( $wav_path )
				)
			);
			if ( ! $okWav || ! file_exists( $wav_path ) ) {
				$this->restoreOriginal( $backup, $original );
				$this->setState( $attachment_id, self::STATE_ERR_WAV_FAILED );
				StarmusLogger::error( 'PostProcessingService', 'WAV generation failed', array( 'ffmpeg' => $wavLog ) );
				return false;
			}

			// 2) MP3 distribution
			$this->setState( $attachment_id, self::STATE_CONVERTING_MP3 );
			$filters = array( 'loudnorm=I=-16:TP=-1.5:LRA=11' );
			if ( empty( $options['preserve_silence'] ) ) {
				$filters[] = 'silenceremove=start_periods=1:start_threshold=-50dB';
			}
			$filter_chain = implode( ',', array_filter( $filters ) );

			$mp3_path         = trailingslashit( $upload_dir['path'] ) . "{$base_filename}.mp3";
			[$okMp3, $mp3Log] = $this->execFfmpeg(
				$ffmpeg,
				sprintf(
					'-y -i %s -af %s -c:a libmp3lame -b:a %s %s',
					escapeshellarg( $backup ),
					escapeshellarg( $filter_chain ),
					escapeshellarg( (string) $options['bitrate'] ),
					escapeshellarg( $mp3_path )
				)
			);
			if ( ! $okMp3 || ! file_exists( $mp3_path ) ) {
				$this->restoreOriginal( $backup, $original );
				if ( file_exists( $wav_path ) ) {
					wp_delete_file( $wav_path );
				}
				$this->setState( $attachment_id, self::STATE_ERR_MP3_FAILED );
				StarmusLogger::error( 'PostProcessingService', 'MP3 generation failed', array( 'ffmpeg' => $mp3Log ) );
				return false;
			}

			// 3) Attach MP3 as the definitive file (update attachment & metadata via DAL)
			$this->dal->update_attachment_metadata( $attachment_id, $mp3_path );
			// Use correct DAL method name (plural) and provide robust fallback.
			if ( method_exists( $this->dal, 'persist_audio_outputs' ) ) {
				$this->dal->persist_audio_outputs( $attachment_id, $mp3_path, $wav_path );
			} else {
				// Best-effort fallback to attachment meta keys
				$this->dal->update_audio_post_meta( $attachment_id, '_audio_mp3_path', (string) $mp3_path );
				$this->dal->update_audio_post_meta( $attachment_id, '_audio_wav_path', (string) $wav_path );
				$this->dal->update_audio_post_meta( $attachment_id, '_starmus_archival_path', (string) $wav_path );
			}

			// 4) ID3 tagging with detailed diagnostics
			$this->setState( $attachment_id, self::STATE_ID3_WRITING );
			$id3_ok = $this->id3->process_attachment( $attachment_id );
			if ( ! $id3_ok ) {
				$this->setState( $attachment_id, self::STATE_ERR_ID3_FAILED );
				// process_attachment() already logs granular errors
				// We choose to continue (soft failure) to keep deliverables usable
			} else {
				$this->dal->record_id3_timestamp( $attachment_id );
			}
			do_action( 'starmus_audio_id3_written', $attachment_id, $mp3_path, $audio_post_id, $id3_ok );

			// 5) Waveform generation
			$this->setState( $attachment_id, self::STATE_WAVEFORM );
			$this->waveform->generate_waveform_data( $attachment_id );

			// 6) Metadata persistence to CPT (ACF if present) via DAL
			// Prefer save_audio_outputs(post_id, waveform_json|null, mp3_url|null, wav_url|null)
			if ( method_exists( $this->dal, 'save_audio_outputs' ) ) {
				$this->dal->save_audio_outputs( $audio_post_id, null, $mp3_path, $wav_path );
			} else {
				// Fallback to earlier behaviour
				$this->dal->update_audio_post_fields(
					$audio_post_id,
					array(
						self::ACF_ARCHIVAL_WAV => $wav_path,
						self::ACF_MASTERED_MP3 => $mp3_path,
					)
				);
			}

			// 7) Optional cloud offload (no-op if FileService doesn’t replace)
			$this->files->upload_and_replace_attachment( $attachment_id, $mp3_path );

			// 8) Cleanup
			wp_delete_file( $backup );
			// If original lived in sys temp and WP copied it earlier, remove the local temp
			if ( $is_temp_source && file_exists( $original ) ) {
				@unlink( $original );
			}

			do_action(
				'starmus_audio_postprocessed',
				$attachment_id,
				array(
					'post_id' => $audio_post_id,
					'mp3'     => $mp3_path,
					'wav'     => $wav_path,
					'ffmpeg'  => array(
						'wav' => $wavLog,
						'mp3' => $mp3Log,
					),
					'options' => $options,
				)
			);

			$this->setState( $attachment_id, self::STATE_COMPLETED );
			StarmusLogger::timeEnd( 'audio_postprocess', 'PostProcessingService' );
			StarmusLogger::info(
				'PostProcessingService',
				'Post-processing complete',
				array(
					'attachment_id' => $attachment_id,
					'mp3'           => $mp3_path,
					'wav'           => $wav_path,
				)
			);
			return true;

		} catch ( \Throwable $e ) {
			$this->setState( $attachment_id, self::STATE_ERR_UNKNOWN );
			StarmusLogger::error(
				'PostProcessingService',
				'Unhandled post-process exception',
				array(
					'attachment_id' => $attachment_id,
					'error'         => $e->getMessage(),
				)
			);
			return false;
		}
	}

	/** Resolve ffmpeg path via DAL; allow external override through filter. */
	private function resolveFfmpegPath(): ?string {
		$path = $this->dal->get_ffmpeg_path();
		if ( $path !== null && $path !== '' ) {
			$path = apply_filters( self::FILTER_FFMPEG_PATH, $path );
		}
		return $path ?: null;
	}

	/** Execute ffmpeg, return [success(bool), combined_output(string)] */
	private function execFfmpeg( string $ffmpeg, string $args ): array {
		$cmd  = $ffmpeg . ' ' . $args . ' 2>&1';
		$out  = array();
		$code = 0;
		exec( $cmd, $out, $code );
		$combined = implode( "\n", $out );
		StarmusLogger::debug(
			'PostProcessingService',
			'FFmpeg exec',
			array(
				'cmd'  => $cmd,
				'exit' => $code,
			)
		);
		return array( $code === 0, $combined );
	}

	/** Restore original file on failure */
	private function restoreOriginal( string $backup, string $original ): void {
		@rename( $backup, $original );
	}

	/** Update processing state on attachment via DAL */
	private function setState( int $attachment_id, string $state ): void {
		// DAL does not currently implement set_audio_state universally; centralize via save_post_meta
		try {
			$this->dal->save_post_meta( $attachment_id, '_audio_processing_state', $state );
		} catch ( \Throwable $e ) {
			// Fallback to direct meta update if DAL fails
			StarmusLogger::warning(
				'PostProcessingService',
				'Failed to persist processing state via DAL; falling back to update_post_meta',
				array(
					'attachment_id' => $attachment_id,
					'state'         => $state,
				)
			);
			update_post_meta( $attachment_id, '_audio_processing_state', $state );
		}
	}

	/** Resolve and filter options (bitrate, samplerate, preserve_silence) */
	private function resolveOptions( int $post_id, int $attachment_id, array $options ): array {
		$defaults = array(
			'preserve_silence' => true,   // keep dead air for alignment
			'bitrate'          => '192k', // MP3 bitrate
			'samplerate'       => 44100,  // WAV sample rate
		);
		$resolved = array_replace( $defaults, $options );
		return apply_filters(
			self::FILTER_PROCESS_OPTIONS,
			$resolved,
			array(
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
			)
		);
	}
}
