<?php

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\services;

if (! \defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

/**
 * Unified audio post-processing service.
 * Integrated with StarmusFileService for Cloudflare/Offload compatibility.
 * PHP 8.2 Optimized.
 */
class StarmusPostProcessingService
{
    private readonly StarmusAudioRecorderDAL $dal;
    private readonly StarmusWaveformService $waveform_service;
    private readonly StarmusId3Service $id3_service;
    private readonly StarmusFileService $file_service;

    public function __construct()
    {
        $this->dal = new StarmusAudioRecorderDAL();
        // Pass the file service to the waveform service too
        $this->file_service = new StarmusFileService();
        $this->waveform_service = new StarmusWaveformService(null, $this->file_service);
        $this->id3_service = new StarmusId3Service();
    }

    public function process(int $post_id, int $attachment_id, array $params = []): bool
    {
        StarmusLogger::setCorrelationId();
        StarmusLogger::timeStart('audio_process');

        $source_path = null;
        $is_temp_file = false;

        try {
            // 1. GET LOCAL SOURCE (Critical for Offloaded Files)
            $source_path = $this->file_service->get_local_copy($attachment_id);
            
            if (! $source_path || ! file_exists($source_path)) {
                throw new \RuntimeException('Source file could not be retrieved locally for attachment: ' . $attachment_id);
            }

            // Check if this is a temp download we need to cleanup later
            $attached_path = get_attached_file($attachment_id);
            if ($source_path !== $attached_path) {
                $is_temp_file = true;
            }

            // 2. Output Directory
            $uploads    = wp_upload_dir();
            $output_dir = trailingslashit($uploads['basedir']) . 'starmus_processed';
            if (! is_dir($output_dir)) wp_mkdir_p($output_dir);

            // 3. FFmpeg Check
            $ffmpeg_path = $this->dal->get_ffmpeg_path() ?: trim((string)shell_exec('command -v ffmpeg'));
            if (empty($ffmpeg_path)) {
                update_post_meta($post_id, 'processing_log', 'FFmpeg binary not found. Processing skipped.');
                return false;
            }

            // 4. Params
            $network_type = $params['network_type'] ?? '4g';
            $sample_rate  = (int)($params['samplerate'] ?? 44100);
            $bitrate      = $params['bitrate'] ?? '192k';
            $session_uuid = $params['session_uuid'] ?? 'unknown';

            // 5. Filters
            $highpass = match ($network_type) {
                '2g', 'slow-2g' => 'highpass=f=100,lowpass=f=4000',
                '3g'            => 'highpass=f=80,lowpass=f=7000',
                default         => 'highpass=f=60',
            };

            // Loudness Normalization
            $cmd_scan = sprintf(
                '%s -hide_banner -nostats -i %s -af "loudnorm=I=-23:LRA=7:tp=-2:print_format=json" -f null - 2>&1',
                escapeshellarg($ffmpeg_path),
                escapeshellarg($source_path)
            );
            $scan_output = shell_exec($cmd_scan);
            preg_match('/\{.*\}/s', $scan_output ?: '', $matches);
            $loudness = json_decode($matches[0] ?? '{}', true);

            $loudnorm = sprintf(
                'loudnorm=I=-23:LRA=7:tp=-2:measured_I=%s:measured_LRA=%s:measured_tp=%s:measured_thresh=%s:offset=%s',
                $loudness['input_i'] ?? -23,
                $loudness['input_lra'] ?? 7,
                $loudness['input_tp'] ?? -2,
                $loudness['input_thresh'] ?? -70,
                $loudness['target_offset'] ?? 0
            );

            $filter = "$highpass,$loudnorm";

            // 6. Output Files
            $mp3_path = $output_dir . '/' . $post_id . '_master.mp3';
            $wav_path = $output_dir . '/' . $post_id . '_archival.wav';

            $meta_arg = sprintf('-metadata comment=%s', escapeshellarg("Starmus|Profile:$network_type|UUID:$session_uuid"));

            // 7. Transcode
            // MP3
            $cmd_mp3 = sprintf(
                '%s -y -i %s -ar %d -b:a %s -ac 1 -af "%s" %s %s 2>&1',
                escapeshellarg($ffmpeg_path), escapeshellarg($source_path),
                $sample_rate, escapeshellarg($bitrate),
                $filter, $meta_arg, escapeshellarg($mp3_path)
            );
            $log[] = shell_exec($cmd_mp3);

            // WAV
            $cmd_wav = sprintf(
                '%s -y -i %s -ar %d -ac 1 -sample_fmt s16 -af "%s" %s %s 2>&1',
                escapeshellarg($ffmpeg_path), escapeshellarg($source_path),
                $sample_rate, $filter, $meta_arg, escapeshellarg($wav_path)
            );
            $log[] = shell_exec($cmd_wav);

            // 8. ID3 Tags
            $post = get_post($post_id);
            if ($post) {
                $author = get_the_author_meta('display_name', $post->post_author) ?: 'Starmus User';
                $tags = [
                    'title'   => [$post->post_title],
                    'artist'  => [$author],
                    'album'   => [get_bloginfo('name') . ' Archives'],
                    'year'    => [date('Y')],
                    'comment' => ["Starmus ID: $post_id"]
                ];
                $this->id3_service->writeTags($mp3_path, $tags, $post_id);
            }

            // 9. Import to Media Library (Standard WP hooks will handle offloading these new files)
            $mp3_id = $this->import_media($mp3_path, $post_id, 'audio/mpeg');
            $wav_id = $this->import_media($wav_path, $post_id, 'audio/wav');

            // 10. Waveform (Optional)
            // Use the newly created WAV ID if available, otherwise use original attachment
            $this->waveform_service->generate_waveform_data($wav_id ?: $attachment_id, $post_id);

            // 11. Save Metadata
            update_post_meta($post_id, 'mastered_mp3', $mp3_id);
            update_post_meta($post_id, 'archival_wav', $wav_id);
            update_post_meta($post_id, 'processing_log', implode("\n", $log));

            return true;

        } catch (\Throwable $e) {
            error_log('Processing Error: ' . $e->getMessage());
            update_post_meta($post_id, 'processing_log', "ERROR: " . $e->getMessage());
            return false;
        } finally {
            // CRITICAL: Cleanup temp file if we downloaded it from Cloudflare
            if ($is_temp_file && $source_path && file_exists($source_path)) {
                @unlink($source_path);
            }
            StarmusLogger::timeEnd('audio_process');
        }
    }

    private function import_media(string $path, int $parent, string $mime): int
    {
        if (!file_exists($path)) return 0;
        
        // Deduplication check
        $existing = get_posts([
            'post_type' => 'attachment',
            'meta_key' => '_starmus_source_file',
            'meta_value' => basename($path),
            'posts_per_page' => 1
        ]);
        
        if (!empty($existing)) return $existing[0]->ID;

        $attach_id = wp_insert_attachment([
            'post_mime_type' => $mime,
            'post_title'     => basename($path),
            'post_status'    => 'inherit'
        ], $path, $parent);

        if (!is_wp_error($attach_id)) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            // This function triggers 'wp_update_attachment_metadata' which offloaders listen to
            $data = wp_generate_attachment_metadata($attach_id, $path);
            wp_update_attachment_metadata($attach_id, $data);
            update_post_meta($attach_id, '_starmus_source_file', basename($path));
            return $attach_id;
        }
        return 0;
    }
}