<?php

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\services;

if (! \defined('ABSPATH')) {
    exit;
}

use getid3_writetags;
use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Throwable;

/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * UNIFIED POST-PROCESSING SERVICE
 * ---------------------------------------------------------
 * Combines Transcoding, EBU R128 Normalization, ID3 Tagging, 
 * and Waveform Generation into a single atomic operation.
 *
 * @package Starisian\Sparxstar\Starmus\services
 * @version 2.0.0 (Unified)
 */
class StarmusPostProcessingService
{
    private StarmusAudioRecorderDAL $dal;
    private StarmusWaveformService $waveform_service;

    public function __construct()
    {
        $this->dal              = new StarmusAudioRecorderDAL();
        $this->waveform_service = new StarmusWaveformService();
    }

    /**
     * Main Entry Point: Process an audio recording.
     *
     * @param int $post_id The 'audio-recording' Post ID.
     * @param int $attachment_id The ID of the uploaded 'original' file.
     * @param array $params Context parameters (network_type, bitrates, etc).
     *
     * @return bool True on success.
     */
    public function process(int $post_id, int $attachment_id, array $params = []): bool
    {
        StarmusLogger::setCorrelationId();
        StarmusLogger::timeStart('audio_process');

        try {
            // 1. Validate Source
            $source_path = get_attached_file($attachment_id);
            if (!$source_path || !file_exists($source_path)) {
                throw new \RuntimeException("Source file missing for attachment ID: $attachment_id");
            }

            // 2. Prepare Output Directory
            $uploads    = wp_upload_dir();
            $output_dir = trailingslashit($uploads['basedir']) . 'starmus_processed';
            if (!is_dir($output_dir)) {
                wp_mkdir_p($output_dir);
            }

            // 3. Resolve FFmpeg
            $ffmpeg_path = $this->dal->get_ffmpeg_path(); 
            if (!$ffmpeg_path) {
                // Fallback for local dev environments where 'ffmpeg' is in PATH
                $ffmpeg_path = trim(shell_exec('command -v ffmpeg') ?: '');
            }
            if (!$ffmpeg_path) {
                throw new \RuntimeException('FFmpeg binary not found on server.');
            }

            // 4. Determine Encoding Parameters
            $network_type = $params['network_type'] ?? '4g';
            $sample_rate  = \intval($params['samplerate'] ?? 44100);
            $bitrate      = $params['bitrate'] ?? '192k';
            $session_uuid = $params['session_uuid'] ?? 'unknown';

            // 5. Build Filter Chain (Adaptive Highpass + Loudness Normalization)
            $highpass = match ($network_type) {
                '2g', 'slow-2g' => 'highpass=f=100,lowpass=f=4000',
                '3g'            => 'highpass=f=80,lowpass=f=7000',
                default         => 'highpass=f=60',
            };

            // Pass 1: Loudness Scan (EBU R128)
            $cmd_scan = \sprintf(
                '%s -hide_banner -nostats -i %s -af "loudnorm=I=-23:LRA=7:tp=-2:print_format=json" -f null - 2>&1',
                escapeshellarg($ffmpeg_path),
                escapeshellarg($source_path)
            );
            $scan_output = shell_exec($cmd_scan);
            
            // Parse JSON from FFmpeg output
            preg_match('/\{.*\}/s', $scan_output, $matches);
            $loudness_data = !empty($matches[0]) ? json_decode($matches[0], true) : [];

            $loudnorm_filter = \sprintf(
                'loudnorm=I=-23:LRA=7:tp=-2:measured_I=%s:measured_LRA=%s:measured_tp=%s:measured_thresh=%s:offset=%s',
                $loudness_data['input_i'] ?? -23,
                $loudness_data['input_lra'] ?? 7,
                $loudness_data['input_tp'] ?? -2,
                $loudness_data['input_thresh'] ?? -70,
                $loudness_data['target_offset'] ?? 0
            );

            $full_filter = "$highpass,$loudnorm_filter";

            // 6. Define Output Paths
            $mp3_filename = $post_id . '_master.mp3';
            $wav_filename = $post_id . '_archival.wav';
            $mp3_path     = $output_dir . '/' . $mp3_filename;
            $wav_path     = $output_dir . '/' . $wav_filename;

            // Metadata Tags for FFmpeg
            $ffmpeg_meta = \sprintf(
                '-metadata comment=%s',
                escapeshellarg("Source: Starmus | Profile: $network_type | Session: $session_uuid")
            );

            // 7. Transcode (Pass 2)
            // MP3 Generation
            $cmd_mp3 = \sprintf(
                '%s -hide_banner -y -i %s -ar %d -b:a %s -ac 1 -af "%s" %s %s 2>&1',
                escapeshellarg($ffmpeg_path),
                escapeshellarg($source_path),
                $sample_rate,
                escapeshellarg($bitrate),
                $full_filter,
                $ffmpeg_meta,
                escapeshellarg($mp3_path)
            );

            // WAV Generation
            $cmd_wav = \sprintf(
                '%s -hide_banner -y -i %s -ar %d -ac 1 -sample_fmt s16 -af "%s" %s %s 2>&1',
                escapeshellarg($ffmpeg_path),
                escapeshellarg($source_path),
                $sample_rate,
                $full_filter,
                $ffmpeg_meta,
                escapeshellarg($wav_path)
            );

            $log   = [];
            $log[] = "Loudness Scan:\n" . $scan_output;
            $log[] = "---\nMP3 Command:\n" . $cmd_mp3 . "\nOutput:\n" . shell_exec($cmd_mp3);
            $log[] = "---\nWAV Command:\n" . $cmd_wav . "\nOutput:\n" . shell_exec($cmd_wav);

            // 8. Import Results to WordPress Media Library
            $mp3_id = $this->import_to_media_library($mp3_path, $post_id, 'audio/mpeg');
            $wav_id = $this->import_to_media_library($wav_path, $post_id, 'audio/wav');

            if (!$mp3_id) {
                throw new \RuntimeException("Failed to import MP3 to Media Library.");
            }

            // 9. Apply ID3 Tags (to the MP3)
            $this->apply_id3_tags($mp3_path, $post_id, $mp3_id);

            // 10. Generate Waveform Data
            // We use the WAV file for faster processing if available, otherwise MP3
            $waveform_source_id = $wav_id ?: $mp3_id;
            $this->waveform_service->generate_waveform_data($waveform_source_id);
            // Copy waveform data to parent post meta for easy access
            $waveform_json = get_post_meta($waveform_source_id, '_waveform_data', true);
            if ($waveform_json) {
                update_post_meta($post_id, 'waveform_json', is_string($waveform_json) ? $waveform_json : json_encode($waveform_json));
            }

            // 11. Update Post Metadata (The "Verified Keys")
            update_post_meta($post_id, 'mastered_mp3', $mp3_id);
            update_post_meta($post_id, 'archival_wav', $wav_id);
            update_post_meta($post_id, 'processing_log', implode("\n", $log));
            
            // Legacy / Backup keys
            update_post_meta($post_id, '_audio_attachment_id', $attachment_id); // Keep link to original source
            
            // Also update ACF fields if plugin exists
            if (function_exists('update_field')) {
                update_field('mastered_mp3', $mp3_id, $post_id);
                update_field('archival_wav', $wav_id, $post_id);
            }

            StarmusLogger::info('StarmusPostProcessing', 'Processing Complete', ['post_id' => $post_id, 'mp3_id' => $mp3_id]);
            return true;

        } catch (Throwable $e) {
            StarmusLogger::error('StarmusPostProcessing', $e, ['post_id' => $post_id]);
            update_post_meta($post_id, 'processing_log', "CRITICAL ERROR:\n" . $e->getMessage());
            return false;
        } finally {
            StarmusLogger::timeEnd('audio_process', 'StarmusPostProcessing');
        }
    }

    /**
     * Helper to import physical files into WP Media Library.
     */
    private function import_to_media_library(string $filepath, int $parent_post_id, string $mime_type): int
    {
        if (!file_exists($filepath)) return 0;

        $filename = basename($filepath);
        $attachment = [
            'post_mime_type' => $mime_type,
            'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];

        $attach_id = wp_insert_attachment($attachment, $filepath, $parent_post_id);
        
        if (!is_wp_error($attach_id)) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
            wp_update_attachment_metadata($attach_id, $attach_data);
            return $attach_id;
        }

        return 0;
    }

    /**
     * Writes ID3 tags using getID3 library.
     */
    private function apply_id3_tags(string $filepath, int $post_id, int $attachment_id): void
    {
        if (!class_exists('getid3_writetags')) {
            // Try to load from WP core or included lib if available
            return; 
        }

        try {
            $post = get_post($post_id);
            $author_name = get_the_author_meta('display_name', $post->post_author);
            $site_name   = get_bloginfo('name');

            $TaggingFormat = 'UTF-8';
            $writer = new getid3_writetags();
            $writer->filename       = $filepath;
            $writer->tagformats     = ['id3v2.3'];
            $writer->overwrite_tags = true;
            $writer->tag_encoding   = $TaggingFormat;
            $writer->remove_other_tags = true;

            $TagData = [
                'title'   => [$post->post_title],
                'artist'  => [$author_name],
                'album'   => [$site_name . ' Archives'],
                'year'    => [date('Y')],
                'comment' => ["Recorded via Starmus | ID: $post_id"],
                'copyright_message' => ["© $site_name"],
                'publisher' => [$site_name],
            ];

            $writer->tag_data = $TagData;
            $writer->WriteTags();
        } catch (\Throwable $e) {
            StarmusLogger::warning('ID3 Write Failed', $e->getMessage());
        }
    }
}