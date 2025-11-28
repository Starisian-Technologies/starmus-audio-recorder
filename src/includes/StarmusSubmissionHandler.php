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

use const DAY_IN_SECONDS;

use function file_exists;
use function file_put_contents;
use function filemtime;
use function get_current_user_id;
use function get_permalink;
use function glob;
use function home_url;
use function is_dir;
use function is_wp_error;
use function pathinfo;
use function preg_match;
use function sanitize_text_field;

use Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface;
use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\helpers\StarmusSanitizer;
use Starisian\Sparxstar\Starmus\services\StarmusPostProcessingService;
use Throwable;

use function trailingslashit;
use function uniqid;

use WP_Error;

use function wp_get_attachment_url;
use function wp_mkdir_p;
use function wp_next_scheduled;

use WP_REST_Request;

use function wp_schedule_single_event;
use function wp_unslash;
use function wp_upload_dir;

/**
 * Handles validation and persistence for audio submissions (DAL integrated).
 */
final class StarmusSubmissionHandler
{
	public const STARMUS_REST_NAMESPACE = 'star-starmus-audio-recorder/v1';

	private ?StarmusSettings $settings;

	private \Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface $dal;

	/** Allow a small list of safe upload keys the client might send. */
	private array $fallback_file_keys = ['audio_file', 'file', 'upload'];

	/** Default allowed mime types if settings are empty. */
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
			StarmusLogger::error('SubmissionHandler', $throwable, ['phase' => '__construct']);
			// Let constructor throw in truly fatal cases:
			throw $throwable;
		}
	}

    /*
    ======================================================================
     * CHUNKED UPLOAD HANDLER
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
		try {
			StarmusLogger::timeStart('process_completed_file');

			// This logic is adapted directly from your finalize_submission() method.
			if (! file_exists($file_path)) {
				return $this->err('file_missing', 'No file to process.', 400, ['path' => $file_path]);
			}

			$filename    = $form_data['filename'] ?? pathinfo($file_path, PATHINFO_BASENAME);
			$upload_dir  = wp_upload_dir();
			$destination = trailingslashit($upload_dir['path']) . wp_unique_filename($upload_dir['path'], $filename);

			// Validate the file before moving it
			$mime  = (string) ($form_data['filetype'] ?? mime_content_type($file_path));
			$size  = (int) @filesize($file_path);
			$valid = $this->validate_file_against_settings($mime, $size);
			if (is_wp_error($valid)) {
				wp_delete_file($file_path);
				return $valid;
			}

			global $wp_filesystem;
			if (empty($wp_filesystem)) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}

			if (! $wp_filesystem->move($file_path, $destination, true)) {
				wp_delete_file($file_path);
				return $this->err('move_failed', 'Failed to move upload into uploads path.', 500, ['dest' => $destination]);
			}

			// Now, the rest is nearly identical to your existing logic...
			$attachment_id = $this->dal->create_attachment_from_file($destination, $filename);
			if (is_wp_error($attachment_id)) {
				wp_delete_file($destination);
				return $attachment_id;
			}

			// Check if this is a re-recording (post_id provided in form_data)
			$existing_post_id = isset($form_data['post_id']) ? absint($form_data['post_id']) : 0;

			if ($existing_post_id > 0 && get_post_type($existing_post_id) === $this->get_cpt_slug()) {
				// Re-recording: Update existing post with new attachment
				$cpt_post_id = $existing_post_id;

				// Delete old attachment if exists
				$old_attachment_id = (int) get_post_meta($cpt_post_id, '_audio_attachment_id', true);
				if ($old_attachment_id > 0) {
					$this->dal->delete_attachment($old_attachment_id);
				}
			} else {
				// New recording: Create new post
				$user_id = isset($form_data['user_id']) ? absint($form_data['user_id']) : 0;
				if ($user_id && ! get_userdata($user_id)) {
					$user_id = 0; // Invalid user ID, fallback to anonymous.
				}

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

			// Fire action hook with full request data for external plugins
			do_action('starmus_after_audio_saved', (int) $cpt_post_id, $form_data);

			StarmusLogger::timeEnd('process_completed_file', 'SubmissionHandler');

			return [
				'success'       => true,
				'attachment_id' => (int) $attachment_id,
				'post_id'       => (int) $cpt_post_id,
				'url'           => wp_get_attachment_url((int) $attachment_id),
			];
		} catch (Throwable $throwable) {
			StarmusLogger::error('SubmissionHandler', $throwable, ['phase' => 'process_completed_file']);
			return $this->err('server_error', 'Failed to process completed file.', 500);
		}
	}

	public function handle_upload_chunk_rest(WP_REST_Request $request): array|WP_Error
	{
		try {
			StarmusLogger::setCorrelationId();
			StarmusLogger::timeStart('chunk_upload');
			StarmusLogger::info(
				'SubmissionHandler',
				'Chunk request received',
				[
					'user_id' => get_current_user_id(),
					'ip'      => StarmusSanitizer::get_user_ip(),
				]
			);

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
				$final = $this->finalize_submission($tmp_file, $params);
				StarmusLogger::timeEnd('chunk_upload', 'SubmissionHandler');
				return $final;
			}

			StarmusLogger::info(
				'SubmissionHandler',
				'Chunk stored',
				[
					'upload_id'   => $params['upload_id']   ?? null,
					'chunk_index' => $params['chunk_index'] ?? null,
					'bytes'       => isset($params['data']) ? \strlen((string) $params['data']) : 0,
				]
			);

			StarmusLogger::timeEnd('chunk_upload', 'SubmissionHandler');
			return [
				'success' => true,
				'data'    => [
					'status'      => 'chunk_received',
					'upload_id'   => $params['upload_id']   ?? null,
					'chunk_index' => $params['chunk_index'] ?? null,
				],
			];
		} catch (Throwable $throwable) {
			StarmusLogger::error('SubmissionHandler', $throwable, ['phase' => 'chunk']);
			return $this->err('server_error', 'Failed to process chunk.', 500);
		}
	}

	/*
    ======================================================================
     * FALLBACK FORM UPLOAD HANDLER (multipart/form-data)
     * ==================================================================== */

	public function handle_fallback_upload_rest(WP_REST_Request $request): array|WP_Error
	{
		try {
			StarmusLogger::setCorrelationId();
			StarmusLogger::timeStart('fallback_upload');
			StarmusLogger::info(
				'SubmissionHandler',
				'Fallback upload request received',
				[
					'user_id' => get_current_user_id(),
					'ip'      => StarmusSanitizer::get_user_ip(),
				]
			);

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

			StarmusLogger::debug(
				'SubmissionHandler',
				'Fallback upload file accepted',
				[
					'file_key' => $file_key,
					'name'     => $file['name'] ?? '',
					'mime'     => $mime,
					'size'     => $size_bytes,
				]
			);

			$result = $this->process_fallback_upload($files_data, $form_data, $file_key);

			StarmusLogger::timeEnd('fallback_upload', 'SubmissionHandler');
			return $result;
		} catch (Throwable $throwable) {
			StarmusLogger::error('SubmissionHandler', $throwable, ['phase' => 'fallback']);
			return $this->err('server_error', 'Failed to process fallback upload.', 500);
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
			StarmusLogger::error('SubmissionHandler', $throwable, ['phase' => 'validate_chunk_data']);
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
			StarmusLogger::error('SubmissionHandler', $throwable, ['phase' => 'write_chunk_streamed']);
			return $this->err('server_error', 'Could not persist chunk.', 500);
		}
	}

	private function finalize_submission(string $file_path, array $form_data): array|WP_Error
	{
		try {
			StarmusLogger::timeStart('finalize_submission');

			if (! file_exists($file_path)) {
				return $this->err('file_missing', 'No file to finalize.', 400, ['path' => $file_path]);
			}

			$filename   = $form_data['filename'] ?? uniqid('starmus_', true) . '.webm';
			$upload_dir = wp_upload_dir();
			if (empty($upload_dir['path']) || ! is_dir($upload_dir['path'])) {
				return $this->err('uploads_unavailable', 'Uploads directory not available.', 500, $upload_dir);
			}

			$destination = trailingslashit($upload_dir['path']) . $filename;

			$mime  = (string) ($form_data['mime'] ?? 'audio/webm');
			$size  = (int) @filesize($file_path);
			$valid = $this->validate_file_against_settings($mime, $size);
			if (is_wp_error($valid)) {
				wp_delete_file($file_path);
				return $valid;
			}

			global $wp_filesystem;
			if (empty($wp_filesystem)) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}

			if (! $wp_filesystem->move($file_path, $destination, true)) {
				wp_delete_file($file_path);
				return $this->err('move_failed', 'Failed to move upload into uploads path.', 500, ['dest' => $destination]);
			}

			try {
				$attachment_id = $this->dal->create_attachment_from_file($destination, $filename);
			} catch (Throwable $e) {
				StarmusLogger::error('SubmissionHandler', $e, ['phase' => 'create_attachment']);
				wp_delete_file($destination);
				return $this->err('attachment_create_failed', 'Could not create attachment.', 500);
			}

			if (is_wp_error($attachment_id)) {
				wp_delete_file($destination);
				return $attachment_id;
			}

			try {
				$cpt_post_id = $this->dal->create_audio_post(
					$form_data['starmus_title'] ?? pathinfo((string) $filename, PATHINFO_FILENAME),
					$this->get_cpt_slug(),
					get_current_user_id()
				);
			} catch (Throwable $e) {
				StarmusLogger::error('SubmissionHandler', $e, ['phase' => 'create_post']);
				$this->dal->delete_attachment((int) $attachment_id);
				return $this->err('post_create_failed', 'Could not create audio post.', 500);
			}

			if (is_wp_error($cpt_post_id)) {
				$this->dal->delete_attachment((int) $attachment_id);
				return $cpt_post_id;
			}

			try {
				$this->dal->save_post_meta((int) $cpt_post_id, '_audio_attachment_id', (int) $attachment_id);
				$this->dal->set_attachment_parent((int) $attachment_id, (int) $cpt_post_id);
				$this->save_all_metadata((int) $cpt_post_id, (int) $attachment_id, $form_data);
			} catch (Throwable $e) {
				StarmusLogger::error('SubmissionHandler', $e, ['phase' => 'save_meta']);
				// Intentionally do not delete post or attachment; admins can reconcile if needed.
			}

			StarmusLogger::timeEnd('finalize_submission', 'SubmissionHandler');

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
			StarmusLogger::error('SubmissionHandler', $throwable, ['phase' => 'finalize_submission']);
			return $this->err('server_error', 'Finalize failed.', 500);
		}
	}

	/**
	 * Process standard multipart upload via media sideload.
	 *
	 * @param array $files_data Full $_FILES-like array from request.
	 * @param array $form_data Sanitized form params.
	 * @param string $file_key Which key contained the uploaded file.
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

			$this->dal->save_post_meta((int) $cpt_post_id, '_audio_attachment_id', (int) $attachment_id);
			$this->dal->set_attachment_parent((int) $attachment_id, (int) $cpt_post_id);

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
			StarmusLogger::error('SubmissionHandler', $throwable, ['phase' => 'fallback_pipeline']);
			return $this->err('server_error', 'Failed to process fallback upload.', 500);
		}
	}

	/*
    ======================================================================
     * METADATA + POST-PROCESSING
     * ==================================================================== */

	public function save_all_metadata(int $audio_post_id, int $attachment_id, array $form_data): void
	{
		try {
			StarmusLogger::debug('SubmissionHandler', 'Saving all metadata', ['post_id' => $audio_post_id]);

			// --- FIRST PASS TRANSCRIPTION (from browser SpeechRecognition) ---
			if (isset($form_data['first_pass_transcription']) && $form_data['first_pass_transcription'] !== '') {
				$this->update_acf_field(
					'first_pass_transcription',
					wp_unslash($form_data['first_pass_transcription']),
					$audio_post_id
				);
				StarmusLogger::info('SubmissionHandler', 'Saved first_pass_transcription', ['post_id' => $audio_post_id]);
			}

			// --- RECORDING METADATA (browser-generated metadata JSON) ---
			if (isset($form_data['recording_metadata']) && $form_data['recording_metadata'] !== '') {
				$this->update_acf_field(
					'recording_metadata',
					wp_unslash($form_data['recording_metadata']),
					$audio_post_id
				);
				StarmusLogger::info('SubmissionHandler', 'Saved recording_metadata', ['post_id' => $audio_post_id]);
			}

			// --- WAVEFORM JSON (client-side waveform array) ---
			if (isset($form_data['waveform_json']) && $form_data['waveform_json'] !== '') {
				$this->update_acf_field(
					'waveform_json',
					wp_unslash($form_data['waveform_json']),
					$audio_post_id
				);
				StarmusLogger::info('SubmissionHandler', 'Saved waveform_json', ['post_id' => $audio_post_id]);
			}

			// 1. Decode the main metadata blob from the client.
			$metadata = [];
			if (isset($form_data['metadata']) && \is_string($form_data['metadata'])) {
				$decoded = json_decode((string) wp_unslash($form_data['metadata']), true);
				if (\is_array($decoded)) {
					$metadata = $decoded;
				}
			}

			// 2. Save the raw JSON blobs for archival and debugging.
			if ($metadata !== []) {
				$this->update_acf_field('recording_metadata', wp_json_encode($metadata, JSON_PRETTY_PRINT), $audio_post_id);
			}

			$env_snapshot = $metadata['environmentSnapshot'] ?? [];
			if (! empty($env_snapshot)) {
				$this->update_acf_field('environment_data', wp_json_encode($env_snapshot, JSON_PRETTY_PRINT), $audio_post_id);
			}

			if (! empty($env_snapshot['identifiers']['visitor_id'])) {
				$this->update_acf_field('device_fingerprint', $env_snapshot['identifiers']['visitor_id'], $audio_post_id);
			}

			// 3. Populate structured ACF fields from the metadata.
			if (! empty($metadata['recordedAt'])) {
				try {
					$date = new \DateTime((string) $metadata['recordedAt']);
					$this->update_acf_field('session_date', $date->format('Ymd'), $audio_post_id);
					$this->update_acf_field('session_start_time', $date->format('H:i:s'), $audio_post_id);
				} catch (\Throwable) {
					StarmusLogger::warning('SubmissionHandler', 'Invalid recordedAt format');
				}
			}

			// 4. Handle user-submitted form fields.
			$this->update_acf_field('submission_ip', StarmusSanitizer::get_user_ip(), $audio_post_id);
			foreach (['project_collection_id', 'accession_number', 'location', 'usage_restrictions_rights', 'access_level'] as $field) {
				if (isset($form_data[$field])) {
					$this->update_acf_field($field, sanitize_text_field((string) wp_unslash($form_data[$field])), $audio_post_id);
				}
			}

			// 5. Link attachments and taxonomies.
			if ($attachment_id !== 0) {
				$this->update_acf_field('audio_files_originals', $attachment_id, $audio_post_id);
			}

			if (! empty($form_data['language'])) {
				wp_set_post_terms($audio_post_id, [(int) $form_data['language']], 'language');
			}

			if (! empty($form_data['recording_type'])) {
				wp_set_post_terms($audio_post_id, [(int) $form_data['recording_type']], 'recording-type');
			}

			do_action('starmus_after_save_submission_metadata', $audio_post_id, $form_data, $metadata);

			// 6. Detect offline sync mode and adjust processing priority.
			$is_offline_sync = isset($metadata['sync_mode']) && 'offline' === $metadata['sync_mode'];
			$sync_delay      = isset($metadata['sync_delay_seconds']) ? (int) $metadata['sync_delay_seconds'] : 0;
			$sync_network    = $metadata['sync_network_type'] ?? null;

			if ($is_offline_sync) {
				StarmusLogger::info(
					'SubmissionHandler',
					'Offline sync detected',
					[
						'delay_seconds' => $sync_delay,
						'sync_network'  => $sync_network,
					]
				);

				// Tag post with offline sync metadata
				$this->update_acf_field('sync_mode', 'offline', $audio_post_id);
				$this->update_acf_field('sync_delay_seconds', $sync_delay, $audio_post_id);

				// If synced after long delay (> 1 hour), mark as low priority
				if ($sync_delay > 3600) {
					$this->update_acf_field('processing_priority', 'low', $audio_post_id);
				}
			}

			// 7. Trigger the adaptive post-processing based on captured metadata.
			$network_type   = $metadata['connection']['effectiveType'] ?? $sync_network ?? '4g';
			$ffmpeg_bitrate = '192k';
			$sample_rate    = 44100;

			// Combine offline sync detection with network type for optimal processing
			if ($is_offline_sync && \in_array($network_type, ['2g', 'slow-2g'], true)) {
				// Offline sync from 2G = ultra-constrained device
				$ffmpeg_bitrate = '24k';
				$sample_rate    = 8000;
			} elseif ($is_offline_sync && '3g' === $network_type) {
				// Offline sync from 3G = constrained device
				$ffmpeg_bitrate = '48k';
				$sample_rate    = 16000;
			} else {
				// Standard network-based processing
				switch ($network_type) {
					case '2g':
					case 'slow-2g':
						$ffmpeg_bitrate = '24k';
						$sample_rate    = 8000;
						break;
					case '3g':
						$ffmpeg_bitrate = '48k';
						$sample_rate    = 16000;
						break;
				}
			}

			$processing_params = apply_filters('starmus_post_processing_params', [
				'bitrate'      => $ffmpeg_bitrate,
				'samplerate'   => $sample_rate,
				'network_type' => $network_type,
				'session_uuid' => $metadata['sessionUUID'] ?? ($metadata['submissionUUID'] ?? 'unknown'),
			], $audio_post_id, $metadata);

			StarmusLogger::info(
				'SubmissionHandler',
				'Determined adaptive post-processing profile',
				array_merge(
					[
						'post_id'         => $audio_post_id,
						'network_type'    => $network_type,
						'is_offline_sync' => $is_offline_sync,
					],
					$processing_params
				)
			);

			try {
				$post_processing = new StarmusPostProcessingService();
				$ok              = $post_processing->process($audio_post_id, $attachment_id, $processing_params);
				if (! $ok && ! wp_next_scheduled('starmus_cron_process_pending_audio', [$audio_post_id, $attachment_id])) {
					wp_schedule_single_event(time() + 300, 'starmus_cron_process_pending_audio', [$audio_post_id, $attachment_id]);
				}
			} catch (Throwable $e) {
				StarmusLogger::error('SubmissionHandler', $e, ['phase' => 'post_processing']);
				wp_schedule_single_event(time() + 600, 'starmus_cron_process_pending_audio', [$audio_post_id, $attachment_id]);
			}
		} catch (Throwable $throwable) {
			StarmusLogger::error('SubmissionHandler', $throwable, ['phase' => 'save_all_metadata']);
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
			StarmusLogger::error('SubmissionHandler', $throwable, ['phase' => 'get_cpt_slug']);
			return 'audio-recording';
		}
	}

	private function update_acf_field(string $field_key, $value, int $post_id): void
	{
		try {
			$this->dal->save_post_meta($post_id, $field_key, $value);
		} catch (Throwable $throwable) {
			StarmusLogger::error(
				'SubmissionHandler',
				$throwable,
				[
					'phase'   => 'update_acf_field',
					'field'   => $field_key,
					'post_id' => $post_id,
				]
			);
		}
	}

	public function sanitize_submission_data(array $data): array
	{
		try {
			return StarmusSanitizer::sanitize_submission_data($data);
		} catch (Throwable $throwable) {
			StarmusLogger::error('SubmissionHandler', $throwable, ['phase' => 'sanitize_submission_data']);
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
			StarmusLogger::error(
				'SubmissionHandler',
				$throwable,
				[
					'phase'   => 'is_rate_limited',
					'user_id' => $user_id,
				]
			);
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
			StarmusLogger::error('SubmissionHandler', $throwable, ['phase' => 'get_temp_dir']);
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
			StarmusLogger::error('SubmissionHandler', $throwable, ['phase' => 'cleanup_stale_temp_files']);
		}
	}

	private function get_redirect_url(): string
	{
		try {
			$redirect_page_id = $this->settings instanceof \Starisian\Sparxstar\Starmus\core\StarmusSettings ? (int) $this->settings->get('redirect_page_id', 0) : 0;
			return $redirect_page_id !== 0 ? (string) get_permalink($redirect_page_id) : (string) home_url('/my-submissions');
		} catch (Throwable $throwable) {
			StarmusLogger::error('SubmissionHandler', $throwable, ['phase' => 'get_redirect_url']);
			return (string) home_url('/my-submissions');
		}
	}

    /*
    ======================================================================
     * INTERNAL UTILITIES
     * ==================================================================== */

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
				]
			);
		} catch (Throwable) {
			// As a last resort, ensure a WP_Error still returns even if logging fails.
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
			StarmusLogger::error('SubmissionHandler', $throwable, ['phase' => 'ensure_uploads_writable']);
			return $this->err('server_error', 'Uploads not writable (internal error).', 500);
		}
	}

	/**
	 * Detect the uploaded file key from a set of common alternatives.
	 */
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
			StarmusLogger::error('SubmissionHandler', $throwable, ['phase' => 'detect_file_key']);
			return null;
		}
	}

	/**
	 * Validates file against size/mime settings.
	 */
	private function validate_file_against_settings(string $mime, int $size_bytes): true|WP_Error
	{
		try {
			$max_mb = (int) ($this->settings instanceof \Starisian\Sparxstar\Starmus\core\StarmusSettings ? ($this->settings->get('file_size_limit', 10)) : 10);
			if ($max_mb > 0 && $size_bytes > ($max_mb * 1024 * 1024)) {
				return $this->err('file_too_large', \sprintf('File exceeds maximum size of %dMB.', $max_mb), 413, ['size_bytes' => $size_bytes]);
			}

			$allowed = $this->settings instanceof \Starisian\Sparxstar\Starmus\core\StarmusSettings ? $this->settings->get('allowed_file_types', []) : [];
			if (\is_string($allowed) && $allowed !== '') {
				$allowed = array_values(array_filter(array_map('trim', explode(',', (string) $allowed)), fn($v) => $v !== ''));
			}

			if (! \is_array($allowed) || $allowed === []) {
				$allowed = $this->default_allowed_mimes;
			}

			$mime_lc = strtolower($mime);
			$ok      = false;
			foreach ($allowed as $allowed_type) {
				$allowed_type = strtolower((string) $allowed_type);
				if ($allowed_type === $mime_lc) {
					$ok = true;
					break;
				}

				if (str_starts_with($mime_lc, $allowed_type)) {
					$ok = true;
					break;
				}
			}

			if (! $ok) {
				return $this->err(
					'mime_not_allowed',
					'This file type is not allowed.',
					415,
					[
						'mime'    => $mime_lc,
						'allowed' => $allowed,
					]
				);
			}

			return true;
		} catch (Throwable $throwable) {
			StarmusLogger::error('SubmissionHandler', $throwable, ['phase' => 'validate_file_against_settings']);
			return $this->err('server_error', 'File validation failed.', 500);
		}
	}
}
