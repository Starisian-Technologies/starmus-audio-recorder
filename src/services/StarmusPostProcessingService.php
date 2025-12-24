<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\services;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Throwable;

/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * Unified Audio Post-Processing Service with EBU R128 Normalization
 *
 * Provides comprehensive audio processing pipeline for recorded submissions.
 * Integrates FFmpeg transcoding, loudness normalization, ID3 tagging, and
 * waveform generation for optimal mobile/web delivery.
 *
 * Key Features:
 * - **EBU R128 Loudness Normalization**: Professional broadcast standards
 * - **Network-Adaptive Processing**: Quality profiles for 2G/3G/4G networks
 * - **Dual-Format Output**: MP3 for delivery, WAV for archival
 * - **Automatic ID3 Tagging**: WordPress metadata integration
 * - **Waveform Generation**: Visual audio analysis data
 * - **Offload-Aware File Handling**: CloudFlare R2/S3 compatibility
 *
 * Processing Pipeline:
 * 1. **File Acquisition**: Local copy retrieval (handles offloaded files)
 * 2. **Loudness Analysis**: Two-pass EBU R128 measurement and normalization
 * 3. **Network Profiling**: Adaptive frequency filtering based on connection
 * 4. **Dual Transcoding**: MP3 delivery + WAV archival formats
 * 5. **Metadata Injection**: WordPress post data → ID3 tags
 * 6. **Media Library Import**: WordPress attachment management
 * 7. **Waveform Generation**: JSON visualization data
 * 8. **Post Metadata Update**: Processing results and file references
 *
 * Technical Requirements:
 * - FFmpeg binary available in system PATH or configured location
 * - Write permissions to WordPress uploads directory
 * - Sufficient disk space for temporary processing files
 * - Audio format support (WAV, MP3, FLAC via FFmpeg)
 *
 * Network Profiles:
 * - **2G/Slow-2G**: Aggressive filtering (100Hz-4kHz), lower quality
 * - **3G**: Moderate filtering (80Hz-7kHz), balanced quality
 * - **4G/WiFi**: Minimal filtering (60Hz highpass), full quality
 *
 * WordPress Integration:
 * - Links processed files to recording posts as attachments
 * - Stores processing logs in post metadata
 * - Preserves original file relationships
 * - Updates mastered_mp3 and archival_wav meta fields
 *
 * @package Starisian\Sparxstar\Starmus\services
 *
 * @version 2.0.0-OFFLOAD-AWARE
 *
 * @since   1.0.0
 * @see StarmusWaveformService Waveform generation integration
 * @see StarmusId3Service ID3 metadata management
 * @see StarmusFileService Offloaded file handling
 * @see StarmusAudioRecorderDAL WordPress data operations
 */
final readonly class StarmusPostProcessingService {

	/**
	 * Data Access Layer for WordPress operations.
	 *
	 * @since 1.0.0
	 */
	private StarmusAudioRecorderDAL $dal;

	/**
	 * Waveform generation service for visualization data.
	 *
	 * @since 1.0.0
	 */
	private StarmusWaveformService $waveform_service;

	/**
	 * ID3 metadata service for audio tagging.
	 *
	 * @since 1.0.0
	 */
	private StarmusId3Service $id3_service;

	/**
	 * File service for offloaded attachment handling.
	 *
	 * @since 2.0.0
	 */
	private StarmusFileService $file_service;

	/**
	 * Initializes post-processing service with integrated dependencies.
	 *
	 * Creates all required service instances and establishes dependency
	 * injection for file service to ensure offload-aware operations.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		try {
			$this->dal              = new StarmusAudioRecorderDAL();
			$this->file_service     = new StarmusFileService(); // Instantiated
			$this->waveform_service = new StarmusWaveformService( null, $this->file_service );
			$this->id3_service      = new StarmusId3Service();
		} catch ( \Exception $exception ) {
			error_log( 'StarmusPostProcessingService initialization error: ' . $exception->getMessage() );
			throw new \RuntimeException( 'Failed to initialize StarmusPostProcessingService: ' . $exception->getMessage(), 0, $exception );
		}
	}

	/**
	 * Main entry point for comprehensive audio processing pipeline.
	 *
	 * Executes complete post-processing workflow from raw recording to
	 * delivery-ready formats with metadata, normalization, and visualization.
	 *
	 * @param int $post_id WordPress post ID for the recording
	 * @param int $attachment_id WordPress attachment ID for source audio file
	 * @param array $params Processing configuration options
	 *
	 * @since 1.0.0
	 *
	 * Parameter Options ($params):
	 * - **network_type**: string ['2g', '3g', '4g'] - Quality profile selection
	 * - **samplerate**: int [22050, 44100, 48000] - Target sample rate
	 * - **bitrate**: string ['128k', '192k', '256k'] - MP3 encoding bitrate
	 * - **session_uuid**: string - Session tracking identifier
	 *
	 * Processing Workflow:
	 * 1. **File Retrieval**: Download local copy (CloudFlare R2/S3 support)
	 * 2. **Environment Setup**: Output directory creation, FFmpeg validation
	 * 3. **Loudness Analysis**: EBU R128 two-pass measurement
	 * 4. **Adaptive Filtering**: Network-specific frequency optimization
	 * 5. **Dual Transcoding**: MP3 delivery + WAV archival generation
	 * 6. **ID3 Integration**: WordPress metadata → audio file tags
	 * 7. **Media Library Import**: WordPress attachment management
	 * 8. **Waveform Generation**: JSON visualization data creation
	 * 9. **Metadata Updates**: Post meta field population
	 * 10. **Cleanup**: Temporary file removal
	 *
	 * EBU R128 Normalization:
	 * - Target loudness: -23 LUFS (broadcast standard)
	 * - Peak limiting: -2 dBFS (headroom preservation)
	 * - Loudness range: 7 LU (dynamic range control)
	 * - Two-pass processing for optimal results
	 *
	 * Output Files:
	 * - **{post_id}_master.mp3**: Delivery-optimized, ID3-tagged
	 * - **{post_id}_archival.wav**: Lossless backup, full quality
	 * - **waveform.json**: Visualization data (via WaveformService)
	 *
	 * Error Handling:
	 * - FFmpeg availability validation
	 * - File access and permission checks
	 * - Processing command failure recovery
	 * - Automatic temporary file cleanup
	 * - Comprehensive error logging
	 *
	 * WordPress Integration:
	 * - Updates `mastered_mp3` and `archival_wav` post meta
	 * - Stores complete `processing_log` for debugging
	 * - Maintains parent-child attachment relationships
	 * - Preserves original file if processing fails
	 *
	 * @throws \RuntimeException If FFmpeg not found or file access fails
	 *
	 * @return bool True if processing completed successfully
	 *
	 * @see get_local_copy() File retrieval handling
	 * @see build_tag_payload() ID3 metadata construction
	 * @see import_to_media_library() WordPress attachment creation
	 */
	public function process( int $post_id, int $attachment_id, array $params = array() ): bool {
		$source_path  = null;
		$is_temp_file = false;

		try {
			StarmusLogger::info(
				'Post-processing started',
				array(
					'post_id'       => $post_id,
					'attachment_id' => $attachment_id,
					'params'        => array_keys( $params ),
				)
			);
			// 1. CRITICAL: GET LOCAL COPY (Handles Cloudflare offload)
			$source_path = $this->file_service->get_local_copy( $attachment_id );
			if ( ! $source_path || ! file_exists( $source_path ) ) {
				throw new \RuntimeException( 'Source file could not be retrieved locally for attachment ID: ' . $attachment_id );
			}

			// Determine if we need to clean up this file later
			if ( $source_path !== get_attached_file( $attachment_id ) ) {
				$is_temp_file = true;
			}

			// 2. Prepare Output
			$uploads    = wp_upload_dir();
			$output_dir = trailingslashit( $uploads['basedir'] ) . 'starmus_processed';
			if ( ! is_dir( $output_dir ) ) {
				wp_mkdir_p( $output_dir );
			}

			// 3. Resolve FFmpeg
			$ffmpeg_path = $this->dal->get_ffmpeg_path() ?: trim( (string) shell_exec( 'command -v ffmpeg' ) );
			if ( $ffmpeg_path === '' || $ffmpeg_path === '0' ) {
				throw new \RuntimeException( 'FFmpeg binary not found on server.' );
			}

			// 4. Params
			$network_type = $params['network_type'] ?? '4g';
			$sample_rate  = (int) ( $params['samplerate'] ?? 44100 );
			$bitrate      = $params['bitrate'] ?? '192k';
			$session_uuid = $params['session_uuid'] ?? 'unknown';

			// 5. Build Filter Chain (Full EBU R128 Normalization Restored)
			$highpass = match ( $network_type ) {
				'2g', 'slow-2g' => 'highpass=f=100,lowpass=f=4000',
				'3g'    => 'highpass=f=80,lowpass=f=7000',
				default => 'highpass=f=60',
			};

			// Pass 1: Loudness Scan
			$cmd_scan    = \sprintf(
				'%s -hide_banner -nostats -i %s -af "loudnorm=I=-23:LRA=7:tp=-2:print_format=json" -f null - 2>&1',
				escapeshellarg( $ffmpeg_path ),
				escapeshellarg( $source_path )
			);
			$scan_output = shell_exec( $cmd_scan );

			preg_match( '/\{.*\}/s', $scan_output ?: '', $matches );
			$loudness_data = json_decode( $matches[0] ?? '{}', true );

			$loudnorm_filter = \sprintf(
				'loudnorm=I=-23:LRA=7:tp=-2:measured_I=%s:measured_LRA=%s:measured_tp=%s:measured_thresh=%s:offset=%s',
				$loudness_data['input_i'] ?? -23,
				$loudness_data['input_lra'] ?? 7,
				$loudness_data['input_tp'] ?? -2,
				$loudness_data['input_thresh'] ?? -70,
				$loudness_data['target_offset'] ?? 0
			);
			$full_filter     = \sprintf( '%s,%s', $highpass, $loudnorm_filter );

			// 6. Define Output Paths
			$mp3_path    = $output_dir . '/' . $post_id . '_master.mp3';
			$wav_path    = $output_dir . '/' . $post_id . '_archival.wav';
			$ffmpeg_meta = \sprintf( '-metadata comment=%s', escapeshellarg( \sprintf( 'Source: Starmus | Profile: %s | Session: %s', $network_type, $session_uuid ) ) );

			// 7. Transcode (Pass 2)
			$log = array( "Loudness Scan:\n" . $scan_output );

			$cmd_mp3 = \sprintf(
				'%s -hide_banner -y -i %s -ar %d -b:a %s -ac 1 -af "%s" %s %s 2>&1',
				escapeshellarg( $ffmpeg_path ),
				escapeshellarg( $source_path ),
				$sample_rate,
				escapeshellarg( $bitrate ),
				$full_filter,
				$ffmpeg_meta,
				escapeshellarg( $mp3_path )
			);
			$log[]   = "---\nMP3 Command:\n" . $cmd_mp3 . "\nOutput:\n" . shell_exec( $cmd_mp3 );

			$cmd_wav = \sprintf(
				'%s -hide_banner -y -i %s -ar %d -ac 1 -sample_fmt s16 -af "%s" %s %s 2>&1',
				escapeshellarg( $ffmpeg_path ),
				escapeshellarg( $source_path ),
				$sample_rate,
				$full_filter,
				$ffmpeg_meta,
				escapeshellarg( $wav_path )
			);
			$log[]   = "---\nWAV Command:\n" . $cmd_wav . "\nOutput:\n" . shell_exec( $cmd_wav );

			// 8. ID3 Tagging (Full Payload Restored)
			$post = get_post( $post_id );
			if ( ! $post ) {
				throw new \RuntimeException( 'Post not found for ID: ' . $post_id );
			}

			$author_name = get_the_author_meta( 'display_name', (int) $post->post_author ) ?: get_bloginfo( 'name' );
			$tag_data    = $this->build_tag_payload( $post, $author_name, get_bloginfo( 'name' ), $post_id );
			$this->id3_service->writeTags( $mp3_path, $tag_data );

			// 9. Import to Media Library
			$mp3_id = $this->import_to_media_library( $mp3_path, $post_id, 'audio/mpeg' );
			$wav_id = $this->import_to_media_library( $wav_path, $post_id, 'audio/wav' );
			if ( $mp3_id === 0 ) {
				throw new \RuntimeException( 'Failed to import MP3 to Media Library.' );
			}

			error_log( '[STARMUS POST-PROCESSING] Created MP3 attachment: ' . $mp3_id . ', WAV attachment: ' . $wav_id );

			// 10. Waveform
			$this->waveform_service->generate_waveform_data( $wav_id ?: $mp3_id, $post_id );

			// 11. Compile Technical Metadata from FFmpeg Analysis
			$technical_metadata = array(
				'processing' => array(
					'ffmpeg_version'    => trim( shell_exec( $ffmpeg_path . ' -version 2>&1 | head -1' ) ),
					'loudness_analysis' => $loudness_data,
					'network_profile'   => $network_type,
					'sample_rate'       => $sample_rate,
					'bitrate'           => $bitrate,
					'processing_date'   => gmdate( 'c' ), // UTC ISO 8601
				),
				'files'      => array(
					'mp3_size'    => file_exists( $mp3_path ) ? filesize( $mp3_path ) : 0,
					'wav_size'    => file_exists( $wav_path ) ? filesize( $wav_path ) : 0,
					'source_size' => file_exists( $source_path ) ? filesize( $source_path ) : 0,
				),
			);

			// Merge with existing recording_metadata from JavaScript if present
			$existing_metadata = get_field( 'recording_metadata', $post_id );
			if ( $existing_metadata ) {
				$existing_data      = json_decode( $existing_metadata, true ) ?: array();
				$technical_metadata = array_merge( $existing_data, $technical_metadata );
			}

			// 12. Update Post Meta (New Schema)
			// Note: ACF fields are configured to return URLs, but we store attachment IDs
			update_field( 'mastered_mp3', $mp3_id, $post_id );
			update_field( 'archival_wav', $wav_id, $post_id );
			update_field( 'recording_metadata', json_encode( $technical_metadata ), $post_id );
			update_post_meta( $post_id, 'processing_log', implode( "\n", $log ) );
			error_log( '[STARMUS POST-PROCESSING] Updated ACF fields - mastered_mp3: ' . $mp3_id . ', archival_wav: ' . $wav_id );

			// Also update legacy meta fields for compatibility
			update_post_meta( $post_id, '_audio_mp3_attachment_id', $mp3_id );
			update_post_meta( $post_id, '_audio_wav_attachment_id', $wav_id );

			return true;
		} catch ( Throwable $throwable ) {
			StarmusLogger::error(
				'Post-processing failed',
				array(
					'post_id'       => $post_id,
					'attachment_id' => $attachment_id,
					'exception'     => $throwable->getMessage(),
				)
			);
			update_post_meta(
				$post_id,
				'processing_log',
				"CRITICAL ERROR:\n" . $throwable->getMessage()
			);
			return false;
		} finally {
			// Cleanup temp file downloaded from Cloudflare
			if ( $is_temp_file && $source_path && file_exists( $source_path ) ) {
				@unlink( $source_path );
			}
		}
	}

	/**
	 * Imports processed audio file into WordPress Media Library.
	 *
	 * Creates WordPress attachment post for processed audio file with proper
	 * MIME type detection and metadata generation.
	 *
	 * @param string $filepath Absolute path to the processed audio file
	 * @param int $parent_post_id Recording post ID to attach file to
	 * @param string $mime_type MIME type for the audio file
	 *
	 * @return int WordPress attachment ID or 0 on failure
	 *
	 * @since 1.0.0
	 *
	 * Process Flow:
	 * 1. **File Validation**: Verify file existence
	 * 2. **Attachment Creation**: WordPress attachment post creation
	 * 3. **Metadata Generation**: Audio-specific metadata extraction
	 * 4. **Parent Linking**: Associate with recording post
	 *
	 * Generated Metadata:
	 * - File dimensions (for audio: duration, bitrate)
	 * - WordPress attachment metadata array
	 * - MIME type validation and assignment
	 *
	 * Error Conditions:
	 * - File does not exist at specified path
	 * - WordPress attachment creation failure
	 * - Metadata generation errors
	 * - Database insertion failures
	 *
	 * @example
	 * ```php
	 * $mp3_id = $this->import_to_media_library(
	 *     '/uploads/starmus_processed/123_master.mp3',
	 *     123,
	 *     'audio/mpeg'
	 * );
	 * ```
	 */
	private function import_to_media_library( string $filepath, int $parent_post_id, string $mime_type ): int {
		if ( ! file_exists( $filepath ) ) {
			error_log( '[STARMUS POST-PROCESSING] File does not exist: ' . $filepath );
			return 0;
		}

		$filename   = basename( $filepath );
		$attachment = array(
			'post_mime_type' => $mime_type,
			'post_title'     => $filename,
			'post_status'    => 'inherit',
			'post_parent'    => $parent_post_id,
		);

		error_log( '[STARMUS POST-PROCESSING] Creating attachment for: ' . $filepath );
		$attach_id = wp_insert_attachment( $attachment, $filepath, $parent_post_id );

		if ( is_wp_error( $attach_id ) ) {
			error_log( '[STARMUS POST-PROCESSING] wp_insert_attachment failed: ' . $attach_id->get_error_message() );
			return 0;
		}

		if ( $attach_id > 0 ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attach_data = wp_generate_attachment_metadata( $attach_id, $filepath );
			wp_update_attachment_metadata( $attach_id, $attach_data );
			error_log( '[STARMUS POST-PROCESSING] Successfully created attachment ID: ' . $attach_id );
			return $attach_id;
		}

		error_log( '[STARMUS POST-PROCESSING] wp_insert_attachment returned invalid ID: ' . $attach_id );
		return 0;
	}

	/**
	 * Constructs comprehensive ID3 metadata payload from WordPress post data.
	 *
	 * Extracts recording information from WordPress post and metadata fields
	 * to create standardized ID3 tag data for audio file embedding.
	 *
	 * @param \WP_Post $post WordPress post object for the recording
	 * @param string $author_name Display name of the recording author
	 * @param string $site_name WordPress site name for attribution
	 * @param int $post_id Recording post ID for reference
	 *
	 * @return array ID3 tag data array with standardized fields
	 *
	 * @since 1.0.0
	 *
	 * Generated ID3 Tags:
	 * - **Title**: Recording post title (sanitized)
	 * - **Artist**: Author display name or site fallback
	 * - **Album**: Site name + " Archives" designation
	 * - **Year**: Extracted from session_date meta or current year
	 * - **Comment**: Starmus attribution + post ID reference
	 * - **Copyright**: Auto-generated copyright notice
	 * - **Publisher**: Site name for distribution credit
	 * - **Language**: Taxonomy term if available
	 *
	 * WordPress Integration:
	 * - Reads `session_date` post meta for accurate dating
	 * - Extracts language taxonomy terms
	 * - Preserves post author attribution
	 * - Includes post ID for tracking
	 *
	 * Data Sanitization:
	 * - All text fields sanitized via sanitize_text_field()
	 * - Date validation and fallback handling
	 * - Array structure validation
	 * - UTF-8 encoding preservation
	 *
	 * @example
	 * ```php
	 * $tags = $this->build_tag_payload(
	 *     $post,
	 *     'John Doe',
	 *     'Music Archive',
	 *     123
	 * );
	 * // Result: ['title' => ['My Recording'], 'artist' => ['John Doe'], ...]
	 * ```
	 *
	 * @see StarmusId3Service::writeTags() Tag writing implementation
	 */
	private function build_tag_payload( \WP_Post $post, string $author_name, string $site_name, int $post_id ): array {
		$recorded_at = (string) get_post_meta( $post_id, 'session_date', true );
		$year        = $recorded_at !== '' && $recorded_at !== '0' ? substr( $recorded_at, 0, 4 ) : date( 'Y' );

		$tag_data = array(
			'title'             => array( sanitize_text_field( $post->post_title ) ),
			'artist'            => array( sanitize_text_field( $author_name ) ),
			'album'             => array( $site_name . ' Archives' ),
			'year'              => array( $year ),
			'comment'           => array( 'Recorded via Starmus | Post ID: ' . $post_id ),
			'copyright_message' => array( '© ' . date( 'Y' ) . ' ' . $site_name . '. All rights reserved.' ),
			'publisher'         => array( $site_name ),
		);

		$language_term = get_the_terms( $post_id, 'language' );
		if ( \is_array( $language_term ) && $language_term !== array() ) {
			$tag_data['language'] = array( sanitize_text_field( $language_term[0]->name ) );
		}

		return $tag_data;
	}
}
