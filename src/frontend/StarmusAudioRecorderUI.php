<?php

/**
 * Manages the front-end audio recording submission process and the user's recordings list.
 * This class implements a resilient, chunked upload handler to support intermittent connections.
 *
 * @package Starmus\frontend
 */

namespace Starmus\frontend;

use Starmus\includes\StarmusSettings;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

class StarmusAudioRecorderUI
{
    public function __construct()
    {
        // Register the shortcodes this class provides
        add_shortcode('starmus_my_recordings', [$this, 'render_my_recordings_shortcode']);
        add_shortcode('starmus_audio_recorder', [$this, 'render_recorder_shortcode']);

        // Hook for enqueueing scripts and styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        // --- NEW: A single AJAX hook for the chunked upload process ---
        add_action('wp_ajax_starmus_handle_upload_chunk', [$this, 'handle_upload_chunk']);
        // Note: For submissions by non-logged-in users, you would also add:
        // add_action('wp_ajax_nopriv_starmus_handle_upload_chunk', [$this, 'handle_upload_chunk']);
    }

    /**
     * [starmus_my_recordings]
     * Renders a list of the current user's audio submissions.
     */
    public function render_my_recordings_shortcode(): string
    {
        if (! is_user_logged_in()) {
            return '<p>' . esc_html__('You must be logged in to view your recordings.', 'starmus_audio_recorder') . '</p>';
        }

        $query = new \WP_Query([
            'post_type'      => StarmusSettings::starmus_get_option('cpt_slug', 'audio-recording'),
            'author'         => get_current_user_id(),
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'draft', 'pending'],
        ]);

        if (! $query->have_posts()) {
            return '<p>' . esc_html__('You have not submitted any recordings yet.', 'starmus_audio_recorder') . '</p>';
        }

        ob_start();
        echo '<div class="starmus-my-recordings-list">';
        while ($query->have_posts()) {
            $query->the_post();
            $attachment_id = get_post_meta(get_the_ID(), '_audio_attachment_id', true);
            $audio_url     = $attachment_id ? wp_get_attachment_url($attachment_id) : '';
            $edit_page_url = home_url('/edit-audio/'); // Assumes a page with this slug exists
            $edit_link     = add_query_arg('post_id', get_the_ID(), $edit_page_url);
?>
            <div class="starmus-recording-item">
                <h4><?php the_title(); ?></h4>
                <p><em><?php echo esc_html(get_the_date()); ?> (<?php echo esc_html__('Status:', 'starmus_audio_recorder'); ?> <?php echo esc_html(get_post_status()); ?>)</em></p>
                <?php if ($audio_url) : ?>
                    <audio controls src="<?php echo esc_url($audio_url); ?>"></audio>
                    <p><a href="<?php echo esc_url($edit_link); ?>" class="button"><?php esc_html_e('Edit Details & Annotate', 'starmus_audio_recorder'); ?></a></p>
                <?php else : ?>
                    <p><em><?php esc_html_e('Audio file is processing or missing.', 'starmus_audio_recorder'); ?></em></p>
                <?php endif; ?>
            </div>
<?php
        }
        echo '</div>';
        wp_reset_postdata();
        return ob_get_clean();
    }

    /**
     * [starmus_audio_recorder]
     * Renders the recorder form by including the template file.
     */
    public function render_recorder_shortcode($atts = []): string
    {
        // FIX: Define the variables that the template needs to use.
        $attributes = shortcode_atts(['form_id' => 'starmusAudioForm'], $atts);
        $form_id = esc_attr($attributes['form_id']);
        $consent_message = StarmusSettings::starmus_get_option('consent_message');
        $data_policy_url = StarmusSettings::starmus_get_option('data_policy_url');

        ob_start();
        $template_path = STARMUS_PATH . 'templates/starmus-audio-recorder-ui.php';

        if (file_exists($template_path)) {
            // The variables $form_id, $consent_message, and $data_policy_url are now available inside the included file.
            include $template_path;
        } else {
            return '<p>' . esc_html__('Error: Audio recorder form template not found.', 'starmus_audio_recorder') . '</p>';
        }
        return ob_get_clean();
    }

    /**
     * Enqueues scripts and styles for the recorder and the recordings list.
     */
    public function enqueue_scripts(): void
    {
        global $post;
        if (!is_a($post, 'WP_Post')) {
            return;
        }

        $has_recorder = has_shortcode($post->post_content, 'starmus_audio_recorder');
        $has_list = has_shortcode($post->post_content, 'starmus_my_recordings');

        if ($has_recorder) {
            // Enqueue the two JS files required for the form to function
            wp_enqueue_script('starmus-audio-recorder-module', STARMUS_URL . 'assets/js/starmus-audio-recorder-module.min.js', [], STARMUS_VERSION, true);
            wp_enqueue_script('starmus-audio-recorder-submissions', STARMUS_URL . 'assets/js/starmus-audio-recorder-submissions.min.js', ['starmus-audio-recorder-module'], STARMUS_VERSION, true);

            // Pass the NEW action name and nonce to the submission script
            wp_localize_script('starmus-audio-recorder-submissions', 'starmusFormData', [
                'ajax_url'      => admin_url('admin-ajax.php'),
                'action'        => 'starmus_handle_upload_chunk', // Use the new single endpoint
                'nonce'         => wp_create_nonce('starmus_chunk_upload'),
            ]);
        }

        // Load the stylesheet if either the recorder or the list is on the page
        if ($has_recorder || $has_list) {
            wp_enqueue_style('starmus-audio-recorder-style', STARMUS_URL . 'assets/css/starmus-audio-recorder-style.min.css', [], STARMUS_VERSION);
        }
    }

    /**
     * Handles incoming audio chunks, reassembles them, and finalizes the submission.
     * This is the new, single endpoint for the resilient uploader.
     */
    public function handle_upload_chunk(): void
    {
        // 1. Security & Validation
        check_ajax_referer('starmus_chunk_upload', 'nonce');

        if (! current_user_can('upload_files')) {
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

        $max_size_mb   = (int) StarmusSettings::starmus_get_option('file_size_limit');
        $max_size_bytes = $max_size_mb * 1024 * 1024;
        if ($max_size_bytes > 0 && $file_chunk['size'] > $max_size_bytes) {
            wp_send_json_error(['message' => esc_html__('File exceeds maximum allowed size.', 'starmus_audio_recorder')], 400);
        }

        $allowed_types = StarmusSettings::starmus_get_option('allowed_file_types', '');
        $allowed       = array_map('strtolower', array_map('trim', explode(',', $allowed_types)));
        $file_info     = wp_check_filetype_and_ext($file_chunk['tmp_name'], $file_name);
        if (! $file_info['type'] || ! in_array(strtolower($file_info['ext']), $allowed, true)) {
            wp_send_json_error(['message' => esc_html__('Invalid file type.', 'starmus_audio_recorder')], 400);
        }

        // 2. Prepare Temporary Storage
        $temp_dir = trailingslashit(wp_upload_dir()['basedir']) . 'starmus-temp';
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }
        $temp_file_path = $temp_dir . '/' . $uuid . '.part';

        // 3. Append Chunk to Temporary File
        $chunk_content = file_get_contents($file_chunk['tmp_name']);

        if (false === $chunk_content) {
            wp_send_json_error(['message' => esc_html__('Server error: Could not read uploaded chunk.', 'starmus_audio_recorder')], 500);
        }

        if (false === file_put_contents($temp_file_path, $chunk_content, FILE_APPEND)) {
            wp_send_json_error(['message' => esc_html__('Server error: Could not write chunk to disk.', 'starmus_audio_recorder')], 500);
        }

        // 4. Handle First Chunk: Create the draft post
        if ($offset === 0) {
            $meta_input = [
                'audio_uuid'        => $uuid,
                'upload_total_size' => $total_size,
            ];

            if (StarmusSettings::starmus_get_option('collect_ip_ua') && ! empty($_POST['audio_consent'])) {
                $meta_input['submission_ip'] = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
                $meta_input['submission_user_agent'] = sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
            }

            $post_data = [
                'post_title'   => $file_name,
                'post_type'    => StarmusSettings::starmus_get_option('cpt_slug', 'audio-recording'),
                'post_status'  => 'draft',
                'post_author'  => get_current_user_id(),
                'meta_input'   => $meta_input,
            ];
            wp_insert_post($post_data);
        }

        // 5. Handle Final Chunk: Finalize the post and media
        if (($offset + $file_chunk['size']) >= $total_size) {
            $post_query = new \WP_Query([
                'post_type' => StarmusSettings::starmus_get_option('cpt_slug', 'audio-recording'),
                'meta_key' => 'audio_uuid',
                'meta_value' => $uuid,
                'post_status' => 'draft',
                'posts_per_page' => 1
            ]);
            $post = $post_query->posts[0] ?? null;

            if (!$post) {
                unlink($temp_file_path); // Cleanup temp file
                wp_send_json_error(['message' => esc_html__('Database error: Could not find original submission entry to finalize.', 'starmus_audio_recorder')], 500);
            }

            // Move the completed temp file into the WordPress media library
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');

            $upload = wp_upload_bits($file_name, null, file_get_contents($temp_file_path));

            if (!empty($upload['error'])) {
                unlink($temp_file_path); // Cleanup temp file
                wp_send_json_error([
                    'message' => sprintf(
                        esc_html__('File finalization error: %s', 'starmus_audio_recorder'),
                        esc_html($upload['error'])
                    )
                ], 500);
            }

            $attachment_id = wp_insert_attachment([
                'guid'           => $upload['url'],
                'post_mime_type' => $upload['type'],
                'post_title'     => preg_replace('/\.[^.]+$/', '', $file_name),
                'post_content'   => '',
                'post_status'    => 'inherit'
            ], $upload['file'], $post->ID);

            $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);

            if (empty($metadata) || is_wp_error($metadata)) {
                if (defined('WP_DEBUG') && WP_DEBUG && is_wp_error($metadata)) {
                    error_log('Metadata generation error: ' . $metadata->get_error_message());
                }

                wp_delete_attachment($attachment_id, true);
                unlink($temp_file_path); // Cleanup failed upload

                wp_send_json_error([
                    'message' => esc_html__('Metadata processing error.', 'starmus_audio_recorder'),
                ], 500);
            }

            wp_update_attachment_metadata($attachment_id, $metadata);

            // Update the main post to publish it and link the attachment
            wp_update_post([
                'ID'           => $post->ID,
                'post_status'  => 'publish',
                'post_content' => '[audio src="' . esc_url($upload['url']) . '"]',
            ]);
            update_post_meta($post->ID, '_audio_attachment_id', $attachment_id);

            unlink($temp_file_path); // Cleanup successful upload

            wp_send_json_success([
                'message' => esc_html__('Submission complete!', 'starmus_audio_recorder'),
                'post_id'   => $post->ID,
            ]);
        }

        // For all intermediate chunks, just acknowledge success
        wp_send_json_success(['message' => esc_html__('Chunk received.', 'starmus_audio_recorder')]);
    }
}
