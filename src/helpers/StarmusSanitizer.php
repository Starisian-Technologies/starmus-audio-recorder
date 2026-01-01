<?php
/**
 * Sanitizer for Starmus audio submissions.
 *
 * Handles general request params, structured metadata, and system context.
 * Preserves legacy key mapping for strict backward compatibility.
 *
 * @package Starisian\Sparxstar\Starmus\helpers
 * @version 1.2.0
 */

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\helpers;

use Starisian\Sparxstar\Starmus\data\mappers\StarmusSchemaMapper;
use function array_map;
use function array_merge;
use function explode;
use function get_field;
use function get_post;
use function in_array;
use function is_array;
use function is_string;
use function json_encode;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function str_contains;
use function str_starts_with;
use function trim;
use function wp_unslash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StarmusSanitizer {

	/**
	 * List of keys that contain JSON or long text and must NOT use sanitize_text_field.
	 * This prevents JSON from being corrupted (quotes stripped).
	 */
	private const COMPLEX_FIELDS = array(
		'environment_data',
		'_starmus_env',
		'waveform_json',
		'recording_metadata',
		'transcriber',
		'_starmus_calibration',
		'description',
		'_starmus_description',
		'transcription_text',
		'translation_text'
	);

	/**
	 * Reference list of allowed fields (Documentation/Validation purpose).
	 */
	private const PASSTHROUGH_ALLOWLIST = array(
		'title', 'description', 'language', 'dialect', 'project_id',
		'interview_type', 'story_type', 'rating', 'geolocation',
		'countries_lived', 'custom_fields', 'environment_data', 'waveform_json',
		'dc_title', 'dc_description', 'dc_language', 'dc_identifier',
		'dc_subject', 'dc_publisher', 'dc_format', 'dc_rights',
		'contributor_name', 'contributor_email', 'contributor_affiliation',
		'date_created', 'session_date'
	);

	/**
	 * Sanitize general submission data from forms or REST params.
	 *
	 * @param array<string, mixed> $data Raw request parameters.
	 * @return array<string, mixed> Sanitized data.
	 */
	public static function sanitize_submission_data( array $data ): array {
		$clean = array();

		foreach ( $data as $key => $value ) {
			$safe_key = sanitize_key( $key );

			// 1. JSON/Complex Field Protection
			if ( in_array( $key, self::COMPLEX_FIELDS, true ) ) {
				$clean[ $safe_key ] = self::sanitize_complex( $value );
				continue;
			}

			// 2. Recursive Array Handling
			if ( is_array( $value ) ) {
				$clean[ $safe_key ] = self::sanitize_array_recursive( $value );
				continue;
			}

			// 3. Standard Text Sanitization
			$clean[ $safe_key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
		}

		return $clean;
	}

	/**
	 * Sanitize structured metadata for saving into CPT/attachment.
	 * Maps form fields into normalized meta keys (Legacy Support).
	 *
	 * @param array<string, mixed> $form_data Sanitized form parameters.
	 * @return array<string, mixed> Key → Value metadata array.
	 */
	public static function sanitize_metadata( array $form_data ): array {
		$meta = array();

		// Use SchemaMapper for consistent field mapping (New Schema)
		$mapped_data = StarmusSchemaMapper::map_form_data( $form_data );

		// --- LEGACY MAPPING (PRESERVED) ---

		if ( ! empty( $mapped_data['dc_creator'] ) ) {
			$meta['_starmus_title'] = sanitize_text_field( $mapped_data['dc_creator'] );
		}

		if ( ! empty( $form_data['description'] ) ) {
			$meta['_starmus_description'] = sanitize_textarea_field( $form_data['description'] );
		}

		if ( ! empty( $mapped_data['language'] ) ) {
			$meta['_starmus_language'] = sanitize_text_field( (string) $mapped_data['language'] );
		}

		if ( ! empty( $form_data['dialect'] ) ) {
			$meta['_starmus_dialect'] = sanitize_text_field( (string) $form_data['dialect'] );
		}

		if ( ! empty( $form_data['project_id'] ) ) {
			$meta['_starmus_project_id'] = sanitize_text_field( $form_data['project_id'] );
		}

		if ( ! empty( $form_data['interview_type'] ) ) {
			$meta['_starmus_interview_type'] = sanitize_text_field( $form_data['interview_type'] );
		}

		// Content classification
		if ( ! empty( $form_data['story_type'] ) ) {
			$meta['_story_type'] = sanitize_text_field( $form_data['story_type'] );
		}

		if ( ! empty( $form_data['rating'] ) ) {
			$meta['_content_rating'] = sanitize_text_field( $form_data['rating'] );
		}

		// Location context
		if ( ! empty( $form_data['geolocation'] ) ) {
			$meta['_geolocation'] = sanitize_text_field( $form_data['geolocation'] );
		}

		if ( ! empty( $form_data['countries_lived'] ) && is_array( $form_data['countries_lived'] ) ) {
			$meta['_countries_lived'] = array_map( sanitize_text_field( ... ), $form_data['countries_lived'] );
		}

		// Custom fields passthrough (prefix enforcement)
		foreach ( $form_data as $key => $value ) {
			if ( str_starts_with( (string) $key, 'custom_' ) ) {
				$meta['_' . sanitize_key( $key )] = sanitize_text_field( (string) $value );
			}
		}

		// --- NEW SCHEMA MERGE ---
		return array_merge( $mapped_data, $meta );
	}

	/**
	 * Retrieve the best-effort client IP address.
	 * Handles complex proxy chains (X-Forwarded-For) securely.
	 *
	 * @return string Sanitized IPv4/IPv6 address string.
	 */
	public static function get_user_ip(): string {
		$ip = '';
		$headers = array(
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR'
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = wp_unslash( $_SERVER[ $header ] );
				break;
			}
		}

		// Handle multiple IPs (e.g. "client, proxy1, proxy2")
		if ( str_contains( (string) $ip, ',' ) ) {
			$parts = explode( ',', (string) $ip );
			$ip    = trim( $parts[0] );
		}

		return sanitize_text_field( (string) $ip ) ?: '0.0.0.0';
	}

	/**
	 * Captures system-level context to prevent data loss from environment stripping.
	 * Useful for debugging firewall/proxy issues.
	 *
	 * @return array<string, string>
	 */
	public static function capture_system_context(): array {
		return array(
			'user_agent'      => sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ),
			'server_protocol' => sanitize_text_field( $_SERVER['SERVER_PROTOCOL'] ?? '' ),
			'request_method'  => sanitize_text_field( $_SERVER['REQUEST_METHOD'] ?? '' ),
			'content_type'    => sanitize_text_field( $_SERVER['CONTENT_TYPE'] ?? '' ),
			'timestamp_utc'   => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Retrieves and sanitizes prosody data for a given post ID.
	 * Used by the Prosody Engine.
	 *
	 * @param int $post_id
	 * @return array<string, mixed>
	 */
	public static function get_sanitized_prosody_data( int $post_id ): array {
		try {
			$post_id = (int) $post_id;
			$post    = get_post( $post_id );

			// Note: Adjusted check to match 'audio-script' or 'starmus-script' depending on your CPT name
			if ( ! $post || ! in_array( $post->post_type, array( 'starmus-script', 'audio-script' ), true ) ) {
				return array();
			}

			$raw_data = array(
				'performance_mode'   => get_field( 'performance_mode', $post_id ) ?: 'conversational',
				'energy_level'       => get_field( 'energy_level', $post_id ) ?: 'neutral',
				'visual_density'     => (int) get_field( 'visual_density', $post_id ),
				'calibrated_pace_ms' => (int) get_field( 'calibrated_pace_ms', $post_id ),
			);

			// Sanitize Selections
			$raw_data['performance_mode'] = self::sanitize_selection(
				(string) $raw_data['performance_mode'],
				array( 'conversational', 'narrative', 'dramatic', 'announcer' )
			);

			$raw_data['energy_level'] = self::sanitize_selection(
				(string) $raw_data['energy_level'],
				array( 'high', 'neutral', 'low' )
			);

			return $raw_data;
		} catch ( \Throwable $e ) {
			StarmusLogger::log( $e );
			return array();
		}
	}

	// --- PRIVATE HELPERS ---

	private static function sanitize_array_recursive( array $array ): array {
		$clean = array();
		foreach ( $array as $key => $val ) {
			if ( is_array( $val ) ) {
				$clean[ $key ] = self::sanitize_array_recursive( $val );
			} else {
				$clean[ $key ] = is_string( $val ) ? sanitize_text_field( $val ) : $val;
			}
		}
		return $clean;
	}

	private static function sanitize_complex( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return (string) json_encode( $value );
		}
		return sanitize_textarea_field( wp_unslash( $value ) );
	}

	private static function sanitize_selection( string $input, array $allowed ): string {
		return in_array( $input, $allowed, true ) ? $input : 'default';
	}
}