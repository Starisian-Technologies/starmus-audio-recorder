<?php
/**
 * Optimized and secure settings management for Starmus plugin.
 *
 * @package Starmus\includes
 * @version 0.7.5
 * @since 0.3.1
 */

namespace Starmus\includes;

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

final class StarmusSettings {

		/**
		 * Option key used to store plugin settings.
		 *
		 * @var string
		 */
	public const STAR_OPTION_KEY = 'starmus_options';

		/**
		 * Cached plugin settings for the current request.
		 *
		 * @var array|null
		 */
	private static $star_cache = array();

		/**
		 * Cached default settings to avoid repeated computation.
		 *
		 * @var array|null
		 */
	private static $star_default_cache = array();

	public function __construct() {
		// get all settings
		self::all();
	}

	/**
	 * Get a single setting with caching.
	 */
	public static function get( string $key, $default = null ) {
		$settings = self::all();
		return $settings[ $key ] ?? $default;
	}

	/**
	 * Get all settings with caching optimization.
	 */
	public static function all(): array {
		if ( self::$star_cache === null ) {
			$saved            = \get_option( self::STAR_OPTION_KEY, array() );
			$defaults         = self::get_defaults();
			self::$star_cache = wp_parse_args( $saved, $defaults );
		}
		return self::$star_cache;
	}

	/**
	 * Set a single setting with validation.
	 */
	public static function set( string $key, $value ): bool {
		if ( ! self::is_valid_key( $key ) ) {
			return false;
		}

		$saved         = \get_option( self::STAR_OPTION_KEY, array() );
		$saved[ $key ] = self::starmus_sanitize_value( $key, $value );

		$result = \update_option( self::STAR_OPTION_KEY, $saved );
		if ( $result ) {
			self::starmus_clear_cache();
		}
		return $result;
	}

	/**
	 * Update all settings with validation and defaults merge.
	 */
	public static function update_all( array $settings ): bool {
		$sanitized = array();
		foreach ( $settings as $key => $value ) {
			if ( self::is_valid_key( $key ) ) {
				$sanitized[ $key ] = self::starmus_sanitize_value( $key, $value );
			}
		}

		// Merge with defaults to prevent missing keys
		$merged = \wp_parse_args( $sanitized, self::get_defaults() );

		$result = \update_option( self::STAR_OPTION_KEY, $merged );
		if ( $result ) {
			self::starmus_clear_cache();
		}
		return $result;
	}

	/**
	 * Get defaults with caching.
	 */
	public static function get_defaults(): array {
		if ( self::$star_default_cache === null ) {
			self::$star_default_cache = array(
				'cpt_slug'              => 'audio-recording',
				'file_size_limit'       => 10,
				'recording_time_limit'  => 300,
				'allowed_file_types'    => '', // Blank by default, admin must set
				'consent_message'       => \__( 'I consent to having this audio recording stored and used.', 'starmus-audio-recorder' ),
				'collect_ip_ua'         => 0,
				'data_policy_url'       => '',
				'edit_page_id'          => 0,
				'recorder_page_id'      => 0,
				'my_recordings_page_id' => 0,
			);
			self::$star_default_cache = \apply_filters( 'starmus_default_settings', self::$star_default_cache );
		}
		return self::$star_default_cache;
	}

	/**
	 * Initialize defaults on activation with proper handling.
	 */
	public static function add_defaults_on_activation(): void {
		$existing = get_option( self::STAR_OPTION_KEY );
		if ( $existing === false ) {
			\add_option( self::STAR_OPTION_KEY, self::get_defaults() );
		} else {
			// Merge with existing to preserve user settings
			$merged = \wp_parse_args( $existing, self::get_defaults() );
			\update_option( self::STAR_OPTION_KEY, $merged );
		}
		self::starmus_clear_cache();
	}

	/**
	 * Validate setting key.
	 */
	private static function is_valid_key( string $key ): bool {
		$valid_keys = array_keys( self::get_defaults() );
		return in_array( $key, $valid_keys, true );
	}

	/**
	 * Sanitize setting value based on key.
	 */
	private static function starmus_sanitize_value( string $key, $value ) {
		switch ( $key ) {
			case 'cpt_slug':
				return \sanitize_key( $value );
			case 'file_size_limit':
			case 'recording_time_limit':
			case 'collect_ip_ua':
			case 'edit_page_id':
			case 'recorder_page_id':
			case 'my_recordings_page_id':
				return \absint( $value );
			case 'allowed_file_types':
				return \sanitize_text_field( $value );
			case 'consent_message':
				return \wp_kses_post( $value );
			case 'data_policy_url':
				return \esc_url_raw( $value );
			default:
				return \sanitize_text_field( $value );
		}
	}

	/**
	 * Clear internal cache.
	 */
	private static function starmus_clear_cache(): void {
		self::$star_cache = null;
	}

	/**
	 * Delete all settings (for uninstall).
	 */
	public static function delete_all(): bool {
		self::starmus_clear_cache();
		return \delete_option( self::STAR_OPTION_KEY );
	}

	/**
	 * @deprecated Use get() instead
	 */
	public static function starmus_get_option( string $key, $default_settings = '' ) {
		return self::get( $key, $default_settings );
	}
}
