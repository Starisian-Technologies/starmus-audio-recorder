<?php

/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * StarmusWaveformService (DAL-integrated)
 * --------------------------------
 * Generates waveform JSON data using the audiowaveform CLI tool.
 *
 * @package Starisian\Sparxstar\Starmus\services
 * @version 6.6.0-ROBUST-FIX
 */
namespace Starisian\Sparxstar\Starmus\services;

if (! \defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

// FIX: Removed 'readonly' for PHP < 8.2 compatibility
final class StarmusWaveformService
{
    private $dal;
    private $files;

    public function __construct(?StarmusAudioRecorderDAL $dal = null, ?StarmusFileService $file_service = null)
    {
        $this->dal   = $dal ?: new StarmusAudioRecorderDAL();
        $this->files = $file_service ?: new StarmusFileService();
    }

    private function get_config(): array
    {
        $defaults = [
            'pixels_per_second' => 100,
            'bits'              => 8,
            'output_format'     => 'json',
        ];
        return apply_filters('starmus_waveform_config', $defaults);
    }

    public function is_tool_available(): bool
    {
        $path = trim((string) shell_exec('command -v audiowaveform'));
        return !empty($path);
    }

    /**
     * MAIN ENTRYPOINT
     * Generates waveform JSON and stores it on the parent recording.
     * 
     * @param int $attachment_id The audio file attachment ID.
     * @param int|null $explicit_parent_id Optional parent ID if known (prevents lookup failure).
     */
    public function generate_waveform_data(int $attachment_id, ?int $explicit_parent_id = null): bool
    {
        StarmusLogger::setCorrelationId();
        
        // 1. Tool Check
        if (!$this->is_tool_available()) {
            StarmusLogger::warning('StarmusWaveformService', 'audiowaveform binary missing. Skipping.');
            return false;
        }

        // 2. Resolve Parent ID
        $recording_id = $explicit_parent_id ?: (int) get_post_meta($attachment_id, '_parent_recording_id', true);
        
        if ($recording_id <= 0) {
            // Fallback: Check if attachment itself is parented
            $post = get_post($attachment_id);
            if ($post && $post->post_parent > 0) {
                $recording_id = $post->post_parent;
            } else {
                error_log('Waveform Gen Failed: Missing parent recording reference for attachment: ' . $attachment_id);
                return false;
            }
        }

        // 3. Skip if exists
        $existing = get_post_meta($recording_id, 'waveform_json', true);
        if (!empty($existing)) {
            return true;
        }

        // 4. Get File Path (FIXED SYNTAX)
        $file_path = $this->files->get_local_copy($attachment_id);
        
        if (!$file_path || !file_exists($file_path)) {
            // Fallback to standard WP path
            $file_path = get_attached_file($attachment_id);
        }

        if (!$file_path || !file_exists($file_path)) {
            error_log('Audio file not found: ' . $attachment_id);
            return false;
        }

        // 5. Generate
        $data = $this->extract_waveform_from_file($file_path);
        if (!$data || empty($data['data'])) {
            error_log('Waveform extraction returned empty data: ' . $attachment_id);
            return false;
        }

        // 6. Save
        try {
            // Save as JSON string
            $json_str = wp_json_encode($data['data']);
            
            // Save to Post Meta (Standard)
            update_post_meta($recording_id, 'waveform_json', $json_str);
            
            // Update ACF if available
            if (function_exists('update_field')) {
                update_field('waveform_json', $json_str, $recording_id);
            }

            StarmusLogger::info('StarmusWaveformService', 'Waveform saved.', ['id' => $recording_id]);
            return true;

        } catch (\Throwable $e) {
            error_log('Waveform Save Error: ' . $e->getMessage());
            return false;
        }
    }

    private function extract_waveform_from_file(string $file_path): ?array
    {
        $config = $this->get_config();
        
        // Ensure temp dir is writable
        $temp_dir = sys_get_temp_dir();
        $temp_json = $temp_dir . '/waveform-' . uniqid() . '.json';

        $cmd = sprintf(
            'audiowaveform -i %s -o %s --pixels-per-second %d --bits %d --output-format %s',
            escapeshellarg($file_path),
            escapeshellarg($temp_json),
            (int) $config['pixels_per_second'],
            (int) $config['bits'],
            escapeshellarg((string) $config['output_format'])
        );

        try {
            exec($cmd . ' 2>&1', $output, $code);

            if ($code !== 0 || !file_exists($temp_json)) {
                // If MP3 fails, try converting to WAV first (common issue with audiowaveform)
                throw new \RuntimeException('CLI Error: ' . implode("\n", $output));
            }

            $json = file_get_contents($temp_json);
            $data = json_decode($json, true);

            @unlink($temp_json); // Cleanup

            return [
                'data' => $data['data'] ?? [],
                'json_path' => $file_path . '.waveform.json'
            ];

        } catch (\Throwable $e) {
            error_log('Waveform CLI Error: ' . $e->getMessage());
            if (file_exists($temp_json)) @unlink($temp_json);
            return null;
        }
    }
}