<?php

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\services;

if (! \defined('ABSPATH')) {
    exit;
}

// REMOVED: use getID3;
// REMOVED: use getid3_lib;
// REMOVED: use getid3_writetags;

// KEPT:
use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

// ADDED: The new, dedicated ID3 service

/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * Unified audio post-processing service.
 *
 * This service combines multiple audio processing operations into a single atomic workflow:
 * - Audio transcoding with FFmpeg (MP3 and WAV generation)
 * - EBU R128 loudness normalization for broadcast standards
 * - Adaptive frequency filtering based on network conditions
 * - ID3 metadata tagging for proper audio file identification
 * - Waveform data generation for visual playback interfaces
 * - WordPress media library integration
 *
 * The service processes uploaded audio recordings through a comprehensive pipeline
 * that ensures consistent quality and metadata across different network conditions
 * while maintaining broadcast-quality standards.
 *
 * @package Starisian\Sparxstar\Starmus\services
 *
 * @since   0.1.0
 * <<<<<<< HEAD
 *
 * @version 0.9.2 (Unified)
 * =======
 * @version 0.9.2 (Unified)
 * >>>>>>> 1098d442 (fix: Use working auto-fixers in GitHub workflows)
 */
class StarmusPostProcessingService
{
    /**
     * Data Access Layer instance for database operations.
     *
     * Provides access to plugin configuration, FFmpeg paths, and other
     * database-stored settings required for audio processing.
     *
     * @since 0.1.0
     *
     * @var StarmusAudioRecorderDAL
     */
    private readonly StarmusAudioRecorderDAL $dal;

    /**
     * Waveform generation service instance.
     *
     * Handles the creation of visual waveform data from processed audio files
     * for use in frontend playback interfaces and audio visualization.
     *
     * @since 0.1.0
     *
     * @var StarmusWaveformService
     */
    private readonly StarmusWaveformService $waveform_service;

    /**
     * ID3 metadata tagging service instance.
     *
     * Manages the application of ID3 tags to processed audio files,
     * including artist, title, album, and custom metadata fields.
     *
     * @since 2.0.0
     *
     * @var StarmusID3Service
     */
    private readonly StarmusID3Service $id3_service;

    /**
     * Initialize the post-processing service with required dependencies.
     *
     * Sets up the Data Access Layer, waveform generation service, and ID3 tagging service.
     * All dependencies are marked as readonly to ensure immutability after construction.
     *
     * @since 0.1.0
     */
    public function __construct()
    {
        $this->dal              = new StarmusAudioRecorderDAL();
        $this->waveform_service = new StarmusWaveformService();
        // INITIALIZE: The new service that handles all ID3 logic
        $this->id3_service = new StarmusID3Service();
    }

    /**
     * Main entry point for processing an audio recording.
     *
     * Executes a comprehensive audio processing pipeline:
     * 1. Validates source file existence and accessibility
     * 2. Prepares output directory structure
     * 3. Resolves FFmpeg binary path
     * 4. Determines encoding parameters based on network conditions
     * 5. Builds adaptive filter chain (highpass + EBU R128 normalization)
     * 6. Performs two-pass loudness analysis and normalization
     * 7. Transcodes to MP3 and WAV formats
     * 8. Applies ID3 metadata tags
     * 9. Imports processed files to WordPress media library
     * 10. Generates waveform visualization data
     * 11. Updates post metadata with processing results
     *
     * The process is atomic - either all steps succeed or the entire operation fails.
     * All operations are logged for debugging and audit purposes.
     *
     * @since 0.1.0
     *
     * @param int $post_id The 'audio-recording' post ID to process.
     * @param int $attachment_id The WordPress attachment ID of the uploaded 'original' file.
     * @param array $params Context parameters including:
     *                      - network_type: '2g', '3g', '4g' for adaptive filtering
     *                      - bitrate: Target MP3 bitrate (default: '192k')
     *                      - samplerate: Target sample rate (default: 44100)
     *                      - session_uuid: Unique session identifier for logging
     *
     * @return bool True on successful processing, false on any error.
     */
    public function process(int $post_id, int $attachment_id, array $params = []): bool
    {
        StarmusLogger::setCorrelationId();
        StarmusLogger::timeStart('audio_process');

        try {
            // 1. Validate Source
            $source_path = get_attached_file($attachment_id);
            if (! $source_path || ! file_exists($source_path)) {
                throw new \RuntimeException('Source file missing for attachment ID: ' . $attachment_id);
            }

            // 2. Prepare Output Directory
            $uploads    = wp_upload_dir();
            $output_dir = trailingslashit($uploads['basedir']) . 'starmus_processed';
            if (! is_dir($output_dir)) {
                wp_mkdir_p($output_dir);
            }

            // 3. Resolve FFmpeg
            $ffmpeg_path = $this->dal->get_ffmpeg_path();
            if (!$ffmpeg_path) {
                // Fallback for local dev environments where 'ffmpeg' is in PATH
                $ffmpeg_path = trim(shell_exec('command -v ffmpeg') ?: '');
            }

            if ($ffmpeg_path === '' || $ffmpeg_path === '0') {
                throw new \RuntimeException('FFmpeg binary not found on server.');
            }

            // 4. Determine Encoding Parameters
            $network_type = $params['network_type'] ?? '4g';
            $sample_rate  = \intval($params['samplerate'] ?? 44100);
            $bitrate      = $params['bitrate']      ?? '192k';
            $session_uuid = $params['session_uuid'] ?? 'unknown';

            // 5. Build Filter Chain (Adaptive Highpass + Loudness Normalization)
            $highpass = match ($network_type) {
                '2g', 'slow-2g' => 'highpass=f=100,lowpass=f=4000',
                '3g'    => 'highpass=f=80,lowpass=f=7000',
                default => 'highpass=f=60',
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
            $loudness_data = empty($matches[0]) ? [] : json_decode($matches[0], true);

            $loudnorm_filter = \sprintf(
                'loudnorm=I=-23:LRA=7:tp=-2:measured_I=%s:measured_LRA=%s:measured_tp=%s:measured_thresh=%s:offset=%s',
                $loudness_data['input_i']       ?? -23,
                $loudness_data['input_lra']     ?? 7,
                $loudness_data['input_tp']      ?? -2,
                $loudness_data['input_thresh']  ?? -70,
                $loudness_data['target_offset'] ?? 0
            );

            $full_filter = \sprintf('%s,%s', $highpass, $loudnorm_filter);

            // 6. Define Output Paths
            $mp3_filename = $post_id . '_master.mp3';
            $wav_filename = $post_id . '_archival.wav';
            $mp3_path     = $output_dir . '/' . $mp3_filename;
            $wav_path     = $output_dir . '/' . $wav_filename;

            // Metadata Tags for FFmpeg
            $ffmpeg_meta = \sprintf(
                '-metadata comment=%s',
                escapeshellarg(\sprintf('Source: Starmus | Profile: %s | Session: %s', $network_type, $session_uuid))
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

            // --- ID3 TAGGING: PREPARE DATA AND DELEGATE TO SERVICE ---
            $post = get_post($post_id);
            if (! $post instanceof \WP_Post) {
                throw new \RuntimeException('Post not found for ID: ' . $post_id);
            }

            $author_name = get_the_author_meta('display_name', (int) $post->post_author) ?: get_bloginfo('name');
            $site_name   = get_bloginfo('name');
            $tag_data    = $this->build_tag_payload($post, $author_name, $site_name, $post_id);

            // 8. Apply ID3 Tags (to the MP3) - CALLS DEDICATED SERVICE
            $this->id3_service->writeTags($mp3_path, $tag_data, $post_id);

            // 9. Import Results to WordPress Media Library
            $mp3_id = $this->import_to_media_library($mp3_path, $post_id, 'audio/mpeg');
            $wav_id = $this->import_to_media_library($wav_path, $post_id, 'audio/wav');

            if ($mp3_id === 0) {
                throw new \RuntimeException('Failed to import MP3 to Media Library.');
            }

            // 10. Generate Waveform Data
            // We use the WAV file for faster processing if available, otherwise MP3
            $waveform_source_id = $wav_id ?: $mp3_id;
            $this->waveform_service->generate_waveform_data($waveform_source_id);
            // Copy waveform data to parent post meta for easy access
            $waveform_json = get_post_meta($waveform_source_id, '_waveform_data', true);
            if ($waveform_json) {
                update_post_meta(
                    $post_id,
                    'waveform_json',
                    \is_string($waveform_json) ? $waveform_json : wp_json_encode($waveform_json)
                );
            }

            // 11. Update Post Metadata (The "Verified Keys")
            update_post_meta($post_id, 'mastered_mp3', $mp3_id);
            update_post_meta($post_id, 'archival_wav', $wav_id);
            update_post_meta($post_id, 'processing_log', implode("\n", $log));

            // Legacy / Backup keys
            update_post_meta($post_id, '_audio_attachment_id', $attachment_id); // Keep link to original source

            // Also update ACF fields if plugin exists
            if (\function_exists('update_field')) {
                update_field('mastered_mp3', $mp3_id, $post_id);
                update_field('archival_wav', $wav_id, $post_id);
            }

            StarmusLogger::info('StarmusPostProcessing', 'Processing Complete', ['post_id' => $post_id, 'mp3_id' => $mp3_id]);
            return true;
        } catch (\Throwable $throwable) {
            error_log($throwable);
            update_post_meta($post_id, 'processing_log', "CRITICAL ERROR:\n" . $throwable->getMessage());
            return false;
        } finally {
            StarmusLogger::timeEnd('audio_process', 'StarmusPostProcessing');
        }
    }

    /**
     * Import a processed audio file to WordPress media library.
     *
     * Creates a new attachment entry in the WordPress media library for a processed
     * audio file, linking it to the parent recording post. Generates attachment
     * metadata and returns the new attachment ID.
     *
     * @since 0.1.0
     *
     * @param string $filepath Full filesystem path to the audio file to import.
     * @param int $parent_post_id Post ID of the parent 'audio-recording' post.
     * @param string $mime_type MIME type of the file ('audio/mpeg' or 'audio/wav').
     *
     * @return int WordPress attachment ID on success, 0 on failure.
     */
    private function import_to_media_library(string $filepath, int $parent_post_id, string $mime_type): int
    {
        // ... (body of method unchanged)
        if (! file_exists($filepath)) {
            return 0;
        }

        $filename   = basename($filepath);
        $attachment = [
            'post_mime_type' => $mime_type,
            'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attach_id = wp_insert_attachment($attachment, $filepath, $parent_post_id);

        if (! is_wp_error($attach_id)) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
            wp_update_attachment_metadata($attach_id, $attach_data);
            return $attach_id;
        }

        return 0;
    }

    /**
     * Build ID3 metadata payload using WordPress post data.
     *
     * Constructs a standardized array of ID3 tag data from WordPress post information.
     * Includes title, artist, album, year, copyright, and language metadata.
     * The payload is prepared for consumption by the ID3 tagging service.
     *
     * @since 0.1.0
     *
     * @param \WP_Post $post WordPress post object containing recording data.
     * @param string $author_name Display name of the recording author.
     * @param string $site_name WordPress site name for album and publisher fields.
     * @param int $post_id Post ID for reference in comment field.
     *
     * @return array Associative array of ID3 tag data with standardized structure:
     *               - title: Recording title from post title
     *               - artist: Author display name
     *               - album: Site name + " Archives"
     *               - year: Extracted from session_date or current year
     *               - comment: Starmus identifier with post ID
     *               - copyright_message: Site copyright notice
     *               - publisher: Site name
     *               - language: Recording language from taxonomy (if available)
     */
    private function build_tag_payload(\WP_Post $post, string $author_name, string $site_name, int $post_id): array
    {
        $recorded_at = (string) get_post_meta($post_id, 'session_date', true);
        $year        = $recorded_at !== '' ? substr($recorded_at, 0, 4) : date('Y');

        $tag_data = [
            'title'             => [sanitize_text_field($post->post_title)],
            'artist'            => [sanitize_text_field($author_name)],
            'album'             => [$site_name . ' Archives'],
            'year'              => [$year],
            'comment'           => ['Recorded via Starmus | Post ID: ' . $post_id],
            'copyright_message' => ['© ' . $site_name],
            'publisher'         => [$site_name],
        ];

        $language_term = get_the_terms($post_id, 'language');
        if (\is_array($language_term) && $language_term !== []) {
            $tag_data['language'] = [sanitize_text_field($language_term[0]->name ?? '')];
        }

        return $tag_data;
    }
}
