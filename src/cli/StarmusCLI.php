<?php

/**
 * WP-CLI commands for managing the Starmus Audio Recorder plugin.
 *
 * This is the final, consolidated class containing all commands and best practices.
 *
 * @package Starisian\Sparxstar\Starmus\cli
 *
 * @version 0.9.1
 */

namespace Starisian\Sparxstar\Starmus\cli;

use Starisian\Sparxstar\Starmus\cron\StarmusCron;
use Starisian\Sparxstar\Starmus\frontend\StarmusAudioRecorderUI;
use Starisian\Sparxstar\Starmus\services\StarmusWaveformService;
use WP_Query;

// WP_CLI guard removed: always define the class, only register commands when WP_CLI is present.

/**
 * Manages the Starmus Audio Recorder plugin.
 */
class StarmusCLI extends \WP_CLI_Command
{
    /**
     * Waveform service instance.
     *
     * @var StarmusWaveformService|null
     */
    private ?StarmusWaveformService $waveform_service = null;

    public function __construct()
    {
        $this->waveform_service = new StarmusWaveformService();
    }

    /**
     * Manages audio recording waveforms.
     *
     * ## EXAMPLES
     *
     *     # Generate waveforms for all recordings missing them.
     *     $ wp starmus waveform generate
     *
     *     # Force regenerate waveforms for posts 123 and 456.
     *     $ wp starmus waveform generate --post_ids=123,456 --regenerate
     *
     *     # Delete waveform data for attachment ID 789.
     *     $ wp starmus waveform delete --attachment_ids=789
     *
     * @param mixed $args
     */
    public function waveform($args, array $assoc_args): void
    {
        if (empty($args[0])) {
            WP_CLI::error("Please specify an action: 'generate' or 'delete'.");
        }

        $action = $args[0];

        match ($action) {
            'generate' => $this->generate_waveforms($assoc_args),
            'delete'   => $this->delete_waveforms($assoc_args),
            default    => WP_CLI::error(\sprintf("Invalid action '%s'. Supported actions: generate, delete.", $action)),
        };
    }

    /**
     * Manages the Starmus caches.
     *
     * ## EXAMPLES
     *
     *     # Flush taxonomy caches
     *     $ wp starmus cache flush
     *
     * @param mixed $args
     * @param mixed $assoc_args
     */
    public function cache($args, $assoc_args): void
    {
        if (empty($args[0])) {
            WP_CLI::error("Please specify an action: 'flush'.");
        }

        $action = $args[0];

        if ('flush' === $action) {
            $this->flush_cache();
        } else {
            WP_CLI::error(\sprintf("Invalid action '%s'. Supported actions: flush.", $action));
        }
    }

    /**
     * Cleans up stale temporary files.
     *
     * ## OPTIONS
     *
     * [--days=<days>]
     * : Cleanup files older than this many days. Defaults to 1.
     * ---
     * default: 1
     * ---
     *
     * @param mixed $args
     */
    public function cleanup_temp_files($args, array $assoc_args): void
    {
        $days = absint($assoc_args['days'] ?? 1);
        strtotime(\sprintf('-%s days', $days));

        WP_CLI::line(\sprintf('Cleaning temporary files older than %s day(s)...', $days));

        // Cleanup is handled by StarmusSubmissionHandler, not UI
        $handler = new \Starisian\Sparxstar\Starmus\includes\StarmusSubmissionHandler(
            new \Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL(),
            new \Starisian\Sparxstar\Starmus\core\StarmusSettings()
        );
        if (method_exists($handler, 'cleanup_stale_temp_files')) {
            $handler->cleanup_stale_temp_files();
            WP_CLI::success('Cleanup process complete.');
        } else {
            WP_CLI::error('Required method cleanup_stale_temp_files() not found.');
        }
    }

    /**
     * Exports audio recording metadata.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : The export format. `csv` or `json`. Defaults to `csv`.
     * ---
     * default: csv
     * options:
     *   - csv
     *   - json
     * ---
     *
     * @param mixed $args
     */
    public function export($args, array $assoc_args): void
    {
        $query = new WP_Query(
            [
                'post_type'      => 'audio-recording',
                'post_status'    => 'any',
                'posts_per_page' => -1,
            ]
        );

        if (! $query->have_posts()) {
            WP_CLI::error('No recordings found to export.');
        }

        $items   = [];
        $headers = ['ID', 'Title', 'Date', 'Author ID', 'Audio URL', 'Language', 'Recording Type'];

        foreach ($query->posts as $post) {
            $attachment_id = get_post_meta($post->ID, '_audio_attachment_id', true);
            $items[]       = [
                'ID'             => $post->ID,
                'Title'          => $post->post_title,
                'Date'           => $post->post_date,
                'Author ID'      => $post->post_author,
                'Audio URL'      => $attachment_id ? wp_get_attachment_url($attachment_id) : '',
                'Language'       => wp_strip_all_tags(get_the_term_list($post->ID, 'language', '', ', ')),
                'Recording Type' => wp_strip_all_tags(get_the_term_list($post->ID, 'recording-type', '', ', ')),
            ];
        }

        $format = $assoc_args['format'] ?? 'csv';
        if ('json' === $format) {
            WP_CLI::line(json_encode($items, JSON_PRETTY_PRINT));
        } else {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CLI context requires direct stdout access
            $output = fopen('php://stdout', 'w');
            if ($output) {
                fputcsv($output, $headers);
                foreach ($items as $item) {
                    fputcsv($output, $item);
                }

                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CLI context requires direct stdout access
                fclose($output);
            }
        }
    }

    /**
     * Imports audio recordings from a CSV file.
     *
     * ## OPTIONS
     *
     * [<file>]
     * : The path to the CSV file to import.
     *
     * [--dry-run]
     * : Preview the import without creating posts or attachments.
     *
     * @param mixed $args
     * @param mixed $assoc_args
     */
    public function import($args, $assoc_args): void
    {
        if (empty($args[0])) {
            WP_CLI::error('Please provide a path to the CSV file.');
        }

        $csv_file = $args[0];
        if (! file_exists($csv_file) || ! is_readable($csv_file)) {
            WP_CLI::error('CSV file not found or is not readable at: ' . $csv_file);
        }

        WP_CLI::error('The import command is not yet implemented.');
    }

    /**
     * Private handler for generating waveforms.
     */
    private function generate_waveforms(array $assoc_args): void
    {
        // PRE-FLIGHT CHECK: Verify the tool is available before doing anything else.
        if (! $this->waveform_service->is_tool_available()) {
            WP_CLI::error(
                "The 'audiowaveform' command-line tool is not installed or not in the server's PATH. " .
                    'Please install it to generate waveforms. See: https://github.com/bbc/audiowaveform'
            );
            return; // Exit immediately.
        }

        $query_args = [
            'post_type'      => 'audio-recording',
            'post_status'    => 'publish',
            'posts_per_page' => (int) ($assoc_args['chunk_size'] ?? 100),
            'fields'         => 'ids',
            'paged'          => 1,
        ];

        if (! empty($assoc_args['post_ids'])) {
            $query_args['post__in']       = array_map(absint(...), explode(',', (string) $assoc_args['post_ids']));
            $query_args['posts_per_page'] = \count($query_args['post__in']);
        }

        // Use a WP_Query to get a reliable count of posts to be processed.
        $count_query = new WP_Query($query_args);
        $total_posts = $count_query->found_posts;

        if (! $total_posts) {
            WP_CLI::success('No recordings found to process.');
            return;
        }

        $regenerate = isset($assoc_args['regenerate']);
        $progress   = \WP_CLI\Utils\make_progress_bar('Generating Waveforms', $total_posts);
        $processed  = 0;
        $skipped    = 0;
        $failed     = 0;

        do {
            $query = new WP_Query($query_args);
            if (! $query->have_posts()) {
                break;
            }

            foreach ($query->posts as $post_id) {
                $attachment_id = get_post_meta($post_id, '_audio_attachment_id', true);

                if (! $attachment_id) {
                    ++$skipped;
                } elseif (! $regenerate && $this->waveform_service->has_waveform_data($attachment_id)) {
                    ++$skipped;
                } elseif ($this->waveform_service->generate_waveform_data($attachment_id, $regenerate)) {
                    ++$processed;
                } else {
                    ++$failed;
                    WP_CLI::warning("\nFailed for Post ID {$post_id} (Attachment ID: {$attachment_id}). Check server logs.");
                }

                $progress->tick();
            }

            // Clear WP's internal object cache between chunks to prevent memory leaks on very large sites.
            wp_cache_flush();
            ++$query_args['paged'];
        } while ($query_args['paged'] <= $query->max_num_pages);

        $progress->finish();
        WP_CLI::success(\sprintf('Processing complete. Generated: %d, Skipped: %d, Failed: %d.', $processed, $skipped, $failed));
    }

    /**
     * Private handler for deleting waveforms.
     */
    private function delete_waveforms(array $assoc_args): void
    {
        if (empty($assoc_args['attachment_ids'])) {
            WP_CLI::error('Please provide one or more attachment IDs using --attachment_ids=<ids>.');
        }

        $attachment_ids = array_map(absint(...), explode(',', (string) $assoc_args['attachment_ids']));
        $deleted        = 0;
        $skipped        = 0;

        foreach ($attachment_ids as $attachment_id) {
            if ($this->waveform_service->delete_waveform_data($attachment_id)) {
                WP_CLI::log('Deleted waveform data for attachment ID: ' . $attachment_id);
                ++$deleted;
            } else {
                WP_CLI::log('No waveform data to delete for attachment ID: ' . $attachment_id);
                ++$skipped;
            }
        }

        WP_CLI::success(\sprintf('Deletion complete. Deleted: %d, Skipped: %d.', $deleted, $skipped));
    }

    /**
     * Private handler for flushing caches.
     */
    private function flush_cache(): void
    {
        WP_CLI::line('Flushing Starmus taxonomy caches...');
        $recorder_ui = new StarmusAudioRecorderUI(null);
        $recorder_ui->clear_taxonomy_transients();
        WP_CLI::success('Starmus caches have been flushed.');
    }

    /**
     * Force waveform + mastering regeneration for an attachment.
     *
     * ## OPTIONS
     *
     * <attachment_id>
     * : The attachment ID to process.
     *
     * ## EXAMPLES
     *
     *     wp starmus regen 1234
     *
     * @subcommand regen
     */
    public function regen(array $args): void
    {
        [$id] = $args;

        $id = (int) $id;
        if ($id <= 0) {
            \WP_CLI::error('Invalid attachment ID.');
            return;
        }

        $attachment = get_post($id);
        if (! $attachment || $attachment->post_type !== 'attachment') {
            \WP_CLI::error(\sprintf('Attachment %d not found.', $id));
            return;
        }

        \WP_CLI::log(\sprintf('Regenerating waveform and audio pipeline for attachment %d...', $id));

        $cron = new StarmusCron();
        $cron->run_audio_processing_pipeline($id);

        \WP_CLI::success(\sprintf('Rebuilt waveform and audio pipeline for attachment %d.', $id));
    }

    /**
     * Scan for audio recordings with missing waveform_json and optionally repair them.
     *
     * ## OPTIONS
     *
     * [--repair]
     * : Automatically regenerate waveforms for recordings with missing data.
     *
     * [--limit=<number>]
     * : Maximum number of recordings to process (default: 100).
     *
     * ## EXAMPLES
     *
     *     wp starmus scan-missing
     *     wp starmus scan-missing --repair
     *     wp starmus scan-missing --repair --limit=50
     *
     * @subcommand scan-missing
     */
    public function scan_missing(array $args, array $assoc_args): void
    {
        $repair = isset($assoc_args['repair']);
        $limit  = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 100;

        \WP_CLI::log('Scanning for audio recordings with missing waveform_json...');

        $query_args = [
            'post_type'      => 'audio-recording',
            'post_status'    => 'any',
            'posts_per_page' => $limit,
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => 'waveform_json',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => 'waveform_json',
                    'value'   => '',
                    'compare' => '=',
                ],
            ],
        ];

        $recordings = get_posts($query_args);

        if (empty($recordings)) {
            \WP_CLI::success('No recordings found with missing waveform_json.');
            return;
        }

        \WP_CLI::log(\sprintf('Found %d recording(s) with missing waveform_json.', \count($recordings)));

        $repaired = 0;
        $cron     = $repair ? new StarmusCron() : null;

        foreach ($recordings as $recording) {
            $attachment_id = (int) get_post_meta($recording->ID, '_audio_attachment_id', true);

            if ($attachment_id <= 0) {
                \WP_CLI::warning(\sprintf('Recording %d has no attachment ID, skipping.', $recording->ID));
                continue;
            }

            \WP_CLI::log(\sprintf('Recording %d (Attachment %d): Missing waveform_json', $recording->ID, $attachment_id));

            if ($repair && $cron) {
                \WP_CLI::log(\sprintf('  → Regenerating waveform for attachment %d...', $attachment_id));
                $cron->run_audio_processing_pipeline($attachment_id);
                $repaired++;
            }
        }

        if ($repair) {
            \WP_CLI::success(\sprintf('Repaired %d recording(s).', $repaired));
        } else {
            \WP_CLI::log('Use --repair to automatically regenerate missing waveforms.');
        }
    }

    /**
     * Batch regenerate waveforms for all audio attachments.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Maximum number of attachments to process (default: 100).
     *
     * [--offset=<number>]
     * : Offset for pagination (default: 0).
     *
     * ## EXAMPLES
     *
     *     wp starmus batch-regen
     *     wp starmus batch-regen --limit=50 --offset=100
     *
     * @subcommand batch-regen
     */
    public function batch_regen(array $args, array $assoc_args): void
    {
        $limit  = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 100;
        $offset = isset($assoc_args['offset']) ? (int) $assoc_args['offset'] : 0;

        \WP_CLI::log(\sprintf('Batch regenerating waveforms for audio attachments (limit: %d, offset: %d)...', $limit, $offset));

        $attachments = get_posts([
            'post_type'      => 'attachment',
            'post_mime_type' => 'audio',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'post_status'    => 'inherit',
        ]);

        if (empty($attachments)) {
            \WP_CLI::success('No audio attachments found.');
            return;
        }

        \WP_CLI::log(\sprintf('Found %d audio attachment(s) to process.', \count($attachments)));

        $cron      = new StarmusCron();
        $processed = 0;

        foreach ($attachments as $attachment) {
            \WP_CLI::log(\sprintf('Processing attachment %d...', $attachment->ID));
            $cron->run_audio_processing_pipeline($attachment->ID);
            $processed++;
        }

        \WP_CLI::success(\sprintf('Processed %d attachment(s).', $processed));
    }

    /**
     * Queue waveform regeneration for a specific attachment (runs via cron).
     *
     * ## OPTIONS
     *
     * <attachment_id>
     * : The attachment ID to queue.
     *
     * ## EXAMPLES
     *
     *     wp starmus queue 1234
     *
     * @subcommand queue
     */
    public function queue(array $args): void
    {
        [$id] = $args;

        $id = (int) $id;
        if ($id <= 0) {
            \WP_CLI::error('Invalid attachment ID.');
            return;
        }

        $attachment = get_post($id);
        if (! $attachment || $attachment->post_type !== 'attachment') {
            \WP_CLI::error(\sprintf('Attachment %d not found.', $id));
            return;
        }

        \WP_CLI::log(\sprintf('Queueing waveform regeneration for attachment %d...', $id));

        $cron = new StarmusCron();
        $cron->schedule_audio_processing($id);

        \WP_CLI::success(\sprintf('Queued attachment %d for cron processing.', $id));
    }
}
