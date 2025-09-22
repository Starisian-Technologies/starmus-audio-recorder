<?php
/**
 * Service class for post-save audio transcoding, mastering, and archival using ffmpeg.
 * Produces both MP3 (distribution) and WAV (archival) and triggers metadata writing.
 *
 * @package Starmus\services
 * @version 1.3.0
 */

namespace Starmus\services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PostProcessingService {

    private $audio_processing_service;

    public function __construct() {
        $this->audio_processing_service = new AudioProcessingService();
    }

    public function is_tool_available(): bool {
        $path = shell_exec('command -v ffmpeg');
        return !empty(trim($path));
    }

    public function process_and_archive_audio( int $attachment_id ): bool {
        if ( !$this->is_tool_available() ) { /* ... error handling ... */ return false; }

        $original_path = get_attached_file( $attachment_id );
        if ( ! $original_path || ! file_exists( $original_path ) ) { /* ... error handling ... */ return false; }

        $upload_dir = wp_get_upload_dir();
        $base_filename = pathinfo($original_path, PATHINFO_FILENAME);

        $backup_path = $original_path . '.bak';
        if (!rename($original_path, $backup_path)) { /* ... error handling ... */ return false; }

        // --- 1. Generate Lossless Archival WAV ---
        $archival_path = $upload_dir['path'] . '/' . $base_filename . '-archive.wav';
        // ... (ffmpeg command for WAV as in previous version) ...
        // ... (error handling for WAV creation) ...

        // --- 2. Generate Mastered Distribution MP3 ---
        $filters = ["loudnorm=I=-16", "silenceremove=start_periods=1:start_threshold=-50dB"];
        $filters = apply_filters( 'starmus_ffmpeg_filters', $filters, $attachment_id );
        $filter_chain = implode(',', array_filter($filters));
        
        $mp3_path = $upload_dir['path'] . '/' . $base_filename . '.mp3';
        // ... (ffmpeg command for MP3 as in previous version) ...
        // ... (error handling for MP3 creation, including restoring backup) ...

        // --- 3. Update WordPress to use the new MP3 ---
        update_attached_file( $attachment_id, $mp3_path );
        wp_update_post(['ID' => $attachment_id, 'post_mime_type' => 'audio/mpeg']);
        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $mp3_path));

        // --- 4. Call Metadata Service to write ID3 tags and run transcription hook ---
        // This is the key change: the metadata/tagging step is now part of this service's flow.
        $this->audio_processing_service->process_attachment( $attachment_id );

        // --- 5. Finalize ---
        update_post_meta($attachment_id, '_starmus_archival_path', $archival_path);
        unlink($backup_path);

        do_action('starmus_audio_postprocessed', $attachment_id, ['mp3' => $mp3_path, 'wav' => $archival_path]);

        return true;
    }
}
