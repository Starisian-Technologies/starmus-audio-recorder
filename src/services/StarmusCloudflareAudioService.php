<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\services;

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

if ( ! \defined('ABSPATH')) {
    exit;
}

/**
 * Cloudflare R2 Compatible Audio Processor
 *
 * Works with StarmusFileService to process offloaded files.
 * Handles download -> process -> upload workflow for R2 storage.
 */
final class StarmusCloudflareAudioService
{
    private ?StarmusFileService $file_service = null;

    private ?StarmusEnhancedId3Service $id3_service = null;

    public function __construct(
        StarmusFileService $file_service,
        StarmusEnhancedId3Service $id3_service
    ) {
        $this->file_service = $file_service;
        $this->id3_service  = $id3_service;
    }

    /**
     * Process audio for African bandwidth - Cloudflare R2 compatible
     */
    public function processForAfrica(int $attachment_id): array
    {
        $results = [];

        // 1. Get local copy (downloads from R2 if needed)
        $local_path = $this->file_service->get_local_copy($attachment_id);
        if ( ! $local_path) {
            return ['error' => 'Cannot access file'];
        }

        try {
            // 2. Check if optimization needed
            if ( ! $this->id3_service->needsAfricaOptimization($local_path)) {
                return ['message' => 'No optimization needed'];
            }

            // 3. Create optimized versions
            $optimized = $this->createAfricaVersions($local_path);

            // 4. Upload each version to R2 and get attachment IDs
            foreach ($optimized as $quality => $temp_path) {
                if ($temp_path && file_exists($temp_path)) {
                    $new_attachment_id = $this->uploadToWordPress($temp_path, $attachment_id, $quality);
                    if ($new_attachment_id) {
                        $results[ $quality ] = [
                         'attachment_id' => $new_attachment_id,
                         'url'           => $this->file_service->star_get_public_url($new_attachment_id),
                         'size'          => filesize($temp_path),
                        ];
                    }

                    unlink($temp_path); // Clean up temp file
                }
            }

            StarmusLogger::info(
                'Africa optimization completed',
                ['component' => self::class]
            );

        } finally {
            // Clean up original temp file if it was downloaded
            if ($local_path !== get_attached_file($attachment_id)) {
                unlink($local_path);
            }
        }

        return $results;
    }

    /**
     * Create bandwidth-optimized versions
     */
    private function createAfricaVersions(string $input_path): array
    {
        $base_name = pathinfo($input_path, PATHINFO_FILENAME);
        $temp_dir  = sys_get_temp_dir();

        $versions = [
        'africa_2g'   => ['-b:a', '32k', '-ar', '16000', '-ac', '1'],
        'africa_3g'   => ['-b:a', '48k', '-ar', '22050', '-ac', '1'],
        'africa_wifi' => ['-b:a', '64k', '-ar', '44100', '-ac', '1'],
        ];

        $results = [];
        foreach ($versions as $quality => $params) {
            $output_path = \sprintf('%s/%s_%s.mp3', $temp_dir, $base_name, $quality);

            if ($this->convertAudio($input_path, $output_path, $params)) {
                // Copy metadata to optimized version
                $this->copyMetadata($input_path, $output_path);
                $results[ $quality ] = $output_path;
            }
        }

        return $results;
    }

    /**
     * Convert audio using FFmpeg
     */
    private function convertAudio(string $input, string $output, array $params): bool
    {
        $cmd = implode(
            ' ',
            array_merge(
                ['ffmpeg -y -i', escapeshellarg($input)],
                $params,
                ['-f mp3', escapeshellarg($output), '2>/dev/null']
            )
        );

        exec($cmd, $out, $code);
        return $code === 0 && file_exists($output);
    }

    /**
     * Copy metadata using getID3
     */
    private function copyMetadata(string $source, string $destination): void
    {
        $analysis = $this->id3_service->analyzeFile($source);

        if ( ! empty($analysis['comments'])) {
            $tags = [];
            foreach ($analysis['comments'] as $key => $values) {
                if ( ! empty($values[0])) {
                    $tags[ $key ] = $values;
                }
            }

            // Add Africa optimization marker
            $tags['comment'] = [($tags['comment'][0] ?? '') . ' [Africa-Optimized]'];

            $this->id3_service->writeTags($destination, $tags);
        }
    }

    /**
     * Upload processed file to WordPress (will auto-offload to R2)
     */
    private function uploadToWordPress(string $temp_path, int $parent_id, string $quality): ?int
    {
        if ( ! \function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $file_array = [
        'name'     => basename($temp_path),
        'tmp_name' => $temp_path,
        'type'     => 'audio/mpeg',
        'size'     => filesize($temp_path),
        ];

        $attachment_id = media_handle_sideload($file_array, $parent_id);

        if (is_wp_error($attachment_id)) {
            StarmusLogger::error(
                'Upload failed',
                [
            'component' => self::class,
            'post_id'   => $parent_id,
            'quality'   => $quality,
                ]
            );
            return null;
        }

        // Set title to indicate quality
        wp_update_post(
            [
        'ID'         => $attachment_id,
        'post_title' => get_the_title($parent_id) . \sprintf(' (%s)', $quality),
            ]
        );

        return $attachment_id;
    }

    /**
     * Get data usage estimates for African networks
     */
    public function getAfricaDataEstimate(int $attachment_id): array
    {
        $local_path = $this->file_service->get_local_copy($attachment_id);
        if ( ! $local_path) {
            return [];
        }

        $size_mb = filesize($local_path) / (1024 * 1024);

        // Clean up if temp file
        if ($local_path !== get_attached_file($attachment_id)) {
            unlink($local_path);
        }

        return [
        'original_size_mb'         => round($size_mb, 2),
        'estimated_2g_size_mb'     => round($size_mb * 0.15, 2), // ~85% reduction
        'estimated_cost_usd'       => round($size_mb * 0.15, 2), // Gambia data rates
        'download_time_2g_seconds' => round($size_mb / 0.03, 0), // 30KB/s
        'recommended_version'      => $size_mb > 5 ? '2g' : ($size_mb > 2 ? '3g' : 'wifi'),
        ];
    }
}
