<?php

/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * StarmusWaveformService (DAL-integrated)
 * --------------------------------
 * Generates waveform JSON data using the audiowaveform CLI tool
 * and stores it via the DAL + ACF on the parent recording post.
 *
 * @package Starisian\Sparxstar\Starmus\services
 *
 * @version 0.9.2
 */
namespace Starisian\Sparxstar\Starmus\services;

if (! \defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

final readonly class StarmusWaveformService
{
    /**
     * Data Access Layer instance.
     *
     * @var StarmusAudioRecorderDAL
     */
    private StarmusAudioRecorderDAL $dal;

    /**
     * File service instance.
     *
     * @var StarmusFileService
     */
    private StarmusFileService $files;

    public function __construct(?StarmusAudioRecorderDAL $dal = null, ?StarmusFileService $file_service = null)
    {
        $this->dal   = $dal ?: new StarmusAudioRecorderDAL();
        $this->files = $file_service ?: new StarmusFileService();
    }

    /** Configurable audiowaveform parameters */
    private function get_config(): array
    {
        $defaults = [
            'pixels_per_second' => 100,
            'bits'              => 8,
            'output_format'     => 'json',
        ];
        return apply_filters('starmus_waveform_config', $defaults);
    }

    /** Check whether audiowaveform is installed */
    public function is_tool_available(): bool
    {
        StarmusLogger::debug('StarmusWaveformService', 'Checking audiowaveform availability...');
        $path = trim((string) shell_exec('command -v audiowaveform'));

        if ($path === '') {
            StarmusLogger::error('StarmusWaveformService', 'audiowaveform binary not found.');
            return false;
        }

        StarmusLogger::info('StarmusWaveformService', 'audiowaveform found at ' . $path);
        return true;
    }

    /**
     * MAIN ENTRYPOINT
     * Generates waveform JSON and stores it on the parent recording.
     */
    public function generate_waveform_data(int $attachment_id, bool $force = false): bool
    {
        StarmusLogger::setCorrelationId();
        StarmusLogger::timeStart('waveform_generate');

        StarmusLogger::info(
            'StarmusWaveformService',
            'Starting waveform generation',
            ['attachment_id' => $attachment_id]
        );

        // Parent Recording
        $recording_id = (int) get_post_meta($attachment_id, '_parent_recording_id', true);
        if ($recording_id <= 0) {
            StarmusLogger::error('StarmusWaveformService', 'Missing parent recording reference.', ['attachment_id' => $attachment_id]);
            return false;
        }

        // Skip if waveform exists
        if (! $force && ! empty(get_field('waveform_json', $recording_id))) {
            StarmusLogger::notice('StarmusWaveformService', 'Waveform already exists; skipping regeneration.', ['recording_id' => $recording_id]);
            return true;
        }

        // Get audio file
        $file_path = (new $this->files())->get_local_copy($attachment_id);
        if (! $file_path || ! file_exists($file_path)) {
            StarmusLogger::error('StarmusWaveformService', 'Audio file not found.', ['attachment_id' => $attachment_id]);
            return false;
        }

        // Extract waveform data
        $data = $this->extract_waveform_from_file($file_path);
        if ($data === null || $data === []) {
            StarmusLogger::error('StarmusWaveformService', 'Waveform extraction failed.', ['attachment_id' => $attachment_id]);
            return false;
        }

        // Persist waveform JSON via DAL
        try {
            $this->dal->save_post_meta($recording_id, 'waveform_json', wp_json_encode($data['data']));
            StarmusLogger::info(
                'StarmusWaveformService',
                'Waveform persisted via DAL',
                [
                    'recording_id' => $recording_id,
                    'points'       => \count($data['data']),
                ]
            );
        } catch (\Throwable $throwable) {
            StarmusLogger::error(
                'StarmusWaveformService',
                $throwable,
                [
                    'phase'        => 'persist_waveform',
                    'recording_id' => $recording_id,
                ]
            );

            if (\function_exists('update_field')) {
                @update_field('waveform_json', wp_json_encode($data['data']), $recording_id);
            }
        }

        do_action('starmus_waveform_stored', $recording_id, $data);

        StarmusLogger::info(
            'StarmusWaveformService',
            'Waveform stored successfully.',
            [
                'recording_id' => $recording_id,
                'points'       => \count($data['data']),
            ]
        );

        StarmusLogger::timeEnd('waveform_generate', 'StarmusWaveformService');
        return true;
    }

    /**
     * Deletes stored waveform JSON from ACF/DAL.
     */
    public function delete_waveform_data(int $attachment_id): bool
    {
        $recording_id = (int) get_post_meta($attachment_id, '_parent_recording_id', true);

        if ($recording_id <= 0) {
            return false;
        }

        if (\function_exists('delete_field')) {
            @delete_field('waveform_json', $recording_id);
        }

        StarmusLogger::notice(
            'StarmusWaveformService',
            'Waveform JSON removed from recording.',
            ['recording_id' => $recording_id]
        );

        return true;
    }

    /**
     * Extract waveform JSON using audiowaveform CLI.
     */
    private function extract_waveform_from_file(string $file_path): ?array
    {
        $config = $this->get_config();

        $temp = tempnam(sys_get_temp_dir(), 'waveform-');
        if (! $temp) {
            StarmusLogger::error('StarmusWaveformService', 'Failed to create temp file.');
            return null;
        }

        wp_delete_file($temp);
        $temp_json = $temp . '.json';

        $cmd = \sprintf(
            'audiowaveform -i %s -o %s --pixels-per-second %d --bits %d --output-format %s',
            escapeshellarg($file_path),
            escapeshellarg($temp_json),
            (int) $config['pixels_per_second'],
            (int) $config['bits'],
            escapeshellarg((string) $config['output_format'])
        );

        $cmd = apply_filters('starmus_waveform_command', $cmd, $file_path, $temp_json);

        register_shutdown_function(
            static function () use ($temp_json): void {
                if (file_exists($temp_json)) {
                    wp_delete_file($temp_json);
                }
            }
        );

        try {
            exec($cmd . ' 2>&1', $output, $code);

            if ($code !== 0 || ! file_exists($temp_json)) {
                throw new \RuntimeException('audiowaveform failed: ' . implode("\n", $output));
            }

            $json = file_get_contents($temp_json);
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            if (empty($data['data'])) {
                throw new \RuntimeException('Empty waveform data returned.');
            }

            return [
                'data'      => $data['data'],
                'json_path' => $file_path . '.waveform.json',
            ];
        } catch (\Throwable $throwable) {
            StarmusLogger::error(
                'StarmusWaveformService',
                'Waveform extraction error',
                [
                    'error'   => $throwable->getMessage(),
                    'command' => $cmd,
                ]
            );
            return null;
        } finally {
            if (file_exists($temp_json)) {
                wp_delete_file($temp_json);
            }
        }
    }
}
