<?php

declare(strict_types=1);

/**
 * Core submission service responsible for processing uploads using the DAL.
 *
 * @package   Starisian\Sparxstar\Starmus\includes
 * @version   6.8.0-FULL-RESTORATION
 */

namespace Starisian\Sparxstar\Starmus\includes;

if (! \defined('ABSPATH')) {
    exit;
}

use function array_filter;
use function array_map;
use function array_values;
use const DAY_IN_SECONDS;
use function fclose;
use function file_exists;
use function file_put_contents;
use function filemtime;
use function filesize;
use function fopen;
use function get_current_user_id;
use function get_permalink;
use function get_post_meta;
use function get_post_type;
use function glob;
use function home_url;
use function is_dir;
use function is_wp_error;
use function json_decode;
use function mime_content_type;
use function move_uploaded_file;
use function pathinfo;
use function preg_match;
use function rmdir;
use function sanitize_key;
use function sanitize_text_field;
use function stream_copy_to_stream;
use function strtolower;
use function strpos; // PHP 7.4 Compat
use function sys_get_temp_dir;
use function time;
use function trailingslashit;
use function uniqid;
use function unlink;
use function wp_delete_file;
use function wp_get_attachment_url;
use function wp_json_encode;
use function wp_mkdir_p;
use function wp_next_scheduled;
use function wp_schedule_single_event;
use function wp_set_post_terms;
use function wp_unique_filename;
use function wp_unslash;
use function wp_upload_dir;

use Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface;
use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\helpers\StarmusSanitizer;
use Starisian\Sparxstar\Starmus\services\StarmusPostProcessingService;
use Throwable;
use WP_Error;
use WP_REST_Request;

final class StarmusSubmissionHandler
{
    public const STARMUS_REST_NAMESPACE = 'star-starmus-audio-recorder/v1';

    private ?StarmusSettings $settings;
    private StarmusAudioRecorderDALInterface $dal;

    private array $fallback_file_keys = ['audio_file', 'file', 'upload'];
    private array $default_allowed_mimes = [
        'audio/webm', 'audio/webm;codecs=opus', 'audio/ogg', 'audio/ogg;codecs=opus', 'audio/mpeg', 'audio/wav',
    ];

    public function __construct(StarmusAudioRecorderDALInterface $DAL, StarmusSettings $settings)
    {
        try {
            $this->dal      = $DAL;
            $this->settings = $settings;
            add_action('starmus_cleanup_temp_files', [$this, 'cleanup_stale_temp_files']);
            StarmusLogger::info('SubmissionHandler', 'Constructed successfully');
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
            throw $throwable;
        }
    }

    /* ======================================================================
    * UPLOAD HANDLERS
    * ==================================================================== */

    public function process_completed_file(string $file_path, array $form_data): array|WP_Error
    {
        return $this->_finalize_from_local_disk($file_path, $form_data);
    }

    private function _finalize_from_local_disk(string $file_path, array $form_data): array|WP_Error
    {
        try {
            if (! file_exists($file_path)) {
                return $this->err('file_missing', 'No file to process.', 400, ['path' => $file_path]);
            }

            $filename    = $form_data['filename'] ?? pathinfo($file_path, PATHINFO_BASENAME);
            $upload_dir  = wp_upload_dir();
            $destination = trailingslashit($upload_dir['path']) . wp_unique_filename($upload_dir['path'], $filename);

            $mime  = (string) ($form_data['filetype'] ?? mime_content_type($file_path));
            $size  = (int) @filesize($file_path);
            
            $valid = $this->validate_file_against_settings($mime, $size);
            if (is_wp_error($valid)) {
                @wp_delete_file($file_path);
                return $valid;
            }

            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }

            if (! $wp_filesystem->move($file_path, $destination, true)) {
                @wp_delete_file($file_path);
                return $this->err('move_failed', 'Failed to move upload.', 500);
            }

            $attachment_id = $this->dal->create_attachment_from_file($destination, $filename);
            if (is_wp_error($attachment_id)) {
                @wp_delete_file($destination);
                return $attachment_id;
            }

            // RE-RECORDER FIX: Check for 'post_id' to trigger update logic
            $existing_post_id = isset($form_data['post_id']) ? absint($form_data['post_id']) : 0;

            if ($existing_post_id > 0 && get_post_type($existing_post_id) === $this->get_cpt_slug()) {
                $cpt_post_id = $existing_post_id;
                $old_attachment_id = (int) get_post_meta($cpt_post_id, '_audio_attachment_id', true);
                if ($old_attachment_id > 0) $this->dal->delete_attachment($old_attachment_id);
            } else {
                $user_id = isset($form_data['user_id']) ? absint($form_data['user_id']) : get_current_user_id();
                $cpt_post_id = $this->dal->create_audio_post(
                    $form_data['starmus_title'] ?? pathinfo($filename, PATHINFO_FILENAME),
                    $this->get_cpt_slug(),
                    $user_id
                );
                if (is_wp_error($cpt_post_id)) {
                    $this->dal->delete_attachment((int) $attachment_id);
                    return $cpt_post_id;
                }
            }

            $this->dal->save_post_meta((int) $cpt_post_id, '_audio_attachment_id', (int) $attachment_id);
            $this->dal->set_attachment_parent((int) $attachment_id, (int) $cpt_post_id);
            $this->save_all_metadata((int) $cpt_post_id, (int) $attachment_id, $form_data);

            do_action('starmus_after_audio_saved', (int) $cpt_post_id, $form_data);

            return [
                'success'       => true,
                'attachment_id' => (int) $attachment_id,
                'post_id'       => (int) $cpt_post_id,
                'url'           => wp_get_attachment_url((int) $attachment_id),
            ];
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
            return $this->err('server_error', 'File finalization failed.', 500);
        }
    }

    public function handle_fallback_upload_rest(WP_REST_Request $request): array|WP_Error
    {
        try {
            if ($this->is_rate_limited(get_current_user_id())) {
                return $this->err('rate_limited', 'Too frequent.', 429);
            }

            $form_data  = $this->sanitize_submission_data($request->get_params() ?? []);
            $files_data = $request->get_file_params() ?? [];

            $file_key = $this->detect_file_key($files_data);
            if (! $file_key) {
                return $this->err('missing_file', 'No audio file provided.', 400);
            }

            $file = $files_data[$file_key];
            if (! isset($file['error']) || (int) $file['error'] !== 0 || empty($file['tmp_name'])) {
                return $this->err('upload_error', 'Upload failed on client.', 400);
            }

            $validation = $this->validate_file_against_settings($file['type'] ?? '', (int) ($file['size'] ?? 0));
            if (is_wp_error($validation)) return $validation;

            $result = $this->process_fallback_upload($files_data, $form_data, $file_key);
            
            if (is_wp_error($result)) return $result;

            return ['success' => true, 'data' => $result['data']];
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
            return $this->err('server_error', 'Fallback upload exception.', 500);
        }
    }

    public function process_fallback_upload(array $files_data, array $form_data, string $file_key): array|WP_Error
    {
        try {
            // 500 ERROR FIX: Ensure media functions are loaded
            if (!function_exists('media_handle_sideload')) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
            }

            if (empty($files_data[$file_key])) {
                return $this->err('missing_file', 'No audio file provided.', 400);
            }

            $attachment_id = $this->dal->create_attachment_from_sideload($files_data[$file_key]);
            if (is_wp_error($attachment_id)) return $attachment_id;

            $existing_id = isset($form_data['post_id']) ? absint($form_data['post_id']) : 0;
            
            if ($existing_id > 0 && get_post_type($existing_id) === $this->get_cpt_slug()) {
                $cpt_post_id = $existing_id;
            } else {
                $title = $form_data['starmus_title'] ?? pathinfo((string) $files_data[$file_key]['name'], PATHINFO_FILENAME);
                $cpt_post_id = $this->dal->create_audio_post($title, $this->get_cpt_slug(), get_current_user_id());
            }

            if (is_wp_error($cpt_post_id)) {
                $this->dal->delete_attachment((int) $attachment_id);
                return $cpt_post_id;
            }

            $this->dal->save_post_meta((int) $cpt_post_id, '_audio_attachment_id', (int) $attachment_id);
            $this->dal->set_attachment_parent((int) $attachment_id, (int) $cpt_post_id);
            
            $form_data['audio_files_originals'] = (int) $attachment_id;
            $this->save_all_metadata((int) $cpt_post_id, (int) $attachment_id, $form_data);

            return [
                'success' => true,
                'data'    => [
                    'attachment_id' => (int) $attachment_id,
                    'post_id'       => (int) $cpt_post_id,
                    'url'           => wp_get_attachment_url((int) $attachment_id),
                    'redirect_url'  => esc_url($this->get_redirect_url()),
                ],
            ];
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
            return $this->err('server_error', 'Fallback processing failed.', 500);
        }
    }

    /*
    ======================================================================
     * METADATA SAVING (RESTORED FULL LOGIC)
     * ==================================================================== */

    public function save_all_metadata(int $audio_post_id, int $attachment_id, array $form_data): void
    {
        try {
            $metadata = $form_data;

            // --- 1. RESOLVE TELEMETRY ---
            $all_telemetry = class_exists('\Starisian\SparxstarUEC\StarUserEnv') ? (\Starisian\SparxstarUEC\StarUserEnv::get_snapshot() ?? []) : [];

            $env_json_raw = $form_data['_starmus_env'] ?? '';
            $env_snapshot = $env_json_raw ? (json_decode(wp_unslash($env_json_raw), true) ?: []) : [];
            if ($env_json_raw) $this->update_acf_field('environment_data', wp_unslash($env_json_raw), $audio_post_id);
            
            // --- 2. EXTRACT & SAVE SPECIFIC METADATA ---
            $client_data = $all_telemetry['client_side_data']['identifiers_extra'] ?? [];
            $raw_technical_data = $client_data['technical']['raw'] ?? $env_snapshot['technical']['raw'] ?? [];
            $browser_data = $raw_technical_data['browser'] ?? $env_snapshot['browser'] ?? [];
            $os_data = $env_snapshot['device']['os'] ?? [];

            $calibration_json = $form_data['_starmus_calibration'] ?? '';
            if ($calibration_json) $this->update_acf_field('runtime_metadata', wp_unslash($calibration_json), $audio_post_id);
            
            $transcript_text = $form_data['first_pass_transcription'] ?? '';
            if ($transcript_text) $this->update_acf_field('first_pass_transcription', wp_unslash($transcript_text), $audio_post_id);
            
            $full_ua_string = $env_snapshot['device']['userAgent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? '';
            $this->update_acf_field('user_agent', sanitize_text_field($full_ua_string), $audio_post_id);

            $this->update_acf_field('submission_ip', StarmusSanitizer::get_user_ip(), $audio_post_id);
            
            $fingerprint_value = isset($env_snapshot['identifiers']) ? wp_json_encode($env_snapshot['identifiers']) : '';
            if ($fingerprint_value) $this->update_acf_field('device_fingerprint', $fingerprint_value, $audio_post_id);

            // --- 3. SAVE STANDARD FIELDS & TAXONOMIES ---
            foreach (['project_collection_id', 'accession_number', 'location', 'contributor_id'] as $field) {
                if (isset($form_data[$field])) {
                    $this->update_acf_field($field, sanitize_text_field((string) wp_unslash($form_data[$field])), $audio_post_id);
                }
            }
            if ($attachment_id !== 0) $this->update_acf_field('audio_files_originals', $attachment_id, $audio_post_id);

            if (!empty($form_data['language'])) wp_set_post_terms($audio_post_id, [(int)$form_data['language']], 'language');
            if (!empty($form_data['recording_type'])) wp_set_post_terms($audio_post_id, [(int)$form_data['recording_type']], 'recording-type');

            do_action('starmus_after_save_submission_metadata', $audio_post_id, $form_data, $metadata);

            // --- 4. TRIGGER POST-PROCESSING ---
            $this->trigger_post_processing($audio_post_id, $attachment_id, []);
            
        } catch (\Throwable $throwable) {
            error_log($throwable->getMessage());
        }
    }

    private function trigger_post_processing(int $post_id, int $attachment_id, array $params): void
    {
        try {
            $processor = new StarmusPostProcessingService();
            if (!$processor->process($post_id, $attachment_id, $params)) {
                if (! wp_next_scheduled('starmus_cron_process_pending_audio', [$post_id, $attachment_id])) {
                    wp_schedule_single_event(time() + 60, 'starmus_cron_process_pending_audio', [$post_id, $attachment_id]);
                }
            }
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
        }
    }
    
    /*
    ======================================================================
     * HELPER METHODS (RESTORED)
     * ==================================================================== */

    public function get_cpt_slug(): string
    {
        $slug = $this->settings ? $this->settings->get('cpt_slug', 'audio-recording') : 'audio-recording';
        return \is_string($slug) && $slug !== '' ? sanitize_key($slug) : 'audio-recording';
    }

    private function update_acf_field(string $field_key, $value, int $post_id): void
    {
        $this->dal->save_post_meta($post_id, $field_key, $value);
    }

    public function sanitize_submission_data(array $data): array
    {
        return StarmusSanitizer::sanitize_submission_data($data);
    }

    private function is_rate_limited(int $user_id): bool
    {
        return $this->dal->is_rate_limited($user_id);
    }

    private function get_temp_dir(): string
    {
        $base = wp_upload_dir()['basedir'] ?? '';
        return trailingslashit($base ?: sys_get_temp_dir()) . 'starmus_tmp/';
    }

    public function cleanup_stale_temp_files(): void
    {
        $dir = $this->get_temp_dir();
        if (is_dir($dir)) {
            foreach (glob($dir . '*.part') as $file) {
                if (@filemtime($file) < time() - DAY_IN_SECONDS) @wp_delete_file($file);
            }
        }
    }

    private function get_redirect_url(): string
    {
        $id = $this->settings ? (int) $this->settings->get('redirect_page_id', 0) : 0;
        return $id ? (string) get_permalink($id) : (string) home_url('/my-submissions');
    }

    private function validate_file_against_settings(string $mime, int $size_bytes): true|WP_Error
    {
        $max_mb = $this->settings ? (int)$this->settings->get('file_size_limit', 10) : 10;
        if ($max_mb > 0 && $size_bytes > ($max_mb * 1024 * 1024)) {
            return $this->err('file_too_large', "Max ${max_mb}MB", 413);
        }

        $allowed = $this->settings ? $this->settings->get('allowed_file_types', '') : '';
        $exts = $allowed ? array_map('trim', explode(',', $allowed)) : $this->default_allowed_mimes;
        if (empty($exts)) $exts = ['webm', 'mp3', 'wav', 'ogg'];

        $mime_lc = strtolower($mime);
        foreach ($exts as $ext) {
            // PHP 7.4 Compat
            if (strpos($mime_lc, strtolower((string)$ext)) !== false) return true;
        }
        return $this->err('mime_not_allowed', 'Type not allowed: ' . $mime_lc, 415);
    }

    private function err(string $code, string $message, int $status = 400, array $context = []): WP_Error
    {
        try {
            $cid = StarmusLogger::getCorrelationId();
            StarmusLogger::warning('SubmissionHandler', "$code: $message", array_merge($context, ['status' => $status, 'cid' => $cid]));
            return new WP_Error($code, $message, ['status' => $status, 'cid' => $cid]);
        } catch (Throwable $e) {
            return new WP_Error($code, $message, ['status' => $status]);
        }
    }

    private function ensure_uploads_writable(): true|WP_Error
    {
        $uploads = wp_upload_dir();
        $base = $uploads['basedir'] ?? '';
        if (!$base) return $this->err('uploads_unavailable', 'Uploads dir unavailable.', 500);

        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if (!$wp_filesystem->is_writable($base)) {
            return $this->err('uploads_unwritable', 'Uploads dir not writable.', 500);
        }
        return true;
    }

    private function detect_file_key(array $files): ?string
    {
        foreach ($this->fallback_file_keys as $key) {
            if (!empty($files[$key]) && is_array($files[$key])) return $key;
        }
        foreach ($files as $key => $val) {
            if (is_array($val) && !empty($val['tmp_name'])) return (string) $key;
        }
        return null;
    }
    
    // --- CHUNK HELPERS ---

    private function cleanup_chunks_dir(string $path): void
    {
        // PHP 7.4 Compat
        if (strpos($path, $this->get_temp_dir()) !== false && is_dir($path)) {
            array_map('unlink', glob($path . '*'));
            @rmdir($path);
        }
    }

    private function combine_chunks_multipart(string $id, string $base, int $total): string|WP_Error
    {
        $final = $this->get_temp_dir() . $id . '.tmp.file';
        $fp = @fopen($final, 'wb');
        if (!$fp) return $this->err('combine_open_failed', 'File create error', 500);

        for ($i = 0; $i < $total; $i++) {
            $chunk = $base . 'chunk_' . $i;
            if (!file_exists($chunk)) {
                usleep(500000); 
                clearstatcache();
            }
            if (!file_exists($chunk)) {
                fclose($fp); @unlink($final);
                return $this->err('missing_chunk', "Chunk $i missing", 400);
            }
            $chunk_fp = @fopen($chunk, 'rb');
            if (!$chunk_fp) {
                fclose($fp); return $this->err('read_chunk_failed', "Chunk $i read error", 500);
            }
            stream_copy_to_stream($chunk_fp, $fp);
            fclose($chunk_fp);
        }
        fclose($fp);
        return $final;
    }
}