<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\services;

use Starisian\Sparxstar\Starmus\data\interfaces\IStarmusAudioDAL;
use Starisian\Sparxstar\Starmus\data\StarmusAudioDAL;

if ( ! \defined('ABSPATH')) {
    exit;
}

/**
 * Bandwidth-Optimized Audio Service for Africa/Gambia
 *
 * Minimal FFmpeg wrapper focused on extreme bandwidth conservation.
 */
final class StarmusAfricaBandwidthService
{
    private string $ffmpeg_path;

    public function __construct(?IStarmusAudioDAL $dal = null)
    {
        $dal               = $dal ?: new StarmusAudioDAL();
        $this->ffmpeg_path = $dal->get_ffmpeg_path() ?: 'ffmpeg';
    }

    /**
     * Generate ultra-low bandwidth versions for African networks
     */
    public function createAfricaOptimized(string $input_path): array
    {
        $base_name = pathinfo($input_path, PATHINFO_FILENAME);
        $dir       = \dirname($input_path);

        return [
        // 2G networks (EDGE) - 32kbps, 16kHz
        'africa_2g' => $this->convert(
            $input_path,
            \sprintf('%s/%s_2g.mp3', $dir, $base_name),
            [
        '-b:a',
        '32k',
        '-ar',
        '16000',
        '-ac',
        '1',
            ]
        ),

        // 3G networks - 48kbps, 22kHz
        'africa_3g' => $this->convert(
            $input_path,
            \sprintf('%s/%s_3g.mp3', $dir, $base_name),
            [
        '-b:a',
        '48k',
        '-ar',
        '22050',
        '-ac',
        '1',
            ]
        ),

        // WiFi/4G - 64kbps, 44kHz
        'africa_wifi' => $this->convert(
            $input_path,
            \sprintf('%s/%s_wifi.mp3', $dir, $base_name),
            [
        '-b:a',
        '64k',
        '-ar',
        '44100',
        '-ac',
        '1',
            ]
        ),
        ];
    }

    /**
     * Minimal conversion with aggressive compression
     */
    private function convert(string $input, string $output, array $params): ?string
    {
        $cmd = implode(
            ' ',
            array_merge(
                [$this->ffmpeg_path, '-i', escapeshellarg($input)],
                $params,
                ['-f mp3', escapeshellarg($output), '2>/dev/null']
            )
        );

        exec($cmd, $out, $code);
        return $code === 0 ? $output : null;
    }

    /**
     * Generate a short preview clip (Pipeline 2 requirement)
     */
    public function generatePreviewClip(string $input_path, int $duration = 30): ?string
    {
        $output_path = \dirname($input_path) . '/' . pathinfo($input_path, PATHINFO_FILENAME) . '_preview.mp3';

        $cmd = [$this->ffmpeg_path, '-i', escapeshellarg($input_path), '-t', (string) $duration, '-ac', '1', '-ar', '22050', '-b:a', '64k', escapeshellarg($output_path), '2>/dev/null'];

        exec(implode(' ', $cmd), $out, $code);
        return $code === 0 ? $output_path : null;
    }

    /**
     * Estimate data usage for African data plans
     */
    public function estimateDataUsage(string $file_path): array
    {
        if ( ! file_exists($file_path)) {
            return [];
        }

        $size_mb = filesize($file_path) / (1024 * 1024);

        return [
        'size_mb'           => round($size_mb, 2),
        'cost_estimate_usd' => round($size_mb * 0.15, 2), // ~$0.15/MB in Gambia
        'download_time_2g'  => round($size_mb / 0.03, 0) . 's', // ~30KB/s
        'download_time_3g'  => round($size_mb / 0.1, 0) . 's',  // ~100KB/s
        'recommended'       => $size_mb > 5 ? '2g' : ($size_mb > 2 ? '3g' : 'wifi'),
        ];
    }
}
