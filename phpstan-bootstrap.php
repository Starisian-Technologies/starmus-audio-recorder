<?php
/**
 * PHPStan Bootstrap File.
 *
 * This file is loaded by PHPStan before scanning the codebase. It sets up a
 * minimal, mock WordPress environment to prevent "file not found" or "function
 * not defined" errors during static analysis.
 *
 * @package StarmusAudioRecorder
 * @since 0.1.0
 * @version 0.3.0
 */

// Define a minimal set of WordPress constants to avoid errors.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
define( 'ABSPATH', __DIR__ . '/fake-wordpress-dir/' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
define( 'WPINC', 'wp-includes' );

// Mock WordPress functions that are required early in the plugin's main file.
if ( ! function_exists( 'plugin_dir_path' ) ) {
	/**
	 * Mock of the plugin_dir_path() function.
	 *
	 * @param string $file The file path.
	 * @return string The directory path.
	 */
	function plugin_dir_path( $file ) {
		return trailingslashit( dirname( $file ) );
	}
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
	/**
	 * Mock of the plugin_dir_url() function.
	 *
	 * @param string $file The file path.
	 * @return string The directory URL.
	 */
	function plugin_dir_url( $file ) {
		// This is a simplistic mock; adjust if you need a more realistic URL.
		return 'https://example.com/wp-content/plugins/starmus-audio-recorder/';
	}
}

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_read_is_dir
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_read_mkdir
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_read_touch
// Native file functions are required here because WP_Filesystem is not available.

// Create dummy WordPress core files that are being required.
// This prevents "file not found" errors for `require_once`.
$starmus_dummy_wp_admin_dir = __DIR__ . '/wp-admin/includes';
if ( ! is_dir( $starmus_dummy_wp_admin_dir ) ) {
	mkdir( $starmus_dummy_wp_admin_dir, 0755, true );
}
touch( $starmus_dummy_wp_admin_dir . '/file.php' );
touch( $starmus_dummy_wp_admin_dir . '/media.php' );
touch( $starmus_dummy_wp_admin_dir . '/image.php' );

// phpcs:enable

// Now, load the plugin's main file, which will define its own constants.
require_once __DIR__ . '/starmus-audio-recorder.php';