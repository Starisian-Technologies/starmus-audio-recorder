<?php

declare(strict_types=1);

/**
 * Schema field mapping helper for the Unified Archival Schema migration.
 *
 * @package Starisian\Sparxstar\Starmus\helpers
 */
namespace Starisian\Sparxstar\Starmus\helpers;

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles field mapping between legacy and new unified archival schema.
 */
final class StarmusSchemaMapper {

	/**
	 * Legacy to new field mappings for audio recordings.
	 */
	private const FIELD_MAPPINGS = array(
		// Legacy -> New Schema
		'audio_file'             => 'original_source',
		'recorded_by_user_id'    => 'subject_user_id',
		'consent_timestamp'      => 'agreement_datetime',
		'consent_ip'             => 'contributor_ip',
		'starmus_title'          => 'dc_creator',
		'filename'               => 'dc_creator',
		'dc_creator'             => 'dc_creator', // Direct mapping
		'_audio_mp3_path'        => 'mastered_mp3',
		'_audio_wav_path'        => 'archival_wav',
		'_starmus_archival_path' => 'archival_wav',
		// JavaScript field mappings
		'_starmus_env'           => 'environment_data',
		'_starmus_calibration'   => 'transcriber',
		// Additional new mappings
		'user_agent'             => 'contributor_user_agent',
		'submission_url'         => 'url',
		'agreement_to_terms'     => 'agreement_to_terms_toggle',
		'recording_type'         => 'recording_type', // Direct mapping
		'language'               => 'language', // Direct mapping
	);

	/**
	 * JSON fields that need encoding/decoding.
	 */
	private const JSON_FIELDS = array(
		'school_reviewed',
		'qa_review',
		'transcriber',
		'translator',
		'ai_training',
		'ai_trained',
		'waveform_json',
		'parental_permission_slip',
		'contributor_verification',
		'transcribed',
		'translated',
		'environment_data',
		'recording_metadata',
	);

	/**
	 * Fields that should be initialized as empty JSON if missing.
	 */
	private const REQUIRED_JSON_FIELDS = array(
		'school_reviewed'  => '{}',
		'qa_review'        => '{}',
		'transcriber'      => '{}',
		'translator'       => '{}',
		'ai_training'      => '{}',
		'ai_trained'       => '{}',
		'environment_data' => '{}',
	);

	/**
	 * Map legacy field name to new schema field name.
	 */
	public static function map_field_name( string $legacy_field ): string {
		return self::FIELD_MAPPINGS[ $legacy_field ] ?? $legacy_field;
	}

	/**
	 * Check if field should be JSON encoded.
	 */
	public static function is_json_field( string $field_name ): bool {
		return \in_array( $field_name, self::JSON_FIELDS, true );
	}

	/**
	 * Prepare field value for storage based on field type.
	 */
	public static function prepare_field_value( string $field_name, mixed $value ): mixed {
		if ( self::is_json_field( $field_name ) ) {
			return \is_string( $value ) ? $value : json_encode( $value );
		}

		return $value;
	}

	/**
	 * Get field value from storage, decoding JSON if needed.
	 */
	public static function get_field_value( string $field_name, mixed $stored_value ): mixed {
		if ( self::is_json_field( $field_name ) && \is_string( $stored_value ) ) {
			$decoded = json_decode( $stored_value, true );
			return $decoded ?? $stored_value;
		}

		return $stored_value;
	}

	/**
	 * Map form data to new schema fields.
	 */
	public static function map_form_data( array $form_data ): array {
		$mapped = array();

		foreach ( $form_data as $key => $value ) {
			$new_key            = self::map_field_name( $key );
			$mapped[ $new_key ] = self::prepare_field_value( $new_key, $value );
		}

		// Initialize required JSON fields if missing
		foreach ( self::REQUIRED_JSON_FIELDS as $field => $default ) {
			if ( ! isset( $mapped[ $field ] ) ) {
				$mapped[ $field ] = $default;
			}
		}

		return $mapped;
	}

	/**
	 * Extract user ID mappings from form data.
	 */
	public static function extract_user_ids( array $form_data ): array {
		$user_id = get_current_user_id();

		return array(
			'copyright_licensor'     => $user_id,
			'subject_user_id'        => $form_data['user_id'] ?? $user_id,
			'authorized_user_id'     => $user_id,
			'interviewers_recorders' => array( $user_id ),
		);
	}

	/**
	 * Map environment and calibration data to schema fields.
	 */
	public static function map_environment_data( array $env_data, array $cal_data ): array {
		$mapped = array();

		// Environment data goes to processing fields as JSON
		if ( $env_data !== array() ) {
			$mapped['contributor_verification'] = json_encode( $env_data );
		}

		// Calibration data (gain, speechLevel) goes to transcriber field as JSON
		if ( $cal_data !== array() ) {
			$mapped['transcriber'] = json_encode( $cal_data );
		}

		return $mapped;
	}
}
