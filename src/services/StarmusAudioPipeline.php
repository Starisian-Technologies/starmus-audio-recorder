<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\services;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

/**
 * Audio Processing Pipeline
 *
 * Orchestrates getID3 and FFmpeg services for complete audio processing workflow.
 * Integrates with your existing StarmusSubmissionHandler.
 */
final class StarmusAudioPipeline {

	private ?StarmusId3Service $id3_service = null;

	private ?StarmusFFmpegService $ffmpeg_service =  null;

	public function __construct() {
		try{
			$this->id3_service    = new StarmusId3Service();
			$this->ffmpeg_service = new StarmusFFmpegService( $this->id3_service );
		} catch (\Throwable $throwable){
			StarmusLogger::log($throwable);
		}
	}

	/**
	 * Process uploaded audio file - call this from your submission handler
	 */
	public function processUploadedAudio( string $file_path, array $form_data, int $post_id ): array {
		$results = array(
			'original_analysis' => array(),
			'web_versions'      => array(),
			'waveform_data'     => null,
			'preview_file'      => null,
			'metadata_written'  => false,
		);

		try {
			// 1. Analyze original file with getID3
			$analysis                     = $this->id3_service->analyzeFile( $file_path );
			$results['original_analysis'] = $this->extractKeyMetadata( $analysis );

			// 2. Write Starmus metadata to original file
			$starmus_tags                = $this->generateStarmusTags( $form_data, $post_id );
			$results['metadata_written'] = $this->id3_service->writeTags( $file_path, $starmus_tags );

			// 3. Generate web-optimized versions
			$upload_dir = wp_upload_dir();
			$output_dir = $upload_dir['path'] . '/starmus_processed';

			if ( ! is_dir( $output_dir ) ) {
				wp_mkdir_p( $output_dir );
			}

			$results['web_versions'] = $this->ffmpeg_service->optimizeForWeb( $file_path, $output_dir );

			// 4. Generate waveform for editor
			$results['waveform_data'] = $this->ffmpeg_service->generateWaveform( $file_path );

			// 5. Create preview clip
			$duration = $analysis['playtime_seconds'] ?? 0;
			if ( $duration > 30 ) {
				$results['preview_file'] = $this->ffmpeg_service->extractPreview( $file_path );
			}

			StarmusLogger::info(
				'Processing completed',
				array(
					'component' => __CLASS__,
					'post_id'   => $post_id,
				)
			);

		} catch ( \Throwable $throwable ) {
			StarmusLogger::log(
				$throwable,
				array(
					'component' => __CLASS__,
					'post_id'   => $post_id,
					'file_path' => $file_path,
				)
			);
		}

		return $results;
	}

	/**
	 * Extract key metadata for WordPress storage
	 */
	private function extractKeyMetadata( array $analysis ): array {
		$audio    = $analysis['audio'] ?? array();
		$comments = $analysis['comments'] ?? array();

		return array(
			'format'       => $analysis['fileformat'] ?? 'unknown',
			'duration'     => $audio['playtime_seconds'] ?? 0,
			'bitrate'      => $audio['bitrate'] ?? 0,
			'sample_rate'  => $audio['sample_rate'] ?? 0,
			'channels'     => $audio['channels'] ?? 0,
			'file_size'    => $analysis['filesize'] ?? 0,
			'title'        => $comments['title'][0] ?? '',
			'artist'       => $comments['artist'][0] ?? '',
			'quality_tier' => $this->assessQuality( $audio ),
		);
	}

	/**
	 * Generate Starmus-specific ID3 tags
	 */
	private function generateStarmusTags( array $form_data, int $post_id ): array {
		$site_name = get_bloginfo( 'name' );
		$year      = date( 'Y' );

		return array(
			'title'             => array( $form_data['title'] ?? 'Recording #' . $post_id ),
			'artist'            => array( $form_data['speaker_name'] ?? $site_name ),
			'album'             => array( $site_name . ' Audio Archive' ),
			'year'              => array( $year ),
			'comment'           => array( $this->buildComment( $form_data ) ),
			'copyright_message' => array( \sprintf( '© %s %s', $year, $site_name ) ),
			'publisher'         => array( $site_name ),
			'language'          => array( $form_data['language'] ?? 'en' ),
			'genre'             => array( 'Spoken Word' ),
		);
	}

	/**
	 * Build descriptive comment from form data
	 */
	private function buildComment( array $form_data ): string {
		$parts = array();

		if ( ! empty( $form_data['description'] ) ) {
			$parts[] = $form_data['description'];
		}

		if ( ! empty( $form_data['location'] ) ) {
			$parts[] = 'Recorded in: ' . $form_data['location'];
		}

		if ( ! empty( $form_data['recording_type'] ) ) {
			$parts[] = 'Type: ' . $form_data['recording_type'];
		}

		return implode( ' | ', $parts );
	}

	/**
	 * Assess audio quality tier
	 */
	private function assessQuality( array $audio ): string {
		$bitrate     = $audio['bitrate'] ?? 0;
		$sample_rate = $audio['sample_rate'] ?? 0;

		if ( $bitrate >= 256000 && $sample_rate >= 44100 ) {
			return 'high';
		}

		if ( $bitrate >= 128000 && $sample_rate >= 22050 ) {
			return 'medium';
		}

		return 'low';
	}
}

/**
 * Integration hook for StarmusSubmissionHandler
 *
 * Add this to your save_all_metadata method:
 */
function starmus_process_audio_with_pipeline( int $post_id, int $attachment_id, array $form_data ): void {
	try{
		$file_path = get_attached_file( $attachment_id );

	if ( $file_path && file_exists( $file_path ) ) {
		$pipeline = new StarmusAudioPipeline();
		$results  = $pipeline->processUploadedAudio( $file_path, $form_data, $post_id );

		// Store results in post meta
		update_post_meta( $post_id, '_starmus_audio_analysis', $results['original_analysis'] );
		update_post_meta( $post_id, '_starmus_web_versions', $results['web_versions'] );
		update_post_meta( $post_id, '_starmus_waveform_data', $results['waveform_data'] );

		if ( $results['preview_file'] ) {
			update_post_meta( $post_id, '_starmus_preview_file', $results['preview_file'] );
		}
	}
	} catch (\Throwable $throwable) {
		StarmusLogger::log($throwable);
	}
}
