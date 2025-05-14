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
 * Version:           0.4.0
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

final class AudioRecorder {
	const VERSION = '0.4.0';
	const MINIMUM_PHP_VERSION = '7.2';
	const MINIMUM_WP_VERSION = '5.2';

	private static $instance = null;
	private $plugin_path;
	private $plugin_url;

	private function __construct() {
		$this->plugin_path = plugin_dir_path( __FILE__ );
		$this->plugin_url  = plugin_dir_url( __FILE__ );

		if ( ! $this->check_compatibility() ) {
			add_action( 'admin_notices', array( $this, 'admin_notice_compatibility' ) );
			return;
		}

		$this->load_dependencies();
		$this->register_hooks();
	}

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
		// No external dependencies yet.
	}

	private function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets(): void {
		$allowed_pages = apply_filters( 'starmus_audio_recorder_allowed_pages', array( 'submit-oral-history', 'test' ) );
		if ( ! is_page( $allowed_pages ) ) {
			return;
		}

		wp_enqueue_script(
			'starmus-audio-recorder',
			$this->plugin_url . 'assets/js/starmus-audio-recorder.js',
			array(),
			self::VERSION,
			true
		);

		wp_enqueue_style(
			'starmus-audio-recorder-style',
			$this->plugin_url . 'assets/css/starmus-audio-recorder-style.css',
			array(),
			self::VERSION
		);
	}

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

Starmus\AudioRecorder::starmus_run();
