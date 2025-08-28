<?php

/**
 * Manages the front-end audio recording submission process and the user's recordings list.
 * This class implements a resilient, chunked upload handler to support intermittent connections.
 *
 * @package Starmus\frontend
 */

namespace Starmus\frontend;

use Starmus\includes\StarmusSettings;
use WP_Query;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class StarmusAudioRecorderUI
{
    /**
     * Constructor. Registers all necessary hooks and shortcodes.
     */
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
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('You must be logged in to view your recordings.', 'starmus_audio_recorder') . '</p>';
        }

        $attributes = shortcode_atts(['posts_per_page' => 10], $atts);
        $paged = get_query_var('paged') ? get_query_var('paged') : 1;

        $query = new WP_Query([
            'post_type'      => StarmusSettings::get('cpt_slug', 'audio-recording'),
            'author'         => get_current_user_id(),
            'posts_per_page' => absint($attributes['posts_per_page']),
            'paged'          => $paged,
            'post_status'    => ['publish', 'draft', 'pending'],
        ]);

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
                'consent_message' => StarmusSettings::get('consent_message'),
                'data_policy_url' => StarmusSettings::get('data_policy_url'),
            ]
        );
    }

    /**
     * Enqueues scripts and styles for the recorder and the recordings list.
     */
    public function enqueue_scripts(): void
    {
        global $post;
        if (!is_a($post, 'WP_Post') || !is_singular()) {
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
        // 1. Security & Validation
        check_ajax_referer('starmus_chunk_upload', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => esc_html__('You do not have permission to upload files.', 'starmus_audio_recorder')], 403);
        }

        $uuid = isset($_POST['audio_uuid']) ? sanitize_key($_POST['audio_uuid']) : '';
        $offset = isset($_POST['chunk_offset']) ? absint($_POST['chunk_offset']) : 0;
        $total_size = isset($_POST['total_size']) ? absint($_POST['total_size']) : 0;
        $file_chunk = $_FILES['audio_file'] ?? null;
        $file_name = isset($_POST['fileName']) ? sanitize_file_name($_POST['fileName']) : 'audio-submission.webm';

        if (empty($uuid) || !$file_chunk || $file_chunk['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => esc_html__('Invalid request: Missing required data.', 'starmus_audio_recorder')], 400);
        }

        // 2. Prepare Temporary Storage and Append Chunk
        $temp_dir = trailingslashit(wp_get_upload_dir()['basedir']) . 'starmus-temp';
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }
        $temp_file_path = $temp_dir . '/' . $uuid . '.part';

        if (false === file_put_contents($temp_file_path, file_get_contents($file_chunk['tmp_name']), FILE_APPEND)) {
            wp_send_json_error(['message' => esc_html__('Server error: Could not write chunk to disk.', 'starmus_audio_recorder')], 500);
        }

        // 3. Handle First Chunk: Create the draft post using form data
        if ($offset === 0) {
            $this->create_draft_post_from_upload_data($uuid, $total_size, $file_name, $_POST);
        }

        // 4. Handle Final Chunk: Finalize the post and media
        if (($offset + $file_chunk['size']) >= $total_size) {
            // Pass $_POST to finalize_submission to make it available for hooks
            $this->finalize_submission($uuid, $file_name, $temp_file_path, $_POST);
        }

        // For all intermediate chunks, just acknowledge success
        wp_send_json_success(['message' => esc_html__('Chunk received.', 'starmus_audio_recorder')]);
    }

    /**
     * Creates the initial draft post using data from the submitted form.
     */
    private function create_draft_post_from_upload_data(string $uuid, int $total_size, string $file_name, array $form_data): void
    {
        $post_title = !empty($form_data['audio_title'])
            ? sanitize_text_field($form_data['audio_title'])
            : sanitize_text_field(pathinfo($file_name, PATHINFO_FILENAME));

        $meta_input = [
            'audio_uuid'        => $uuid,
            'upload_total_size' => $total_size,
        ];

        if (StarmusSettings::get('collect_ip_ua') && !empty($form_data['audio_consent'])) {
            $meta_input['submission_ip'] = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
            $meta_input['submission_user_agent'] = sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
        }

        $post_data = [
            'post_title'   => $post_title,
            'post_type'    => StarmusSettings::get('cpt_slug'),
            'post_status'  => 'draft',
            'post_author'  => get_current_user_id(),
            'meta_input'   => $meta_input,
        ];

        wp_insert_post($post_data);
    }

    /**
     * Finalizes the submission after the last chunk is received.
     */
    private function finalize_submission(string $uuid, string $file_name, string $temp_file_path, array $form_data): void
    {
        $post = $this->find_post_by_uuid($uuid);
        if (!$post) {
            unlink($temp_file_path);
            wp_send_json_error(['message' => esc_html__('Database error: Could not find original submission entry to finalize.', 'starmus_audio_recorder')], 500);
        }

        $upload_dir = wp_get_upload_dir();
        $final_filename = wp_unique_filename($upload_dir['path'], $file_name);
        $final_filepath = $upload_dir['path'] . '/' . $final_filename;

        if (!rename($temp_file_path, $final_filepath)) {
            wp_send_json_error(['message' => esc_html__('Server error: Could not move temp file.', 'starmus_audio_recorder')], 500);
        }

        $file_url = $upload_dir['url'] . '/' . $final_filename;
        $file_type = mime_content_type($final_filepath);

        $attachment_id = wp_insert_attachment([
            'guid'           => $file_url,
            'post_mime_type' => $file_type,
            'post_title'     => sanitize_text_field(pathinfo($final_filename, PATHINFO_FILENAME)),
            'post_status'    => 'inherit'
        ], $final_filepath, $post->ID);

        if (is_wp_error($attachment_id)) {
            unlink($final_filepath);
            wp_send_json_error(['message' => $attachment_id->get_error_message()], 500);
        }

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $metadata = wp_generate_attachment_metadata($attachment_id, $final_filepath);
        wp_update_attachment_metadata($attachment_id, $metadata);

        $waveform_generated = $this->generate_waveform_data($attachment_id);

        wp_update_post([
            'ID'           => $post->ID,
            'post_status'  => 'publish',
            'post_content' => '[audio src="' . esc_url($file_url) . '"]',
        ]);
        update_post_meta($post->ID, '_audio_attachment_id', $attachment_id);

        /**
         * === ACTION HOOK FOR EXTENSIBILITY ===
         * Fires after an audio recording has been successfully uploaded and processed.
         *
         * @param int    $post_id         The ID of the newly created post (audio recording).
         * @param int    $attachment_id   The ID of the attachment representing the audio file.
         * @param array  $form_data       All form data submitted with the audio recording (the $_POST array).
         * @param string $final_filepath  The absolute server path to the final audio file.
         */
        do_action('starmus_audio_recorder_after_upload', $post->ID, $attachment_id, $form_data, $final_filepath);


        /**
         * === FILTER HOOK FOR EXTENSIBILITY ===
         * Allows modification of the success data sent back to the client.
         * Useful for adding a custom redirect URL or other data.
         *
         * @param array  $response_data   The default success response data.
         * @param int    $post_id         The ID of the newly created post.
         * @param int    $attachment_id   The ID of the attachment.
         * @param array  $form_data       All form data submitted with the audio recording.
         */
        $response_data = apply_filters('starmus_audio_recorder_success_response', [
            'message'            => esc_html__('Submission complete!', 'starmus_audio_recorder'),
            'post_id'            => $post->ID,
            'waveform_generated' => $waveform_generated,
        ], $post->ID, $attachment_id, $form_data);

        wp_send_json_success($response_data);
    }

    /**
     * Generates a waveform data file using the audiowaveform tool.
     */
    private function generate_waveform_data(int $attachment_id): bool
    {
        $audio_filepath = get_attached_file($attachment_id);
        if (!$audio_filepath || !file_exists($audio_filepath)) {
            error_log('Starmus Waveform Error: Could not find audio file for attachment ID ' . $attachment_id);
            return false;
        }

        $waveform_filepath = preg_replace('/\.[^.]+$/', '', $audio_filepath) . '.json';

        $command = sprintf(
            'audiowaveform -i %s -o %s -b 8 --pixels-per-second 100 --waveform-color FFFFFF',
            escapeshellarg($audio_filepath),
            escapeshellarg($waveform_filepath)
        );

        @shell_exec($command);

        if (!file_exists($waveform_filepath)) {
            error_log('Starmus Waveform Error: Generation failed. Verify `audiowaveform` is installed. Command: ' . $command);
            return false;
        }

        update_post_meta($attachment_id, '_waveform_json_path', $waveform_filepath);
        return true;
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
        $edit_page_id = StarmusSettings::get('edit_page_id');
        if ($edit_page_id && get_permalink($edit_page_id)) {
            return get_permalink($edit_page_id);
        }
        return home_url('/edit-audio/');
    }

    /**
     * Helper to find the draft post associated with a chunked upload UUID.
     */
    private function find_post_by_uuid(string $uuid): ?\WP_Post
    {
        $query = new WP_Query([
            'post_type'      => StarmusSettings::get('cpt_slug'),
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
