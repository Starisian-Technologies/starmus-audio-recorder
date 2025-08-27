<?php

/**
 * Manages the front-end audio recording submission process and the user's recordings list.
 * This class implements a resilient, chunked upload handler to support intermittent connections.
 *
 * @package Starmus\frontend
 */

namespace Starmus\frontend;

use Starmus\includes\StarmusSettings;
use WP_Query; // Use statement for cleaner code.

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

class StarmusAudioRecorderUI
{
    public function __construct()
    {
        add_shortcode('starmus_my_recordings', [$this, 'render_my_recordings_shortcode']);
        add_shortcode('starmus_audio_recorder', [$this, 'render_recorder_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_starmus_handle_upload_chunk', [$this, 'handle_upload_chunk']);
    }

    /**
     * [starmus_my_recordings]
     * Renders a paginated list of the current user's audio submissions from a template.
     */
    public function render_my_recordings_shortcode($atts = []): string
    {
        if (! is_user_logged_in()) {
            return '<p>' . esc_html__('You must be logged in to view your recordings.', 'starmus_audio_recorder') . '</p>';
        }

        $attributes = shortcode_atts(['posts_per_page' => 10], $atts);
        $paged = get_query_var('paged') ? get_query_var('paged') : 1;

        $query = new WP_Query([
            'post_type'      => StarmusSettings::starmus_get_option('cpt_slug', 'audio-recording'),
            'author'         => get_current_user_id(),
            'posts_per_page' => absint($attributes['posts_per_page']),
            'paged'          => $paged,
            'post_status'    => ['publish', 'draft', 'pending'],
        ]);

        // REFACTOR: Use a template for consistency and better organization.
        return $this->render_template(
            'starmus-my-recordings-list.php',
            [
                'query'          => $query,
                'edit_page_url'  => $this->get_edit_page_url(),
            ]
        );
    }

    /**
     * [starmus_audio_recorder]
     * Renders the recorder form by including the template file.
     */
    public function render_recorder_shortcode($atts = []): string
    {
        $attributes = shortcode_atts(['form_id' => 'starmusAudioForm'], $atts);

        return $this->render_template(
            'starmus-audio-recorder-ui.php',
            [
                'form_id'         => esc_attr($attributes['form_id']),
                'consent_message' => StarmusSettings::starmus_get_option('consent_message'),
                'data_policy_url' => StarmusSettings::starmus_get_option('data_policy_url'),
            ]
        );
    }

    /**
     * Enqueues scripts and styles for the recorder and the recordings list.
     */
    public function enqueue_scripts(): void
    {
        // No changes needed here, this is already excellent.
        global $post;
        if (!is_a($post, 'WP_Post') || ! is_singular()) {
            return;
        }

        $has_recorder = has_shortcode($post->post_content, 'starmus_audio_recorder');
        $has_list = has_shortcode($post->post_content, 'starmus_my_recordings');

        if ($has_recorder) {
            wp_enqueue_script('starmus-audio-recorder-module', STARMUS_URL . 'assets/js/starmus-audio-recorder-module.min.js', [], STARMUS_VERSION, ['in_footer' => true]);
            wp_enqueue_script('starmus-audio-recorder-submissions', STARMUS_URL . 'assets/js/starmus-audio-recorder-submissions.min.js', ['starmus-audio-recorder-module'], STARMUS_VERSION, ['in_footer' => true]);

            wp_localize_script('starmus-audio-recorder-submissions', 'starmusFormData', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'action'   => 'starmus_handle_upload_chunk',
                'nonce'    => wp_create_nonce('starmus_chunk_upload'),
            ]);
        }

        if ($has_recorder || $has_list) {
            wp_enqueue_style('starmus-audio-recorder-style', STARMUS_URL . 'assets/css/starmus-audio-recorder-style.min.css', [], STARMUS_VERSION);
        }
    }

    /**
     * Handles incoming audio chunks, reassembles them, and finalizes the submission.
     */
    public function handle_upload_chunk(): void
    {
        // 1. Security & Validation (No changes needed)
        // ... (This part of your code is excellent)

        // --- PERFORMANCE FIX: Only the 'Final Chunk' section is modified ---
        // 5. Handle Final Chunk: Finalize the post and media
        if (($offset + $file_chunk['size']) >= $total_size) {
            $post = $this->find_post_by_uuid($uuid);

            if (!$post) {
                unlink($temp_file_path);
                wp_send_json_error(['message' => esc_html__('Database error: Could not find original submission entry.', 'starmus_audio_recorder')], 500);
            }

            // --- REFACTOR: Move the file instead of reading it into memory ---
            $upload_dir = wp_upload_dir();
            $final_filename = wp_unique_filename($upload_dir['path'], $file_name);
            $final_filepath = $upload_dir['path'] . '/' . $final_filename;

            // Move the completed temporary file to its final destination
            if (!rename($temp_file_path, $final_filepath)) {
                unlink($temp_file_path); // Cleanup temp file
                wp_send_json_error(['message' => esc_html__('Server error: Could not move file to uploads directory.', 'starmus_audio_recorder')], 500);
            }

            // The file is now in place. We just need to create the attachment record for it.
            $file_url = $upload_dir['url'] . '/' . $final_filename;
            $file_type = mime_content_type($final_filepath);

            $attachment_id = wp_insert_attachment([
                'guid'           => $file_url,
                'post_mime_type' => $file_type,
                'post_title'     => preg_replace('/\.[^.]+$/', '', $final_filename),
                'post_content'   => '',
                'post_status'    => 'inherit'
            ], $final_filepath, $post->ID);

            if (is_wp_error($attachment_id)) {
                unlink($final_filepath); // Cleanup failed media entry
                wp_send_json_error(['message' => $attachment_id->get_error_message()], 500);
            }

            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $metadata = wp_generate_attachment_metadata($attachment_id, $final_filepath);
            wp_update_attachment_metadata($attachment_id, $metadata);
            
            // Update the main post
            wp_update_post([
                'ID'           => $post->ID,
                'post_status'  => 'publish', // Or 'pending' for moderation
                'post_content' => '[audio src="' . esc_url($file_url) . '"]',
            ]);
            update_post_meta($post->ID, '_audio_attachment_id', $attachment_id);

            // Note: $temp_file_path has been renamed, so no need to unlink it.

            wp_send_json_success([
                'message' => esc_html__('Submission complete!', 'starmus_audio_recorder'),
                'post_id'   => $post->ID,
            ]);
        }

        wp_send_json_success(['message' => esc_html__('Chunk received.', 'starmus_audio_recorder')]);
    }

    /**
     * A helper method to render a template file with passed variables.
     */
    private function render_template(string $template_name, array $args = []): string
    {
        $template_path = STARMUS_PATH . 'templates/' . $template_name;

        if (!file_exists($template_path)) {
            return '<p>' . esc_html__('Error: A required template file is missing.', 'starmus_audio_recorder') . '</p>';
        }

        // Make variables available to the template.
        extract($args);

        ob_start();
        include $template_path;
        return ob_get_clean();
    }

    /**
     * Gets the URL for the audio editing page from settings, with a fallback.
     */
    private function get_edit_page_url(): string
    {
        // REFACTOR: Avoid hardcoding URL.
        $edit_page_id = StarmusSettings::starmus_get_option('edit_page_id');
        if ($edit_page_id && get_permalink($edit_page_id)) {
            return get_permalink($edit_page_id);
        }
        // Fallback for legacy or un-configured setups.
        return home_url('/edit-audio/');
    }

    /**
     * Helper to find the draft post associated with a chunked upload UUID.
     */
    private function find_post_by_uuid(string $uuid): ?\WP_Post
    {
        $query = new WP_Query([
            'post_type'      => StarmusSettings::starmus_get_option('cpt_slug', 'audio-recording'),
            'meta_key'       => 'audio_uuid',
            'meta_value'     => $uuid,
            'post_status'    => 'draft',
            'posts_per_page' => 1,
            'fields'         => 'ids' // More efficient
        ]);

        if (empty($query->posts)) {
            return null;
        }

        return get_post($query->posts[0]);
    }
}
