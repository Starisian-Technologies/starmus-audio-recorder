<?php
/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * WaveformService
 * ----------------
 * Generates waveform JSON data using the audiowaveform CLI tool
 * and stores it via ACF on the parent recording post.
 *
 * Key Features:
 *  - Configurable pixels-per-second and bit depth via filter
 *  - Robust temp file cleanup (try/finally + shutdown safety)
 *  - Full StarmusLogger instrumentation
 *
 * @package Starisian\Sparxstar\Starmus\services
 * @version 1.5.0
 */

namespace Starisian\Sparxstar\Starmus\services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\services\FileService;

final class WaveformService {

	/**
	 * Returns configuration parameters for audiowaveform.
	 */
	private function get_config(): array {
		$defaults = array(
			'pixels_per_second' => 100,
			'bits'              => 8,
			'output_format'     => 'json',
		);
		return apply_filters( 'starmus_waveform_config', $defaults );
	}

	/**
	 * Verifies audiowaveform CLI availability.
	 */
	public function is_tool_available(): bool {
		StarmusLogger::debug( 'WaveformService', 'Checking audiowaveform availability...' );
		$path = trim( (string) shell_exec( 'command -v audiowaveform' ) );
		if ( $path === '' ) {
			StarmusLogger::error( 'WaveformService', 'audiowaveform binary not found.' );
			return false;
		}
		StarmusLogger::info( 'WaveformService', 'audiowaveform found at ' . $path );
		return true;
	}

	/**
	 * Generates waveform JSON and stores in ACF field on parent recording.
	 */
	public function generate_waveform_data( int $attachment_id, bool $force = false ): bool {
		StarmusLogger::setCorrelationId();
		StarmusLogger::timeStart( 'waveform_generate' );
		StarmusLogger::info( 'WaveformService', 'Starting waveform generation', array( 'attachment_id' => $attachment_id ) );

		$recording_id = (int) get_post_meta( $attachment_id, '_parent_recording_id', true );
		if ( $recording_id <= 0 ) {
			StarmusLogger::error( 'WaveformService', 'Missing parent recording reference.', array( 'attachment_id' => $attachment_id ) );
			return false;
		}

		if ( ! $force && ! empty( get_field( 'waveform_json', $recording_id ) ) ) {
			StarmusLogger::notice( 'WaveformService', 'Waveform already exists; skipping regeneration.', array( 'recording_id' => $recording_id ) );
			return true;
		}

		$file_path = FileService::get_local_copy( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			StarmusLogger::error( 'WaveformService', 'Audio file not found.', array( 'attachment_id' => $attachment_id ) );
			return false;
		}

		$data = $this->extract_waveform_from_file( $file_path );
		if ( empty( $data ) ) {
			StarmusLogger::error( 'WaveformService', 'Waveform extraction failed.', array( 'attachment_id' => $attachment_id ) );
			return false;
		}

		update_field( 'waveform_json', wp_json_encode( $data['data'] ), $recording_id );
		do_action( 'starmus_waveform_stored', $recording_id, $data );

		StarmusLogger::info(
			'WaveformService',
			'Waveform stored successfully.',
			array(
				'recording_id' => $recording_id,
				'points'       => count( $data['data'] ),
			)
		);

		StarmusLogger::timeEnd( 'waveform_generate', 'WaveformService' );
		return true;
	}

	/**
	 * Deletes waveform JSON from ACF.
	 */
	public function delete_waveform_data( int $attachment_id ): bool {
		$recording_id = (int) get_post_meta( $attachment_id, '_parent_recording_id', true );
		if ( $recording_id <= 0 ) {
			return false;
		}
		delete_field( 'waveform_json', $recording_id );
		StarmusLogger::notice( 'WaveformService', 'Waveform JSON removed from recording.', array( 'recording_id' => $recording_id ) );
		return true;
	}

	/**
	 * Extract waveform JSON via audiowaveform with fail-safe temp handling.
	 */
	private function extract_waveform_from_file( string $file_path ): ?array {
		$config = $this->get_config();

		$temp = tempnam( sys_get_temp_dir(), 'waveform-' );
		if ( ! $temp ) {
			StarmusLogger::error( 'WaveformService', 'Failed to create temp file.' );
			return null;
		}
		wp_delete_file( $temp );
		$temp_json = $temp . '.json';

		$cmd = sprintf(
			'audiowaveform -i %s -o %s --pixels-per-second %d --bits %d --output-format %s',
			escapeshellarg( $file_path ),
			escapeshellarg( $temp_json ),
			(int) $config['pixels_per_second'],
			(int) $config['bits'],
			escapeshellarg( $config['output_format'] )
		);

		$cmd = apply_filters( 'starmus_waveform_command', $cmd, $file_path, $temp_json );

		// Safety cleanup in case of PHP shutdown before finally{}
		register_shutdown_function(
			static function () use ( $temp_json ) {
				if ( file_exists( $temp_json ) ) {
					@unlink( $temp_json );
				}
			}
		);

		try {
			exec( $cmd . ' 2>&1', $output, $code );
			if ( $code !== 0 || ! file_exists( $temp_json ) ) {
				throw new \RuntimeException( 'audiowaveform failed: ' . implode( "\n", $output ) );
			}

			$json = file_get_contents( $temp_json );
			$data = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );

			if ( empty( $data['data'] ) ) {
				throw new \RuntimeException( 'Empty waveform data returned.' );
			}

			return array(
				'data'      => $data['data'],
				'json_path' => $file_path . '.waveform.json',
			);
		} catch ( \Throwable $e ) {
			StarmusLogger::error(
				'WaveformService',
				'Waveform extraction error',
				array(
					'error'   => $e->getMessage(),
					'command' => $cmd,
				)
			);
			return null;
		} finally {
			if ( file_exists( $temp_json ) ) {
				wp_delete_file( $temp_json );
			}
		}
	}
}
