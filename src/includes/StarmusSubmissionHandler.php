<?php

declare(strict_types=1);

/**
 * Core submission service responsible for processing uploads using the DAL.
 *
 * @package   Starisian\Sparxstar\Starmus\includes
 */

namespace Starisian\Sparxstar\Starmus\includes;

if (! \defined('ABSPATH')) {
    exit;
}

use function array_filter;
use function array_map;
use function array_values;

use const DAY_IN_SECONDS; // Added

use function fclose; // Added
// Added
use function file_exists; // Added
use function file_put_contents; // Added
use function filemtime; // Added
use function filesize; // Added
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

use Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface;
use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\helpers\StarmusSanitizer;
use Starisian\Sparxstar\Starmus\services\StarmusPostProcessingService;

use function str_contains;
use function stream_copy_to_stream;
use function strtolower;
use function sys_get_temp_dir;

use Throwable;

use function time;
use function trailingslashit;
use function uniqid;
use function unlink;
use function wp_delete_file;

use WP_Error;

use function wp_get_attachment_url;
use function wp_json_encode;
use function wp_mkdir_p;
use function wp_next_scheduled;

use WP_REST_Request;

use function wp_schedule_single_event;
use function wp_set_post_terms;
use function wp_unique_filename;
use function wp_unslash;
use function wp_upload_dir;

/**
 * Handles validation and persistence for audio submissions (DAL integrated).
 */
final class StarmusSubmissionHandler
{
    /**
     * REST API namespace.
     *
     * @var string
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
     *
     * @var string[]
     */
    private array $fallback_file_keys = ['audio_file', 'file', 'upload'];

    /**
     * Default allowed mime types if settings are empty.
     *
     * @var string[]
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
            add_action('starmus_cleanup_temp_files', $this->cleanup_stale_temp_files(...));

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
     * This is the new entry point for post-upload processing (e.g., from tusd).
     *
     * @param string $file_path Absolute path to the completed file.
     * @param array $form_data Sanitized metadata from the client.
     *
     * @return array|WP_Error Success array with IDs or WP_Error.
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

    // --- REFACTORED: UNIFIED CORE FINALIZATION METHOD ---
    /**
     * UNIFIED Core finalization pipeline for a completed file already on the local disk.
     * Used by: TUS Webhook (via process_completed_file), Chunked Upload (via finalize_submission),
     *          and Multipart Chunked Upload (via handle_upload_chunk_rest_multipart).
     *
     * @param string $file_path Absolute path to the completed file (from tusd or chunked temp).
     * @param array $form_data Sanitized metadata from the client.
     *
     * @return array|WP_Error Success array with IDs or WP_Error.
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
            $existing_post_id = isset($form_data['post_id']) ? absint($form_data['post_id']) : 0;

            if ($existing_post_id > 0 && get_post_type($existing_post_id) === $this->get_cpt_slug()) {
                // Update existing post (TUS use case)
                $cpt_post_id       = $existing_post_id;
                $old_attachment_id = (int) get_post_meta($cpt_post_id, '_audio_attachment_id', true);
                if ($old_attachment_id > 0) {
                    $this->dal->delete_attachment($old_attachment_id); // Clean up old attachment
                }
            } else {
                // Create new post (Chunked/Fallback use case)
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

    // --- END UNIFIED CORE FINALIZATION METHOD ---

    // --- LEGACY BASE64 CHUNK HANDLER ---
    /**
     * Handles LEGACY Base64/JSON chunk uploads.
     * Kept for backwards compatibility if a client still uses it.
     */
    public function handle_upload_chunk_rest_base64(WP_REST_Request $request): array|WP_Error
    {
        try {
            StarmusLogger::setCorrelationId();
            StarmusLogger::timeStart('chunk_upload_base64');

            if ($this->is_rate_limited(get_current_user_id())) {
                return $this->err('rate_limited', 'You are uploading too frequently.', 429);
            }

            $params_raw = $request->get_json_params() ?? [];
            $params     = $this->sanitize_submission_data($params_raw);

            $valid = $this->validate_chunk_data($params);
            if (is_wp_error($valid)) {
                return $valid;
            }

            $writable_check = $this->ensure_uploads_writable();
            if (is_wp_error($writable_check)) {
                return $writable_check;
            }

            $tmp_file = $this->write_chunk_streamed($params);
            if (is_wp_error($tmp_file)) {
                return $tmp_file;
            }

            if (! empty($params['is_last_chunk'])) {
                // Assuming client-side Base64 logic finalizes into a single temp file path.
                $final = $this->finalize_submission($tmp_file, $params);
                StarmusLogger::timeEnd('chunk_upload_base64', 'SubmissionHandler');
                return $final;
            }

            StarmusLogger::timeEnd('chunk_upload_base64', 'SubmissionHandler');
            return [
                'success' => true,
                'data'    => [
                    'status'      => 'chunk_received',
                    'upload_id'   => $params['upload_id']   ?? null,
                    'chunk_index' => $params['chunk_index'] ?? null,
                ],
            ];
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
            return $this->err('server_error', 'Failed to process base64 chunk.', 500);
        }
    }

    // --- NEW MULTIPART CHUNK HANDLER (Priority 2 Target) ---
    /**
     * Handles the new multipart FormData chunk upload method from starmus-tus.js.
     * Route: POST /star-starmus-audio-recorder/v1/upload-chunk (Must be mapped by StarmusRESTHandler)
     */
    public function handle_upload_chunk_rest_multipart(WP_REST_Request $request): array|WP_Error
    {
        try {
            StarmusLogger::setCorrelationId();
            StarmusLogger::timeStart('chunk_upload_multipart');

            // --- PARAM EXTRACTION ---
            $params = $this->sanitize_submission_data($request->get_params() ?? []);
            $files  = $request->get_file_params() ?? [];

            // CRITICAL ESCAPE HATCH: If no file is present, try the full fallback immediately.
            if (!isset($files['audio_chunk']) && empty($params['finalize'])) {
                return $this->handle_fallback_upload_rest($request);
            }

            $chunk_file   = $files['audio_chunk'] ?? null;
            $chunk_index  = isset($params['chunk_index']) ? (int) $params['chunk_index'] : -1;
            $total_chunks = isset($params['total_chunks']) ? (int) $params['total_chunks'] : -1;
            $upload_id    = sanitize_key($params['upload_id'] ?? '');
            $create_new   = isset($params['create_upload_id']);

            if ($create_new || $upload_id === '') {
                $upload_id = uniqid('upload_', true);
            }

            if (!$chunk_file || (int) $chunk_file['error'] !== 0) {
                return $this->err('missing_chunk_file', 'Chunk file missing or upload failed.', 400);
            }

            if ($chunk_index < 0 || $total_chunks < 1) {
                return $this->err('invalid_chunk_params', 'Invalid chunk index or total chunk count.', 400);
            }

            // --- DIRECTORY SETUP ---
            $base_path = trailingslashit($this->get_temp_dir()) . $upload_id . '/';
            if (!is_dir($base_path)) {
                wp_mkdir_p($base_path);
            }

            $chunk_dest = $base_path . 'chunk_' . $chunk_index;

            // PROTECT AGAINST RE-PLAY / DUPLICATE
            if (file_exists($chunk_dest) && $chunk_index !== 0) {
                return $this->err('duplicate_chunk', sprintf('Chunk %d already exists.', $chunk_index), 409);
            }

            if (!move_uploaded_file($chunk_file['tmp_name'], $chunk_dest)) {
                return $this->err('chunk_write_failed', 'Failed to save chunk.', 500);
            }

            // --- FINALIZATION REQUEST ---
            if (\intval($params['finalize'] ?? 0) === 1) {

                $combined = $this->combine_chunks_multipart($upload_id, $base_path, $total_chunks);
                if (is_wp_error($combined)) {
                    return $combined;
                }

                // Delegate to the UNIFIED, safe finalization pipeline
                $result = $this->_finalize_from_local_disk($combined, $params);

                $this->cleanup_chunks_dir($base_path);
                StarmusLogger::timeEnd('chunk_upload_multipart', 'SubmissionHandler');
                return $result;
            }

            StarmusLogger::timeEnd('chunk_upload_multipart', 'SubmissionHandler');
            return [
                'success' => true,
                'data'    => [
                    'status'      => 'chunk_received',
                    'upload_id'   => $upload_id,
                    'chunk_index' => $chunk_index,
                ],
            ];
        } catch (\Throwable $throwable) {
            error_log($throwable->getMessage());
            return $this->err('server_error', 'Multipart chunk upload failed.', 500);
        }
    }

    public function handle_fallback_upload_rest(WP_REST_Request $request): array|WP_Error
    {
        try {
            StarmusLogger::setCorrelationId();
            StarmusLogger::timeStart('fallback_upload');

            if ($this->is_rate_limited(get_current_user_id())) {
                return $this->err('rate_limited', 'You are uploading too frequently.', 429);
            }

            $form_data  = $this->sanitize_submission_data($request->get_params() ?? []);
            $files_data = $request->get_file_params() ?? [];

            $file_key = $this->detect_file_key($files_data);
            if (! $file_key) {
                StarmusLogger::warning('SubmissionHandler', 'No file found in request', ['keys_present' => array_keys($files_data)]);
                return $this->err('missing_file', 'No audio file provided.', 400);
            }

            $file = $files_data[$file_key];

            if (! isset($file['error']) || (int) $file['error'] !== 0 || empty($file['tmp_name'])) {
                return $this->err('upload_error', 'Upload failed or missing tmp file.', 400, ['file_meta' => $file]);
            }

            $mime       = (string) ($file['type'] ?? '');
            $size_bytes = (int) ($file['size'] ?? 0);
            $validation = $this->validate_file_against_settings($mime, $size_bytes);
            if (is_wp_error($validation)) {
                return $validation;
            }

            $result = $this->process_fallback_upload($files_data, $form_data, $file_key);

            StarmusLogger::timeEnd('fallback_upload', 'SubmissionHandler');

            if (is_wp_error($result)) {
                return $result;
            }

            return [
                'success' => true,
                'data'    => $result['data'],
            ];
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
            return $this->err('server_error', __('Upload failed. Please try again later.', 'starmus-audio-recorder'), 500);
        }
    }

    /* ======================================================================
     * FILE PROCESSING (Finalization)
     * ==================================================================== */
    private function finalize_submission(string $file_path, array $form_data): array|WP_Error
    {
        StarmusLogger::timeStart('finalize_submission');
        try {
            // Calls the UNIFIED Core Method
            $result = $this->_finalize_from_local_disk($file_path, $form_data);
            StarmusLogger::timeEnd('finalize_submission', 'SubmissionHandler');

            if (is_wp_error($result)) {
                return $result;
            }

            // Reformat the result to include redirect_url, as required by the Chunked REST handler
            $redirect_url = esc_url($this->get_redirect_url());

            return [
                'success' => true,
                'data'    => [
                    'attachment_id' => $result['attachment_id'],
                    'post_id'       => $result['post_id'],
                    'url'           => $result['url'],
                    'redirect_url'  => $redirect_url,
                ],
            ];
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
            return $this->err('server_error', 'Finalize failed.', 500);
        }
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

            $attachment_id = $this->dal->create_attachment_from_sideload($files_data[$file_key]);
            if (is_wp_error($attachment_id)) {
                return $attachment_id;
            }

            $title       = $form_data['starmus_title'] ?? pathinfo((string) $files_data[$file_key]['name'], PATHINFO_FILENAME);
            $cpt_post_id = $this->dal->create_audio_post(
                $title,
                $this->get_cpt_slug(),
                get_current_user_id()
            );

            if (is_wp_error($cpt_post_id)) {
                $this->dal->delete_attachment((int) $attachment_id);
                return $cpt_post_id;
            }

            // 1. CRITICAL FIX: Link the original attachment to the CPT
            $this->dal->save_post_meta((int) $cpt_post_id, '_audio_attachment_id', (int) $attachment_id);
            $this->dal->set_attachment_parent((int) $attachment_id, (int) $cpt_post_id);

            // 2. CRITICAL FIX: Inject the attachment ID into the form_data array.
            // This allows the save_all_metadata method to persist the correct values (audio_files_originals)
            $form_data['audio_files_originals'] = (int) $attachment_id;

            // 3. Save metadata AND trigger post processing
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

    /*
    ======================================================================
     * POST-PROCESSING TRIGGER (UNIFIED SERVICE)
     * ==================================================================== */

    private function trigger_post_processing(int $post_id, int $attachment_id, array $params): void
    {
        try {
            // Instantiate the NEW Unified Service
            $processor = new StarmusPostProcessingService();

            // Attempt immediate processing
            $success = $processor->process($post_id, $attachment_id, $params);

            if ($success) {
                StarmusLogger::info('SubmissionHandler', 'Audio post-processed successfully', ['post_id' => $post_id]);
            } else {
                // If it returns false (e.g., ffmpeg timed out), schedule a retry
                StarmusLogger::warning('SubmissionHandler', 'Immediate processing failed, scheduling cron retry', ['post_id' => $post_id]);

                if (! wp_next_scheduled('starmus_cron_process_pending_audio', [$post_id, $attachment_id])) {
                    wp_schedule_single_event(time() + 60, 'starmus_cron_process_pending_audio', [$post_id, $attachment_id]);
                }
            }
        } catch (Throwable $throwable) {
            // If the service threw a fatal error, log it and schedule a retry
            error_log($throwable->getMessage());

            if (! wp_next_scheduled('starmus_cron_process_pending_audio', [$post_id, $attachment_id])) {
                wp_schedule_single_event(time() + 120, 'starmus_cron_process_pending_audio', [$post_id, $attachment_id]);
            }
        }
    }

    /*
    ======================================================================
     * METADATA SAVING & PIPELINE EXECUTION
     * ==================================================================== */

    public function save_all_metadata(int $audio_post_id, int $attachment_id, array $form_data): void
    {
        try {
            StarmusLogger::debug('SubmissionHandler', 'Saving all metadata', ['post_id' => $audio_post_id]);

            $metadata = $form_data;

            // --- 1. RESOLVE TELEMETRY SOURCE (UEC Session Data) ---
            $all_telemetry = [];
            if (class_exists('\Starisian\SparxstarUEC\StarUserEnv')) {
                $all_telemetry = \Starisian\SparxstarUEC\StarUserEnv::get_snapshot() ?? [];
            }

            // Raw Environment Data (Save the entire blob)
            $env_json_raw = $form_data['_starmus_env'] ?? '';
            $env_snapshot = $env_json_raw === '' ? [] : (json_decode(wp_unslash($env_json_raw), true) ?: []);
            if ($env_json_raw !== '') {
                $this->update_acf_field('environment_data', wp_unslash($env_json_raw), $audio_post_id);
            }

            $client_data        = $all_telemetry['client_side_data']['identifiers_extra'] ?? [];
            $raw_technical_data = $client_data['technical']['raw']                        ?? [];
            $raw_profile_data   = $client_data['technical']['profile']                    ?? [];
            $browser_data       = $raw_technical_data['browser']                          ?? [];
            $os_data            = $env_snapshot['deviceDetails']['os']                    ?? [];
            $all_telemetry_meta = $all_telemetry['fingerprint']                           ?? [];

            // Raw Calibration (Always from form)
            $calibration_json = $form_data['_starmus_calibration'] ?? '';
            $calibration_data = [];
            if ($calibration_json !== '') {
                $calibration_raw  = wp_unslash($calibration_json);
                $calibration_data = json_decode($calibration_raw, true) ?: [];
                $this->update_acf_field('runtime_metadata', $calibration_raw, $audio_post_id);
            }

            // --- CRITICAL TRANSCRIPT FIX ---
            $transcript_text = $form_data['first_pass_transcription'] ?? '';

            // Fallback 1: Check the raw environment JSON (where the recorder often dumps final text)
            if (empty($transcript_text) && !empty($env_snapshot['transcript'])) {
                $transcript_text = $env_snapshot['transcript']['final'] ?? $env_snapshot['transcript']['text'] ?? '';
            }

            if (!empty($transcript_text)) {
                $this->update_acf_field('first_pass_transcription', wp_unslash($transcript_text), $audio_post_id);
            }

            // --- END TRANSCRIPT FIX ---

            // Raw Waveform Data from client
            if (isset($form_data['waveform_json']) && $form_data['waveform_json'] !== '') {
                $this->update_acf_field('waveform_json', wp_unslash($form_data['waveform_json']), $audio_post_id);
            }

            // --- 3. EXTRACT STRUCTURED FIELDS (CRITICAL MISSING DATA) ---

            // User Agent (PRIORITY: UEC Data > Server Header)
            $full_ua_uec = ($browser_data['name'] ?? '') . ' ' . ($browser_data['version'] ?? '') . ' (' . ($os_data['name'] ?? '') . ')';
            $final_ua    = trim($full_ua_uec) ?: ($form_data['user_agent'] ?? (esc_html(wp_unslash($_SERVER['HTTP_USER_AGENT'])) ?? ''));
            $this->update_acf_field('user_agent', sanitize_text_field($final_ua), $audio_post_id);

            // IP & Fingerprint
            $this->update_acf_field('submission_ip', \Starisian\Sparxstar\Starmus\helpers\StarmusSanitizer::get_user_ip(), $audio_post_id);
            $fingerprint_value = empty($all_telemetry_meta) ? '' : wp_json_encode($all_telemetry_meta);
            $this->update_acf_field('device_fingerprint', $fingerprint_value, $audio_post_id);

            // Mic Profile
            $mic_profile = $raw_profile_data['overallProfile'] ?? ($calibration_data['gain'] ?? '');
            if ($mic_profile) {
                $this->update_acf_field('mic_profile', 'Profile: ' . $mic_profile, $audio_post_id);
            }

            // RECORDING DATE/TIME
            if (! empty($raw_technical_data['timestamp'])) {
                try {
                    $date = new \DateTime((string) $raw_technical_data['timestamp']);
                    $this->update_acf_field('session_date', $date->format('Ymd'), $audio_post_id);
                    $this->update_acf_field('session_start_time', $date->format('H:i:s'), $audio_post_id);
                } catch (\Throwable) {
                    // Logged as warning, no fatal error
                }
            }

            // --- 4. FRONTEND METADATA & TAXONOMIES (Unchanged) ---

            foreach (['project_collection_id', 'accession_number', 'location', 'usage_restrictions_rights', 'access_level'] as $field) {
                if (isset($form_data[$field])) {
                    $this->update_acf_field($field, sanitize_text_field((string) wp_unslash($form_data[$field])), $audio_post_id);
                }
            }

            if ($attachment_id !== 0) {
                $this->update_acf_field('audio_files_originals', $attachment_id, $audio_post_id);
            }

            if (! empty($form_data['language'])) {
                wp_set_post_terms($audio_post_id, [(int) $form_data['language']], 'language');
            }

            if (! empty($form_data['recording_type'])) {
                wp_set_post_terms($audio_post_id, [(int) $form_data['recording_type']], 'recording-type');
            }

            do_action('starmus_after_save_submission_metadata', $audio_post_id, $form_data, $metadata ?? []);

            // --- 5. ADAPTIVE PROCESSING CONFIG & TRIGGER ---

            $is_offline_sync = isset($form_data['sync_mode']) && 'offline' === $form_data['sync_mode'];
            $sync_network    = $form_data['sync_network_type'] ?? null;

            $network_type   = $raw_technical_data['network']['effectiveType'] ?? $sync_network ?? '4g';
            $ffmpeg_bitrate = '192k';
            $sample_rate    = 44100;

            if ($is_offline_sync && \in_array($network_type, ['2g', 'slow-2g'], true)) {
                $ffmpeg_bitrate = '24k';
                $sample_rate    = 8000;
            } elseif ($is_offline_sync && '3g' === $network_type) {
                $ffmpeg_bitrate = '48k';
                $sample_rate    = 16000;
            }

            $processing_params = [
                'bitrate'      => $ffmpeg_bitrate,
                'samplerate'   => $sample_rate,
                'network_type' => $network_type,
                'session_uuid' => $all_telemetry_meta['sessionId'] ?? 'unknown',
            ];
            $processing_params = apply_filters('starmus_post_processing_params', $processing_params, $audio_post_id, $metadata ?? []);

            $this->trigger_post_processing($audio_post_id, $attachment_id, $processing_params);
        } catch (\Throwable $throwable) {
            error_log($throwable->getMessage());
        }
    }

    /*
    ======================================================================
     * SETTINGS / STATE HELPERS
     * ==================================================================== */

    public function get_cpt_slug(): string
    {
        try {
            if ($this->settings instanceof StarmusSettings) {
                $slug = $this->settings->get('cpt_slug', 'audio-recording');
                if (\is_string($slug) && $slug !== '') {
                    return sanitize_key($slug);
                }
            }

            return 'audio-recording';
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
            return 'audio-recording';
        }
    }

    private function update_acf_field(string $field_key, $value, int $post_id): void
    {
        try {
            $this->dal->save_post_meta($post_id, $field_key, $value);
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
        }
    }

    public function sanitize_submission_data(array $data): array
    {
        try {
            return StarmusSanitizer::sanitize_submission_data($data);
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
            // Fall back to shallow sanitization
            foreach ($data as $k => $v) {
                if (\is_string($v)) {
                    $data[$k] = sanitize_text_field($v);
                }
            }

            return $data;
        }
    }

    private function is_rate_limited(int $user_id): bool
    {
        try {
            return $this->dal->is_rate_limited($user_id);
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
            return false; // Fail-open to avoid blocking users due to a DAL fault.
        }
    }

    private function get_temp_dir(): string
    {
        try {
            $base = (string) (wp_upload_dir()['basedir'] ?? '');
            if ($base === '') {
                return trailingslashit(sys_get_temp_dir()) . 'starmus_tmp/';
            }

            return trailingslashit($base) . 'starmus_tmp/';
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
            return trailingslashit(sys_get_temp_dir()) . 'starmus_tmp/';
        }
    }

    public function cleanup_stale_temp_files(): void
    {
        try {
            $dir = $this->get_temp_dir();
            if (! is_dir($dir)) {
                return;
            }

            foreach (glob($dir . '*.part') as $file) {
                if (@filemtime($file) < time() - DAY_IN_SECONDS) {
                    wp_delete_file($file);
                }
            }

            StarmusLogger::info('SubmissionHandler', 'Stale temp cleanup complete', ['dir' => $dir]);
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
        }
    }

    private function get_redirect_url(): string
    {
        try {
            $redirect_page_id = $this->settings instanceof \Starisian\Sparxstar\Starmus\core\StarmusSettings ? (int) $this->settings->get('redirect_page_id', 0) : 0;
            return $redirect_page_id !== 0 ? (string) get_permalink($redirect_page_id) : (string) home_url('/my-submissions');
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
            return (string) home_url('/my-submissions');
        }
    }

    /*
    ======================================================================
     * VALIDATION / IO HELPERS
     * ==================================================================== */

    private function validate_chunk_data(array $params): true|WP_Error
    {
        try {
            if (empty($params['upload_id']) || ! isset($params['chunk_index'])) {
                return $this->err('invalid_params', 'Missing upload_id or chunk_index.', 400);
            }

            if (! preg_match('/^[a-zA-Z0-9_-]+$/', (string) $params['upload_id'])) {
                return $this->err('invalid_id', 'Invalid upload_id format.', 400);
            }

            if (! isset($params['data']) || $params['data'] === '') {
                return $this->err('invalid_chunk', 'Missing chunk data.', 400);
            }

            return true;
        } catch (Throwable $throwable) {
            error_log($throwable);
            return $this->err('server_error', 'Validation failed.', 500);
        }
    }

    private function write_chunk_streamed(array $params): string|WP_Error
    {
        try {
            $temp_dir = $this->get_temp_dir();
            if (! is_dir($temp_dir) && ! @wp_mkdir_p($temp_dir)) {
                return $this->err('temp_dir_unwritable', 'Temp directory is not writable.', 500, ['dir' => $temp_dir]);
            }

            $file_path = $temp_dir . $params['upload_id'] . '.part';
            $chunk     = base64_decode((string) $params['data'], true);

            if ($chunk === false) {
                return $this->err('invalid_chunk', 'Chunk data not valid base64.', 400);
            }

            $written = @file_put_contents($file_path, $chunk, FILE_APPEND);
            if ($written === false) {
                return $this->err('write_failed', 'Failed to write chunk to disk.', 500, ['path' => $file_path]);
            }

            return $file_path;
        } catch (Throwable $throwable) {
            error_log($throwable);
            return $this->err('server_error', 'Could not persist chunk.', 500);
        }
    }

    /**
     * Consistent logger + WP_Error creator with correlation id in data.
     */
    private function err(string $code, string $message, int $status = 400, array $context = []): WP_Error
    {
        try {
            $cid = StarmusLogger::getCorrelationId();
            StarmusLogger::warning(
                'SubmissionHandler',
                \sprintf('%s: %s', $code, $message),
                array_merge(
                    $context,
                    [
                        'status'         => $status,
                        'correlation_id' => $cid,
                    ]
                )
            );
            return new WP_Error(
                $code,
                $message,
                [
                    'status'         => $status,
                    'correlation_id' => $cid,
                    'message'        => $message,
                ]
            );
        } catch (Throwable) {
            return new WP_Error($code, $message, ['status' => $status]);
        }
    }

    /**
     * Ensure uploads base dir is writable; returns WP_Error on failure.
     */
    private function ensure_uploads_writable(): true|WP_Error
    {
        try {
            $uploads = wp_upload_dir();
            $base    = (string) ($uploads['basedir'] ?? '');
            if ($base === '') {
                return $this->err('uploads_unavailable', 'Uploads directory not available.', 500, $uploads);
            }

            if (! is_dir($base) && ! @wp_mkdir_p($base)) {
                return $this->err('uploads_unwritable', 'Failed to create uploads directory.', 500, ['basedir' => $base]);
            }

            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }

            if (! $wp_filesystem->is_writable($base)) {
                return $this->err('uploads_unwritable', 'Uploads directory not writable.', 500, ['basedir' => $base]);
            }

            return true;
        } catch (Throwable $throwable) {
            error_log($throwable);
            return $this->err('server_error', 'Uploads not writable (internal error).', 500);
        }
    }

    private function detect_file_key(array $files): ?string
    {
        try {
            foreach ($this->fallback_file_keys as $key) {
                if (! empty($files[$key]) && \is_array($files[$key])) {
                    return $key;
                }
            }

            foreach ($files as $key => $val) {
                if (\is_array($val) && ! empty($val['tmp_name'])) {
                    return (string) $key;
                }
            }

            return null;
        } catch (Throwable $throwable) {
            error_log($throwable);
        }
    }

    /**
     * Validates file against size/mime settings.
     * FIXED: Checks if submitted MIME *contains* the allowed type (e.g., checks 'audio/webm;codecs=opus' against 'webm').
     */
    private function validate_file_against_settings(string $mime, int $size_bytes): true|WP_Error
    {
        try {
            $max_mb = (int) ($this->settings instanceof \Starisian\Sparxstar\Starmus\core\StarmusSettings ? ($this->settings->get('file_size_limit', 10)) : 10);
            if ($max_mb > 0 && $size_bytes > ($max_mb * 1024 * 1024)) {
                return $this->err('file_too_large', \sprintf('File exceeds maximum size of %dMB.', $max_mb), 413, ['size_bytes' => $size_bytes]);
            }

            $allowed_settings = $this->settings instanceof StarmusSettings ? $this->settings->get('allowed_file_types', '') : '';

            $allowed_exts = \is_string($allowed_settings)
                ? array_values(
                    array_filter(
                        array_map(trim(...), explode(',', $allowed_settings)),
                        fn($v): bool => $v !== ''
                    )
                )
                : $this->default_allowed_mimes;

            if ($allowed_exts === []) {
                $allowed_exts = ['webm', 'mp3', 'wav', 'ogg'];
            }

            $mime_lc = strtolower($mime);
            $ok      = false;

            // CRITICAL FIX: Check if the submitted MIME *contains* the allowed extension.
            foreach ($allowed_exts as $allowed_ext) {
                $ext_lc = strtolower((string) $allowed_ext);

                // 1. Check for a direct substring match of the extension (e.g., 'webm' is in 'audio/webm;codecs=opus')
                if (str_contains($mime_lc, $ext_lc)) {
                    $ok = true;
                    break;
                }
            }

            if (! $ok) {
                return $this->err(
                    'mime_not_allowed',
                    'This file type is not allowed. Submitted: ' . $mime_lc,
                    415,
                    [
                        'mime'    => $mime_lc,
                        'allowed' => $allowed_exts,
                    ]
                );
            }

            return true;
        } catch (Throwable $throwable) {
            error_log($throwable);
            return $this->err('server_error', 'File validation failed.', 500);
        }
    }

    /**
     * Delete temporary chunk files for an upload session.
     *
     * @param mixed $path
     */
    private function cleanup_chunks_dir(string $path): void
    {
        try {
            // Only delete if path is inside the expected temp directory as a safety measure
            if (str_contains($path, $this->get_temp_dir())) {
                $files = glob($path . '*');
                foreach ($files as $file) {
                    @unlink($file);
                }

                @rmdir($path);
            }
        } catch (Throwable $throwable) {
            error_log($throwable);
        }
    }

    /**
     * Combine chunk files written via handle_upload_chunk_rest_multipart
     *
     * @return string|WP_Error Final file path or WP_Error.
     */
    private function combine_chunks_multipart(string $upload_id, string $base, int $total): string|WP_Error
    {
        try {
            $final = $this->get_temp_dir() . $upload_id . '.tmp.file';
            $fp    = @fopen($final, 'wb');
            if (!$fp) {
                return $this->err('combine_open_failed', 'Could not create final file.', 500);
            }

           for ($i = 0; $i < $total; $i++) {
                $chunk = $base . 'chunk_' . $i;
                
                // Hardening: Wait slightly if file system is slow (common on shared hosts)
                if (!file_exists($chunk)) {
                    usleep(500000); // Wait 0.5s
                    clearstatcache();
                }

                if (!file_exists($chunk)) {
                    // Clean up locks before returning error
                    if (is_resource($fp)) fclose($fp);
                    @unlink($final);
                    return $this->err('missing_chunk', sprintf('Missing chunk %d during combine.', $i), 400);
                }

                $chunk_fp = @fopen($chunk, 'rb');
                if ($chunk_fp === false) {
                    if (is_resource($fp)) fclose($fp);
                    @unlink($final);
                    return $this->err('read_chunk_failed', sprintf('Could not read chunk %d.', $i), 500);
                }
                
                stream_copy_to_stream($chunk_fp, $fp);
                @fclose($chunk_fp);
            }

            @fclose($fp);
            return $final;
        } catch (Throwable $throwable) {
            error_log($throwable);
            return $this->err('server_error', 'Failed to combine chunks.', 500);
        }
    }
}
