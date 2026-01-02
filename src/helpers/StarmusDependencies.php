<?php

/**
 * Dependency Manager & System Validator.
 *
 * A self-contained static class responsible for the "Environment Negotiation" phase.
 * It enforces version requirements, detects conflicts, and loads bundled dependencies
 * in a deterministic order.
 *
 * @package Starisian\Sparxstar\Starmus\helpers
 * @version 1.5.0
 */

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\helpers;

use function add_action;
use function add_filter;
use function class_exists;
use function define;
use function defined;
use function file_exists;
use function function_exists;
use function implode;
use function is_admin;
use function is_plugin_active;
use function phpversion;
use function version_compare;

if (! defined('ABSPATH')) {
	exit;
}

class StarmusDependencies
{

	private const MIN_PHP_VERSION = '8.2';
	private const MIN_WP_VERSION  = '6.8';

	/**
	 * Main Entry Point: Validates environment and loads dependencies.
	 *
	 * Call this from the bootloader. If it returns true, the Core is safe to launch.
	 *
	 * @return bool True if environment is healthy and dependencies loaded.
	 */
	public static function bootstrap_scf(): bool
	{
		// 1. Check System Requirements (PHP/WP)
		if (! self::check_versions()) {
			return false;
		}
		if (! self::is_safe_to_load_scf()) {
			return false;
		}
		// 2. Negotiate Secure Custom Fields (Conflict Check + Loading)
		if (! self::load_scf()) {
			return false;
		}

		// 3. Register Schema (Only if SCF loaded successfully)
		self::register_acf_schema();

		return true;
	}

	/**
	 * 1. Check PHP and WordPress Versions.
	 */
	private static function check_versions(): bool
	{
		$errors = array();

		if (version_compare(phpversion(), self::MIN_PHP_VERSION, '<')) {
			$errors[] = 'PHP version ' . self::MIN_PHP_VERSION . '+ is required. Running: ' . phpversion();
		}

		global $wp_version;
		if (version_compare($wp_version, self::MIN_WP_VERSION, '<')) {
			$errors[] = 'WordPress version ' . self::MIN_WP_VERSION . '+ is required. Running: ' . $wp_version;
		}

		if (! empty($errors)) {
			self::render_error($errors);
			return false;
		}

		return true;
	}

	/**
	 * 1 Check if ACF/SCF is installed.
	 */
	public static function is_safe_to_load_scf(): bool
	{
		// Ensure helper functions are available
		if (! function_exists('is_plugin_active')) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// A. Check for HARD CONFLICTS (Active Plugins)
		// These plugins explicitly conflict with our bundled version logic.
		$conflicting_plugins = array(
			'secure-custom-fields/secure-custom-fields.php',
			'advanced-custom-fields-pro/acf.php',
			'advanced-custom-fields/acf.php',
		);

		foreach ($conflicting_plugins as $plugin) {
			if (is_plugin_active($plugin)) {
				self::render_error(array(
					'Conflict Detected: An external version of ACF or SCF is active (' . $plugin . ').',
					'Please deactivate it to allow Starmus to load its required bundled version.'
				));
				return false;
			}
		}

		// B. Check for PRE-LOADED ENVIRONMENT (Composer/Other)
		// If code is present but not an active plugin, it is likely a shared library.
		if (function_exists('acf_get_instance') || class_exists('ACF')) {
			// VALIDATION: Ensure the environment is actually usable.
			if (! function_exists('acf_add_local_field_group')) {
				self::render_error(array(
					'Dependency Error: A preloaded ACF/SCF environment was detected, but it appears incomplete or corrupted.',
					'Missing required function: acf_add_local_field_group'
				));
				return false;
			}
			// Environment is valid. Skip loading our bundle.
			return true;
		}
		return true;
	}
	/**
	 * 2. Load Bundled SCF (With Strict Activation Checks).
	 */
	private static function load_scf(): bool
	{
		// C. Installation: Load Bundled Version
		$scf_path = STARMUS_PATH . 'vendor/secure-custom-fields/secure-custom-fields.php';

		if (! file_exists($scf_path)) {
			self::render_error(array('Critical Dependency Missing: bundled Secure Custom Fields not found.'));
			return false;
		}

		// Configure SCF Constants & Filters
		if (! defined('STARMUS_ACF_PATH')) define('STARMUS_ACF_PATH', STARMUS_PATH . 'vendor/secure-custom-fields/');
		if (! defined('STARMUS_ACF_URL')) define('STARMUS_ACF_URL', STARMUS_URL . 'vendor/secure-custom-fields/');

		add_filter('acf/settings/path', fn() => STARMUS_ACF_PATH);
		add_filter('acf/settings/url', fn() => STARMUS_ACF_URL);
		add_filter('acf/settings/show_admin', '__return_false');
		add_filter('acf/settings/show_updates', '__return_false', 100);

		require_once $scf_path;

		// Final Verification
		if (! function_exists('acf_get_instance')) {
			self::render_error(array('Dependency Load Failed: Secure Custom Fields could not initialize.'));
			return false;
		}

		return true;
	}

	/**
	 * 3. Register JSON Schema Paths.
	 */
	private static function register_acf_schema(): void
	{
		add_filter('acf/settings/save_json', fn() => STARMUS_PATH . 'acf-json');
		add_filter('acf/settings/load_json', function ($paths) {
			$paths[] = STARMUS_PATH . 'acf-json';
			return $paths;
		});
	}

	/**
	 * Helper to display admin notices.
	 */
	private static function render_error(array $messages): void
	{
		if (! is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
			return;
		}

		add_action('admin_notices', function () use ($messages) {
?>
			<div class="notice notice-error">
				<p><strong>Starmus Audio Recorder Error:</strong></p>
				<ul>
					<?php foreach ($messages as $msg) : ?>
						<li><?php echo esc_html($msg); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
<?php
		});
	}
}
