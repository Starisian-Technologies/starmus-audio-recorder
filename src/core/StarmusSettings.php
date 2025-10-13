<?php
/**
 * Optimized and secure settings management for the Starmus plugin.
 *
 * @package Starisian\Starmus\core
 * @version 0.8.0
 * @since 0.3.1
 */

namespace Starisian\Starmus\core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StarmusSettings {

	/**
	 * Whitelisted MIME types for audio/video.
	 * Centralized in a constant for clarity and reuse.
	 */
	private const ALLOWED_MIMES = [
		'mp3'  => 'audio/mpeg',
		'wav'  => 'audio/wav',
		'ogg'  => 'audio/ogg',
		'oga'  => 'audio/ogg',
		'opus' => 'audio/ogg; codecs=opus',
		'weba' => 'audio/webm',
		'aac'  => 'audio/aac',
		'm4a'  => 'audio/mp4',
		'flac' => 'audio/flac',
		'mp4'  => 'video/mp4',
		'm4v'  => 'video/x-m4v',
		'mov'  => 'video/quicktime',
		'webm' => 'video/webm',
		'ogv'  => 'video/ogg',
		'avi'  => 'video/x-msvideo',
		'wmv'  => 'video/x-ms-wmv',
		'3gp'  => 'video/3gpp',
		'3g2'  => 'video/3gpp2',
	];

	/**
	 * WP option key for storing settings.
	 * REVERTED: Back to OPTION_KEY, no transients.
	 */
	public const STARMUS_OPTION_KEY = 'starmus_options';

	/**
	 * Cached plugin settings for current request.
	 * Using nullable type allows simple cache invalidation with `null`.
	 */
	private ?array $obj_cache = null;

	/**
	 * Cached default settings to avoid recomputation.
	 */
	private ?array $default_obj_cache = null;

	public function __construct() {
		// Prime the caches for immediate use.
		$this->default_obj_cache = $this->get_defaults();
		$this->obj_cache         = $this->all(); // This will now fetch from options
		$this->register_hooks();
	}

	/**
	 * Register all filters related to MIME validation.
	 */
	private function register_hooks(): void {
		add_filter( 'wp_check_filetype_and_ext', [ $this, 'filter_filetype_and_ext' ], 10, 5 );
		add_filter( 'upload_mimes', [ $this, 'filter_upload_mimes' ] );
	}

	/**
	 * Retrieve a single setting by key with default fallback.
	 *
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$settings = $this->all();
		return $settings[ $key ] ?? $default;
	}

	/**
	 * Retrieve all settings (from option, with cache).
	 * REVERTED: Now pulls from wp_options.
	 */
	public function all(): array {
		if ( $this->obj_cache === null ) {
			$saved  = \get_option( self::STARMUS_OPTION_KEY, [] );

            // --- ADD THIS DEBUGGING LINE ---
            error_log( 'StarmusSettings::all() - RAW result from get_option(' . self::STARMUS_OPTION_KEY . '): ' . print_r( $saved, true ) );
            // --- END DEBUGGING LINE ---

			$merged = \wp_parse_args( $saved, $this->get_defaults() );
			$this->obj_cache = $merged;
		}
		return $this->obj_cache;
	}


	/**
	 * Set a single setting (into wp_options) with sanitization and cache clear.
	 * REVERTED: Now sets option.
	 */
	public function set( string $key, $value ): bool {
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}

		$current_settings         = $this->all(); // Get current (merged) settings
		$current_settings[ $key ] = $this->sanitize_value( $key, $value );

		$updated = \update_option( self::STARMUS_OPTION_KEY, $current_settings ); // Update the entire option
		if ( $updated ) {
			$this->clear_cache();
		}
		return $updated;
	}

	/**
	 * Bulk update settings (into wp_options) with validation and defaults merge.
	 * REVERTED: Now updates option.
	 */
	public function update_all( array $settings ): bool {
		$sanitized = [];
		foreach ( $settings as $key => $value ) {
			if ( $this->is_valid_key( $key ) ) {
				$sanitized[ $key ] = $this->sanitize_value( $key, $value );
			}
		}

		// Ensure that the update preserves existing settings not explicitly passed
		$current_settings       = $this->all(); // Get current effective settings
		$final_settings_to_save = \wp_parse_args( $sanitized, $current_settings ); // New values take precedence

		$updated = \update_option( self::STARMUS_OPTION_KEY, $final_settings_to_save ); // Update the entire option
		if ( $updated ) {
			$this->clear_cache();
		}
		return $updated;
	}

	/**
	 * Retrieve default settings with cache.
	 * FIXED: Syntax error in consent_message.
	 */
	public function get_defaults(): array {
		if ( $this->default_obj_cache === null ) {
			$this->default_obj_cache = [
				'cpt_slug'              => 'audio-recording',
				'file_size_limit'       => 10,
				'recording_time_limit'  => 300,
				'allowed_file_types'    => '',
				'consent_message'       => 'I consent to having this audio recording stored and used.', // FIXED SYNTAX
				'collect_ip_ua'         => 0,
				'data_policy_url'       => '',
				'edit_page_id'          => 0,
				'recorder_page_id'      => 0,
				'my_recordings_page_id' => 0,
			];
			$this->default_obj_cache = \apply_filters( 'starmus_default_settings', $this->default_obj_cache );
		}
		return $this->default_obj_cache;
	}

	/**
	 * Initialize defaults on plugin activation using options API.
	 * REVERTED: Uses add_option/update_option.
	 */
	public function add_defaults_on_activation(): void {
		$existing_options = \get_option( self::STARMUS_OPTION_KEY, false );
		if ( false === $existing_options ) {
			// Option doesn't exist, so add it with defaults.
			\add_option( self::STARMUS_OPTION_KEY, $this->get_defaults(), '', 'yes' ); // 'yes' for autoload
		} else {
			// Option exists, ensure it's merged with current defaults (for new settings in updates).
			$merged = \wp_parse_args( $existing_options, $this->get_defaults() );
			\update_option( self::STARMUS_OPTION_KEY, $merged );
		}
		$this->clear_cache(); // Clear internal cache to ensure new default is used immediately
	}

	/**
	 * Check if the provided key exists in defaults.
	 */
	private function is_valid_key( string $key ): bool {
		return \array_key_exists( $key, $this->get_defaults() );
	}

	/**
	 * Sanitize setting values based on their expected type.
	 */
	private function sanitize_value( string $key, $value ) {
		switch ( $key ) {
			case 'cpt_slug':
				return \sanitize_key( (string) $value );
			case 'file_size_limit':
				$v = (int) $value;
				return max( 1, $v );
			case 'recording_time_limit':
				$v = (int) $value;
				return max( 1, min( $v, 3600 ) );
			case 'collect_ip_ua':
				return (int) ! empty( $value );
			case 'edit_page_id':
			case 'recorder_page_id':
			case 'my_recordings_page_id':
				return \absint( $value );
			case 'allowed_file_types':
				$list = \is_array( $value ) ? $value : explode( ',', (string) $value );
				$list = array_map( static fn( $s ) => trim( strtolower( (string) $s ) ), $list );
				$list = array_filter( $list, static fn( $s ) => $s !== '' );
				$list = array_map( static fn( $s ) => preg_replace( '/[^a-z0-9\.\-+\/]/', '', $s ), $list );
				$list = array_unique( $list );
				return implode( ',', $list );
			case 'consent_message':
				return \wp_kses_post( (string) $value );
			case 'data_policy_url':
				return \esc_url_raw( (string) $value );
			default:
				return \sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
		}
	}

	/**
	 * Clear in-memory caches.
	 */
	public function clear_cache(): void {
		$this->obj_cache         = null;
		$this->default_obj_cache = null;
	}

	/**
	 * Delete all plugin settings (e.g., on uninstall).
	 * REVERTED: Now deletes option.
	 */
	public function delete_all(): bool {
		$this->clear_cache();
		return \delete_option( self::STARMUS_OPTION_KEY );
	}

	/**
	 * Legacy wrapper (deprecated).
	 */
	public function starmus_get_option( string $key, $default = '' ) {
		return $this->get( $key, $default );
	}

	/**
	 * Filter MIME type detection to whitelist allowed formats.
	 */
	public function filter_filetype_and_ext( $types, $file, $filename, $mimes_allowed, $real_mime ): array {
		unset( $file, $mimes_allowed, $real_mime );

		$ext       = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		$whitelist = self::get_allowed_mimes();

		if ( isset( $whitelist[ $ext ] ) ) {
			return [
				'ext'             => $ext,
				'type'            => $whitelist[ $ext ],
				'proper_filename' => $filename,
			];
		}

		return is_array( $types ) ? $types : [];
	}

	/**
	 * Filter upload MIME whitelist to allow audio/video formats.
	 */
	public function filter_upload_mimes( array $mimes ): array {
		foreach ( self::get_allowed_mimes() as $ext => $mime ) {
			$mimes[ $ext ] = $mime;
		}
		return $mimes;
	}

	/**
	 * Public static getter for the allowed MIME map.
	 */
	public static function get_allowed_mimes(): array {
		return self::ALLOWED_MIMES;
	}
}