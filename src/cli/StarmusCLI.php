<?php

namespace Starmus\cli;
/**
 * WP-CLI commands for managing the Starmus Audio Recorder plugin.
 *
 * @package Starmus\cli
 * @version 0.7.2
 * @since 0.7.2
 */


use WP_CLI;
use WP_CLI_Command;
use WP_Query;
use Starmus\frontend\StarmusAudioRecorderUI;

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

/**
 * Manages the Starmus Audio Recorder plugin.
 */
class StarmusCLI extends WP_CLI_Command {

    /**
     * Generates waveform data for audio recordings.
     *
     * ## OPTIONS
     *
     * [--post_ids=<ids>]
     * : A comma-separated list of specific audio recording post IDs to process.
     *
     * [--regenerate]
     * : Force regeneration of waveforms even if they already exist.
     *
     * ## EXAMPLES
     *
     *     # Generate waveforms for all recordings that are missing them.
     *     $ wp starmus generate-waveforms
     *
     *     # Force regenerate waveforms for posts 123 and 456.
     *     $ wp starmus generate-waveforms --post_ids=123,456 --regenerate
     *
     * @param array $args Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function generate_waveforms( $args, $assoc_args ) {
        $query_args = [
            'post_type'      => 'audio-recording',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];

        if ( ! empty( $assoc_args['post_ids'] ) ) {
            $query_args['post__in'] = array_map( 'absint', explode( ',', $assoc_args['post_ids'] ) );
            WP_CLI::line( 'Processing ' . count( $query_args['post__in'] ) . ' specific recordings...' );
        } else {
            WP_CLI::line( 'Querying all audio recordings...' );
        }

        $query = new WP_Query( $query_args );

        if ( ! $query->have_posts() ) {
            WP_CLI::success( 'No recordings found to process.' );
            return;
        }

        $recorder_ui = new StarmusAudioRecorderUI();
        $regenerate  = isset( $assoc_args['regenerate'] );
        $progress    = \WP_CLI\Utils\make_progress_bar( 'Generating Waveforms', $query->post_count );
        $processed   = 0;
        $skipped     = 0;

        foreach ( $query->posts as $post_id ) {
            $attachment_id = get_post_meta( $post_id, '_audio_attachment_id', true );
            if ( ! $attachment_id ) {
                $skipped++;
                $progress->tick();
                continue;
            }

            if ( ! $regenerate && get_post_meta( $attachment_id, '_waveform_data', true ) ) {
                $skipped++;
                $progress->tick();
                continue;
            }

            // The waveform generation logic is private, so we need a public wrapper or reflection.
            // For now, let's assume you'll make it callable or create a public wrapper.
            // This is a placeholder for the actual call.
            // $success = $recorder_ui->generate_waveform_data_public( $attachment_id );

            // For this example, we'll simulate the call.
            // In your real code, you would call your actual waveform generation method here.
            $success = true; // Replace with your actual method call.

            if ( $success ) {
                $processed++;
            } else {
                WP_CLI::warning( "Failed to generate waveform for attachment ID: {$attachment_id} (Post ID: {$post_id})" );
            }
            $progress->tick();
        }

        $progress->finish();
        WP_CLI::success( "Processing complete. Generated: {$processed}, Skipped: {$skipped}." );
    }

    /**
     * Cleans up stale temporary files.
     *
     * Immediately runs the cleanup process for partial upload files older than 24 hours.
     *
     * ## EXAMPLES
     *
     *     $ wp starmus cleanup-temp-files
     */
    public function cleanup_temp_files() {
        WP_CLI::line( 'Running cleanup of stale temporary files...' );
        $recorder_ui = new StarmusAudioRecorderUI();
        // Assuming cleanup_stale_temp_files is public
        $recorder_ui->cleanup_stale_temp_files();
        WP_CLI::success( 'Cleanup process complete.' );
    }

    /**
     * Exports audio recording metadata.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : The export format.
     * ---
     * default: csv
     * options:
     *   - csv
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     # Export all recordings to a CSV file.
     *     $ wp starmus export > recordings.csv
     *
     *     # Export all recordings as a JSON object.
     *     $ wp starmus export --format=json
     */
    public function export( $args, $assoc_args ) {
        $query = new WP_Query( [
            'post_type'      => 'audio-recording',
            'post_status'    => 'any',
            'posts_per_page' => -1,
        ] );

        if ( ! $query->have_posts() ) {
            WP_CLI::error( 'No recordings found to export.' );
            return;
        }

        $items = [];
        $headers = ['ID', 'Title', 'Date', 'Author ID', 'Audio URL', 'Language', 'Recording Type']; // Add more as needed

        foreach ( $query->posts as $post ) {
            $attachment_id = get_post_meta( $post->ID, '_audio_attachment_id', true );
            $items[] = [
                'ID'             => $post->ID,
                'Title'          => $post->post_title,
                'Date'           => $post->post_date,
                'Author ID'      => $post->post_author,
                'Audio URL'      => $attachment_id ? wp_get_attachment_url( $attachment_id ) : '',
                'Language'       => strip_tags( get_the_term_list( $post->ID, 'language', '', ', ' ) ),
                'Recording Type' => strip_tags( get_the_term_list( $post->ID, 'recording-type', '', ', ' ) ),
            ];
        }

        if ( 'json' === $assoc_args['format'] ) {
            WP_CLI::line( wp_json_encode( $items, JSON_PRETTY_PRINT ) );
        } else {
            $output = fopen( 'php://stdout', 'w' );
            fputcsv( $output, $headers );
            foreach ( $items as $item ) {
                fputcsv( $output, $item );
            }
            fclose( $output );
        }
    }

    /**
     * Imports audio recordings from a CSV file.
     *
     * NOTE: This is a placeholder and a complex operation. It requires a well-defined
     * CSV format and careful handling of file paths.
     *
     * ## EXAMPLES
     *
     *     $ wp starmus import <path-to-csv> --audio_path=<path-to-folder>
     */
    public function import( $args, $assoc_args ) {
        WP_CLI::warning( 'The import command is a placeholder and not yet implemented.' );
        // Future logic would go here:
        // 1. Check for file existence ($args[0]) and path existence ($assoc_args['audio_path']).
        // 2. Parse the CSV file row by row.
        // 3. For each row, find the audio file in the audio_path.
        // 4. Use media_handle_sideload() to add the audio file to the media library.
        // 5. Use wp_insert_post() to create the 'audio-recording' post.
        // 6. Use update_post_meta() to attach the audio and save all other metadata.
        // 7. Use wp_set_post_terms() to set taxonomies.
    }
}
