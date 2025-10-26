<?php
/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * Asynchronous background processor for the Starmus mastering pipeline.
 * Handles waveform generation, post-processing, and temp cleanup via WP-Cron.
 *
 * @package   Starisian\Sparxstar\Starmus\cron
 * @version   1.1.0
 */

namespace Starisian\Sparxstar\Starmus\cron;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\services\WaveformService;
use Starisian\Sparxstar\Starmus\services\PostProcessingService;

use function trailingslashit;

/**
 * StarmusCron
 *
 * Coordinates deferred waveform + mastering jobs and periodic cleanup.
 * Use this class when you want to run heavy audio post-processing
 * safely in the background after uploads.
 */
final class StarmusCron {

	/** Single-run job for background mastering. */
	public const PROCESS_AUDIO_HOOK = 'starmus_process_audio_attachment';

	/** Recurring hourly cleanup for stale temp upload chunks. */
	public const CLEANUP_TEMP_FILES_HOOK = 'starmus_cleanup_temp_files';

	private WaveformService $waveform;
	private PostProcessingService $post;

	public function __construct(
		?WaveformService $waveform_service = null,
		?PostProcessingService $post_service = null
	) {
		$this->waveform = $waveform_service ?: new WaveformService();
		$this->post     = $post_service ?: new PostProcessingService();
	}

	/** Registers WP hooks for both the processor and cleanup jobs. */
	public function register_hooks(): void {
		add_action( self::PROCESS_AUDIO_HOOK, array( $this, 'run_audio_processing_pipeline' ), 10, 1 );
		add_action( self::CLEANUP_TEMP_FILES_HOOK, array( $this, 'cleanup_stale_temp_files' ) );
		add_filter( 'cron_schedules', array( $this, 'register_custom_schedules' ) );
	}

	/**
	 * Queue a background mastering job for a given attachment.
	 *
	 * @param int $attachment_id
	 */
	public function schedule_audio_processing( int $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return;
		}

		if ( ! wp_next_scheduled( self::PROCESS_AUDIO_HOOK, array( $attachment_id ) ) ) {
			wp_schedule_single_event( time() + 60, self::PROCESS_AUDIO_HOOK, array( $attachment_id ) );
			StarmusLogger::info( 'Cron', 'Scheduled audio processing', array( 'attachment_id' => $attachment_id ) );
		}
	}

	/**
	 * Executes the full pipeline asynchronously.
	 * This is what WP-Cron calls once the job is due.
	 */
	public function run_audio_processing_pipeline( int $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return;
		}

		StarmusLogger::setCorrelationId();
		StarmusLogger::info( 'Cron', 'Starting background pipeline', array( 'attachment_id' => $attachment_id ) );
		update_post_meta( $attachment_id, '_audio_processing_status', PostProcessingService::STATE_PROCESSING );

		try {
			// === STEP 1: Waveform Generation ===
			$this->waveform->generate_waveform_data( $attachment_id );
			update_post_meta( $attachment_id, '_audio_processing_status', PostProcessingService::STATE_WAVEFORM );

			// === STEP 2: Full Transcoding + Archival ===
			$parent_id = (int) wp_get_post_parent_id( $attachment_id );
			if ( $parent_id <= 0 ) {
				// fallback: try to infer parent from _aiwa_recording_post meta
				$parent_id = (int) get_post_meta( $attachment_id, '_aiwa_recording_post', true );
			}

			if ( $parent_id <= 0 ) {
				StarmusLogger::warn( 'Cron', 'No parent post linked to attachment', array( 'attachment_id' => $attachment_id ) );
			}

			$success = $this->post->process_and_archive_audio( $parent_id, $attachment_id );

			if ( $success ) {
				update_post_meta( $attachment_id, '_audio_processing_status', PostProcessingService::STATE_COMPLETED );
				do_action( 'starmus_audio_pipeline_complete', $attachment_id );
				StarmusLogger::info(
					'Cron',
					'Background processing complete',
					array(
						'attachment_id' => $attachment_id,
						'post_id'       => $parent_id,
					)
				);
			} else {
				update_post_meta( $attachment_id, '_audio_processing_status', PostProcessingService::STATE_ERR_UNKNOWN );
				StarmusLogger::error( 'Cron', 'Background processing failed', array( 'attachment_id' => $attachment_id ) );
			}
		} catch ( \Throwable $e ) {
			update_post_meta( $attachment_id, '_audio_processing_status', PostProcessingService::STATE_ERR_UNKNOWN );
			StarmusLogger::error(
				'Cron',
				'Fatal exception during cron pipeline',
				array(
					'attachment_id' => $attachment_id,
					'error'         => $e->getMessage(),
				)
			);
			error_log( "StarmusCron fatal error for attachment {$attachment_id}: {$e->getMessage()}" );
		}
	}

	/**
	 * Remove stale temp upload files (>24h old).
	 * Runs hourly or every 15 minutes, depending on your schedule.
	 */
	public function cleanup_stale_temp_files(): void {
		$dir = $this->get_temp_dir();
		if ( ! $dir || ! is_dir( $dir ) ) {
			return;
		}

		$files = glob( $dir . '*.part' );
		if ( empty( $files ) ) {
			return;
		}

		$cutoff = time() - DAY_IN_SECONDS;
		foreach ( $files as $file ) {
			if ( is_file( $file ) && @filemtime( $file ) < $cutoff ) {
				@unlink( $file );
			}
		}

		StarmusLogger::debug(
			'Cron',
			'Temp cleanup executed',
			array(
				'dir'           => $dir,
				'removed_count' => count( $files ),
			)
		);
	}

	/** Schedule recurring cleanup on plugin activation. */
	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CLEANUP_TEMP_FILES_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CLEANUP_TEMP_FILES_HOOK );
		}
	}

	/** Unschedule all cleanup jobs on plugin deactivation. */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( self::CLEANUP_TEMP_FILES_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CLEANUP_TEMP_FILES_HOOK );
		}
	}

	/**
	 * Optional: adds a 15-minute recurring schedule.
	 */
	public function register_custom_schedules(array $schedules): array {
		try {
			$schedules['starmus_quarter_hour'] = [
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __('Every 15 Minutes (Starmus)', 'starmus-audio-recorder'),
			];
		} catch (\Throwable $e) {
			error_log('[StarmusCron] Failed to register schedule: ' . $e->getMessage());
		}
		return $schedules;
	}


	/**
	 * Shared helper to ensure our temp upload directory exists and is protected.
	 */
	private function get_temp_dir(): string {
		$upload_dir       = wp_get_upload_dir();
		$default_temp_dir = trailingslashit( $upload_dir['basedir'] ) . 'starmus-temp/';

		if ( ! wp_mkdir_p( $default_temp_dir ) ) {
			return '';
		}

		// Harden directory
		$htaccess_path = $default_temp_dir . '.htaccess';
		if ( ! file_exists( $htaccess_path ) ) {
			@file_put_contents( $htaccess_path, "Deny from all\n" );
		}
		$index_path = $default_temp_dir . 'index.html';
		if ( ! file_exists( $index_path ) ) {
			@file_put_contents( $index_path, '' );
		}

		return $default_temp_dir;
	}
}
