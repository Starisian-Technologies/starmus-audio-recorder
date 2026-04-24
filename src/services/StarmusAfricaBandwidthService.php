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

    /**
     * Approximate mobile data cost per MB (USD) by country ISO-3166-1 alpha-2.
     *
     * Sources: GSMA Intelligence, Alliance for Affordable Internet (A4AI) 2024 reports.
     * Costs reflect prepaid 1 GB bundles divided by 1024. Update annually.
     *
     * @var array<string, float>
     */
    private const COST_PER_MB = [
        'GM' => 0.15, // Gambia
        'SN' => 0.13, // Senegal
        'GH' => 0.10, // Ghana
        'NG' => 0.08, // Nigeria
        'KE' => 0.07, // Kenya
        'ZA' => 0.05, // South Africa
        'TZ' => 0.12, // Tanzania
        'UG' => 0.11, // Uganda
        'ET' => 0.18, // Ethiopia
        'CM' => 0.14, // Cameroon
        'CI' => 0.16, // Côte d'Ivoire
        'ML' => 0.20, // Mali
        'BF' => 0.22, // Burkina Faso
        'NE' => 0.25, // Niger
        'TD' => 0.28, // Chad
    ];

    /**
     * Default cost per MB (USD) used when a country code is not in the table.
     *
     * @var float
     */
    private const DEFAULT_COST_PER_MB = 0.15;

    public function __construct(?IStarmusAudioDAL $dal = null)
    {
        $dal = $dal ?: new StarmusAudioDAL();
        $this->ffmpeg_path = $dal->get_ffmpeg_path() ?: 'ffmpeg';
    }

    /**
     * Generate ultra-low bandwidth versions for African networks
     */
    public function createAfricaOptimized(string $input_path): array
    {
        $base_name = pathinfo($input_path, PATHINFO_FILENAME);
        $dir = \dirname($input_path);

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
     * Minimal conversion with aggressive compression.
     *
     * @param string   $input  Absolute path to the source file.
     * @param string   $output Absolute path for the output file.
     * @param string[] $params Additional FFmpeg flags (must be safe/pre-validated strings).
     */
    private function convert(string $input, string $output, array $params): ?string
    {
        $cmd = implode(
            ' ',
            array_merge(
                [escapeshellarg($this->ffmpeg_path), '-i', escapeshellarg($input)],
                $params,
                ['-f', 'mp3', escapeshellarg($output), '2>/dev/null']
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

        $cmd = [
            escapeshellarg($this->ffmpeg_path),
            '-i',
            escapeshellarg($input_path),
            '-t',
            (string) $duration,
            '-ac',
            '1',
            '-ar',
            '22050',
            '-b:a',
            '64k',
            escapeshellarg($output_path),
            '2>/dev/null',
        ];

        exec(implode(' ', $cmd), $out, $code);
        return $code === 0 ? $output_path : null;
    }

    /**
     * Estimate data usage for African data plans.
     *
     * @param string $file_path   Absolute path to the audio file.
     * @param string $country_iso ISO-3166-1 alpha-2 country code (e.g. 'GM', 'NG').
     *                            Defaults to the service default cost when omitted or unknown.
     * @return array{size_mb: float, cost_estimate_usd: float, download_time_2g: string, download_time_3g: string, recommended: string}
     */
    public function estimateDataUsage(string $file_path, string $country_iso = ''): array
    {
        if ( ! file_exists($file_path)) {
            return [];
        }

        $size_mb    = filesize($file_path) / (1024 * 1024);
        $country    = strtoupper(trim($country_iso));
        $cost_per_mb = self::COST_PER_MB[$country] ?? self::DEFAULT_COST_PER_MB;

        return [
        'size_mb'            => round($size_mb, 2),
        'cost_estimate_usd'  => round($size_mb * $cost_per_mb, 2),
        'download_time_2g'   => round($size_mb / 0.03, 0) . 's', // ~30 KB/s EDGE
        'download_time_3g'   => round($size_mb / 0.1, 0) . 's',  // ~100 KB/s HSPA
        'recommended'        => $size_mb > 5 ? '2g' : ($size_mb > 2 ? '3g' : 'wifi'),
        ];
    }
}
