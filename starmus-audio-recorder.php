<?php

/**
 * SPARXSTAR Starmus Audio
 *
 * Production bootloader for the Starmus Audio Recorder plugin.
 *
 * Responsibilities:
 * - Load Composer autoloader
 * - Initialize logging
 * - Gate plugin execution on SparxStar SCF Runtime state
 * - Boot core Starmus services
 *
 * Non-Responsibilities:
 * - SCF lifecycle or version management
 * - Schema installation or registration (JSON is installer)
 * - ACF / SCF internals probing
 *
 * @package   Starisian\Sparxstar\Starmus
 * @author    Starisian Technologies
 * @license   Starisian Technologies Proprietary
 * @version   0.9.3
 *
 * Plugin Name:       Starmus Audio Recorder
 * Description:       Mobile-friendly audio recorder for optimized for emerging markets.
 * Version:           0.9.2
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Text Domain:       starmus-audio-recorder
 * Update URI:        https://starism.com/sparxstar/starmus-audio-recorder/update
 * GitHub Plugin URI:  Starisian-Technologies/starmus-audio-recorder
 * Domain Path:       /languages
 * Requires Plugins:  secure-custom-fields/secure-custom-fields, Starisian-Technologies/sparxstar-secure-custom-fields-runtime
 *
 * SPARXSTAR-SCF-Runtime MU-Plugin available at:
 * https://github.com/Starisian-Technologies/sparxstar-secure-custom-fields-runtime
 *
 *  Copyright (c) 2023-2024 Starisian Technologies (https://starisian.com)
 *
 * create a secret key using openssl:
 *
 * Set the tus key to use for webhook validation:
 * tusd \
 *  -hooks-http "https://contribute.sparxstar.com/wp-json/starmus/v1/hook" \
 *  -hooks-http-header "x-starmus-secret: Y84d34624286938554e5e19d9fafe9f5da3562c4d1d443e02c186f8e44019406e" \
 *  -hooks-enabled-events "post-finish"
 *
 * define( 'STARMUS_TUS_WEBHOOK_SECRET', 'YOUR_SECRET_STRING' );
 */

declare(strict_types=1);

use Starisian\Sparxstar\install\SCF\Sparxstar_SCF_Runtime;
use Starisian\Sparxstar\Starmus\StarmusAudioRecorder;
use Starisian\Sparxstar\Starmus\cron\StarmusCron;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

if ( ! defined('ABSPATH')) {
    exit;
}


// -------------------------------------------------------------------------
// 0. FATAL ERROR CATCHER (The "Safety Net")
// -------------------------------------------------------------------------
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[STARMUS FATAL] ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
    }
});

/* -------------------------------------------------------------------------
 * 1. CONSTANTS
 * ------------------------------------------------------------------------- */
define('STARMUS_VERSION', '0.9.2');
define('STARMUS_MAIN_FILE', __FILE__);
define('STARMUS_PATH', plugin_dir_path(STARMUS_MAIN_FILE));
define('STARMUS_URL', plugin_dir_url(STARMUS_MAIN_FILE));
define('STARMUS_PLUGIN_PREFIX', 'starmus');
define('STARMUS_PLUGIN_DIR', plugin_dir_path(STARMUS_MAIN_FILE));

if ( ! defined('STARMUS_LOG_LEVEL')) {
    define('STARMUS_LOG_LEVEL', 8);
}
if ( ! defined('STARMUS_TUS_ENDPOINT')) {
    define('STARMUS_TUS_ENDPOINT', 'https://upload.sparxstar.com/files/');
}
if ( ! defined('STARMUS_R2_ENDPOINT')) {
    define('STARMUS_R2_ENDPOINT', 'https://cdn.sparxstar.com/');
}
if ( ! defined('STARMUS_TUS_WEBHOOK_SECRET')) {
    define('STARMUS_TUS_WEBHOOK_SECRET', '84d34624286938554e5e19d9fafe9f5da3562c4d1d443e02c186f8e44019406e');
}
if ( ! defined('STARMUS_REST_NAMESPACE')) {
    define('STARMUS_REST_NAMESPACE', 'starmus/v1');
}
if ( ! defined('STARMUS_DELETE_ON_UNINSTALL')) {
    define('STARMUS_DELETE_ON_UNINSTALL', false);
}
// 1. Define the path to the bundled SCF directory
// Adjust 'vendor/wpackagist-plugin/secure-custom-fields/' to match your actual path
if (! defined('SPARXSTAR_SCF_PATH')) {
	define('SPARXSTAR_SCF_PATH', STARMUS_PATH . 'vendor/secure-custom-fields/');
}
if (! defined('SPARXSTAR_SCF_URL')) {
	define('SPARXSTAR_SCF_URL', STARMUS_URL . 'vendor/secure-custom-fields/');
}



// -------------------------------------------------------------------------
// 2. AUTOLOAD & BUNDLED SCF (Priority 0)
// -------------------------------------------------------------------------
add_action('plugins_loaded', static function (): void {
	try {
		// A. Load Composer Autoloader
		$autoloader = STARMUS_PATH . 'vendor/autoload.php';
		if (file_exists($autoloader)) {
			require_once $autoloader;
		} else {
			// Only show admin notice in admin area
			if (is_admin() && !defined('DOING_AJAX')) {
				add_action('admin_notices', static function () {
					echo '<div class="notice notice-error"><p>Starmus Critical: vendor/autoload.php missing. Run composer install.</p></div>';
				});
			}
			error_log('Starmus Autoloader missing. Run composer install.');
			return; // Stop execution if autoloader is missing
		}

		// B. Bootstrap Bundled SCF
		// We check for 'ACF' class because SCF uses the ACF class namespace for compatibility.
		if (! class_exists('ACF')) {

			if (file_exists(SPARXSTAR_SCF_PATH . 'secure-custom-fields.php')) {

				// CRITICAL: Point SCF to the bundled URL so it can find JS/CSS
				add_filter('acf/settings/url', function () {
					return SPARXSTAR_SCF_URL;
				});

				// CRITICAL: Point SCF to the bundled Path so it can find PHP includes
				add_filter('acf/settings/path', function () {
					return SPARXSTAR_SCF_PATH;
				});

				// Hide SCF menu item (optional, keeping your setting)
				add_filter('acf/settings/show_admin', '__return_true');
				add_filter('acf/settings/show_updates', '__return_false');

				// Load SCF
				require_once(SPARXSTAR_SCF_PATH . 'secure-custom-fields.php');
			} else {
				error_log('Starmus Error: Bundled SCF not found at ' . SPARXSTAR_SCF_PATH);
			}
		} else {
			// SCF or ACF is already active as a standard plugin.
			// We step aside and let the installed version run.
			error_log('Starmus Notice: External SCF/ACF detected. Skipping bundled version.');
		}
	} catch (\Throwable $e) {
		error_log('Starmus Autoload/Bootstrap Failed: ' . $e->getMessage());
	}
}, 0);


// -------------------------------------------------------------------------
// 3. JSON SYNC (CPT/Field Installation)
// -------------------------------------------------------------------------

/**
 * Register a custom load point for SCF/ACF JSON files.
 * This effectively "installs" your Fields, CPTs, and Taxonomies on load.
 */
add_filter('acf/settings/load_json', function ($paths) {
	// Append the new path to the existing array of paths
	$paths[] = STARMUS_PATH . 'acf-json';
	return $paths;
});

/**
 * Register a custom save point for SCF/ACF JSON files.
 * Useful during development to write changes back to your plugin.
 */
add_filter('acf/settings/save_json', function ($path) {
	// Only save to plugin folder in non-production environments
	if (wp_get_environment_type() !== 'production') {
		$path = STARMUS_PATH . 'acf-json';
	}
	return $path;
});


// -------------------------------------------------------------------------
// 4. APP BOOT (Priority 10)
// -------------------------------------------------------------------------
add_action('plugins_loaded', static function (): void {
	try {
		// Ensure Autoload happened and class exists
		if (!class_exists(\Starisian\Sparxstar\Starmus\StarmusAudioRecorder::class)) {
			return;
		}

		// Boot the App
		\Starisian\Sparxstar\Starmus\StarmusAudioRecorder::starmus_get_instance();
	} catch (\Throwable $e) {
		error_log('Starmus App Boot Failed: ' . $e->getMessage());
		if (is_admin()) {
			add_action('admin_notices', function () {
				echo '<div class="notice notice-error"><p>Starmus Audio failed to start. Check error logs.</p></div>';
			});
		}
	}
}, 10);


// -------------------------------------------------------------------------
// 5. LIFECYCLE MANAGEMENT
// -------------------------------------------------------------------------

/**
 * ACTIVATION
 */
function starmus_on_activate(): void
{
	try {
		if (class_exists(\Starisian\Sparxstar\Starmus\cron\StarmusCron::class)) {
			\Starisian\Sparxstar\Starmus\cron\StarmusCron::activate();
		}
		flush_rewrite_rules();
	} catch (\Throwable $e) {
		error_log('Starmus Activation Error: ' . $e->getMessage());
	}
}

/**
 * DEACTIVATION
 */
function starmus_on_deactivate(): void
{
	try {
		if (class_exists(\Starisian\Sparxstar\Starmus\cron\StarmusCron::class)) {
			\Starisian\Sparxstar\Starmus\cron\StarmusCron::deactivate();
		}
		flush_rewrite_rules();

		// Check if runtime class exists before calling static method
		if (class_exists(Sparxstar_SCF_Runtime::class)) {
			Sparxstar_SCF_Runtime::sparx_scf_deactivate_scf();
		}
	} catch (\Throwable $e) {
		error_log('Starmus Deactivation Error: ' . $e->getMessage());
	}
}

/**
 * UNINSTALL
 */
function starmus_on_uninstall(): void
{
	try {
		if (class_exists(Sparxstar_SCF_Runtime::class)) {
			Sparxstar_SCF_Runtime::uninstall_site();
		}

		if (defined('STARMUS_DELETE_ON_UNINSTALL') && STARMUS_DELETE_ON_UNINSTALL) {
			$file = STARMUS_PATH . 'uninstall.php';
			if (file_exists($file)) {
				require_once $file;
			}
		}
	} catch (\Throwable $e) {
		// Use error_log here; Custom Logger classes might not be available during uninstall hooks
		error_log('Starmus uninstall error: ' . $e->getMessage());
	}
}

register_activation_hook(__FILE__, 'starmus_on_activate');
register_deactivation_hook(__FILE__, 'starmus_on_deactivate');
register_uninstall_hook(__FILE__, 'starmus_on_uninstall');
