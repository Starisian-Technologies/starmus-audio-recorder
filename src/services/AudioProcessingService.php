<?php
/**
 * Enhanced Audio Processing Service v1.6.0
 *
 * - WEBA/WEBM → MP3 (distribution) + WAV (archival)
 * - Robust FFmpeg path resolution (option → env → auto-detect)
 * - Explicit per-phase status & meta updates
 * - Detailed getID3 diagnostics
 * - Emits hooks for external cleanup / lifecycle management
 *
 * @package   Starisian\Sparxstar\Starmus\services
 * @version   1.6.0
 */

namespace Starisian\Sparxstar\Starmus\services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

use getid3_writetags;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

class AudioProcessingService {

	/** Main entry. Returns a structured result for higher-level orchestration. */
	public function process_attachment( int $attachment_id, array $args = array() ): array {
		StarmusLogger::setCorrelationId();
		StarmusLogger::timeStart( 'audio_process' );
		$result = array(
			'attachment_id' => $attachment_id,
			'source'        => null,
			'mp3'           => array(
				'path'   => null,
				'status' => 'pending',
				'error'  => null,
			),
			'wav'           => array(
				'path'   => null,
				'status' => 'pending',
				'error'  => null,
			),
			'id3'           => array(
				'status'   => 'pending',
				'error'    => null,
				'warnings' => array(),
			),
			'ok'            => false,
		);

		try {
			$source_path      = FileService::get_local_copy( $attachment_id );
			$result['source'] = $source_path;

			if ( ! $source_path || ! file_exists( $source_path ) ) {
				$this->mark_status( $attachment_id, 'processing', 'source_missing' );
				StarmusLogger::error( 'AudioProcessingService', 'Missing source file', compact( 'attachment_id', 'source_path' ) );
				do_action( 'starmus_audio_processing_failed', $attachment_id, $result );
				return $result;
			}

			$this->mark_status( $attachment_id, 'processing', 'processing' );
			do_action( 'starmus_audio_processing_started', $attachment_id, $source_path );

			// Resolve ffmpeg early (throws if missing).
			$ffmpeg = $this->resolve_ffmpeg_path();

			// ------- Convert → MP3 (distribution) -------
			$mp3_path = $this->convert_audio( $ffmpeg, $source_path, 'mp3', $args, $attachment_id );
			if ( ! $mp3_path || ! file_exists( $mp3_path ) ) {
				$result['mp3']['status'] = 'fail';
				$result['mp3']['error']  = 'mp3_conversion_failed';
				$this->mark_status( $attachment_id, 'conversion', 'mp3_failed' );
				StarmusLogger::error( 'AudioProcessingService', 'MP3 conversion failed', compact( 'attachment_id', 'mp3_path' ) );
				do_action( 'starmus_audio_conversion_failed', $attachment_id, 'mp3', $result );
				// MP3 is critical → stop here.
				do_action( 'starmus_audio_processing_failed', $attachment_id, $result );
				return $result;
			}
			$result['mp3'] = array(
				'path'   => $mp3_path,
				'status' => 'ok',
				'error'  => null,
			);
			update_post_meta( $attachment_id, '_starmus_mp3_path', $mp3_path );

			// ------- Convert → WAV (archival) -------
			$wav_path = $this->convert_audio( $ffmpeg, $source_path, 'wav', $args, $attachment_id );
			if ( ! $wav_path || ! file_exists( $wav_path ) ) {
				$result['wav']['status'] = 'fail';
				$result['wav']['error']  = 'wav_conversion_failed';
				$this->mark_status( $attachment_id, 'conversion', 'wav_failed' );
				StarmusLogger::warning( 'AudioProcessingService', 'WAV conversion failed (continuing)', compact( 'attachment_id', 'wav_path' ) );
				do_action( 'starmus_audio_conversion_failed', $attachment_id, 'wav', $result );
			} else {
				$result['wav'] = array(
					'path'   => $wav_path,
					'status' => 'ok',
					'error'  => null,
				);
				update_post_meta( $attachment_id, '_starmus_archival_path', $wav_path );
			}

			// ------- ID3 on MP3 -------
			$id3_ok = $this->write_id3_tags( $attachment_id, $mp3_path );
			if ( ! $id3_ok ) {
				$result['id3']['status'] = 'fail';
				$result['id3']['error']  = get_post_meta( $attachment_id, '_audio_id3_error', true ) ?: 'id3_write_failed';
				$this->mark_status( $attachment_id, 'id3', 'failed' );
				StarmusLogger::warning( 'AudioProcessingService', 'ID3 write failed', compact( 'attachment_id' ) );
			} else {
				$result['id3']['status'] = 'ok';
				$this->mark_status( $attachment_id, 'id3', 'ok' );
				update_post_meta( $attachment_id, '_audio_id3_written_at', current_time( 'mysql' ) );
			}

			// Final status
			if ( $result['mp3']['status'] === 'ok' ) {
				$this->mark_status( $attachment_id, 'processing', 'completed' );
				$result['ok'] = true;

				StarmusLogger::info(
					'AudioProcessingService',
					'Processing completed',
					array(
						'attachment_id' => $attachment_id,
						'mp3'           => $mp3_path,
						'wav'           => $wav_path,
					)
				);

				// Let FileService / orchestrator decide original cleanup, re-offload, etc.
				do_action(
					'starmus_audio_processing_success',
					array(
						'attachment_id' => $attachment_id,
						'source'        => $source_path,
						'outputs'       => array(
							'mp3' => $mp3_path,
							'wav' => $wav_path,
						),
						'id3_ok'        => $id3_ok,
					)
				);
			} else {
				$this->mark_status( $attachment_id, 'processing', 'failed' );
				do_action( 'starmus_audio_processing_failed', $attachment_id, $result );
			}

			return $result;

		} catch ( \Throwable $e ) {
			$this->mark_status( $attachment_id, 'processing', 'exception' );
			StarmusLogger::error(
				'AudioProcessingService',
				'Unhandled exception',
				array(
					'attachment_id' => $attachment_id,
					'error'         => $e->getMessage(),
				)
			);
			do_action( 'starmus_audio_processing_failed', $attachment_id, $result, $e );
			return $result;
		} finally {
			StarmusLogger::timeEnd( 'audio_process', 'AudioProcessingService' );
		}
	}

	/** Quick available check */
	public function is_tool_available(): bool {
		try {
			$this->resolve_ffmpeg_path();
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** Resolve FFmpeg: option → env → auto-detect. Throws if not found. */
	private function resolve_ffmpeg_path(): string {
		$opt = trim( (string) get_option( 'ffmpeg_path', '' ) );
		if ( $opt && file_exists( $opt ) && is_executable( $opt ) ) {
			return $opt;
		}

		$env = getenv( 'FFMPEG_BIN' );
		if ( $env && file_exists( $env ) && is_executable( $env ) ) {
			return $env;
		}

		$auto = trim( (string) shell_exec( 'command -v ffmpeg' ) );
		if ( $auto && file_exists( $auto ) && is_executable( $auto ) ) {
			return $auto;
		}

		throw new \RuntimeException( 'FFmpeg not found. Set it in Settings or FFMPEG_BIN env.' );
	}

	/**
	 * Convert audio to the given format (mp3|wav).
	 * Supports bitrate & samplerate via $args or filters.
	 */
	private function convert_audio( string $ffmpeg, string $input_path, string $format, array $args, int $attachment_id ): ?string {
		$uploads     = wp_get_upload_dir();
		$base_dir    = trailingslashit( $uploads['path'] );
		$base_name   = pathinfo( $input_path, PATHINFO_FILENAME );
		$output_path = $base_dir . $base_name . ( $format === 'wav' ? '-archive.wav' : '.mp3' );

		$bitrate    = (string) ( $args['bitrate'] ?? apply_filters( 'starmus_mp3_bitrate', '192k', $attachment_id ) );
		$samplerate = (int) ( $args['samplerate'] ?? apply_filters( 'starmus_archive_samplerate', 44100, $attachment_id ) );
		$preserve   = (bool) ( $args['preserve_silence'] ?? apply_filters( 'starmus_preserve_silence', true, $attachment_id ) );

		if ( $format === 'mp3' ) {
			// Normalization (and optional silence removal if you decide later)
			$filters = array( 'loudnorm=I=-16:TP=-1.5:LRA=11' );
			if ( ! $preserve ) {
				$filters[] = 'silenceremove=start_periods=1:start_threshold=-50dB';
			}
			$filters      = apply_filters( 'starmus_ffmpeg_filters', $filters, $attachment_id );
			$filter_chain = implode( ',', array_filter( $filters ) );

			$cmd = sprintf(
				'%s -y -i %s -af %s -c:a libmp3lame -b:a %s %s 2>&1',
				escapeshellarg( $ffmpeg ),
				escapeshellarg( $input_path ),
				escapeshellarg( $filter_chain ),
				escapeshellarg( $bitrate ),
				escapeshellarg( $output_path )
			);
		} elseif ( $format === 'wav' ) {
			$cmd = sprintf(
				'%s -y -i %s -c:a pcm_s16le -ar %d %s 2>&1',
				escapeshellarg( $ffmpeg ),
				escapeshellarg( $input_path ),
				(int) $samplerate,
				escapeshellarg( $output_path )
			);
		} else {
			StarmusLogger::error( 'AudioProcessingService', 'Unsupported output format', compact( 'format' ) );
			return null;
		}

		StarmusLogger::debug( 'AudioProcessingService', 'Executing ffmpeg', array( 'cmd' => $cmd ) );
		exec( $cmd, $out, $code );

		if ( $code !== 0 || ! file_exists( $output_path ) ) {
			StarmusLogger::error(
				'AudioProcessingService',
				'ffmpeg conversion failed',
				array(
					'format'        => $format,
					'exit_code'     => $code,
					'output'        => implode( "\n", $out ),
					'attachment_id' => $attachment_id,
				)
			);
			return null;
		}

		StarmusLogger::info(
			'AudioProcessingService',
			strtoupper( $format ) . ' created',
			array(
				'attachment_id' => $attachment_id,
				'path'          => $output_path,
			)
		);

		// Allow external systems to react (e.g., offload, checksum)
		do_action( 'starmus_audio_converted', $attachment_id, $format, $output_path );

		return $output_path;
	}

	/**
	 * Build ID3 tag data from WP + options and write via getID3.
	 * Persists detailed diagnostics on failure.
	 */
	private function write_id3_tags( int $attachment_id, string $file_path ): bool {
		$post = get_post( $attachment_id );
		if ( ! $post ) {
			StarmusLogger::warning( 'AudioProcessingService', 'Attachment post not found for ID3', compact( 'attachment_id' ) );
			return false;
		}

		// Base tags
		$tags = array(
			'title'   => array( (string) $post->post_title ),
			'artist'  => array( (string) get_the_author_meta( 'display_name', $post->post_author ) ),
			'album'   => array( (string) get_bloginfo( 'name' ) ),
			'year'    => array( (string) get_the_date( 'Y', $post ) ),
			'comment' => array( "Recorded via Starmus. Attachment ID: {$attachment_id}" ),
		);

		// Copyright / label options
		$opt       = (array) get_option( 'starmus_audio_settings', array() );
		$copyright = $opt['copyright_text'] ?? ( '© ' . date( 'Y' ) . ' ' . get_bloginfo( 'name' ) . '. All rights reserved.' );
		$label     = $opt['record_label'] ?? get_bloginfo( 'name' );
		$publisher = $opt['publisher'] ?? $label;
		$email     = $opt['contact_email'] ?? get_bloginfo( 'admin_email' );

		$cr                = "{$copyright} | Label: {$label} | Publisher: {$publisher} | Contact: {$email}";
		$tags['copyright'] = array( $cr );
		update_post_meta( $attachment_id, '_audio_copyright_source', $cr );

		// Transcriptions → USLT
		$stored = get_post_meta( $attachment_id, 'audio_transcriptions', true );
		if ( is_array( $stored ) ) {
			foreach ( $stored as $t ) {
				if ( ! empty( $t['text'] ) ) {
					$tags['unsynchronised_lyric'][] = array(
						'data'        => (string) $t['text'],
						'description' => (string) ( $t['desc'] ?? 'Transcription' ),
						'language'    => (string) ( $t['lang'] ?? 'eng' ),
					);
				}
			}
		}

		// Extensibility
		$tags = apply_filters( 'starmus_id3_tag_data', $tags, $attachment_id, $file_path );

		// Write
		$writer                    = new getid3_writetags();
		$writer->filename          = $file_path;
		$writer->tagformats        = array( 'id3v2.3' );
		$writer->overwrite_tags    = true;
		$writer->tag_encoding      = 'UTF-8';
		$writer->remove_other_tags = true;
		$writer->tag_data          = $tags;

		$ok       = $writer->WriteTags();
		$errors   = $writer->errors ?? array();
		$warnings = $writer->warnings ?? array();

		if ( ! $ok || ! empty( $errors ) ) {
			$diag = array(
				'errors'   => $errors,
				'warnings' => $warnings,
				'file'     => $file_path,
				'time'     => current_time( 'mysql' ),
			);
			update_post_meta( $attachment_id, '_audio_id3_error', wp_json_encode( $diag ) );
			StarmusLogger::error( 'AudioProcessingService', 'ID3 tag write failed', $diag );
			do_action( 'starmus_audio_id3_failed', $attachment_id, $diag );
			return false;
		}

		if ( ! empty( $warnings ) ) {
			update_post_meta( $attachment_id, '_audio_id3_warnings', wp_json_encode( $warnings ) );
			StarmusLogger::warning(
				'AudioProcessingService',
				'ID3 tag write warnings',
				array(
					'attachment_id' => $attachment_id,
					'warnings'      => $warnings,
				)
			);
		}

		do_action( 'starmus_audio_id3_written', $attachment_id, $file_path );
		return true;
	}

	/** Convenience meta marker */
	private function mark_status( int $attachment_id, string $phase, string $status ): void {
		update_post_meta( $attachment_id, "_audio_{$phase}_status", $status );
	}
}
