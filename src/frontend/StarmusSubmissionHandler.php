<?php
/**
 * Core submission service responsible for processing uploads.
 *
 * @package   Starmus
 */

namespace Starmus\frontend;

if (!defined('ABSPATH')) {
    exit;
}

use Starmus\helpers\StarmusLogger;
use Starmus\helpers\StarmusSanitizer;
use Starmus\includes\StarmusSettings;
use WP_Error;
use WP_REST_Request;

use function register_rest_route;
use function is_wp_error;
use function wp_upload_dir;
use function wp_insert_attachment;
use function wp_update_attachment_metadata;
use function wp_generate_attachment_metadata;
use function wp_delete_attachment;
use function update_post_meta;
use function wp_update_post;
use function wp_get_attachment_url;
use function media_handle_upload;
use function get_current_user_id;
use function get_transient;
use function set_transient;
use function wp_mkdir_p;
use function trailingslashit;
use function pathinfo;
use function mime_content_type;
use function preg_match;
use function file_put_contents;
use function file_exists;
use function uniqid;
use function glob;
use function filemtime;
use function unlink;
use const MINUTE_IN_SECONDS;
use const DAY_IN_SECONDS;

class StarmusSubmissionHandler
{
    public const STAR_REST_NAMESPACE = 'star-starmus-audio-recorder/v1';
    private ?StarmusSettings $settings;

    public function __construct(?StarmusSettings $settings)
    {
        $this->settings = $settings;
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('starmus_cleanup_temp_files', [$this, 'cleanup_stale_temp_files']);
    }

    public function register_rest_routes(): void
    {
        register_rest_route(
            self::STAR_REST_NAMESPACE,
            '/upload-chunk',
            [
                'methods' => 'POST',
                'callback' => [$this, 'handle_upload_chunk_rest'],
                'permission_callback' => static fn() => current_user_can('upload_files'),
            ]
        );

        register_rest_route(
            self::STAR_REST_NAMESPACE,
            '/upload-fallback',
            [
                'methods' => 'POST',
                'callback' => [$this, 'handle_fallback_upload_rest'],
                'permission_callback' => static fn() => current_user_can('upload_files'),
            ]
        );
    }

    public function handle_upload_chunk_rest(WP_REST_Request $request): array|WP_Error
    {
        try {
            if ($this->is_rate_limited(get_current_user_id())) {
                return new WP_Error('rate_limited', 'You are uploading too frequently.', ['status' => 429]);
            }

            $params = $this->sanitize_submission_data($request->get_json_params() ?? []);
            $validation = $this->validate_chunk_data($params);
            if (is_wp_error($validation)) {
                return $validation;
            }

            $tmp_file = $this->write_chunk_streamed($params);
            if (is_wp_error($tmp_file)) {
                return $tmp_file;
            }

            if (!empty($params['is_last_chunk'])) {
                return $this->finalize_submission($tmp_file, $params);
            }

            return [
                'success' => true,
                'data' => [
                    'status' => 'chunk_received',
                    'file' => $tmp_file,
                ],
            ];
        } catch (\Throwable $e) {
            StarmusLogger::error('REST:upload_chunk', $e);
            return new WP_Error('server_error', 'Failed to process chunk.', ['status' => 500]);
        }
    }

    public function handle_fallback_upload_rest(WP_REST_Request $request): array|WP_Error
    {
        try {
            $form_data = $this->sanitize_submission_data($request->get_params() ?? []);
            $files_data = $request->get_file_params();

            if (empty($files_data['audio_file'])) {
                return new WP_Error('missing_file', 'No audio file provided.');
            }

            return $this->process_fallback_upload($files_data, $form_data);
        } catch (\Throwable $e) {
            StarmusLogger::error('REST:fallback_upload', $e);
            return new WP_Error('server_error', 'Failed to process fallback upload.', ['status' => 500]);
        }
    }

    private function validate_chunk_data(array $params): true|WP_Error
    {
        if (empty($params['upload_id']) || empty($params['chunk_index'])) {
            return new WP_Error('invalid_params', 'Missing upload_id or chunk_index.');
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $params['upload_id'])) {
            return new WP_Error('invalid_id', 'Invalid upload_id format.');
        }
        return true;
    }

    private function write_chunk_streamed(array $params): string|WP_Error
    {
        $temp_dir = $this->get_temp_dir();
        if (!is_dir($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }

        $file_path = $temp_dir . $params['upload_id'] . '.part';
        $chunk = base64_decode($params['data'] ?? '', true);

        if ($chunk === false) {
            return new WP_Error('invalid_chunk', 'Chunk data not valid base64.');
        }

        $result = file_put_contents($file_path, $chunk, FILE_APPEND);
        if ($result === false) {
            return new WP_Error('write_failed', 'Failed to write chunk.');
        }

        return $file_path;
    }

    private function finalize_submission(string $file_path, array $form_data): array|WP_Error
    {
        if (!file_exists($file_path)) {
            return new WP_Error('file_missing', 'No file to finalize.');
        }

        $filename = $form_data['filename'] ?? uniqid('starmus_', true) . '.webm';
        $upload_dir = wp_upload_dir();
        $destination = $upload_dir['path'] . '/' . $filename;

        if (!@rename($file_path, $destination)) {
            return new WP_Error('move_failed', 'Failed to move upload.');
        }

        $attachment_id = wp_insert_attachment(
            [
                'post_mime_type' => mime_content_type($destination),
                'post_title' => pathinfo($filename, PATHINFO_FILENAME),
                'post_content' => '',
                'post_status' => 'inherit',
            ],
            $destination
        );

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $destination));

        $cpt_post_id = $this->create_recording_post((int) $attachment_id, $form_data, $filename);
        if (is_wp_error($cpt_post_id)) {
            wp_delete_attachment($attachment_id, true);
            return $cpt_post_id;
        }

        update_post_meta($cpt_post_id, '_audio_attachment_id', $attachment_id);
        wp_update_post(['ID' => $attachment_id, 'post_parent' => $cpt_post_id]);
        $this->save_all_metadata($cpt_post_id, $attachment_id, $form_data);

        return [
            'success' => true,
            'data' => [
                'attachment_id' => $attachment_id,
                'post_id' => $cpt_post_id,
                'url' => wp_get_attachment_url($attachment_id),
                'redirect_url' => esc_url($this->get_redirect_url()),
            ],
        ];
    }

    public function process_fallback_upload(array $files_data, array $form_data): array|WP_Error
    {
        try {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            $_FILES['audio_file_upload'] = $files_data['audio_file'];
            $attachment_id = media_handle_upload('audio_file_upload', 0);
            if (is_wp_error($attachment_id)) {
                return $attachment_id;
            }

            $cpt_post_id = $this->create_recording_post(
                (int) $attachment_id,
                $form_data,
                $files_data['audio_file']['name']
            );

            if (is_wp_error($cpt_post_id)) {
                wp_delete_attachment($attachment_id, true);
                return $cpt_post_id;
            }

            update_post_meta($cpt_post_id, '_audio_attachment_id', $attachment_id);
            wp_update_post(['ID' => $attachment_id, 'post_parent' => $cpt_post_id]);
            $this->save_all_metadata($cpt_post_id, $attachment_id, $form_data);

            return [
                'success' => true,
                'data' => [
                    'attachment_id' => $attachment_id,
                    'post_id' => $cpt_post_id,
                    'url' => wp_get_attachment_url($attachment_id),
                    'redirect_url' => esc_url($this->get_redirect_url()),
                ],
            ];
        } catch (\Throwable $e) {
            StarmusLogger::error('Fallback upload error', $e);
            return new WP_Error('server_error', 'Failed to process fallback.', ['status' => 500]);
        }
    }

    private function create_recording_post(int $attachment_id, array $form_data, string $original_filename): int|WP_Error
    {
        return wp_insert_post(
            [
                'post_title' => $form_data['starmus_title'] ?? pathinfo($original_filename, PATHINFO_FILENAME),
                'post_type' => $this->settings->get('cpt_slug', 'audio-recording'),
                'post_status' => 'publish',
                'post_author' => get_current_user_id(),
            ]
        );
    }

    public function save_all_metadata(int $audio_post_id, int $attachment_id, array $form_data): void
    {
        $meta = StarmusSanitizer::sanitize_metadata($form_data);
        foreach ($meta as $key => $value) {
            update_post_meta($audio_post_id, $key, $value);
            update_post_meta($attachment_id, $key, $value);
        }
    }

    public function sanitize_submission_data(array $data): array
    {
        return StarmusSanitizer::sanitize_submission_data($data);
    }

    private function is_rate_limited(int $user_id): bool
    {
        $key = 'starmus_rate_' . $user_id;
        $count = (int) get_transient($key);
        if ($count > 10) {
            return true;
        }
        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return false;
    }

    private function get_temp_dir(): string
    {
        return trailingslashit(wp_upload_dir()['basedir']) . 'starmus_tmp/';
    }

    public function cleanup_stale_temp_files(): void
    {
        $dir = $this->get_temp_dir();
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '*.part') as $file) {
            if (filemtime($file) < time() - DAY_IN_SECONDS) {
                @unlink($file);
            }
        }
    }

    private function get_redirect_url(): string
    {
        $redirect_page_id = $this->settings ? $this->settings->get('redirect_page_id', 0) : 0;
        return $redirect_page_id ? get_permalink((int) $redirect_page_id) : home_url('/my-submissions');
    }
}
