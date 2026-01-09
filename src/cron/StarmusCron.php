<?php

/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * Asynchronous background processor for the Starmus mastering pipeline.
 * Handles waveform generation, post-processing, and temp cleanup via WP-Cron.
 *
 * @package   Starisian\Sparxstar\Starmus\cron
 *
 * @version 0.9.2
 */
namespace Starisian\Sparxstar\Starmus\cron;

use function add_action;
use function add_filter;

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\services\StarmusAudioPipeline;
use Starisian\Sparxstar\Starmus\services\StarmusPostProcessingService;
use Starisian\Sparxstar\Starmus\services\StarmusWaveformService;
use Throwable;

use function time;
use function trailingslashit;
use function wp_delete_file;
use function wp_get_upload_dir;
use function wp_mkdir_p;
use function wp_next_scheduled;
use function wp_schedule_event;
use function wp_unschedule_event;

if ( ! \defined('ABSPATH')) {
    exit;
}

/**
 * StarmusCron
 *
 * Coordinates deferred waveform + mastering jobs and periodic cleanup.
 * Use this class when you want to run heavy audio post-processing
 * safely in the background after uploads.
 */
final readonly class StarmusCron
{
    /** Single-run job for background mastering. */
    public const PROCESS_AUDIO_HOOK = 'starmus_process_audio_attachment';

    /** Recurring hourly cleanup for stale temp upload chunks. */
    public const CLEANUP_TEMP_FILES_HOOK = 'starmus_cleanup_temp_files';

    /**
     * Waveform service instance.
     */
    private ?StarmusWaveformService $waveform;

    /**
     * Post-processing service instance.
     */
    private ?StarmusPostProcessingService $post;

    private ?StarmusAudioPipeline $pipeline;

    /**
     * Builds the cron coordinator with optional injected services.
     *
     * @param StarmusWaveformService|null $waveform_service Waveform generation dependency
     * @param StarmusPostProcessingService|null $post_service Post-processing dependency
     */
    public function __construct(
        ?StarmusWaveformService $waveform_service = null,
        ?StarmusPostProcessingService $post_service = null,
        ?StarmusAudioPipeline $audio_pipeline = null,
    ) {
        $this->waveform = $waveform_service ?: new StarmusWaveformService();
        $this->post     = $post_service ?: new StarmusPostProcessingService();
        $this->pipeline = $audio_pipeline ?: new StarmusAudioPipeline();
    }

    /** Registers WP hooks for both the processor and cleanup jobs. */
    public function register_hooks(): void
    {
        add_action(self::PROCESS_AUDIO_HOOK, $this->run_audio_processing_pipeline(...), 10, 1);
        add_action(self::CLEANUP_TEMP_FILES_HOOK, $this->cleanup_stale_temp_files(...));
        add_filter('cron_schedules', $this->register_custom_schedules(...));
    }

    /**
     * Queue a background mastering job for a given attachment.
     */
    public function schedule_audio_processing(int $attachment_id): void
    {
        if ($attachment_id <= 0) {
            return;
        }

        if ( ! wp_next_scheduled(self::PROCESS_AUDIO_HOOK, [$attachment_id])) {
            wp_schedule_single_event(time() + 60, self::PROCESS_AUDIO_HOOK, [$attachment_id]);
            StarmusLogger::info(
                'Scheduled audio processing',
                [
            'component'     => self::class,
            'attachment_id' => $attachment_id,
                ]
            );
        }
    }

    /**
     * Executes the full pipeline asynchronously.
     *
     * @param int $attachment_id WordPress attachment ID for processing.
     */
    public function run_audio_processing_pipeline(int $attachment_id): void
    {
        if ($attachment_id <= 0) {
            return;
        }

        StarmusLogger::info(
            'Starting background pipeline',
            [
        'component'     => self::class,
        'attachment_id' => $attachment_id,
            ]
        );
        update_post_meta($attachment_id, '_audio_processing_status', StarmusPostProcessingService::STATE_PROCESSING);

        try {
            // === STEP 1: Waveform Generation ===
            $this->waveform->generate_waveform_data($attachment_id);
            update_post_meta($attachment_id, '_audio_processing_status', StarmusPostProcessingService::STATE_WAVEFORM);

            // === STEP 2: Full Transcoding + Archival ===
            $parent_id = (int) wp_get_post_parent_id($attachment_id);
            if ($parent_id <= 0) {
                $parent_id = (int) get_post_meta($attachment_id, '_aiwa_recording_post', true);
            }

            if ($parent_id <= 0) {
                StarmusLogger::warning(
                    'No parent post linked to attachment',
                    [
                'component'     => self::class,
                'attachment_id' => $attachment_id,
                    ]
                );
            }

            $success = $this->pipeline->starmus_process_audio_pipeline($parent_id, $attachment_id);

            if ($success) {
                update_post_meta($attachment_id, '_audio_processing_status', StarmusPostProcessingService::STATE_COMPLETED);
                do_action('starmus_audio_pipeline_complete', $attachment_id);
                StarmusLogger::info(
                    'Background processing complete',
                    [
                'component'     => self::class,
                'attachment_id' => $attachment_id,
                'post_id'       => $parent_id,
                    ]
                );
            } else {
                update_post_meta($attachment_id, '_audio_processing_status', StarmusPostProcessingService::STATE_ERR_UNKNOWN);
                error_log('Background processing failed for attachment: ' . $attachment_id);
            }
        } catch (Throwable $throwable) {
            update_post_meta($attachment_id, '_audio_processing_status', StarmusPostProcessingService::STATE_ERR_UNKNOWN);
            StarmusLogger::log(
                $throwable,
                [
            'component'     => self::class,
            'attachment_id' => $attachment_id,
            'post_id'       => $parent_id ?? 0,
                ]
            );
        }
    }

    /**
     * Remove stale temp upload files (>24h old).
     */
    public function cleanup_stale_temp_files(): void
    {
        $dir = $this->get_temp_dir();
        if ( ! $dir || ! is_dir($dir)) {
            return;
        }

        $files = glob($dir . '*.part');
        if ($files === [] || $files === false) {
            return;
        }

        $cutoff = time() - DAY_IN_SECONDS;
        foreach ($files as $file) {
            if (is_file($file) && @filemtime($file) < $cutoff) {
                wp_delete_file($file);
            }
        }

        StarmusLogger::debug(
            'Temp cleanup executed',
            [
        'component' => self::class,
        'path'      => $dir,
        'cutoff'    => $cutoff,
            ]
        );
    }

    /** Schedule recurring cleanup on plugin activation. */
    public static function starmus_activate(): void
    {
        if ( ! wp_next_scheduled(self::CLEANUP_TEMP_FILES_HOOK)) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CLEANUP_TEMP_FILES_HOOK);
        }
    }

    /** Unschedule cleanup on deactivation. */
    public static function starmus_deactivate(): void
    {
        $timestamp = wp_next_scheduled(self::CLEANUP_TEMP_FILES_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CLEANUP_TEMP_FILES_HOOK);
        }
    }

    /**
     * Optional 15-minute schedule.
     */
    public function register_custom_schedules(array $schedules): array
    {
        try {
            $schedules['starmus_quarter_hour'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __('Every 15 Minutes (Starmus)', 'starmus-audio-recorder'),
            ];
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
        }

        return $schedules;
    }

    /**
     * Ensures the temp directory exists & is hardened.
     */
    private function get_temp_dir(): string
    {
        $upload_dir       = wp_get_upload_dir();
        $default_temp_dir = trailingslashit($upload_dir['basedir']) . 'starmus-temp/';

        if ( ! wp_mkdir_p($default_temp_dir)) {
            return '';
        }

        // Harden directory
        $htaccess_path = $default_temp_dir . '.htaccess';
        if ( ! file_exists($htaccess_path)) {
            @file_put_contents($htaccess_path, "Deny from all\n");
        }

        $index_path = $default_temp_dir . 'index.html';
        if ( ! file_exists($index_path)) {
            @file_put_contents($index_path, '');
        }

        return $default_temp_dir;
    }

}
