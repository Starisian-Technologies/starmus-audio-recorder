<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\services;

if (! \defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * Unified audio post-processing service.
 *
 * @package Starisian\Sparxstar\Starmus\services
 * @version 2.0.0-OFFLOAD-AWARE
 */
final readonly class StarmusPostProcessingService
{
    private StarmusAudioRecorderDAL $dal;
    private StarmusWaveformService $waveform_service;
    private StarmusId3Service $id3_service;
    private StarmusFileService $file_service; // Dependency for offloaded files

    public function __construct()
    {
        $this->dal = new StarmusAudioRecorderDAL();
        $this->file_service = new StarmusFileService(); // Instantiated
        $this->waveform_service = new StarmusWaveformService(null, $this->file_service);
        $this->id3_service = new StarmusId3Service();
    }

    /**
     * Main entry point for processing an audio recording.
     */
    public function process(int $post_id, int $attachment_id, array $params = []): bool
    {
        StarmusLogger::setCorrelationId();
        $source_path = null;
        $is_temp_file = false;

        try {
            // 1. CRITICAL: GET LOCAL COPY (Handles Cloudflare offload)
            $source_path = $this->file_service->get_local_copy($attachment_id);
            if (!$source_path || !file_exists($source_path)) {
                throw new \RuntimeException('Source file could not be retrieved locally for attachment ID: ' . $attachment_id);
            }
            // Determine if we need to clean up this file later
            if ($source_path !== get_attached_file($attachment_id)) {
                $is_temp_file = true;
            }

            // 2. Prepare Output
            $uploads = wp_upload_dir();
            $output_dir = trailingslashit($uploads['basedir']) . 'starmus_processed';
            if (!is_dir($output_dir)) wp_mkdir_p($output_dir);

            // 3. Resolve FFmpeg
            $ffmpeg_path = $this->dal->get_ffmpeg_path() ?: trim((string)shell_exec('command -v ffmpeg'));
            if (empty($ffmpeg_path)) {
                throw new \RuntimeException('FFmpeg binary not found on server.');
            }

            // 4. Params
            $network_type = $params['network_type'] ?? '4g';
            $sample_rate  = (int)($params['samplerate'] ?? 44100);
            $bitrate      = $params['bitrate'] ?? '192k';
            $session_uuid = $params['session_uuid'] ?? 'unknown';

            // 5. Build Filter Chain (Full EBU R128 Normalization Restored)
            $highpass = match ($network_type) {
                '2g', 'slow-2g' => 'highpass=f=100,lowpass=f=4000',
                '3g'            => 'highpass=f=80,lowpass=f=7000',
                default         => 'highpass=f=60',
            };

            // Pass 1: Loudness Scan
            $cmd_scan = sprintf(
                '%s -hide_banner -nostats -i %s -af "loudnorm=I=-23:LRA=7:tp=-2:print_format=json" -f null - 2>&1',
                escapeshellarg($ffmpeg_path), escapeshellarg($source_path)
            );
            $scan_output = shell_exec($cmd_scan);

            preg_match('/\{.*\}/s', $scan_output ?: '', $matches);
            $loudness_data = json_decode($matches[0] ?? '{}', true);

            $loudnorm_filter = sprintf(
                'loudnorm=I=-23:LRA=7:tp=-2:measured_I=%s:measured_LRA=%s:measured_tp=%s:measured_thresh=%s:offset=%s',
                $loudness_data['input_i'] ?? -23, $loudness_data['input_lra'] ?? 7,
                $loudness_data['input_tp'] ?? -2, $loudness_data['input_thresh'] ?? -70,
                $loudness_data['target_offset'] ?? 0
            );
            $full_filter = "$highpass,$loudnorm_filter";

            // 6. Define Output Paths
            $mp3_path = $output_dir . '/' . $post_id . '_master.mp3';
            $wav_path = $output_dir . '/' . $post_id . '_archival.wav';
            $ffmpeg_meta = sprintf('-metadata comment=%s', escapeshellarg("Source: Starmus | Profile: $network_type | Session: $session_uuid"));

            // 7. Transcode (Pass 2)
            $log = ["Loudness Scan:\n" . $scan_output];
            
            $cmd_mp3 = sprintf(
                '%s -hide_banner -y -i %s -ar %d -b:a %s -ac 1 -af "%s" %s %s 2>&1',
                escapeshellarg($ffmpeg_path), escapeshellarg($source_path),
                $sample_rate, escapeshellarg($bitrate), $full_filter, $ffmpeg_meta, escapeshellarg($mp3_path)
            );
            $log[] = "---\nMP3 Command:\n" . $cmd_mp3 . "\nOutput:\n" . shell_exec($cmd_mp3);

            $cmd_wav = sprintf(
                '%s -hide_banner -y -i %s -ar %d -ac 1 -sample_fmt s16 -af "%s" %s %s 2>&1',
                escapeshellarg($ffmpeg_path), escapeshellarg($source_path),
                $sample_rate, $full_filter, $ffmpeg_meta, escapeshellarg($wav_path)
            );
            $log[] = "---\nWAV Command:\n" . $cmd_wav . "\nOutput:\n" . shell_exec($cmd_wav);

            // 8. ID3 Tagging (Full Payload Restored)
            $post = get_post($post_id);
            if (!$post) throw new \RuntimeException('Post not found for ID: ' . $post_id);
            
            $author_name = get_the_author_meta('display_name', (int) $post->post_author) ?: get_bloginfo('name');
            $tag_data = $this->build_tag_payload($post, $author_name, get_bloginfo('name'), $post_id);
            $this->id3_service->writeTags($mp3_path, $tag_data, $post_id);

            // 9. Import to Media Library
            $mp3_id = $this->import_to_media_library($mp3_path, $post_id, 'audio/mpeg');
            $wav_id = $this->import_to_media_library($wav_path, $post_id, 'audio/wav');
            if ($mp3_id === 0) throw new \RuntimeException('Failed to import MP3 to Media Library.');

            // 10. Waveform
            $this->waveform_service->generate_waveform_data($wav_id ?: $mp3_id, $post_id);

            // 11. Update Post Meta
            update_post_meta($post_id, 'mastered_mp3', $mp3_id);
            update_post_meta($post_id, 'archival_wav', $wav_id);
            update_post_meta($post_id, 'processing_log', implode("\n", $log));

            return true;
        } catch (\Throwable $throwable) {
            error_log($throwable->getMessage());
            update_post_meta($post_id, 'processing_log', "CRITICAL ERROR:\n" . $throwable->getMessage());
            return false;
        } finally {
            // Cleanup temp file downloaded from Cloudflare
            if ($is_temp_file && $source_path && file_exists($source_path)) {
                @unlink($source_path);
            }
        }
    }

    private function import_to_media_library(string $filepath, int $parent_post_id, string $mime_type): int
    {
        if (!file_exists($filepath)) return 0;
        $filename = basename($filepath);
        $attachment = ['post_mime_type' => $mime_type, 'post_title' => $filename, 'post_status' => 'inherit'];
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
     * Build ID3 metadata payload (Restored).
     */
    private function build_tag_payload(\WP_Post $post, string $author_name, string $site_name, int $post_id): array
    {
        $recorded_at = (string) get_post_meta($post_id, 'session_date', true);
        $year        = $recorded_at ? substr($recorded_at, 0, 4) : date('Y');

        $tag_data = [
            'title'             => [sanitize_text_field($post->post_title)],
            'artist'            => [sanitize_text_field($author_name)],
            'album'             => [$site_name . ' Archives'],
            'year'              => [$year],
            'comment'           => ['Recorded via Starmus | Post ID: ' . $post_id],
            'copyright_message' => ['© ' . date('Y') . ' ' . $site_name . '. All rights reserved.'],
            'publisher'         => [$site_name],
        ];

        $language_term = get_the_terms($post_id, 'language');
        if (\is_array($language_term) && !empty($language_term)) {
            $tag_data['language'] = [sanitize_text_field($language_term[0]->name)];
        }
        return $tag_data;
    }
}