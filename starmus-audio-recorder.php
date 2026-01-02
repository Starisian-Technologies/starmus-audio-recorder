<?php

/**
 * SPARXSTAR Starmus Audio
 *
 * @package Starisian\Sparxstar\Starmus
 * @author Starisian Technologies (Max Barrett) <support@starisian.com>
 * @license Starisian Technologies Proprietary License
 * @copyright Copyright (c) 2023-2026 Starisian Technologies. All rights reserved.
 * @version 0.9.3
 *
 *
 * Plugin Name:       Starmus Audio Recorder
 * Plugin URI:        https://github.com/Starisian-Technologies/starmus-audio-recorder
 * Description:       Adds a mobile-friendly MP3 audio recorder for oral history submission.
 * Version:           0.9.3
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Starisian Technologies (Max Barrett)
 * Author URI:        https://starisian.com
 * Contributors:      Max Barrett
 * Text Domain:       starmus-audio-recorder
 * Update URI:		  https://starism.com/sparxstar/starmus-audio-recorder/update
 * GitHub Plugin URI:  Starisian-Technologies/starmus-audio-recorder
 * Domain Path:       /languages
 * Requires Plugins:  secure-custom-fields/secure-custom-fields.php
 *
 *
 *
 *  Copyright (c) 2023-2024 Starisian Technologies (https://starisian.com)
 *
 * create a secret key using openssl:
 *
 * Set the tus key to use for webhook validation:
 * tusd \
	-hooks-http "https://contribute.sparxstar.com/wp-json/starmus/v1/hook" \
	-hooks-http-header "x-starmus-secret: Y84d34624286938554e5e19d9fafe9f5da3562c4d1d443e02c186f8e44019406e" \
	-hooks-enabled-events "post-finish"
 *
 * define( 'TUS_WEBHOOK_SECRET', 'YOUR_SECRET_STRING' );
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

// =========================================================================
// 0. SHUTDOWN HANDLER (The Safety Net)
// =========================================================================
register_shutdown_function(function () {
	$error = error_get_last();
	// Check if the script died due to a fatal error
	if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
		// Force write to server error log
		error_log('STARMUS CRITICAL FATAL ERROR: ' . print_r($error, true));
	}
});

// =========================================================================
// 1. BOOTSTRAP GUARD & CORE CONSTANTS
// =========================================================================

/**
 * Prevent multiple plugin instances from loading.
 * If STARMUS_LOADED is already defined, exit early to avoid conflicts.
 */
if (defined('STARMUS_LOADED')) {
	return;
}

/** @var bool Plugin loaded flag to prevent multiple instances */
define('STARMUS_LOADED', true);

/** @var string Current plugin version */
define('STARMUS_VERSION', '0.9.3');

/** @var string Main plugin file path */
define('STARMUS_MAIN_FILE', __FILE__);

/** @var string Plugin directory path with trailing slash */
define('STARMUS_PATH', plugin_dir_path(STARMUS_MAIN_FILE));

/** @var string Plugin directory URL with trailing slash */
define('STARMUS_URL', plugin_dir_url(STARMUS_MAIN_FILE));

/** @var string Plugin prefix for hooks and database options */
define('STARMUS_PLUGIN_PREFIX', 'starmus');

/** @var string Plugin directory path (legacy constant) */
define('STARMUS_PLUGIN_DIR', plugin_dir_path(STARMUS_MAIN_FILE));

// --- Logger Configuration ---

/** @var string Default logging level if not defined elsewhere */
if (! defined('STARMUS_LOG_LEVEL')) {
	define('STARMUS_LOG_LEVEL', 8);
}

/** @var bool Whether to delete all data on plugin uninstall */
if (! defined('STARMUS_DELETE_ON_UNINSTALL')) {
	define('STARMUS_DELETE_ON_UNINSTALL', false);
}

if (!defined('STARMUS_CRON_INTERNAL')) {
	define('STARMUS_CRON_INTERNAL', true);
}

if (! defined('STARMUS_CRON_WP')) {
	define('STARMUS_CRON_WP', false);
}

// FIX 1: Corrected Typo STRARMUS -> STARMUS
if (!defined('STARMUS_REST_ENDPOINT')) {
	define('STARMUS_REST_ENDPOINT', 'star-starmus-audio-recorder/v1');
}

if (!defined('STARMUS_TUS_ENDPOINT')) {
	define('STARMUS_TUS_ENDPOINT', 'https://upload.sparxstar.com/files/');
}

if (! defined('TUS_WEBHOOK_SECRET')) {
	define('TUS_WEBHOOK_SECRET', '84d34624286938554e5e19d9fafe9f5da3562c4d1d443e02c186f8e44019406e');
}

if (!defined('STARMUS_R2_ENDPOINT')) {
	define('STARMUS_R2_ENDPOINT', 'https://cdn.sparxstar.com/');
}

// =========================================================================
// 2. ACTION SCHEDULER LIBRARY
// =========================================================================

/**
 * Load Action Scheduler library for background job processing.
 *
 * Action Scheduler provides reliable background job processing for WordPress.
 * We load it early to ensure availability for our cron.
 */
$action_scheduler_path = STARMUS_PATH . 'libraries/action-scheduler/action-scheduler.php';

// FIX 2: Added !class_exists guard to prevent Fatal Redeclaration crash
if (file_exists($action_scheduler_path) && !class_exists('ActionScheduler')) {
	try {
		require_once $action_scheduler_path;
	} catch (\Throwable $e) {
		error_log('Starmus Action Scheduler Error: ' . $e->getMessage());
	}
}

// =========================================================================
// 3. COMPOSER AUTOLOADER
// =========================================================================

/**
 * Load Composer autoloader for PSR-4 class loading.
 */
$autoloader = STARMUS_PATH . 'vendor/autoload.php';

if (! file_exists($autoloader)) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p><strong>Starmus Audio Recorder Error:</strong> missing <code>vendor/autoload.php</code>. Please run <code>composer install</code>.</p></div>';
		}
	);
} else {
	try {
		require_once $autoloader;
	} catch (\Throwable $e) {
		error_log('Starmus Autoload Error: ' . $e->getMessage());
	}

	// 1. Logger Failsafe (Legacy Support)
	if (defined('STARMUS_PATH')) {
		$logClassFile = STARMUS_PATH . 'src/helpers/StarmusLogger.php';

		if (file_exists($logClassFile)) {
			try {
				// 2. Manual Load
				require_once $logClassFile;

				// 3. Create Alias Safely
				$fqcn = 'Starisian\\Sparxstar\\Starmus\\helpers\\StarmusLogger';

				if (class_exists($fqcn) && ! class_exists('StarmusLogger')) {
					class_alias($fqcn, 'StarmusLogger');
				}

				// 4. Configure Level Safely
				if (defined('STARMUS_LOG_LEVEL') && method_exists($fqcn, 'set_min_level')) {
					$fqcn::set_min_level(STARMUS_LOG_LEVEL);
				}
			} catch (\Throwable $e) {
				error_log('Starmus Logger Failsafe Error: ' . $e->getMessage());
			}
		}
	}
}

// =========================================================================
// 4. BUNDLED SCF DEPENDENCY LOADER
// =========================================================================
/**
 * Loads the bundled Secure Custom Fields plugin if ACF is not already active.
 *
 * @since 0.8.5
 * @return void
 */
function starmus_load_bundled_scf(): void
{
	try {
		// If ACF/SCF is already active from another plugin, do nothing.
		// Using function_exists is safer than class_exists early in boot
		if (class_exists('ACF') || function_exists('acf_get_instance')) {
			return;
		}

		$scf_main_file = STARMUS_PATH . 'vendor/secure-custom-fields/secure-custom-fields.php';

		if (file_exists($scf_main_file)) {
			if (! defined('STARMUS_ACF_PATH')) {
				define('STARMUS_ACF_PATH', STARMUS_PATH . 'vendor/secure-custom-fields/');
			}
			if (! defined('STARMUS_ACF_URL')) {
				define('STARMUS_ACF_URL', STARMUS_URL . 'vendor/secure-custom-fields/');
			}

			// Configure SCF/ACF to load from our local path
			add_filter('acf/settings/path', fn() => STARMUS_ACF_PATH);
			add_filter('acf/settings/url', fn() => STARMUS_ACF_URL);
			add_filter('acf/settings/show_admin', '__return_false');
			add_filter('acf/settings/show_updates', '__return_false', 100);

			require_once $scf_main_file;
		}
	} catch (\Throwable $e) {
		if (class_exists('StarmusLogger')) \StarmusLogger::log($e);
	}
}
// FIX 3: Changed Priority from 5 to 1 to beat other plugins loading early
add_action('plugins_loaded', 'starmus_load_bundled_scf', 1);


// =========================================================================
// 5. ACF JSON INTEGRATION
// =========================================================================
/**
 * Configure ACF JSON save and load paths for field definitions.
 *
 * @since 0.8.5
 * @return void
 */
function starmus_acf_json_integration(): void
{
	try {
		add_filter('acf/settings/save_json', fn() => STARMUS_PATH . 'acf-json');
		add_filter(
			'acf/settings/load_json',
			function ($paths) {
				// Append our path
				$paths[] = STARMUS_PATH . 'acf-json';
				return $paths;
			}
		);
	} catch (\Throwable $e) {
		if (class_exists('StarmusLogger')) \StarmusLogger::log($e);
	}
}
add_action('acf/init', 'starmus_acf_json_integration');


// =========================================================================
// 6. MAIN PLUGIN INITIALIZATION
// =========================================================================

/**
 * Initialize and run the main plugin application.
 *
 * @since 0.8.5
 * @return void
 */
function starmus_run_plugin(): void
{
	// Initialize multi-language support
	try {
		if (file_exists(STARMUS_PATH . 'src/i18n/Starmusi18NLanguage.php')) {
			require_once STARMUS_PATH . 'src/i18n/Starmusi18NLanguage.php';
			if (class_exists('\Starisian\Sparxstar\Starmus\i18n\Starmusi18NLanguage')) {
				new \Starisian\Sparxstar\Starmus\i18n\Starmusi18NLanguage();
			}
		}
	} catch (\Throwable $e) {
		if (class_exists('StarmusLogger')) \StarmusLogger::log($e);
	}

	// Check if ACF class exists now that plugins_loaded has fired.
	if (! class_exists('ACF') && ! function_exists('acf_get_instance')) {
		// Only show error if we are NOT in the middle of activating/deactivating
		if (! isset($_GET['activate'])) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p><strong>Starmus Error:</strong> Secure Custom Fields failed to load.</p></div>';
				}
			);
		}
		return;
	}

	try {
		// Launch the Orchestrator
		// Note: We use the full namespace because we are in Global Scope
		if (class_exists('\Starisian\Sparxstar\Starmus\StarmusAudioRecorder')) {
			\Starisian\Sparxstar\Starmus\StarmusAudioRecorder::starmus_run();
		}

		// Check if we need to flush rewrite rules (set during activation)
		if (get_transient('starmus_flush_rewrite_rules')) {
			flush_rewrite_rules();
			delete_transient('starmus_flush_rewrite_rules');
		}
	} catch (\Throwable $e) {
		if (class_exists('StarmusLogger')) {
			\StarmusLogger::log($e);
		} else {
			error_log('Starmus Main Run Error: ' . $e->getMessage());
		}
	}
}
add_action('plugins_loaded', 'starmus_run_plugin', 20);


// =========================================================================
// 7. ACTIVATION & DEACTIVATION (STRICT MODE)
// =========================================================================
/**
 * Plugin activation hook with strict dependency checking.
 *
 * @since 0.8.5
 * @return void
 * @throws \Throwable If cron activation fails (logged but not fatal)
 */
function starmus_on_activate(): void
{
	// 1. Check Autoloader
	if (! file_exists(STARMUS_PATH . 'vendor/autoload.php')) {
		wp_die('Starmus Error: Composer dependencies missing. Please run `composer install`.');
	}

	// 2. Force load SCF just for this check
	starmus_load_bundled_scf();

	// 3. Verify ACF/SCF loaded
	if (! class_exists('ACF') && ! function_exists('acf_get_instance') && ! file_exists(STARMUS_PATH . 'vendor/secure-custom-fields/secure-custom-fields.php')) {
		wp_die('Starmus Error: Secure Custom Fields plugin missing from vendor folder.');
	}

	// 4. Trigger internal activation logic
	try {
		if (class_exists('\Starisian\Sparxstar\Starmus\cron\StarmusCron')) {
			\Starisian\Sparxstar\Starmus\cron\StarmusCron::activate();
		}
	} catch (\Throwable $e) {
		if (class_exists('StarmusLogger')) \StarmusLogger::log($e);
	}

	// 5. Request a rewrite flush on the NEXT page load.
	set_transient('starmus_flush_rewrite_rules', true, 60);
}

/**
 * Plugin deactivation hook.
 *
 * @since 0.8.5
 * @return void
 */
function starmus_on_deactivate(): void
{
	try {
		if (class_exists('\Starisian\Sparxstar\Starmus\cron\StarmusCron')) {
			\Starisian\Sparxstar\Starmus\cron\StarmusCron::deactivate();
		}
		flush_rewrite_rules();
	} catch (\Throwable $e) {
		// catch errors silently on deactivation
	}
}

/**
 * Plugin uninstall hook.
 *
 * @since 0.8.5
 * @return void
 * @see uninstall.php For the actual uninstall implementation
 */
function starmus_on_uninstall(): void
{
	try {
		if (defined('STARMUS_DELETE_ON_UNINSTALL') && STARMUS_DELETE_ON_UNINSTALL) {
			$uninstall_file = STARMUS_PATH . 'uninstall.php';
			if (file_exists($uninstall_file)) {
				require_once $uninstall_file;
			}
		}
	} catch (\Throwable $e) {
		// Silent catch
	}
}

register_activation_hook(STARMUS_MAIN_FILE, 'starmus_on_activate');
register_deactivation_hook(STARMUS_MAIN_FILE, 'starmus_on_deactivate');
register_uninstall_hook(STARMUS_MAIN_FILE, 'starmus_on_uninstall');
