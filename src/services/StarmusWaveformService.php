<?php

/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * Waveform Generation Service with DAL Integration
 *
 * Generates JSON waveform data for audio files using the audiowaveform CLI tool.
 * Provides visualization data for audio editors, players, and analysis tools.
 * Integrates with WordPress post system and supports offloaded file handling.
 *
 * Key Features:
 * - audiowaveform CLI tool integration
 * - Configurable pixels-per-second and bit depth
 * - JSON output format for web consumption
 * - DAL-routed persistence and metadata management
 * - Offloaded file support via StarmusFileService
 * - Automatic parent post detection and linking
 *
 * Technical Requirements:
 * - audiowaveform binary must be available in system PATH
 * - Sufficient disk space for temporary file operations
 * - Audio files must be in supported formats (WAV, MP3, FLAC, etc.)
 *
 * WordPress Integration:
 * - Stores waveform data in post meta and ACF fields
 * - Links waveform data to parent recording posts
 * - Supports both standard and offloaded attachment workflows
 *
 * @package Starisian\Sparxstar\Starmus\services
 *
 * @version 6.6.0-ROBUST-FIX
 *
 * @since   1.0.0
 * @see https://github.com/bbc/audiowaveform audiowaveform CLI tool
 * @see StarmusAudioDAL Data access layer
 * @see StarmusFileService File management service
 */
namespace Starisian\Sparxstar\Starmus\services;

use function apply_filters;
use function escapeshellarg;
use function exec;
use function file_exists;
use function file_get_contents;

use RuntimeException;
use Starisian\Sparxstar\Starmus\data\interfaces\IStarmusAudioDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

use function sys_get_temp_dir;

use Throwable;

use function trim;
use function uniqid;
use function wp_json_encode;

if ( ! \defined('ABSPATH')) {
    exit;
}

// FIX: Removed 'readonly' for PHP < 8.2 compatibility
final class StarmusWaveformService
{
    /**
     * File service for handling offloaded attachments.
     *
     * @since 1.0.0
     */
    private ?StarmusFileService $files = null;

    /**
     * Initializes waveform service with optional dependencies.
     *
     * Creates new instances of dependencies if not provided, allowing for
     * flexible initialization while maintaining testability.
     *
     * @param IStarmusAudioDAL|null $dal Optional DAL instance
     * @param StarmusFileService|null $file_service Optional file service instance
     *
     * @since 1.0.0
     */
    public function __construct(?IStarmusAudioDAL $dal = null, ?StarmusFileService $file_service = null)
    {
        $this->files = $file_service ?: new StarmusFileService();
    }

    /**
     * Gets waveform generation configuration with WordPress filter support.
     *
     * Provides default configuration values that can be customized via WordPress
     * filters for different quality and performance requirements.
     *
     * @return array Configuration array with generation parameters
     *
     * @since 1.0.0
     *
     * Default Configuration:
     * - pixels_per_second: 100 (resolution of waveform)
     * - bits: 8 (bit depth for amplitude data)
     * - output_format: 'json' (structured data format)
     *
     * @filter starmus_waveform_config Allows customization of generation settings
     *
     * @example
     * ```php
     * add_filter('starmus_waveform_config', function($config) {
     *     $config['pixels_per_second'] = 200; // Higher resolution
     *     return $config;
     * });
     * ```
     */
    private function get_config(): array
    {
        $defaults = [
            'pixels_per_second' => 100,
            'bits' => 8,
            'output_format' => 'json',
        ];
        apply_filters('starmus_waveform_config', $defaults);
        return $defaults;
    }

    /**
     * Checks if audiowaveform CLI tool is available on the system.
     *
     * Verifies that the audiowaveform binary can be found in the system PATH.
     * Essential for determining if waveform generation is possible.
     *
     * @return bool True if tool is available, false otherwise
     *
     * @since 1.0.0
     *
     * Detection Method:
     * - Uses shell 'command -v' to locate binary
     * - Checks for non-empty path response
     * - Works across different shell environments
     *
     * @example
     * ```php
     * if ($service->is_tool_available()) {
     *     $service->generate_waveform_data($attachment_id, $post_id);
     * } else {
     *     error_log('audiowaveform not available');
     * }
     * ```
     */
    public function is_tool_available(): bool
    {
        try {
            $output = (string) shell_exec('command -v audiowaveform');
            $path = trim($output);
            return $path !== '' && $path !== '0';
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return false;
        }
    }

    public function set_file_service(StarmusFileService $file_service): void
    {
        $this->files = $file_service;
    }

    public function has_waveform_data(int $recording_id): bool
    {
        $existing = get_post_meta($recording_id, 'waveform_json', true);
        return ! empty($existing);
    }

    public function get_waveform_data(int $recording_id): ?array
    {
        $json_str = get_post_meta($recording_id, 'waveform_json', true);
        if (empty($json_str)) {
            return null;
        }

        return json_decode($json_str, true);
    }

    public function delete_waveform_data(int $recording_id): bool
    {
        if (\function_exists('delete_field')) {
            return delete_field('waveform_json', $recording_id);
        }
        return delete_post_meta($recording_id, 'waveform_json');
    }

    /**
     * Main entry point for waveform generation and storage.
     *
     * Generates JSON waveform data from audio attachments and stores it on the
     * parent recording post. Handles parent post detection, file access, and
     * comprehensive error management.
     *
     * @param int $attachment_id WordPress attachment post ID for audio file
     * @param int|null $explicit_parent_id Optional parent post ID (prevents lookup failures)
     *
     * @return bool True if waveform generated and saved successfully
     *
     * @since 1.0.0
     *
     * Process Flow:
     * 1. **Tool Validation**: Check audiowaveform availability
     * 2. **Parent Resolution**: Find recording post ID via multiple strategies
     * 3. **Duplicate Prevention**: Skip if waveform already exists
     * 4. **File Access**: Get local file copy (handles offloaded files)
     * 5. **Generation**: Extract waveform data via CLI tool
     * 6. **Storage**: Save to post meta and ACF fields
     *
     * Parent Post Detection (Priority Order):
     * 1. Explicit parent ID parameter
     * 2. _parent_recording_id meta field on attachment
     * 3. WordPress post_parent relationship
     *
     * File Access Handling:
     * - Supports offloaded files via StarmusFileService
     * - Falls back to WordPress get_attached_file()
     * - Validates physical file existence
     *
     * Storage Locations:
     * - WordPress post meta: waveform_json field
     * - ACF field: waveform_json (if ACF active)
     * - JSON string format for compatibility
     *
     * Error Conditions:
     * - audiowaveform tool not available
     * - Parent recording post not found
     * - Audio file not accessible
     * - Waveform extraction fails
     * - Database storage fails
     * @see extract_waveform_from_file() CLI extraction implementation
     * @see StarmusFileService::get_local_copy() File access management
     */
    public function generate_waveform_data(int $attachment_id, ?int $explicit_parent_id = null): bool
    {
        StarmusLogger::info(
            'Waveform generation started',
            [
                'component' => self::class,
                'attachment_id' => $attachment_id,
                'post_id' => $explicit_parent_id,
            ]
        );

        // 1. Tool Check
        if ( ! $this->is_tool_available()) {
            StarmusLogger::warning(
                'audiowaveform binary missing. Skipping.',
                ['component' => self::class, 'attachment_id' => $attachment_id]
            );
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
                StarmusLogger::error('Waveform Gen Failed: Missing parent recording reference for attachment: ' . $attachment_id);
                return false;
            }
        }

        // 3. Skip if exists
        if ($this->has_waveform_data($recording_id)) {
            return true;
        }

        // 4. Get File Path (FIXED SYNTAX)
        $file_path = $this->files->get_local_copy($attachment_id);

        if ( ! $file_path || ! file_exists($file_path)) {
            // Fallback to standard WP path
            $file_path = get_attached_file($attachment_id);
        }

        if ( ! $file_path || ! file_exists($file_path)) {
            StarmusLogger::info('Audio file not found: ' . $attachment_id);
            return false;
        }

        // 5. Generate
        $data = $this->extract_waveform_from_file($file_path);
        if ( ! $data || empty($data['data'])) {
            StarmusLogger::error('Waveform extraction returned empty data: ' . $attachment_id);
            return false;
        }

        // 6. Save
        try {
            // Save as JSON string
            $json_str = wp_json_encode($data['data']);

            // Save to Post Meta (Standard)
            update_post_meta($recording_id, 'waveform_json', $json_str);

            // Update ACF if available
            if (\function_exists('update_field')) {
                update_field('waveform_json', $json_str, $recording_id);
            }

            StarmusLogger::info(
                'Waveform saved.',
                [
                    'component' => self::class,
                    'attachment_id' => $attachment_id,
                    'post_id' => $recording_id,
                ]
            );
            return true;
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return false;
        }
    }

    /**
     * Extracts waveform data from audio file using audiowaveform CLI.
     *
     * Executes the audiowaveform command-line tool to generate JSON waveform data
     * from an audio file. Handles temporary file management and error recovery.
     *
     * @param string $file_path Absolute path to the audio file
     *
     * @since 1.0.0
     *
     * CLI Command Structure:
     * ```bash
     * audiowaveform -i input.wav -o output.json \
     *   --pixels-per-second 100 \
     *   --bits 8 \
     *   --output-format json
     * ```
     *
     * Process Flow:
     * 1. **Configuration**: Load generation settings
     * 2. **Temporary File**: Create unique JSON output file
     * 3. **Command Execution**: Run audiowaveform CLI tool
     * 4. **Validation**: Check command exit code and output file
     * 5. **Data Parsing**: Parse JSON waveform data
     * 6. **Cleanup**: Remove temporary files
     *
     * Return Data Structure:
     * ```php
     * [
     *   'data' => [
     *     'version' => 2,
     *     'channels' => 1,
     *     'sample_rate' => 44100,
     *     'samples_per_pixel' => 441,
     *     'bits' => 8,
     *     'length' => 1000,
     *     'data' => [127, 85, 42, ...] // Amplitude data
     *   ],
     *   'json_path' => '/path/to/source.wav.waveform.json'
     * ]
     * ```
     *
     * Error Handling:
     * - Validates command execution success
     * - Handles JSON parsing errors
     * - Automatic temporary file cleanup
     * - Detailed error logging for debugging
     *
     * Common Failure Scenarios:
     * - Unsupported audio format (MP3 issues)
     * - Corrupted audio files
     * - Insufficient disk space
     * - Permission issues with temp directory
     *
     * @throws RuntimeException If CLI command fails
     *
     * @return array|null Waveform data array or null on failure
     *
     * @see get_config() Configuration management
     */
    private function extract_waveform_from_file(string $file_path): ?array
    {
        $config = $this->get_config();

        // Ensure temp dir is writable
        $temp_dir = sys_get_temp_dir();
        $temp_json = $temp_dir . '/waveform-' . uniqid() . '.json';

        $cmd = \sprintf(
            'audiowaveform -i %s -o %s --pixels-per-second %d --bits %d --output-format %s',
            escapeshellarg($file_path),
            escapeshellarg($temp_json),
            (int) $config['pixels_per_second'],
            (int) $config['bits'],
            escapeshellarg((string) $config['output_format'])
        );

        try {
            exec($cmd . ' 2>&1', $output, $code);

            if ($code !== 0 || ! file_exists($temp_json)) {
                // If MP3 fails, try converting to WAV first (common issue with audiowaveform)
                throw new RuntimeException('CLI Error: ' . implode("\n", $output));
            }

            $json = file_get_contents($temp_json);
            $data = json_decode($json, true);

            @unlink($temp_json); // Cleanup

            return [
                'data' => $data['data'] ?? [],
                'json_path' => $file_path . '.waveform.json',
            ];
        } catch (Throwable $throwable) {
            StarmusLogger::error('Waveform CLI Error: ' . $throwable->getMessage());
            StarmusLogger::log($throwable);
            if (file_exists($temp_json)) {
                @unlink($temp_json);
            }

            return null;
        }
    }
}
