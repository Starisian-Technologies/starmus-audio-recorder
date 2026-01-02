<?php

/**
 * Dependency Manager.
 *
 * Handles detection of external conflicts and loading of bundled dependencies.
 *
 * @package Starisian\Sparxstar\Starmus\helpers
 * @version 1.2.0
 */

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\helpers;

use function add_action;
use function add_filter;
use function class_exists;
use function defined;
use function define;
use function function_exists;
use function is_admin;

if (! defined('ABSPATH')) {
	exit;
}

class StarmusDependencies
{

	/**
	 * Attempt to load the bundled Secure Custom Fields plugin.
	 *
	 * LOGIC:
	 * 1. Check if ACF/SCF is already active (Conflict).
	 * 2. If active -> Register Error & Return False (Do not load bundled).
	 * 3. If inactive -> Load Bundled SCF & Return True.
	 *
	 * @return void
	 */
	public static function try_load_bundled_scf(): void
	{
		error_log('STARMUS TRY LOAD BUNDLED SCF');
		// 1. Check for Interference
		if (function_exists('acf_get_instance') || class_exists('ACF')) {
			// Conflict: External ACF is running.
			if (is_admin() && (! defined('DOING_AJAX') || ! DOING_AJAX)) {
				add_action('admin_notices', function () {
					echo '<div class="notice notice-error"><p><strong>Starmus Error:</strong> An external version of ACF/SCF is active. Please deactivate it to allow Starmus to load its bundled Secure Custom Fields.</p></div>';
				});
			}
			// Mark conflict so Bootstrapper knows to stop
			define('STARMUS_ACF_CONFLICT', true);
			return;
		}

		// 2. Load Bundled
		$scf_path = STARMUS_PATH . 'vendor/secure-custom-fields/secure-custom-fields.php';

		if (file_exists($scf_path)) {
			// Configure Paths using Constants defined in Main File
			if (! defined('STARMUS_ACF_PATH')) define('STARMUS_ACF_PATH', STARMUS_PATH . 'vendor/secure-custom-fields/');
			if (! defined('STARMUS_ACF_URL')) define('STARMUS_ACF_URL', STARMUS_URL . 'vendor/secure-custom-fields/');

			// Hook Filters
			add_filter('acf/settings/path', fn() => STARMUS_ACF_PATH);
			add_filter('acf/settings/url', fn() => STARMUS_ACF_URL);
			add_filter('acf/settings/show_admin', '__return_false');
			add_filter('acf/settings/show_updates', '__return_false', 100);

			// Load
			require_once $scf_path;
		}
	}

	/**
	 * Check if the system is stable enough to boot the Core.
	 *
	 * @return bool
	 */
	public static function ensure_scf(): bool
	{

		// 1. HARD STOP — ANY ACTIVE ACF (FREE OR PRO)
		if (function_exists('acf_get_instance')) {

			// SCF plugin active?
			if (defined('SCF_VERSION') || class_exists('SCF', false)) {
				self::fail(
					'SCF_PLUGIN_ACTIVE',
					'Secure Custom Fields is active as a plugin. Starmus requires the bundled Composer version.'
				);
				return false;
			}

			// Any ACF (free or pro) active
			self::fail(
				'ACF_ACTIVE',
				'Advanced Custom Fields is active. Starmus requires Secure Custom Fields (Composer only).'
			);
			return false;
		}

		// 2. SCF PLUGIN INSTALLED BUT INACTIVE → OK (IGNORE)
		// (do nothing)

		// 3. SCF ALREADY LOADED VIA COMPOSER (by another plugin) → OK
		if (class_exists('SCF', false)) {
			return true;
		}

		// 4. LOAD COMPOSER SCF
		$scf = STARMUS_PATH . 'vendor/secure-custom-fields/secure-custom-fields.php';

		if (! file_exists($scf)) {
			self::fail(
				'SCF_MISSING',
				'Secure Custom Fields not found (Composer package missing).'
			);
			return false;
		}

		define('STARMUS_ACF_PATH', STARMUS_PATH . 'vendor/secure-custom-fields/');
		define('STARMUS_ACF_URL', STARMUS_URL . 'vendor/secure-custom-fields/');

		add_filter('acf/settings/path', fn() => STARMUS_ACF_PATH);
		add_filter('acf/settings/url', fn() => STARMUS_ACF_URL);
		add_filter('acf/settings/show_admin', '__return_false');
		add_filter('acf/settings/show_updates', '__return_false', 100);

		require_once $scf;

		// 5. VERIFY
		if (function_exists('acf_get_instance')) {
			return true;
		}

		self::fail(
			'SCF_BOOT_FAILED',
			'Composer Secure Custom Fields failed to initialize.'
		);
		return false;
	}
	/**
	 * Log failure reason.
	 *
	 * @param string $code Error Code.
	 * @param string $message Error Message.
	 * @return void
	 */
	private static function fail(string $code, string $message): void
	{
		error_log("STARMUS DEPENDENCY ERROR [{$code}]: {$message}");
	}
}
