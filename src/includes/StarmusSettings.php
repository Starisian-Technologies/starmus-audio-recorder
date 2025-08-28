<?php
namespace Starmus\includes;

/**
 * A robust, centralized API for managing all plugin settings.
 *
 * This final class provides a complete CRUD (Create, Read, Update, Delete)
 * interface for the plugin's options, ensuring that all settings are
 * handled consistently and have reliable default values.
 *
 * @package Starmus\includes
 */
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StarmusSettings {

	/**
	 * The single key used to store all plugin options in the wp_options table.
	 *
	 * @var string
	 */
	public const OPTION_KEY = 'starmus_options';

	/**
	 * Retrieves a single setting value.
	 *
	 * This is the primary method for getting settings. It merges saved options
	 * with the defaults, ensuring a valid value is always returned.
	 *
	 * @param string $key The key of the setting to retrieve.
	 * @param mixed  $default A fallback default value if the key is not found in settings or defaults.
	 * @return mixed The value of the setting.
	 */
	public static function get( string $key, $default = null ) {
		$all_settings = self::all();
		return $all_settings[ $key ] ?? $default;
	}

	/**
	 * Retrieves all settings, merged with defaults.
	 *
	 * @return array A complete array of all settings.
	 */
	public static function all(): array {
		$saved_options = get_option( self::OPTION_KEY, array() );
		$defaults      = self::get_defaults();

		// wp_parse_args is the WordPress standard for merging settings with defaults.
		return wp_parse_args( $saved_options, $defaults );
	}

	/**
	 * Helper function to safely get a setting value.
	 *
	 * This method is kept for backward compatibility. It now acts as a wrapper
	 * for the new `get()` method.
	 *
	 * @deprecated Use StarmusSettings::get() instead for new code.
	 */
	public static function starmus_get_option( string $key, $default = '' ) {
		// This now delegates to the new, more robust `get()` method.
		// All the logic is handled there, ensuring consistency.
		return self::get( $key, $default );
	}

	/**
	 * Updates a single setting value.
	 *
	 * @param string $key The key of the setting to update.
	 * @param mixed  $value The new value for the setting.
	 * @return bool True if the option was updated, false otherwise.
	 */
	public static function set( string $key, $value ): bool {
		$all_settings         = self::all(); // Get all current settings, including defaults
		$all_settings[ $key ] = $value;
		return update_option( self::OPTION_KEY, $all_settings );
	}

	/**
	 * Replaces all settings with a new array of settings.
	 * Useful for handling a settings form submission.
	 *
	 * @param array $settings An associative array of settings to save.
	 * @return bool True if the option was updated, false otherwise.
	 */
	public static function update_all( array $settings ): bool {
		return update_option( self::OPTION_KEY, $settings );
	}

	/**
	 * Provides a central, filterable list of default settings.
	 *
	 * @return array The default plugin settings.
	 */
	public static function get_defaults(): array {
		$defaults = array(
			'cpt_slug'             => 'audio-recording',
			'file_size_limit'      => 10, // MB
			'recording_time_limit' => 300, // Seconds
			'allowed_file_types'   => 'mp3,wav,webm,m4a,ogg,opus',
			'consent_message'      => __( 'I consent to having this audio recording stored and used.', 'starmus_audio_recorder' ),
			'collect_ip_ua'        => 0, // Boolean-like (0 or 1)
			'data_policy_url'      => '',
			'edit_page_id'         => 0,
		);

		return apply_filters( 'starmus_default_settings', $defaults );
	}

	/**
	 * Saves the default options to the database on plugin activation.
	 * This should be called from your plugin's activation hook.
	 */
	public static function add_defaults_on_activation(): void {
		add_option( self::OPTION_KEY, self::get_defaults() );
	}
}
