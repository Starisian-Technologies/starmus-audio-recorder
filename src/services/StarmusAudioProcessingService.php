<?php

/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * StarmusAudioProcessingService (DAL-integrated)
 * ---------------------------------------
 * - WEBA/WEBM → MP3 (distribution) + WAV (archival)
 * - Robust FFmpeg path resolution (via DAL)
 * - Explicit per-phase status updates through DAL
 * - Detailed getID3 diagnostics
 *
 * @package   Starisian\Sparxstar\Starmus\services
 * @version 0.8.5-dal
 */

namespace Starisian\Sparxstar\Starmus\services;

if (! defined('ABSPATH')) {
	exit;
}

use getid3_writetags;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;

class StarmusAudioProcessingService
{

	private StarmusAudioRecorderDAL $dal;
	private StarmusFileService $files;

	public function __construct(?StarmusAudioRecorderDAL $dal = null, ?StarmusFileService $file_service = null)
	{
		$this->dal   = $dal ?: new StarmusAudioRecorderDAL();
		$this->files = $file_service ?: new StarmusFileService();
	}

	/** Main entry — returns a structured result for higher-level orchestration. */
	public function process_attachment(int $attachment_id, array $args = array()): array
	{
		StarmusLogger::setCorrelationId();
		StarmusLogger::timeStart('audio_process');
		$result = array(
			'attachment_id' => $attachment_id,
			'ok'            => false,
		);

		try {
			$source_path = $this->files::get_local_copy($attachment_id);
			if (! $source_path || ! file_exists($source_path)) {
				$this->dal->set_audio_state($attachment_id, 'error_source_missing');
				StarmusLogger::error('StarmusAudioProcessingService', 'Missing source file', compact('attachment_id', 'source_path'));
				return $result;
			}

			$this->dal->set_audio_state($attachment_id, 'processing');

			$ffmpeg = $this->dal->get_ffmpeg_path();
			if (! $ffmpeg) {
				$this->dal->set_audio_state($attachment_id, 'error_ffmpeg_missing');
				throw new \RuntimeException('FFmpeg binary not found');
			}

			// Convert → MP3 (distribution)
			$mp3_path = $this->convert_audio($ffmpeg, $source_path, 'mp3', $args, $attachment_id);
			if (! $mp3_path || ! file_exists($mp3_path)) {
				$this->dal->set_audio_state($attachment_id, 'error_mp3_failed');
				return $result;
			}

			// Convert → WAV (archival)
			$wav_path = $this->convert_audio($ffmpeg, $source_path, 'wav', $args, $attachment_id);
			if (! $wav_path || ! file_exists($wav_path)) {
				$this->dal->set_audio_state($attachment_id, 'error_wav_failed');
				StarmusLogger::warning('StarmusAudioProcessingService', 'WAV conversion failed (continuing)', compact('attachment_id', 'wav_path'));
			}

			// Persist file metadata on the attachment and via DAL helpers.
			try {
				$this->dal->update_attachment_metadata($attachment_id, $mp3_path);
				// Correct DAL method name (plural): persist_audio_outputs
				if (method_exists($this->dal, 'persist_audio_outputs')) {
					$this->dal->persist_audio_outputs($attachment_id, $mp3_path, $wav_path);
					StarmusLogger::info('StarmusAudioProcessingService', 'Persisted audio outputs via DAL', compact('attachment_id', 'mp3_path', 'wav_path'));
				} else {
					// Fallback: attempt to use save_post_meta on attachment id directly
					StarmusLogger::warning('StarmusAudioProcessingService', 'DAL missing persist_audio_outputs method; falling back to direct meta updates', compact('attachment_id'));
					update_post_meta($attachment_id, '_audio_mp3_path', $mp3_path);
					update_post_meta($attachment_id, '_audio_wav_path', $wav_path);
					update_post_meta($attachment_id, '_starmus_archival_path', $wav_path);
				}
			} catch (\Throwable $e) {
				StarmusLogger::error(
					'StarmusAudioProcessingService',
					$e,
					array(
						'phase'         => 'persist_outputs',
						'attachment_id' => $attachment_id,
					)
				);
			}
			// Also persist URLs to the parent recording post (ACF fields in Recording Processing group).
			try {
				$recording_id = (int) get_post_meta($attachment_id, '_parent_recording_id', true);
				if ($recording_id <= 0) {
					$post         = get_post($attachment_id);
					$recording_id = $post ? (int) $post->post_parent : 0;
				}
				if ($recording_id > 0) {
					// save_audio_outputs(post_id, waveform_json|null, mp3_url|null, wav_url|null)
					if (method_exists($this->dal, 'save_audio_outputs')) {
						$this->dal->save_audio_outputs($recording_id, null, $mp3_path ?: null, $wav_path ?: null);
						StarmusLogger::info(
							'StarmusAudioProcessingService',
							'Saved audio outputs to recording post via DAL',
							array(
								'recording_id'  => $recording_id,
								'attachment_id' => $attachment_id,
							)
						);
					} else {
						// Direct fallback to ACF/post_meta fields on the recording post
						StarmusLogger::warning('StarmusAudioProcessingService', 'DAL missing save_audio_outputs; falling back to update_field/update_post_meta', array('recording_id' => $recording_id));
						if (function_exists('update_field')) {
							@update_field('mastered_mp3', $mp3_path ?: '', $recording_id);
							@update_field('archival_wav', $wav_path ?: '', $recording_id);
						} else {
							update_post_meta($recording_id, 'mastered_mp3', $mp3_path);
							update_post_meta($recording_id, 'archival_wav', $wav_path);
						}
					}
				} else {
					StarmusLogger::warning('StarmusAudioProcessingService', 'No parent recording found for attachment; cannot save outputs to recording post', compact('attachment_id'));
				}
			} catch (\Throwable $e) {
				StarmusLogger::error(
					'StarmusAudioProcessingService',
					$e,
					array(
						'phase'         => 'persist_to_recording',
						'attachment_id' => $attachment_id,
					)
				);
			}

			// Write ID3
			$id3_ok = $this->write_id3_tags($attachment_id, $mp3_path);
			if (! $id3_ok) {
				$this->dal->set_audio_state($attachment_id, 'error_id3_failed');
			} else {
				$this->dal->record_id3_timestamp($attachment_id);
			}

			// Done
			$this->dal->set_audio_state($attachment_id, 'completed');
			$result['ok'] = true;

			StarmusLogger::info(
				'StarmusAudioProcessingService',
				'Processing completed',
				array(
					'attachment_id' => $attachment_id,
					'mp3'           => $mp3_path,
					'wav'           => $wav_path,
				)
			);
			return $result;
		} catch (\Throwable $e) {
			$this->dal->set_audio_state($attachment_id, 'error_unknown');
			StarmusLogger::error(
				'StarmusAudioProcessingService',
				'Unhandled exception',
				array(
					'attachment_id' => $attachment_id,
					'error'         => $e->getMessage(),
				)
			);
			return $result;
		} finally {
			StarmusLogger::timeEnd('audio_process', 'StarmusAudioProcessingService');
		}
	}

	/** Convert audio to given format (mp3|wav). */
	private function convert_audio(string $ffmpeg, string $input_path, string $format, array $args, int $attachment_id): ?string
	{
		$uploads     = wp_get_upload_dir();
		$base_dir    = trailingslashit($uploads['path']);
		$base_name   = pathinfo($input_path, PATHINFO_FILENAME);
		$output_path = $base_dir . $base_name . ($format === 'wav' ? '-archive.wav' : '.mp3');

		$bitrate    = (string) ($args['bitrate'] ?? '192k');
		$samplerate = (int) ($args['samplerate'] ?? 44100);
		$preserve   = (bool) ($args['preserve_silence'] ?? true);

		if ($format === 'mp3') {
			$filters = array('loudnorm=I=-16:TP=-1.5:LRA=11');
			if (! $preserve) {
				$filters[] = 'silenceremove=start_periods=1:start_threshold=-50dB';
			}
			$filter_chain = implode(',', $filters);
			$cmd          = sprintf(
				'%s -y -i %s -af %s -c:a libmp3lame -b:a %s %s 2>&1',
				escapeshellarg($ffmpeg),
				escapeshellarg($input_path),
				escapeshellarg($filter_chain),
				escapeshellarg($bitrate),
				escapeshellarg($output_path)
			);
		} else {
			$cmd = sprintf(
				'%s -y -i %s -c:a pcm_s16le -ar %d %s 2>&1',
				escapeshellarg($ffmpeg),
				escapeshellarg($input_path),
				(int) $samplerate,
				escapeshellarg($output_path)
			);
		}

		exec($cmd, $out, $code);
		if ($code !== 0 || ! file_exists($output_path)) {
			StarmusLogger::error(
				'StarmusAudioProcessingService',
				'ffmpeg conversion failed',
				array(
					'format'        => $format,
					'exit_code'     => $code,
					'output'        => implode("\n", $out),
					'attachment_id' => $attachment_id,
				)
			);
			return null;
		}
		return $output_path;
	}

	/** Write ID3 tags via getID3; persist results via DAL. */
	private function write_id3_tags(int $attachment_id, string $file_path): bool
	{
		$post = get_post($attachment_id);
		if (! $post) {
			StarmusLogger::warning('StarmusAudioProcessingService', 'Attachment not found for ID3', compact('attachment_id'));
			return false;
		}

		$tags = array(
			'title'   => array($post->post_title),
			'artist'  => array(get_the_author_meta('display_name', $post->post_author)),
			'album'   => array(get_bloginfo('name')),
			'year'    => array(get_the_date('Y', $post)),
			'comment' => array("Recorded via Starmus (Attachment {$attachment_id})"),
		);

		// Copyright string via DAL-managed option lookup
		$cr = '© ' . gmdate('Y') . ' ' . get_bloginfo('name') . '. All rights reserved.';
		$this->dal->set_copyright_source($attachment_id, $cr);
		$tags['copyright'] = array($cr);

		$writer                    = new getid3_writetags();
		$writer->filename          = $file_path;
		$writer->tagformats        = array('id3v2.3');
		$writer->overwrite_tags    = true;
		$writer->tag_encoding      = 'UTF-8';
		$writer->remove_other_tags = true;
		$writer->tag_data          = $tags;

		$ok = $writer->WriteTags();
		if (! $ok) {
			StarmusLogger::error(
				'StarmusAudioProcessingService',
				'ID3 tag write failed',
				array(
					'attachment_id' => $attachment_id,
					'file'          => $file_path,
					'errors'        => $writer->errors ?? array(),
				)
			);
			return false;
		}
		return true;
	}
}
