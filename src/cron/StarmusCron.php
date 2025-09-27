<?php
/**
 * Service class for managing all WP-Cron related tasks for the Starmus plugin.
 *
 * @package Starmus\cron
 * @version 0.7.4
 * @since 0.7.3
 */

namespace Starmus\cron;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StarmusCron {

	const PROCESS_AUDIO_HOOK = 'starmus_process_audio_attachment';

	private $waveform_service;
	private $post_proc_service;

	public function __construct() {
		// This service orchestrates the others, so it needs instances of them.
		$this->waveform_service  = new WaveformService();
		$this->post_proc_service = new PostProcessingService();
	}

	/**
	 * Registers all WordPress hooks related to cron jobs.
	 * This should be called from the main plugin file.
	 */
	public function register_hooks(): void {
		// 		

	}

	/**
	 * Schedules the main audio processing pipeline for a given attachment.
	 * This is the public method that other classes should call.
	 *
	 * @param int $attachment_id The ID of the attachment to process.
	 */
	public function schedule_audio_processing( int $attachment_id ): void {
		// To prevent duplicate jobs, first check if one is already scheduled for this attachment.
		if ( ! wp_next_scheduled( self::PROCESS_AUDIO_HOOK, array( $attachment_id ) ) ) {
			// Schedule it to run in 60 seconds.
			wp_schedule_single_event(
				time() + 60,
				self::PROCESS_AUDIO_HOOK,
				array( $attachment_id )
			);
		}
	}

	/**
	 * The main pipeline function that is executed by WP-Cron.
	 * It orchestrates the different services in the correct order.
	 *
	 * @param int $attachment_id The attachment ID passed from the scheduled event.
	 */
	public function run_audio_processing_pipeline( int $attachment_id ): void {
		// Mark as processing at the very beginning.
		update_post_meta( $attachment_id, '_audio_processing_status', 'processing' );

		// STEP 1: Generate UI waveform from the original uploaded file.
		$this->waveform_service->generate_waveform_data( $attachment_id );

		// STEP 2: Run the full transcoding, mastering, archival, and metadata pipeline.
		$success = $this->post_proc_service->process_and_archive_audio( $attachment_id );

		if ( $success ) {
			// The metadata service sets the final 'complete' status internally.
			do_action( 'starmus_audio_pipeline_complete', $attachment_id );
		} else {

			update_post_meta( $attachment_id, '_audio_processing_status', 'failed_processing' );
		}
	}
}
