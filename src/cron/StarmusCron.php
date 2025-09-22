<?php
namespace Starmus\cron;

use Starmus\services\WaveformService;
use Starmus\services\PostProcessingService;
use Starmus\services\AudioProcessingService;


class StarmusCron {
  private $waveform_service;
  private $audio_processing_service;
  private $post_processing_service;
  

    public function __construct() {
        add_action( 'starmus_cron', array( $this, 'starmus_cron' ) );
        add_action( 'starmus_process_audio_attachment', 'starmus_run_audio_processing_pipeline', 10, 1 );

    }

    public function starmus_cron() {
        $this->starmus_cron_clear_log();
    }

    public function starmus_cron_clear_log() {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}starmus_log" );
    }

/**
 * Defines the complete audio processing pipeline run by WP-Cron.
 */
public function starmus_run_audio_processing_pipeline( $attachment_id ) {
    if ( empty( $attachment_id ) ) return;
    $attachment_id = (int) $attachment_id;

    $this->waveform_service = new WaveformService();
    $this->post_proc_service = new PostProcessingService();
    $this->metadata_service = new AudioProcessingService();

    // STEP 1: Generate waveform for the UI from the original uploaded file (e.g., WebM).
    $this->waveform_service->generate_waveform_data( $attachment_id );

    // STEP 2: Transcode and master the original file into a final MP3.
    // This replaces the original file in the media library.
    $mastering_success = $this->post_proc_service->transcode_and_master_audio( $attachment_id );

    if ( ! $mastering_success ) {
        update_post_meta($attachment_id, '_audio_processing_status', 'failed_mastering');
        return;
    }

    // STEP 3: Write metadata and run transcription on the NEW, MASTERED MP3 file.
    $this->metadata_service->process_attachment( $attachment_id );

    do_action( 'starmus_audio_pipeline_complete', $attachment_id );
}
}
