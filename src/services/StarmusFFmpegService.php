<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\services;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

/**
 * FFmpeg Audio Processing Service
 *
 * Handles audio conversion, optimization, and processing using FFmpeg.
 * Works in conjunction with StarmusId3Service for complete audio workflow.
 */
final class StarmusFFmpegService {

	private string $ffmpeg_path;

	private StarmusId3Service $id3_service;

	public function __construct( StarmusId3Service $id3_service, string $ffmpeg_path = 'ffmpeg' ) {
		$this->id3_service = $id3_service;
		$this->ffmpeg_path = $ffmpeg_path;
	}

	/**
	 * Convert audio to web-optimized formats
	 */
	public function optimizeForWeb( string $input_path, string $output_dir ): array {
		$base_name = pathinfo( $input_path, PATHINFO_FILENAME );
		$results   = array();

		// Generate multiple quality versions
		$formats = array(
			'high'     => array( '-b:a', '192k', '-ar', '44100' ),
			'standard' => array( '-b:a', '128k', '-ar', '44100' ),
			'mobile'   => array( '-b:a', '64k', '-ar', '22050' ),
		);

		foreach ( $formats as $quality => $params ) {
			$output_path = sprintf( '%s/%s_%s.mp3', $output_dir, $base_name, $quality );

			if ( $this->convertAudio( $input_path, $output_path, $params ) ) {
				$results[ $quality ] = $output_path;

				// Copy metadata from original using getID3
				$this->copyMetadata( $input_path, $output_path );
			}
		}

		return $results;
	}

	/**
	 * Generate waveform data for audio editor
	 */
	public function generateWaveform( string $input_path ): ?array {
		$temp_file = tempnam( sys_get_temp_dir(), 'starmus_waveform_' );

		$command = array(
			$this->ffmpeg_path,
			'-i',
			escapeshellarg( $input_path ),
			'-ac',
			'1',
			'-ar',
			'8000',
			'-f',
			'f32le',
			escapeshellarg( $temp_file ),
			'2>/dev/null',
		);

		exec( implode( ' ', $command ), $output, $return_code );

		if ( $return_code === 0 && file_exists( $temp_file ) ) {
			$data = file_get_contents( $temp_file );
			unlink( $temp_file );

			return $this->processWaveformData( $data );
		}

		return null;
	}

	/**
	 * Extract audio segment for preview
	 */
	public function extractPreview( string $input_path, int $start_seconds = 0, int $duration = 30 ): ?string {
		$output_path = tempnam( sys_get_temp_dir(), 'starmus_preview_' ) . '.mp3';

		$command = array(
			$this->ffmpeg_path,
			'-i',
			escapeshellarg( $input_path ),
			'-ss',
			(string) $start_seconds,
			'-t',
			(string) $duration,
			'-b:a',
			'96k',
			'-ar',
			'22050',
			escapeshellarg( $output_path ),
			'2>/dev/null',
		);

		exec( implode( ' ', $command ), $output, $return_code );

		return $return_code === 0 ? $output_path : null;
	}

	/**
	 * Normalize audio levels
	 */
	public function normalizeAudio( string $input_path, string $output_path ): bool {
		$command = array(
			$this->ffmpeg_path,
			'-i',
			escapeshellarg( $input_path ),
			'-af',
			'loudnorm=I=-16:TP=-1.5:LRA=11',
			'-ar',
			'44100',
			escapeshellarg( $output_path ),
			'2>/dev/null',
		);

		exec( implode( ' ', $command ), $output, $return_code );
		return $return_code === 0;
	}

	/**
	 * Basic audio conversion
	 */
	private function convertAudio( string $input, string $output, array $params ): bool {
		$command = array_merge(
			array( $this->ffmpeg_path, '-i', escapeshellarg( $input ) ),
			$params,
			array( escapeshellarg( $output ), '2>/dev/null' )
		);

		exec( implode( ' ', $command ), $cmd_output, $return_code );

		if ( $return_code !== 0 ) {
			StarmusLogger::error(
				'FFmpegService',
				'Conversion failed'
			);
		}

		return $return_code === 0;
	}

	/**
	 * Copy metadata between files using getID3
	 */
	private function copyMetadata( string $source, string $destination ): void {
		$analysis = $this->id3_service->analyzeFile( $source );

		if ( ! empty( $analysis['comments'] ) ) {
			$tags = array();
			foreach ( $analysis['comments'] as $key => $values ) {
				if ( ! empty( $values[0] ) ) {
					$tags[ $key ] = $values;
				}
			}

			if ( $tags !== array() ) {
				$this->id3_service->writeTags( $destination, $tags );
			}
		}
	}

	/**
	 * Process raw waveform data into peaks format
	 */
	private function processWaveformData( string $raw_data ): array {
		$samples    = unpack( 'f*', $raw_data );
		$peaks      = array();
		$chunk_size = 100;
		$counter    = \count( $samples ); // Samples per peak

		for ( $i = 0; $i < $counter; $i += $chunk_size ) {
			$chunk   = \array_slice( $samples, $i, $chunk_size );
			$peaks[] = array(
				'min' => min( $chunk ),
				'max' => max( $chunk ),
			);
		}

		return $peaks;
	}
}
