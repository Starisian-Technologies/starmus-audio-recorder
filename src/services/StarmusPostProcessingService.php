<?php

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\services;

/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * Adaptive PostProcessing Service (Broadcast & AI-Ready)
 * ---------------------------------------------------------
 * Dynamically optimizes audio encoding, applies EBU R128 loudness
 * normalization, and embeds provenance metadata into the final files.
 *
 * @version 1.5.0
 */




if (!defined('ABSPATH')) {
	exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Throwable;


/**
 * StarmusPostProcessingService
 *
 * Responsibilities:
 *  - Resolve ffmpeg path (via DAL → filters)
 *  - Transcode original → WAV (archival), MP3 (distribution)
 *  - Apply ID3 via StarmusAudioProcessingService
 *  - Generate waveform via StarmusWaveformService
 *  - Persist paths & state via DAL
 *  - Optional cloud offload via StarmusFileService
 */
class StarmusPostProcessingService
{

	/** Status/state codes written for observability */
	public const STATE_IDLE           = 'idle';

	public const STATE_PROCESSING     = 'processing';

	public const STATE_CONVERTING_WAV = 'converting_wav';

	public const STATE_CONVERTING_MP3 = 'converting_mp3';

	public const STATE_ID3_WRITING    = 'id3_writing';

	public const STATE_WAVEFORM       = 'waveform';

	public const STATE_COMPLETED      = 'completed';


	public const STATE_ERR_FFMPEG_MISSING = 'error_ffmpeg_missing';

	public const STATE_ERR_SOURCE_MISSING = 'error_source_missing';

	public const STATE_ERR_BACKUP_FAILED  = 'error_backup_failed';

	public const STATE_ERR_WAV_FAILED     = 'error_wav_failed';

	public const STATE_ERR_MP3_FAILED     = 'error_mp3_failed';

	public const STATE_ERR_ID3_FAILED     = 'error_id3_failed';

	public const STATE_ERR_UNKNOWN        = 'error_unknown';






	/** Back-compat alias */
	public function process_and_archive_audio(int $audio_post_id, int $attachment_id, array $options = []): bool
	{
		return $this->process($audio_post_id, $attachment_id, $options);
	}

	/** Back-compat alias (kept to avoid touching older callers) */
	public function star_process_and_archive_audio(int $audio_post_id, int $attachment_id, array $options = []): bool
	{
		return $this->process($audio_post_id, $attachment_id, $options);
	}


	/**
	 * Process an audio recording using adaptive settings.
	 *
	 * @param int   $post_id        The WordPress post ID of the audio artifact.
	 * @param int   $attachment_id  The attachment ID of the original file.
	 * @param array $params         Adaptive encoding parameters from the handler.
	 * @return bool                 True on success, false on failure.
	 */
	public function process(int $post_id, int $attachment_id, array $params): bool
	{
		try {
			$attachment_path = get_attached_file($attachment_id);
			if (!$attachment_path || !file_exists($attachment_path)) {
				throw new \RuntimeException('Attachment file not found for attachment ID: ' . $attachment_id);
			}

			$uploads = wp_upload_dir();
			$output_dir = trailingslashit($uploads['basedir']) . 'starmus_processed';
			if (!is_dir($output_dir)) {
                wp_mkdir_p($output_dir);
            }

			// --- 1. Adaptive Parameter Setup ---
			$network_type = $params['network_type'] ?? '4g';
			$sample_rate  = intval($params['samplerate'] ?? 44100);
			$bitrate      = $params['bitrate'] ?? '192k';
			$session_uuid = $params['session_uuid'] ?? 'unknown';

			$ffmpeg_filters = match ($network_type) {
				'2g', 'slow-2g' => 'highpass=f=100,lowpass=f=4000',
				'3g'            => 'highpass=f=80,lowpass=f=7000',
				default         => 'highpass=f=60',
			};

			// --- 2. EBU R128 Loudness Normalization (Two-Pass) ---
			$cmd_loudness_scan = sprintf(
				'ffmpeg -hide_banner -nostats -i %s -af "loudnorm=I=-23:LRA=7:tp=-2:print_format=json" -f null - 2>&1',
				escapeshellarg($attachment_path)
			);
			$loudness_stats_raw = shell_exec($cmd_loudness_scan);

			$json_start = strpos($loudness_stats_raw, '{');
			$json_end = strrpos($loudness_stats_raw, '}');
			$stats_json = ($json_start !== false && $json_end !== false) ? substr($loudness_stats_raw, $json_start, $json_end - $json_start + 1) : '{}';
			$loudness_params = json_decode($stats_json, true) ?: [];

			$normalization_filter = sprintf(
				'loudnorm=I=-23:LRA=7:tp=-2:measured_I=%s:measured_LRA=%s:measured_tp=%s:measured_thresh=%s:offset=%s',
				$loudness_params['input_i'] ?? -23,
				$loudness_params['input_lra'] ?? 7,
				$loudness_params['input_tp'] ?? -2,
				$loudness_params['input_thresh'] ?? -70,
				$loudness_params['target_offset'] ?? 0
			);
			$full_filter_chain = $ffmpeg_filters . ',' . $normalization_filter;

			// --- 3. Provenance Metadata Setup ---
			$metadata_tags = sprintf(
				'-metadata comment=%s',
				escapeshellarg(sprintf('Source: Starmus | Profile: %s | Session: %s', $network_type, $session_uuid))
			);

			// --- 4. Transcoding (Second Pass) ---
			$output_mp3 = sprintf('%s/%d_master.mp3', $output_dir, $post_id);
			$output_wav = sprintf('%s/%d_archival.wav', $output_dir, $post_id);

			$cmd_mp3 = sprintf(
				'ffmpeg -hide_banner -y -i %s -ar %d -b:a %s -ac 1 -af "%s" %s %s 2>&1',
				escapeshellarg($attachment_path),
				$sample_rate,
				escapeshellarg($bitrate),
				$full_filter_chain,
				$metadata_tags,
				escapeshellarg($output_mp3)
			);
			$cmd_wav = sprintf(
				'ffmpeg -hide_banner -y -i %s -ar %d -ac 1 -sample_fmt s16 -af "%s" %s %s 2>&1',
				escapeshellarg($attachment_path),
				$sample_rate,
				$full_filter_chain,
				$metadata_tags,
				escapeshellarg($output_wav)
			);

			$log = [];
			$log[] = "Loudness Scan Results:\n" . $loudness_stats_raw;
			$log[] = "MP3 Command:\n" . $cmd_mp3 . "\nOutput:\n" . shell_exec($cmd_mp3);
			$log[] = "WAV Command:\n" . $cmd_wav . "\nOutput:\n" . shell_exec($cmd_wav);

			// --- 5. Save Results to WordPress ---
			$mp3_attach_id = $this->import_attachment($output_mp3, $post_id);
			$wav_attach_id = $this->import_attachment($output_wav, $post_id);
            if ($mp3_attach_id !== 0) {
                $this->update_acf_field('mastered_mp3', $mp3_attach_id, $post_id);
            }

            if ($wav_attach_id !== 0) {
                $this->update_acf_field('archival_wav', $wav_attach_id, $post_id);
            }

			do_action('starmus_generate_waveform', $post_id, $output_wav);
			$this->update_acf_field('processing_log', implode("\n---\n", $log), $post_id);

			StarmusLogger::info('StarmusPostProcessingService', 'Adaptive encoding and normalization completed', [
				'post_id' => $post_id,
				'bitrate' => $bitrate,
				'samplerate' => $sample_rate,
			]);

			return true;
		} catch (Throwable $throwable) {
			StarmusLogger::error('StarmusPostProcessingService', $throwable, ['post_id' => $post_id, 'params' => $params]);
			$this->update_acf_field('processing_log', "ERROR: " . $throwable->getMessage() . "\n" . $throwable->getTraceAsString(), $post_id);
			return false;
		}
	}

	private function update_acf_field(string $field_key, int|string $value, int $post_id): void
	{
		if (function_exists('update_field')) {
			update_field($field_key, $value, $post_id);
		} else {
			update_post_meta($post_id, $field_key, $value);
		}
	}

	private function import_attachment(string $path, int $post_id): int
	{
		if (!file_exists($path) || filesize($path) === 0) {
            return 0;
        }

		$filetype = wp_check_filetype(basename($path), null);
		$attachment = [
			'post_mime_type' => $filetype['type'] ?? 'application/octet-stream',
			'post_title'     => preg_replace('/\.[^.]+$/', '', basename($path)),
			'post_content'   => '',
			'post_status'    => 'inherit',
		];

		$attach_id = wp_insert_attachment($attachment, $path, $post_id);
		if (is_wp_error($attach_id)) {
			StarmusLogger::error('StarmusPostProcessingService', 'Failed to insert attachment', ['path' => $path, 'error' => $attach_id->get_error_message()]);
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $path));

		return $attach_id;
	}
}
