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

/**
 * Handles validation and persistence for audio submissions.
 *
 * The class exposes helpers that can be wired into REST callbacks or
 * traditional form submissions. It purposely avoids registering REST
 * routes directly so the caller controls exposure and permissions.
 */
class StarmusSubmissionHandler
{
    /**
     * Namespaced identifier used for REST endpoints.
     */
    public const STAR_REST_NAMESPACE = 'star-starmus-audio-recorder/v1';

    /**
     * Lazily injected plugin settings service.
     */
    private ?StarmusSettings $settings;

    /**
     * Build the submission handler and wire scheduled maintenance hooks.
     *
     * @param StarmusSettings|null $settings Optional plugin settings adapter.
     */
    public function __construct(?StarmusSettings $settings)
    {
        $this->settings = $settings;
        add_action('starmus_cleanup_temp_files', [$this, 'cleanup_stale_temp_files']);
    }

    /**
     * Process a chunked upload request coming from the REST API.
     *
     * @param WP_REST_Request $request Incoming WordPress REST request.
     * @return array|WP_Error Response payload on success or error object.
     */
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

    /**
     * Handle the REST fallback upload that uses multipart/form-data.
     *
     * @param WP_REST_Request $request Incoming WordPress REST request.
     * @return array|WP_Error Response payload on success or error object.
     */
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

    /**
     * Validate the minimal payload required for chunk persistence.
     *
     * @param array $params Sanitized request parameters.
     * @return true|WP_Error True when valid or WP_Error on failure.
     */
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

    /**
     * Append a decoded chunk to the temporary upload file on disk.
     *
     * @param array $params Sanitized request parameters.
     * @return string|WP_Error File path of the temporary upload or error.
     */
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

    /**
     * Finalize a chunked upload by promoting the temporary file to media.
     *
     * @param string $file_path Temporary file path.
     * @param array  $form_data Sanitized submission context.
     * @return array|WP_Error Finalized payload or error object.
     */
    private function finalize_submission(string $file_path, array $form_data): array|WP_Error
    {
        if (!file_exists($file_path)) {
            return new WP_Error('file_missing', 'No file to finalize.');
        }

        $filename = $form_data['filename'] ?? uniqid('starmus_', true) . '.webm';
        $upload_dir = wp_upload_dir();
        $destination = $upload_dir['path'] . '/' . $filename;

        if (!@rename($file_path, $destination)) {
            if (!@unlink($file_path)) {
                StarmusLogger::error("Failed to delete temporary file: " . basename($file_path));
            }
            return new WP_Error('move_failed', 'Failed to move upload.');
        }

        $mime_type = mime_content_type($destination) ?: '';
        if (!preg_match('#^audio/([a-z0-9.+-]+)$#i', $mime_type)) {
            if (!@unlink($destination)) {
                if (class_exists('\Starmus\helpers\StarmusLogger')) {
                    \Starmus\helpers\StarmusLogger::error("Failed to delete invalid audio file: $destination");
                }
            }
            return new WP_Error('invalid_type', 'Uploaded file must be an audio format.');
        }

        $attachment_id = wp_insert_attachment(
            [
                'post_mime_type' => $mime_type,
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

    /**
     * Process classic form uploads submitted through multipart endpoints.
     *
     * @param array $files_data Normalized $_FILES array from the request.
     * @param array $form_data  Sanitized form parameters.
     * @return array|WP_Error Result payload or WP_Error when validation fails.
     */
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

    /**
     * Persist the custom post type entry that pairs with the attachment.
     *
     * @param int    $attachment_id WordPress media attachment identifier.
     * @param array  $form_data     Sanitized submission metadata.
     * @param string $original_filename Original filename for title fallback.
     * @return int|WP_Error Post ID on success or WP_Error on failure.
     */
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

    /**
     * Store sanitized metadata on both the CPT record and attachment.
     *
     * @param int   $audio_post_id  Identifier of the CPT post.
     * @param int   $attachment_id  Identifier of the attachment.
     * @param array $form_data      Sanitized submission metadata.
     * @return void
     */
    public function save_all_metadata(int $audio_post_id, int $attachment_id, array $form_data): void
    {
        $meta = StarmusSanitizer::sanitize_metadata($form_data);
        foreach ($meta as $key => $value) {
            update_post_meta($audio_post_id, $key, $value);
            update_post_meta($attachment_id, $key, $value);
        }
    }

    /**
     * Proxy helper to sanitize request data using the shared sanitizer.
     *
     * @param array $data Raw request payload.
     * @return array Sanitized data.
     */
    public function sanitize_submission_data(array $data): array
    {
        return StarmusSanitizer::sanitize_submission_data($data);
    }

    /**
     * Determine whether the current user has exceeded rate limits.
     *
     * @param int $user_id WordPress user identifier.
     * @return bool True when throttled.
     */
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

    /**
     * Compute the path used to store temporary upload chunks.
     *
     * @return string Absolute path inside the uploads directory.
     */
    private function get_temp_dir(): string
    {
        return trailingslashit(wp_upload_dir()['basedir']) . 'starmus_tmp/';
    }

    /**
     * Remove stale chunk files older than one day to reclaim disk space.
     *
     * @return void
     */
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

    /**
     * Resolve redirect location after a successful submission.
     *
     * @return string Absolute URL pointing to the submissions list.
     */
    private function get_redirect_url(): string
    {
        $redirect_page_id = $this->settings ? $this->settings->get('redirect_page_id', 0) : 0;
        return $redirect_page_id ? get_permalink((int) $redirect_page_id) : home_url('/my-submissions');
    }
}
