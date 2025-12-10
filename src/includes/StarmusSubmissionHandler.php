<?php

declare(strict_types=1);

/**
 * Core submission service responsible for processing uploads using the DAL.
 *
 * @package   Starisian\Sparxstar\Starmus\includes
 * @version   6.6.0-STABLE
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
use function strtolower;
use function strpos; // PHP 7.4 Compat
use function stream_copy_to_stream;
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

/**
 * Handles validation and persistence for audio submissions (DAL integrated).
 */
final class StarmusSubmissionHandler
{
    /**
     * REST API namespace.
     */
    public const STARMUS_REST_NAMESPACE = 'star-starmus-audio-recorder/v1';

    /**
     * Settings service instance.
     */
    private ?StarmusSettings $settings;

    /**
     * Data Access Layer instance.
     */
    private StarmusAudioRecorderDALInterface $dal;

    /**
     * Allow a small list of safe upload keys the client might send.
     */
    private array $fallback_file_keys = ['audio_file', 'file', 'upload'];

    /**
     * Default allowed mime types if settings are empty.
     */
    private array $default_allowed_mimes = [
        'audio/webm',
        'audio/webm;codecs=opus',
        'audio/ogg',
        'audio/ogg;codecs=opus',
        'audio/mpeg',
        'audio/wav',
    ];

    public function __construct(StarmusAudioRecorderDALInterface $DAL, StarmusSettings $settings)
    {
        try {
            $this->dal      = $DAL;
            $this->settings = $settings;

            // Scheduled cleanup of temp chunk files.
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

    /**
     * Processes a file that is already fully present on the local filesystem.
     */
    public function process_completed_file(string $file_path, array $form_data): array|WP_Error
    {
        StarmusLogger::timeStart('process_completed_file');
        try {
            $result = $this->_finalize_from_local_disk($file_path, $form_data);
            StarmusLogger::timeEnd('process_completed_file', 'SubmissionHandler');
            return $result;
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
            return $this->err('server_error', 'Failed to process completed file.', 500);
        }
    }

    /**
     * UNIFIED Core finalization pipeline for a completed file already on the local disk.
     */
    private function _finalize_from_local_disk(string $file_path, array $form_data): array|WP_Error
    {
        try {
            StarmusLogger::timeStart('finalize_from_disk');

            if (! file_exists($file_path)) {
                return $this->err('file_missing', 'No file to process.', 400, ['path' => $file_path]);
            }

            // --- 1. Validation & Destination Setup ---
            $filename    = $form_data['filename'] ?? pathinfo($file_path, PATHINFO_BASENAME);
            $upload_dir  = wp_upload_dir();
            $destination = trailingslashit($upload_dir['path']) . wp_unique_filename($upload_dir['path'], $filename);

            $mime  = (string) ($form_data['filetype'] ?? mime_content_type($file_path));
            $size  = (int) @filesize($file_path);
            
            $valid = $this->validate_file_against_settings($mime, $size);
            if (is_wp_error($valid)) {
                wp_delete_file($file_path);
                return $valid;
            }

            // --- 2. File System Move ---
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }

            if (! $wp_filesystem->move($file_path, $destination, true)) {
                wp_delete_file($file_path);
                return $this->err('move_failed', 'Failed to move upload into uploads path.', 500, ['dest' => $destination]);
            }

            // --- 3. Attachment Creation ---
            $attachment_id = $this->dal->create_attachment_from_file($destination, $filename);
            if (is_wp_error($attachment_id)) {
                wp_delete_file($destination);
                return $attachment_id;
            }

            // --- 4. Post Creation/Update Logic ---
            $cpt_post_id      = 0;
            // CHECK FOR UPDATE ID (Supports both 'post_id' and 'recording_id')
            $existing_post_id = isset($form_data['post_id']) ? absint($form_data['post_id']) : (isset($form_data['recording_id']) ? absint($form_data['recording_id']) : 0);

            if ($existing_post_id > 0 && get_post_type($existing_post_id) === $this->get_cpt_slug()) {
                // Update existing post
                $cpt_post_id       = $existing_post_id;
                $old_attachment_id = (int) get_post_meta($cpt_post_id, '_audio_attachment_id', true);
                if ($old_attachment_id > 0) {
                    $this->dal->delete_attachment($old_attachment_id); // Clean up old attachment
                }
            } else {
                // Create new post
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

            // --- 5. Post Metadata and Post Processing ---
            $this->dal->save_post_meta((int) $cpt_post_id, '_audio_attachment_id', (int) $attachment_id);
            $this->dal->set_attachment_parent((int) $attachment_id, (int) $cpt_post_id);

            $this->save_all_metadata((int) $cpt_post_id, (int) $attachment_id, $form_data);

            do_action('starmus_after_audio_saved', (int) $cpt_post_id, $form_data);

            StarmusLogger::timeEnd('finalize_from_disk', 'SubmissionHandler');

            return [
                'success'       => true,
                'attachment_id' => (int) $attachment_id,
                'post_id'       => (int) $cpt_post_id,
                'url'           => wp_get_attachment_url((int) $attachment_id),
            ];
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
            return $this->err('server_error', 'Failed to process completed file.', 500);
        }
    }

    // --- LEGACY BASE64 CHUNK HANDLER ---
    public function handle_upload_chunk_rest_base64(WP_REST_Request $request): array|WP_Error
    {
        try {
            StarmusLogger::setCorrelationId();
            if ($this->is_rate_limited(get_current_user_id())) {
                return $this->err('rate_limited', 'You are uploading too frequently.', 429);
            }

            $params = $this->sanitize_submission_data($request->get_json_params() ?? []);
            
            $valid = $this->validate_chunk_data($params);
            if (is_wp_error($valid)) return $valid;

            if (is_wp_error($check = $this->ensure_uploads_writable())) return $check;

            $tmp_file = $this->write_chunk_streamed($params);
            if (is_wp_error($tmp_file)) return $tmp_file;

            if (! empty($params['is_last_chunk'])) {
                return $this->finalize_submission($tmp_file, $params);
            }

            return ['success' => true, 'data' => ['status' => 'chunk_received', 'upload_id' => $params['upload_id'] ?? null]];
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
            return $this->err('server_error', 'Failed to process base64 chunk.', 500);
        }
    }

    // --- MULTIPART CHUNK HANDLER ---
    public function handle_upload_chunk_rest_multipart(WP_REST_Request $request): array|WP_Error
    {
        try {
            StarmusLogger::setCorrelationId();
            
            $params = $this->sanitize_submission_data($request->get_params() ?? []);
            $files  = $request->get_file_params() ?? [];

            // CRITICAL ESCAPE HATCH: If no file is present, try the full fallback immediately.
            if (!isset($files['audio_chunk']) && empty($params['finalize'])) {
                return $this->handle_fallback_upload_rest($request);
            }

            $chunk_file   = $files['audio_chunk'] ?? null;
            $chunk_index  = (int) ($params['chunk_index'] ?? -1);
            $total_chunks = (int) ($params['total_chunks'] ?? -1);
            $upload_id    = sanitize_key($params['upload_id'] ?? '');

            if (isset($params['create_upload_id']) || $upload_id === '') {
                $upload_id = uniqid('upload_', true);
            }

            if (!$chunk_file || (int) $chunk_file['error'] !== 0) {
                return $this->err('missing_chunk_file', 'Chunk file missing.', 400);
            }

            if ($chunk_index < 0 || $total_chunks < 1) {
                return $this->err('invalid_chunk_params', 'Invalid index/total.', 400);
            }

            $base_path = trailingslashit($this->get_temp_dir()) . $upload_id . '/';
            if (!is_dir($base_path)) wp_mkdir_p($base_path);

            $chunk_dest = $base_path . 'chunk_' . $chunk_index;
            if (file_exists($chunk_dest) && $chunk_index !== 0) {
                return $this->err('duplicate_chunk', "Chunk $chunk_index exists.", 409);
            }

            if (!move_uploaded_file($chunk_file['tmp_name'], $chunk_dest)) {
                return $this->err('chunk_write_failed', 'Failed to save chunk.', 500);
            }

            if (\intval($params['finalize'] ?? 0) === 1) {
                $combined = $this->combine_chunks_multipart($upload_id, $base_path, $total_chunks);
                if (is_wp_error($combined)) return $combined;

                $result = $this->_finalize_from_local_disk($combined, $params);
                $this->cleanup_chunks_dir($base_path);
                return $result;
            }

            return ['success' => true, 'data' => ['status' => 'chunk_received', 'upload_id' => $upload_id, 'chunk_index' => $chunk_index]];
        } catch (\Throwable $throwable) {
            error_log($throwable->getMessage());
            return $this->err('server_error', 'Multipart chunk upload failed.', 500);
        }
    }

    // --- FALLBACK REST HANDLER ---
    public function handle_fallback_upload_rest(WP_REST_Request $request): array|WP_Error
    {
        try {
            StarmusLogger::setCorrelationId();
            StarmusLogger::timeStart('fallback_upload');

            if ($this->is_rate_limited(get_current_user_id())) {
                return $this->err('rate_limited', 'Too frequent.', 429);
            }

            $form_data  = $this->sanitize_submission_data($request->get_params() ?? []);
            $files_data = $request->get_file_params() ?? [];

            $file_key = $this->detect_file_key($files_data);
            if (! $file_key) {
                StarmusLogger::warning('SubmissionHandler', 'No file found in request');
                return $this->err('missing_file', 'No audio file.', 400);
            }

            $file = $files_data[$file_key];
            if (! isset($file['error']) || (int) $file['error'] !== 0 || empty($file['tmp_name'])) {
                return $this->err('upload_error', 'Upload failed.', 400, ['file_meta' => $file]);
            }

            $validation = $this->validate_file_against_settings($file['type'] ?? '', (int) ($file['size'] ?? 0));
            if (is_wp_error($validation)) return $validation;

            $result = $this->process_fallback_upload($files_data, $form_data, $file_key);
            StarmusLogger::timeEnd('fallback_upload', 'SubmissionHandler');

            if (is_wp_error($result)) return $result;

            return ['success' => true, 'data' => $result['data']];
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
            return $this->err('server_error', 'Upload exception.', 500);
        }
    }

    private function finalize_submission(string $file_path, array $form_data): array|WP_Error
    {
        $result = $this->_finalize_from_local_disk($file_path, $form_data);
        if (is_wp_error($result)) return $result;

        return [
            'success' => true,
            'data'    => [
                'attachment_id' => $result['attachment_id'],
                'post_id'       => $result['post_id'],
                'url'           => $result['url'],
                'redirect_url'  => esc_url($this->get_redirect_url()),
            ],
        ];
    }

    /**
     * Process standard multipart upload via media sideload.
     */
    public function process_fallback_upload(array $files_data, array $form_data, string $file_key = 'audio_file'): array|WP_Error
    {
        try {
            StarmusLogger::timeStart('fallback_pipeline');

            if (empty($files_data[$file_key])) {
                return $this->err('missing_file', 'No audio file provided.', 400);
            }

            // CRITICAL FIX: Ensure media functions are loaded in REST context
            if (!function_exists('media_handle_sideload')) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
            }

            $attachment_id = $this->dal->create_attachment_from_sideload($files_data[$file_key]);
            if (is_wp_error($attachment_id)) {
                return $attachment_id;
            }

            // Check if this is an UPDATE or CREATE
            $existing_id = isset($form_data['post_id']) ? absint($form_data['post_id']) : 0;
            
            if ($existing_id > 0 && get_post_type($existing_id) === $this->get_cpt_slug()) {
                // Update
                $cpt_post_id = $existing_id;
            } else {
                // Create
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

            StarmusLogger::timeEnd('fallback_pipeline', 'SubmissionHandler');

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
            return $this->err('server_error', 'Failed to process fallback upload.', 500);
        }
    }

    // --- METADATA & POST PROCESSING ---

    private function trigger_post_processing(int $post_id, int $attachment_id, array $params): void
    {
        try {
            $processor = new StarmusPostProcessingService();
            $success = $processor->process($post_id, $attachment_id, $params);

            if ($success) {
                StarmusLogger::info('SubmissionHandler', 'Audio post-processed successfully', ['post_id' => $post_id]);
            } else {
                if (! wp_next_scheduled('starmus_cron_process_pending_audio', [$post_id, $attachment_id])) {
                    wp_schedule_single_event(time() + 60, 'starmus_cron_process_pending_audio', [$post_id, $attachment_id]);
                }
            }
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
            if (! wp_next_scheduled('starmus_cron_process_pending_audio', [$post_id, $attachment_id])) {
                wp_schedule_single_event(time() + 120, 'starmus_cron_process_pending_audio', [$post_id, $attachment_id]);
            }
        }
    }

    public function save_all_metadata(int $audio_post_id, int $attachment_id, array $form_data): void
    {
        try {
            StarmusLogger::debug('SubmissionHandler', 'Saving all metadata', ['post_id' => $audio_post_id]);

            // 1. UEC Data & Fingerprint
            $env_json = $form_data['_starmus_env'] ?? '';
            $env_data = [];
            
            if ($env_json) {
                $env_data = json_decode(wp_unslash($env_json), true) ?: [];
                $this->update_acf_field('environment_data', wp_unslash($env_json), $audio_post_id);
                
                // Extract Fingerprint if available
                if (isset($env_data['identifiers'])) {
                    $this->update_acf_field('device_fingerprint', wp_json_encode($env_data['identifiers']), $audio_post_id);
                }
                
                // Extract Browser/OS if available
                if (isset($env_data['device'])) {
                    $ua_info = ($env_data['browser']['name'] ?? '') . ' on ' . ($env_data['device']['type'] ?? '');
                    if(trim($ua_info)) $this->update_acf_field('user_agent', sanitize_text_field($ua_info), $audio_post_id);
                }
            }

            // 2. Calibration
            $cal_json = $form_data['_starmus_calibration'] ?? '';
            if ($cal_json) {
                $this->update_acf_field('runtime_metadata', wp_unslash($cal_json), $audio_post_id);
                $cal_data = json_decode(wp_unslash($cal_json), true) ?: [];
                if (isset($cal_data['gain'])) {
                    $this->update_acf_field('mic_profile', 'Gain: ' . $cal_data['gain'], $audio_post_id);
                }
            }

            // 3. Transcription (Priority: Form > Env > Empty)
            $transcript = $form_data['first_pass_transcription'] ?? '';
            if (!$transcript && !empty($env_data['transcript'])) {
                // Fallback to Env if missing in top level
                $transcript = $env_data['transcript']['final'] ?? '';
            }
            if ($transcript) {
                $this->update_acf_field('first_pass_transcription', wp_unslash($transcript), $audio_post_id);
            }

            // 4. Waveform
            if (!empty($form_data['waveform_json'])) {
                $this->update_acf_field('waveform_json', wp_unslash($form_data['waveform_json']), $audio_post_id);
            }

            // 5. Standard Fields & Taxonomies
            $this->update_acf_field('submission_ip', StarmusSanitizer::get_user_ip(), $audio_post_id);

            foreach (['project_collection_id', 'accession_number', 'location', 'usage_restrictions_rights', 'access_level'] as $field) {
                if (isset($form_data[$field])) {
                    $this->update_acf_field($field, sanitize_text_field((string) wp_unslash($form_data[$field])), $audio_post_id);
                }
            }

            if ($attachment_id !== 0) $this->update_acf_field('audio_files_originals', $attachment_id, $audio_post_id);

            if (!empty($form_data['language'])) wp_set_post_terms($audio_post_id, [(int)$form_data['language']], 'language');
            if (!empty($form_data['recording_type'])) wp_set_post_terms($audio_post_id, [(int)$form_data['recording_type']], 'recording-type');

            do_action('starmus_after_save_submission_metadata', $audio_post_id, $form_data, []);

            // 6. Trigger Processing
            $processing_params = [
                'bitrate' => '192k',
                'samplerate' => 44100,
                'network_type' => '4g',
                'session_uuid' => $env_data['identifiers']['sessionId'] ?? 'unknown'
            ];
            $this->trigger_post_processing($audio_post_id, $attachment_id, $processing_params);

        } catch (\Throwable $e) {
            error_log('Metadata Save Failed: ' . $e->getMessage());
        }
    }

    // --- HELPERS ---

    public function get_cpt_slug(): string
    {
        return ($this->settings && $this->settings->get('cpt_slug')) ? sanitize_key($this->settings->get('cpt_slug')) : 'audio-recording';
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
                if (@filemtime($file) < time() - DAY_IN_SECONDS) wp_delete_file($file);
            }
        }
    }

    private function get_redirect_url(): string
    {
        $id = $this->settings ? (int) $this->settings->get('redirect_page_id', 0) : 0;
        return $id ? get_permalink($id) : home_url('/my-submissions');
    }

    // --- VALIDATION ---

    private function validate_chunk_data(array $params): true|WP_Error
    {
        if (empty($params['upload_id']) || !isset($params['chunk_index'])) return $this->err('invalid_params', 'Missing ID/Index', 400);
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', (string) $params['upload_id'])) return $this->err('invalid_id', 'Bad ID format', 400);
        if (!isset($params['data'])) return $this->err('invalid_chunk', 'No data', 400);
        return true;
    }

    private function write_chunk_streamed(array $params): string|WP_Error
    {
        $dir = $this->get_temp_dir();
        if (!is_dir($dir) && !@wp_mkdir_p($dir)) return $this->err('no_dir', 'Temp dir fail', 500);
        
        $path = $dir . $params['upload_id'] . '.part';
        $chunk = base64_decode((string)$params['data'], true);
        if ($chunk === false) return $this->err('bad_base64', 'Invalid Base64', 400);
        
        if (@file_put_contents($path, $chunk, FILE_APPEND) === false) return $this->err('write_fail', 'Disk error', 500);
        return $path;
    }

    private function validate_file_against_settings(string $mime, int $size_bytes): true|WP_Error
    {
        $max_mb = $this->settings ? (int)$this->settings->get('file_size_limit', 10) : 10;
        if ($size_bytes > ($max_mb * 1024 * 1024)) return $this->err('too_large', "Max ${max_mb}MB", 413);

        $allowed = $this->settings ? $this->settings->get('allowed_file_types', '') : '';
        $exts = $allowed ? array_map('trim', explode(',', $allowed)) : $this->default_allowed_mimes;
        if (empty($exts)) $exts = ['webm', 'mp3', 'wav', 'ogg'];

        $mime_lc = strtolower($mime);
        foreach ($exts as $ext) {
            // PHP 7.4 Compat: strpos instead of str_contains
            if (strpos($mime_lc, strtolower($ext)) !== false) return true;
        }

        return $this->err('bad_type', 'Type not allowed: ' . $mime_lc, 415);
    }

    private function cleanup_chunks_dir(string $path): void
    {
        // PHP 7.4 Compat
        if (strpos($path, $this->get_temp_dir()) !== false && is_dir($path)) {
            array_map('unlink', glob($path . '*'));
            @rmdir($path);
        }
    }

    private function combine_chunks_multipart(string $upload_id, string $base, int $total): string|WP_Error
    {
        $final = $this->get_temp_dir() . $upload_id . '.tmp.file';
        $fp = @fopen($final, 'wb');
        if (!$fp) return $this->err('open_fail', 'File create error', 500);

        for ($i = 0; $i < $total; $i++) {
            $chunk = $base . 'chunk_' . $i;
            // File lock resilience
            if (!file_exists($chunk)) {
                usleep(500000); 
                clearstatcache();
            }
            if (!file_exists($chunk)) {
                fclose($fp);
                @unlink($final);
                return $this->err('missing_chunk', "Chunk $i missing", 400);
            }
            
            $chunk_fp = @fopen($chunk, 'rb');
            if (!$chunk_fp) {
                fclose($fp);
                return $this->err('read_fail', "Chunk $i read error", 500);
            }
            stream_copy_to_stream($chunk_fp, $fp);
            fclose($chunk_fp);
        }
        fclose($fp);
        return $final;
    }

    private function detect_file_key(array $files): ?string
    {
        foreach ($this->fallback_file_keys as $key) {
            if (! empty($files[$key]) && \is_array($files[$key])) return $key;
        }
        foreach ($files as $key => $val) {
            if (\is_array($val) && ! empty($val['tmp_name'])) return (string) $key;
        }
        return null;
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
}