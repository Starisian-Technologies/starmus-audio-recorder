<?php
declare(strict_types=1);

// Import WordPress functions into the namespace
use function plugin_dir_path;
use function plugin_dir_url;
use function get_file_data;
use function add_action;
use function __;
use function esc_html__;
use function esc_html;
use function flush_rewrite_rules;
use function delete_option;
use function get_posts;
use function wp_delete_post;
use function wp_die;
use function register_activation_hook;
use function register_deactivation_hook;
use function register_uninstall_hook;

namespace Starisian\StarmusAudioRecorder;
/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * NOTICE: All information contained herein is, and remains, the property of Starisian Technologies and its suppliers, if any.
 * The intellectual and technical concepts contained herein are proprietary to Starisian Technologies and its suppliers and may be covered by U.S.
 * and foreign patents, patents in process, and are protected by trade secret or copyright law.
 *
 * Dissemination of this information or reproduction of this material is strictly forbidden unless
 * prior written permission is obtained from Starisian Technologies.
 *
 * SPDX-License-Identifier:  LicenseRef-Starisian-Technologies-Proprietary
 * License URI:              https://github.com/Starisian-Technologies/starmus-audio-recorder/LICENSE.md
 */

/**
 * Plugin Name:       Starmus Audio Recorder
 * Plugin URI:        https://github.com/Starisian-Technologies/starmus-audio-recorder
 * Description:       Adds a mobile-friendly audio recorder for oral history submission in low-bandwidth environments.
 * Version:           0.3.1
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Starisian Technologies (Max Barrett)
 * Author URI:        https://starisian.com
 * Text Domain:       starmus-audio-recorder
 * License:           LicenseRef-Starisian-Technologies-Proprietary
 * License URI:       https://github.com/Starisian-Technologies/starmus-audio-recorder/LICENSE.md
 * git URI:           https://github.com/Starisian-Technologies/starmus-audio-recorder.git,
 * update URI:        https://github.com/Starisian-Technologies/starmus-audio-recorder/releases,
 * 
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

// Define plugin constants for easy access.
if ( ! defined( 'STARMUS_PATH' ) ) {
	define( 'STARMUS_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'STARMUS_URL' ) ) {
	define( 'STARMUS_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'STARMUS_VERSION' ) ) {
	// Extract version from plugin header to avoid duplication
	$plugin_data = get_file_data( __FILE__, [ 'Version' => 'Version' ] );
	define( 'STARMUS_VERSION', $plugin_data['Version'] ?: '0.3.1' );
}

// Load the new autoloader.
if ( file_exists( __DIR__ . '/src/Autoloader.php' ) ) {
	require_once __DIR__ . '/src/Autoloader.php';
	if ( class_exists( '\Starisian\\src\\Autoloader' ) ) {
		\Starisian\src\Autoloader::register();
	}
}
// Include the new classes
require_once STARMUS_PATH . 'src/includes/StarmusCustomPostType.php';
require_once STARMUS_PATH . 'src/includes/StarmusSubmissionManager.php';

use Starisian\src\admin\StarmusAdminSettings;
use Starisian\src\includes\StarmusShortcodeManager;
use Starisian\src\includes\StarmusCustomPostType;
use Starisian\src\includes\StarmusAudioSubmissionHandler;

/**
 * Class StarmusAudioRecorder
 *
 * Main plugin class responsible for initialization, compatibility checks,
 * and loading all dependencies. This class is final and cannot be extended.
 *
 * @package StarmusAudioRecorder
 */
final class StarmusAudioRecorder {
	const MINIMUM_PHP_VERSION = '8.2';
	const MINIMUM_WP_VERSION  = '6.4';

	/**
	 * The single instance of the class.
	 * @var StarmusAudioRecorder|null
	 */
	private static ?StarmusAudioRecorder $instance = null;

	/**
	 * The admin settings instance.
	 * @var StarmusAdminSettings|null
	 */
	private ?StarmusAdminSettings $admin_settings = null;

	/**
	 * The shortcode manager instance.
	 * @var StarmusShortcodeManager|null
	 */
	private ?StarmusShortcodeManager $shortcode_manager = null;

	/**
	 * The submission handler instance.
	 * @var StarmusAudioSubmissionHandler|null
	 */
	private ?StarmusAudioSubmissionHandler $submission_handler = null;

	/**
	 * The custom post type handler instance.
	 * @var StarmusCustomPostType|null
	 */
	private ?StarmusCustomPostType $custom_post_type = null;

	/**
	 * Stores compatibility error messages.
	 * @var array
	 */
	private array $compatibility_messages = [];

	/**
	 * Private constructor to enforce the singleton pattern.
	 */
	private function __construct() {
		// First, check for PHP and WordPress version compatibility.
		if ( ! $this->check_compatibility() ) {
			add_action( 'admin_notices', [ $this, 'display_compatibility_notice' ] );
			return; // Stop execution if incompatible.
		}

		// All checks passed, so we can initialize the plugin's components.
		$this->initialize_admin();
		$this->initialize_shortcode_manager();
		$this->initialize_custom_post_type();
		$this->initialize_handlers();
	}

	/**
	 * Retrieves the singleton instance of the class.
	 *
	 * @return StarmusAudioRecorder The single instance of the class.
	 */
	public static function get_instance(): StarmusAudioRecorder {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Checks if the server environment meets the plugin's minimum requirements.
	 *
	 * @return bool True if compatible, false otherwise.
	 */
	private function check_compatibility(): bool {
		if ( version_compare( PHP_VERSION, self::MINIMUM_PHP_VERSION, '<' ) ) {
			$this->compatibility_messages[] = sprintf(
				/* translators: %s: Required PHP version */
				__( 'Starmus Audio Recorder requires PHP version %s or higher.', 'starmus-audio-recorder' ),
				self::MINIMUM_PHP_VERSION
			);
		}

		global $wp_version;
		if ( version_compare( $wp_version, self::MINIMUM_WP_VERSION, '<' ) ) {
			$this->compatibility_messages[] = sprintf(
				/* translators: %s: Required WordPress version */
				__( 'Starmus Audio Recorder requires WordPress version %s or higher.', 'starmus-audio-recorder' ),
				self::MINIMUM_WP_VERSION
			);
		}

		return empty( $this->compatibility_messages );
	}

	/**
	 * Displays an admin notice regarding compatibility issues.
	 */
	public function display_compatibility_notice(): void {
		if ( empty( $this->compatibility_messages ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		foreach ( $this->compatibility_messages as $message ) {
			echo '<strong>' . esc_html__( 'Starmus Audio Recorder Error:', 'starmus-audio-recorder' ) . '</strong> ' . esc_html( $message ) . '<br>';
		}
		echo '</p></div>';
	}

	private function initialize_admin():void{
		if(! class_exists('StarmusAdminSettings')){
			throw new \Exception( 'StarmusAdmin class not found.' );
		}
		try {
			// Initialize the admin settings handler.
			$this->admin_settings = new StarmusAdminSettings();
			// Register the admin settings.
			$this->admin_settings->register_admin_settings();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// Log the error if WP_DEBUG is enabled
				error_log( 'Starmus Audio Recorder: Failed to initialize admin settings - ' . $e->getMessage() );
			}
			return;
		}
	}

	private function initialize_shortcode_manager(): void {
		// Initialize the shortcode manager.
		try {
			if ( ! class_exists( 'StarmusShortcodeManager' ) ) {
				throw new \Exception( 'StarmusShortcodeManager class not found.' );
			}
			$this->shortcode_manager = new StarmusShortcodeManager();
			// Register the shortcodes.
			$this->shortcode_manager->register_shortcodes();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// Log the error if WP_DEBUG is enabled
				error_log( 'Starmus Audio Recorder: Failed to initialize shortcode manager - ' . $e->getMessage() );
			}
		}
	}	

	private function initialize_custom_post_type(): void {
		// Initialize the custom post type handler.
		try {
			if ( ! class_exists( 'StarmusCustomPostType' ) ) {
				throw new \Exception( 'StarmusCustomPostType class not found.' );
			}
			$this->custom_post_type = new StarmusCustomPostType();
			// Register the custom post type.
			$this->custom_post_type->register_custom_post_type();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// Log the error if WP_DEBUG is enabled
				error_log( 'Starmus Audio Recorder: Failed to initialize custom post type - ' . $e->getMessage() );
			}
			return;
		}
	}

	/**
	 * Instantiates the necessary handler classes for the plugin.
	 */
	private function initialize_handlers(): void {
		try {
			if ( ! class_exists( 'StarmusAudioSubmissionHandler' ) ) {
				throw new \Exception( 'StarmusAudioSubmissionHandler class not found.' );
			}
			// Initialize the submission handler.
			$this->submission_handler = \Starisian\src\Includes\StarmusSubmissionManager::get_instance();

		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// Log the error if WP_DEBUG is enabled
				error_log( 'Starmus Audio Recorder: Failed to initialize submission handler - ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Plugin activation callback. Flushes rewrite rules to register the new CPT.
	 */
	public static function activate(): void {
		// Get existing instance or create temporary one for activation
		$instance = self::$instance ?? self::get_instance();
		if ( $instance->custom_post_type ) {
			$instance->custom_post_type->register_custom_post_type();
		}
		//flush rewrite rules to reset links
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation callback.
	 */
	public static function deactivate(): void {
		$instance = self::$instance ?? self::get_instance();
		if ( $instance->custom_post_type ) {
			$instance->custom_post_type->unregister_custom_post_type();
		}

		//flush rewrite rules to reset links
		flush_rewrite_rules();
	}

	/**
	 * Plugin uninstallation callback.
	 * Can be used for cleanup tasks like deleting options.
	 */
	public static function uninstall(): void {
		// Clean up plugin data on uninstall
		delete_option( 'starmus_audio_recorder_settings' );

		

		// Remove custom post type posts (ensure slug matches handler)
		$posts = get_posts( [
				'post_type'   => 'starmus_submission',
				'numberposts' => -1,
				'post_status' => 'any'
			] );

		foreach ( $posts as $post ) {
			wp_delete_post( $post->ID, true );
		}
	}

	/**
	 * Cloning is forbidden.
	 * @since 1.0.0
	 */
	public function __clone() {
		throw new \BadMethodCallException( esc_html__( 'Cloning is forbidden.', 'starmus-audio-recorder' ) );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 * @since 1.0.0
	 */
	public function __wakeup() {
		throw new \BadMethodCallException( esc_html__( 'Unserializing instances of this class is forbidden.', 'starmus-audio-recorder' ) );
	}

	/**
	 * Get the submission handler instance.
	 *
	 * @return StarmusAudioSubmissionHandler|null
	 */
	public function get_submission_handler(): ?StarmusAudioSubmissionHandler {
		return $this->submission_handler;
	}

	/**
	 * Get the custom post type handler instance.
	 *
	 * @return StarmusCustomPostType|null
	 */
	public function get_custom_post_type(): ?StarmusCustomPostType {
		return $this->custom_post_type;
	}he submission handler instance.
	 *
	 * @return StarmusAudioSubmissionHandler|null
	 */
	public function get_submission_handler(): ?StarmusAudioSubmissionHandler {
		return $this->submission_handler;
	}
}

// Register plugin lifecycle hooks.
register_activation_hook( __FILE__, [ __NAMESPACE__ . '\\StarmusAudioRecorder', 'activate' ] );
register_deactivation_hook( __FILE__, [ __NAMESPACE__ . '\\StarmusAudioRecorder', 'deactivate' ] );
register_uninstall_hook( __FILE__, [ __NAMESPACE__ . '\\StarmusAudioRecorder', 'uninstall' ] );

// Initialize the plugin. This is the main entry point.
add_action( 'plugins_loaded', [ __NAMESPACE__ . '\\StarmusAudioRecorder', 'get_instance' ] );