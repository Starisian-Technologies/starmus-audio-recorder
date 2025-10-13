<?php
/**
 * Service class for managing all WP-Cron related tasks for the Starmus plugin.
 *
 * @package Starisian\Starmus\cron
 * @version 0.7.6
 * @since   0.7.3
 */

namespace Starisian\Starmus\cron;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}



/**
 * NOTE:
 * - Call StarmusCron::register_hooks() from your main plugin bootstrap.
 * - Register activation/deactivation hooks in the main plugin file like:
 *
 *   register_activation_hook( __FILE__, [ \Starmus\cron\StarmusCron::class, 'activate' ] );
 *   register_deactivation_hook( __FILE__, [ \Starmus\cron\StarmusCron::class, 'deactivate' ] );
 */

// If your services are namespaced differently, update these use statements.
use Starisian\Starmus\services\WaveformService;
use Starisian\Starmus\services\PostProcessingService;

use function trailingslashit;

class StarmusCron {


	/** Fired when a single audio attachment should be processed by the pipeline. */
	public const PROCESS_AUDIO_HOOK = 'starmus_process_audio_attachment';

	/** Recurring cleanup hook for stale temp upload parts (chunked uploads). */
	public const CLEANUP_TEMP_FILES_HOOK = 'starmus_cleanup_temp_files';

	/** @var WaveformService */
	private $waveform_service;

	/** @var PostProcessingService */
	private $post_proc_service;

	public function __construct() {
		// The cron orchestrator holds references to the pipeline services.
		$this->waveform_service  = new WaveformService();
		$this->post_proc_service = new PostProcessingService();
	}

	/**
	 * Registers all WordPress hooks related to cron jobs.
	 * This should be called from the main plugin file after instances are constructed.
	 */
	public function register_hooks(): void {
		// Main pipeline runner.
		add_action( self::PROCESS_AUDIO_HOOK, array( $this, 'run_audio_processing_pipeline' ), 10, 1 );

		// Periodic cleanup of stale temp files created during chunked uploads.
		add_action( self::CLEANUP_TEMP_FILES_HOOK, array( $this, 'cleanup_stale_temp_files' ) );

		// (Optional) provide a custom schedule; default 'hourly' is usually fine.
		add_filter( 'cron_schedules', array( $this, 'register_custom_schedules' ) );
	}

	/**
	 * Schedules the main audio processing pipeline for a given attachment.
	 * Public API for other classes to enqueue a processing job.
	 *
	 * @param int $attachment_id The ID of the attachment to process.
	 */
	public function schedule_audio_processing( int $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return;
		}

		// Avoid duplicates: only schedule if not already queued with same args.
		if ( ! wp_next_scheduled( self::PROCESS_AUDIO_HOOK, array( $attachment_id ) ) ) {
			wp_schedule_single_event(
				time() + 60, // run in ~1 minute
				self::PROCESS_AUDIO_HOOK,
				array( $attachment_id )
			);
		}
	}

	/**
	 * The main pipeline function that is executed by WP-Cron.
	 * Orchestrates waveform gen + full post-processing / archival pipeline.
	 *
	 * @param int $attachment_id The attachment ID passed from the scheduled event.
	 */
	public function run_audio_processing_pipeline( int $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return;
		}

		// Mark as processing at the very beginning.
		update_post_meta( $attachment_id, '_audio_processing_status', 'processing' );

		// STEP 1: Generate UI waveform from the original uploaded file.
		try {
			$this->waveform_service->generate_waveform_data( $attachment_id );
		} catch ( \Throwable $e ) {
			error_log( "Starmus Pipeline: Waveform generation failed for attachment {$attachment_id}: " . $e->getMessage() );
			update_post_meta( $attachment_id, '_audio_processing_status', 'failed_waveform' );
			return;
		}

		// STEP 2: Run the full transcoding, mastering, archival, and metadata pipeline.
		try {
			$success = $this->post_proc_service->process_and_archive_audio( $attachment_id );
		} catch ( \Throwable $e ) {
			error_log( "Starmus Pipeline: Post-processing exception for attachment {$attachment_id}: " . $e->getMessage() );
			$success = false;
		}

		if ( $success ) {
			// Let downstream listeners update any final state; metadata service can set 'complete'.
			do_action( 'starmus_audio_pipeline_complete', $attachment_id );
			// If nothing else set a status, ensure it's marked complete.
			$current = get_post_meta( $attachment_id, '_audio_processing_status', true );
			if ( empty( $current ) || $current === 'processing' ) {
				update_post_meta( $attachment_id, '_audio_processing_status', 'complete' );
			}
		} else {
			error_log( "Starmus Pipeline: The main processing pipeline failed for attachment {$attachment_id}." );
			update_post_meta( $attachment_id, '_audio_processing_status', 'failed_processing' );
		}
	}

	/**
	 * Clean up stale temporary files (created during chunked uploads) older than 24 hours.
	 * Runs via WP-Cron on the CLEANUP_TEMP_FILES_HOOK schedule.
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
			// Extra safety: only touch files inside our temp dir and matching ".part"
			if ( is_file( $file ) && @filemtime( $file ) < $cutoff ) {
				@unlink( $file );
			}
		}
	}

	/**
	 * Ensure our recurring cleanup job exists. Call on plugin activation.
	 * Schedules hourly by default; can be changed to a custom schedule below.
	 */
	public static function activate(): void {
		// Default to hourly; you can switch to 'starmus_quarter_hour' if you want.
		if ( ! wp_next_scheduled( self::CLEANUP_TEMP_FILES_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CLEANUP_TEMP_FILES_HOOK );
		}
	}

	/**
	 * Clear any recurring jobs we created. Call on plugin deactivation.
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( self::CLEANUP_TEMP_FILES_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CLEANUP_TEMP_FILES_HOOK );
		}
	}

	/**
	 * (Optional) Add custom cron schedules. Example adds a 15-minute cadence.
	 *
	 * @param array $schedules
	 * @return array
	 */
	public function register_custom_schedules( array $schedules ): array {
		if ( ! isset( $schedules['starmus_quarter_hour'] ) ) {
			$schedules['starmus_quarter_hour'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 Minutes (Starmus)', 'starmus-audio-recorder' ),
			);
		}
		return $schedules;
	}

	/**
	 * Resolve / create the temp directory used during chunked uploads.
	 * Mirrors the logic used elsewhere in the plugin to keep paths consistent.
	 *
	 * @return string Absolute path to the temp directory (with trailing slash).
	 */
	private function get_temp_dir(): string {
		$upload_dir       = wp_get_upload_dir();
		$default_temp_dir = trailingslashit( $upload_dir['basedir'] ) . 'starmus-temp/';

		// Ensure directory exists.
		if ( ! wp_mkdir_p( $default_temp_dir ) ) {
			return '';
		}

		// Harden the directory (best effort).
		$htaccess_path = $default_temp_dir . '.htaccess';
		if ( ! file_exists( $htaccess_path ) ) {
			// Deny direct web access.
			@file_put_contents( $htaccess_path, "Deny from all\n" );
		}
		$index_path = $default_temp_dir . 'index.html';
		if ( ! file_exists( $index_path ) ) {
			@file_put_contents( $index_path, '' );
		}

		return $default_temp_dir;
	}
}
