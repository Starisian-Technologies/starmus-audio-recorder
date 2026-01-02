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
	public static function is_safe_to_boot(): bool
	{
		error_log('STARMUS IS SAFE TO BOOT CHECK');
		// If we flagged a conflict earlier, stop.
		if (defined('STARMUS_ACF_CONFLICT')) {
			return false;
		}

		// If ACF isn't loaded (bundled load failed), stop.
		if (! function_exists('acf_get_instance')) {
			if (is_admin()) {
				add_action('admin_notices', function () {
					echo '<div class="notice notice-error"><p><strong>Starmus Error:</strong> Bundled Secure Custom Fields failed to load.</p></div>';
				});
			}
			return false;
		}

		return true;
	}
}
