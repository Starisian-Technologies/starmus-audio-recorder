<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\services;

use Aws\S3\S3Client;
use Exception;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

if (! \defined('ABSPATH')) {
    exit;
}

/**
 * Direct S3-Compatible (R2/AWS) Audio Service
 *
 * Minimal SDK-based S3/R2 control for bandwidth optimization versions.
 * Bypasses WordPress plugins for direct storage management of optimized assets.
 * Supports both Cloudflare R2 (primary) and AWS S3 (backup/alternative).
 */
final class StarmusR2DirectService
{
    private S3Client $storage_client;

    private string $bucket;

    private string $public_endpoint;

    private ?StarmusId3Service $id3_service = null;

    public function __construct(StarmusId3Service $id3_service)
    {
        try {
            $this->id3_service = $id3_service;

            // Detect Provider: Defaults to R2, checks for S3 override
            $provider = \defined('STARMUS_STORAGE_PROVIDER') ? STARMUS_STORAGE_PROVIDER : 'r2'; // 'r2' or 'aws'

            if ($provider === 'aws') {
                $this->configureAws();
            } else {
                $this->configureR2();
            }
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
    }

    private function configureR2(): void
    {
        $this->bucket = \defined('STARMUS_R2_BUCKET') ? STARMUS_R2_BUCKET : 'starmus-audio';
        $account_id   = \defined('STARMUS_R2_ACCOUNT_ID') ? STARMUS_R2_ACCOUNT_ID : '';

        $this->public_endpoint = \defined('STARMUS_R2_ENDPOINT') ? STARMUS_R2_ENDPOINT : '';

        $this->storage_client = new S3Client([
        'version'     => 'latest',
        'region'      => 'auto',
        'endpoint'    => "https://{$account_id}.r2.cloudflarestorage.com",
        'credentials' => [
        'key'    => \defined('STARMUS_R2_ACCESS_KEY') ? STARMUS_R2_ACCESS_KEY : '',
        'secret' => \defined('STARMUS_R2_SECRET_KEY') ? STARMUS_R2_SECRET_KEY : '',
        ],
        'use_path_style_endpoint' => true,
        ]);
    }

    private function configureAws(): void
    {
        $this->bucket = \defined('STARMUS_S3_BUCKET') ? STARMUS_S3_BUCKET : '';
        $region       = \defined('STARMUS_S3_REGION') ? STARMUS_S3_REGION : 'us-east-1';

        // AWS Public Endpoint construction or Custom Domain
        $this->public_endpoint = \defined('STARMUS_S3_ENDPOINT')
        ? STARMUS_S3_ENDPOINT
        : "https://{$this->bucket}.s3.{$region}.amazonaws.com/";

        $this->storage_client = new S3Client([
        'version'     => 'latest',
        'region'      => $region,
        'credentials' => [
        'key'    => \defined('STARMUS_S3_ACCESS_KEY') ? STARMUS_S3_ACCESS_KEY : '',
        'secret' => \defined('STARMUS_S3_SECRET_KEY') ? STARMUS_S3_SECRET_KEY : '',
        ],
        ]);
    }

    /**
     * Process audio for African networks with direct Storage control
     */
    public function processAfricaAudio(string $local_path, int $post_id): array
    {
        if (! $this->id3_service->needsAfricaOptimization($local_path)) {
            return ['message' => 'No optimization needed'];
        }

        $results   = [];
        $base_name = pathinfo($local_path, PATHINFO_FILENAME);

        // Create optimized versions
        $versions = [
        '2g'   => ['-b:a', '32k', '-ar', '16000', '-ac', '1'],
        '3g'   => ['-b:a', '48k', '-ar', '22050', '-ac', '1'],
        'wifi' => ['-b:a', '64k', '-ar', '44100', '-ac', '1'],
        ];

        foreach ($versions as $quality => $params) {
            $temp_file = $this->createOptimizedVersion($local_path, $params);

            if ($temp_file) {
                // Upload directly to Storage (R2/S3)
                $key = \sprintf('audio/%d/%s_%s.mp3', $post_id, $base_name, $quality);
                $url = $this->uploadToStorage($temp_file, $key);

                if ($url) {
                    $results[$quality] = [
                     'url'     => $url,
                     'size_mb' => round(filesize($temp_file) / (1024 * 1024), 2),
                     'key'     => $key,
                    ];
                }

                unlink($temp_file);
            }
        }

        return $results;
    }

    /**
     * Create optimized audio version
     */
    private function createOptimizedVersion(string $input, array $params): ?string
    {
        $temp_file = tempnam(sys_get_temp_dir(), 'starmus_africa_') . '.mp3';

        $cmd = implode(
            ' ',
            array_merge(
                ['ffmpeg -y -i', escapeshellarg($input)],
                $params,
                ['-f mp3', escapeshellarg($temp_file), '2>/dev/null']
            )
        );

        exec($cmd, $output, $code);

        if ($code === 0 && file_exists($temp_file)) {
            // Copy metadata
            $this->copyMetadata($input, $temp_file);
            return $temp_file;
        }

        return null;
    }

    /**
     * Upload file directly to Cloudflare R2 or AWS S3
     */
    private function uploadToStorage(string $file_path, string $key): ?string
    {
        try {
            $result = $this->storage_client->putObject(
                [
            'Bucket'       => $this->bucket,
            'Key'          => $key,
            'Body'         => fopen($file_path, 'rb'),
            'ContentType'  => 'audio/mpeg',
            'CacheControl' => 'public, max-age=31536000', // 1 year cache
            'Metadata'     => [
            'starmus-optimized' => 'africa',
            'created'           => date('c'),
            ],
            ]
            );

            // Return public URL based on provider configuration
            return trailingslashit($this->public_endpoint) . $key;
        } catch (Exception) {
            StarmusLogger::error(
                'Upload failed',
                ['component' => self::class, 'key' => $key]
            );
            return null;
        }
    }

    /**
     * Copy metadata using getID3
     */
    private function copyMetadata(string $source, string $destination): void
    {
        try {
            $analysis = $this->id3_service->analyzeFile($source);

            if (! empty($analysis['comments'])) {
                $tags = [];
                foreach ($analysis['comments'] as $key => $values) {
                    if (! empty($values[0])) {
                        $tags[$key] = $values;
                    }
                }

                $tags['comment'] = [($tags['comment'][0] ?? '') . ' [R2-Africa]'];
                $this->id3_service->writeTags($destination, $tags);
            }
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
    }

    /**
     * Get bandwidth estimates for Africa
     */
    public function getAfricaEstimates(string $file_path): array
    {
        $size_mb = filesize($file_path) / (1024 * 1024);

        return [
        'original_mb'       => round($size_mb, 2),
        'africa_2g_mb'      => round($size_mb * 0.15, 2), // 85% reduction
        'cost_savings_usd'  => round($size_mb * 0.13, 2), // Gambia rates
        'bandwidth_savings' => '85%',
        ];
    }
}
