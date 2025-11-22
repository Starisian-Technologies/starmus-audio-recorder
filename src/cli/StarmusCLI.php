<?php

/**
 * WP-CLI commands for managing the Starmus Audio Recorder plugin.
 *
 * This is the final, consolidated class containing all commands and best practices.
 *
 * @package Starisian\Sparxstar\Starmus\cli
 * @version 0.8.5
 */

namespace Starisian\Sparxstar\Starmus\cli;

use WP_Query;
use Starisian\Sparxstar\Starmus\services\StarmusWaveformService;
use Starisian\Sparxstar\Starmus\frontend\StarmusAudioRecorderUI;

// WP_CLI guard removed: always define the class, only register commands when WP_CLI is present.

/**
 * Manages the Starmus Audio Recorder plugin.
 */
class StarmusCLI extends \WP_CLI_Command
{




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
	 */
	public function waveform($args, $assoc_args)
	{
		if (empty($args[0])) {
			WP_CLI::error("Please specify an action: 'generate' or 'delete'.");
		}

		$action = $args[0];

		switch ($action) {
			case 'generate':
				$this->generate_waveforms($assoc_args);
				break;
			case 'delete':
				$this->delete_waveforms($assoc_args);
				break;
			default:
				WP_CLI::error("Invalid action '{$action}'. Supported actions: generate, delete.");
		}
	}

	/**
	 * Manages the Starmus caches.
	 *
	 * ## EXAMPLES
	 *
	 *     # Flush taxonomy caches
	 *     $ wp starmus cache flush
	 */
	public function cache($args, $assoc_args)
	{
		if (empty($args[0])) {
			WP_CLI::error("Please specify an action: 'flush'.");
		}

		$action = $args[0];

		if ('flush' === $action) {
			$this->flush_cache($assoc_args);
		} else {
			WP_CLI::error("Invalid action '{$action}'. Supported actions: flush.");
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
	 */
	public function cleanup_temp_files($args, $assoc_args)
	{
		$days   = absint($assoc_args['days'] ?? 1);
		$cutoff = strtotime("-{$days} days");

		WP_CLI::line("Cleaning temporary files older than {$days} day(s)...");

		$recorder_ui = new StarmusAudioRecorderUI();

		if (method_exists($recorder_ui, 'cleanup_stale_temp_files')) {
			$recorder_ui->cleanup_stale_temp_files($cutoff);
			WP_CLI::success('Cleanup process complete.');
		} else {
			WP_CLI::error('Required method cleanup_stale_temp_files() not found on StarmusAudioRecorderUI.');
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
	 */
	public function export($args, $assoc_args)
	{
		$query = new WP_Query(
			array(
				'post_type'      => 'audio-recording',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		if (! $query->have_posts()) {
			WP_CLI::error('No recordings found to export.');
		}

		$items   = array();
		$headers = array('ID', 'Title', 'Date', 'Author ID', 'Audio URL', 'Language', 'Recording Type');

		foreach ($query->posts as $post) {
			$attachment_id = get_post_meta($post->ID, '_audio_attachment_id', true);
			$items[]       = array(
				'ID'             => $post->ID,
				'Title'          => $post->post_title,
				'Date'           => $post->post_date,
				'Author ID'      => $post->post_author,
				'Audio URL'      => $attachment_id ? wp_get_attachment_url($attachment_id) : '',
				'Language'       => wp_strip_all_tags(get_the_term_list($post->ID, 'language', '', ', ')),
				'Recording Type' => wp_strip_all_tags(get_the_term_list($post->ID, 'recording-type', '', ', ')),
			);
		}

		$format = $assoc_args['format'] ?? 'csv';
		if ('json' === $format) {
			WP_CLI::line(json_encode($items, JSON_PRETTY_PRINT));
		} else {
			$output = fopen('php://stdout', 'w');
			fputcsv($output, $headers);
			foreach ($items as $item) {
				fputcsv($output, $item);
			}
			fclose($output);
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
	 */
	public function import($args, $assoc_args)
	{
		if (empty($args[0])) {
			WP_CLI::error('Please provide a path to the CSV file.');
		}

		$csv_file = $args[0];
		if (! file_exists($csv_file) || ! is_readable($csv_file)) {
			WP_CLI::error("CSV file not found or is not readable at: {$csv_file}");
		}

		WP_CLI::error('The import command is not yet implemented.');
	}

	/**
	 * Private handler for generating waveforms.
	 */
	private function generate_waveforms($assoc_args)
	{
		// PRE-FLIGHT CHECK: Verify the tool is available before doing anything else.
		if (! $this->waveform_service->is_tool_available()) {
			WP_CLI::error(
				"The 'audiowaveform' command-line tool is not installed or not in the server's PATH. " .
					'Please install it to generate waveforms. See: https://github.com/bbc/audiowaveform'
			);
			return; // Exit immediately.
		}

		$query_args = array(
			'post_type'      => 'audio-recording',
			'post_status'    => 'publish',
			'posts_per_page' => (int) ($assoc_args['chunk_size'] ?? 100),
			'fields'         => 'ids',
			'paged'          => 1,
		);

		if (! empty($assoc_args['post_ids'])) {
			$query_args['post__in']       = array_map('absint', explode(',', $assoc_args['post_ids']));
			$query_args['posts_per_page'] = count($query_args['post__in']);
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
		WP_CLI::success("Processing complete. Generated: {$processed}, Skipped: {$skipped}, Failed: {$failed}.");
	}

	/**
	 * Private handler for deleting waveforms.
	 */
	private function delete_waveforms($assoc_args)
	{
		if (empty($assoc_args['attachment_ids'])) {
			WP_CLI::error('Please provide one or more attachment IDs using --attachment_ids=<ids>.');
		}

		$attachment_ids = array_map('absint', explode(',', $assoc_args['attachment_ids']));
		$deleted        = 0;
		$skipped        = 0;

		foreach ($attachment_ids as $attachment_id) {
			if ($this->waveform_service->delete_waveform_data($attachment_id)) {
				WP_CLI::log("Deleted waveform data for attachment ID: {$attachment_id}");
				++$deleted;
			} else {
				WP_CLI::log("No waveform data to delete for attachment ID: {$attachment_id}");
				++$skipped;
			}
		}
		WP_CLI::success("Deletion complete. Deleted: {$deleted}, Skipped: {$skipped}.");
	}

	/**
	 * Private handler for flushing caches.
	 */
	private function flush_cache($assoc_args)
	{
		WP_CLI::line('Flushing Starmus taxonomy caches...');

		$recorder_ui = new StarmusAudioRecorderUI();

		if (method_exists($recorder_ui, 'clear_taxonomy_transients')) {
			$recorder_ui->clear_taxonomy_transients();
			WP_CLI::success('Starmus caches have been flushed.');
		} else {
			WP_CLI::error('Required method clear_taxonomy_transients() not found.');
		}
	}
}
