<?php
namespace Starmus;
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
 * Description:       Adds a mobile-friendly MP3 audio recorder for oral history submission in low-bandwidth environments.
 * Version:           0.5.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Starisian Technologies (Max Barrett)
 * Author URI:        https://starisian.com
 * Text Domain:       starmus-audio-recorder
 * License:           LicenseRef-Starisian-Technologies-Proprietary
 * License URI:       https://github.com/Starisian-Technologies/starmus-audio-recorder/LICENSE.md
 * Update URI:        https://github.com/Starisian-Technologies/starmus-audio-recorder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'STARMUS_PATH' ) ) {
	define( 'STARMUS_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'STARMUS_URL' ) ) {
	define( 'STARMUS_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'STARMUS_VERSION' ) ) {
	define( 'STARMUS_VERSION', '0.5.0' );
}


use Starmus\includes\StarmusAudioRecorderHandler;

/**
 * Class AudioRecorder
 *
 * Handles audio recording functionalities for the Starmus Audio Recorder WordPress plugin.
 * This class is declared as final and cannot be extended.
 *
 * @package StarmusAudioRecorder
 */
final class AudioRecorder {
	/**
	 * Plugin version constant.
	 *
	 * Represents the current version of the Starmus Audio Recorder plugin.
	 *
	 * @var string
	 */
	const VERSION = '0.5.0';
	const MINIMUM_PHP_VERSION = '7.2';
	const MINIMUM_WP_VERSION = '5.2';

	private static $instance = null;
	private $plugin_path;
	private $plugin_url;
	private $StarmusHandler = null;

	private function __construct() {
		$this->plugin_path = STARMUS_PATH;
		$this->plugin_url  = STARMUS_URL;

		if ( ! $this->check_compatibility() ) {
			add_action( 'admin_notices', array( $this, 'admin_notice_compatibility' ) );
			return;
		}

		$this->load_dependencies();
		// add the handler
		if ( ! isset( $this->StarmusHandler ) ) {
			$this->StarmusHandler = new \Starmus\includes\StarmusAudioSubmissionHandler();
		}
	}

	/**
	 * Retrieves the singleton instance of the AudioRecorder class.
	 *
	 * @return AudioRecorder The single instance of the AudioRecorder.
	 */
	public static function get_instance(): AudioRecorder {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function check_compatibility(): bool {
		if ( version_compare( PHP_VERSION, self::MINIMUM_PHP_VERSION, '<' ) ) {
			return false;
		}

		global $wp_version;
		if ( version_compare( $wp_version, self::MINIMUM_WP_VERSION, '<' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Displays an admin notice regarding compatibility issues.
	 *
	 * This method outputs a notice in the WordPress admin area to inform users about
	 * compatibility concerns related to the plugin or its environment.
	 *
	 * @return void
	 */
	public function admin_notice_compatibility(): void {
		echo '<div class="notice notice-error"><p>';
		if ( version_compare( PHP_VERSION, self::MINIMUM_PHP_VERSION, '<' ) ) {
			echo esc_html__( 'Starmus Audio Recorder requires PHP version ' . self::MINIMUM_PHP_VERSION . ' or higher.', 'starmus-audio-recorder' ) . '<br>';
		}
		if ( version_compare( $GLOBALS['wp_version'], self::MINIMUM_WP_VERSION, '<' ) ) {
			echo esc_html__( 'Starmus Audio Recorder requires WordPress version ' . self::MINIMUM_WP_VERSION . ' or higher.', 'starmus-audio-recorder' );
		}
		echo '</p></div>';
	}

	private function load_dependencies(): void {
		require_once $this->plugin_path . 'includes/starmus-audio-recorder-handler.php';
	}
	
	/**
	 * Executes the main functionality of the Starmus Audio Recorder plugin.
	 *
	 * This static method is the entry point for running the plugin's core logic.
	 * It should be called to initialize and start the audio recording features.
	 *
	 * @return void
	 */
	public static function starmus_run(): void {
		if (
			! isset( $GLOBALS['Starmus\AudioRecorder'] ) ||
			! $GLOBALS['Starmus\AudioRecorder'] instanceof self
		) {
			$GLOBALS['Starmus\AudioRecorder'] = self::get_instance();
		}
	}

	public static function starmus_activate(): void {
		flush_rewrite_rules();
	}

	public static function starmus_deactivate(): void {
		flush_rewrite_rules();
	}

	public static function starmus_uninstall(): void {
		// Optional cleanup logic for uninstall.
		// Example: delete_option('starmus_audio_recorder_settings');
	}
}

register_activation_hook( __FILE__, array( 'Starmus\AudioRecorder', 'starmus_activate' ) );
register_deactivation_hook( __FILE__, array( 'Starmus\AudioRecorder', 'starmus_deactivate' ) );
register_uninstall_hook( __FILE__, array( 'Starmus\AudioRecorder', 'starmus_uninstall' ) );
// Initialize the plugin
add_action( 'plugins_loaded', array( 'Starmus\AudioRecorder', 'starmus_run' ) );


