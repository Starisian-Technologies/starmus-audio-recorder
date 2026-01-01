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
 * GitHub Plugin URI: Starisian-Technologies/starmus-audio-recorder
 * Domain Path:       /languages
 * Requires Plugins:  secure-custom-fields/secure-custom-fields.php
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

// =========================================================================
// 1. BOOTSTRAP GUARD & CORE CONSTANTS
// =========================================================================

if (defined('STARMUS_LOADED')) {
	return;
}

define('STARMUS_LOADED', true);
define('STARMUS_VERSION', '0.9.3');
define('STARMUS_MAIN_FILE', __FILE__);
define('STARMUS_PATH', plugin_dir_path(STARMUS_MAIN_FILE));
define('STARMUS_URL', plugin_dir_url(STARMUS_MAIN_FILE));
define('STARMUS_PLUGIN_PREFIX', 'starmus');
define('STARMUS_PLUGIN_DIR', plugin_dir_path(STARMUS_MAIN_FILE)); // Legacy

// --- Logger & Config ---

if (! defined('STARMUS_LOG_LEVEL')) define('STARMUS_LOG_LEVEL', 8);
if (! defined('STARMUS_DELETE_ON_UNINSTALL')) define('STARMUS_DELETE_ON_UNINSTALL', false);
if (! defined('STARMUS_CRON_INTERNAL')) define('STARMUS_CRON_INTERNAL', true);
if (! defined('STARMUS_CRON_WP')) define('STARMUS_CRON_WP', false);
if (! defined('STRARMUS_REST_ENDPOINT')) define('STRARMUS_REST_ENDPOINT', 'star-starmus-audio-recorder/v1');
if (! defined('STARMUS_TUS_ENDPOINT')) define('STARMUS_TUS_ENDPOINT', 'https://upload.sparxstar.com/files/');
if (! defined('STARMUS_R2_ENDPOINT')) define('STARMUS_R2_ENDPOINT', 'https://cdn.sparxstar.com/');

if (! defined('TUS_WEBHOOK_SECRET')) {
	define('TUS_WEBHOOK_SECRET', '84d34624286938554e5e19d9fafe9f5da3562c4d1d443e02c186f8e44019406e');
}

// =========================================================================
// 2. DEPENDENCIES & LOGGER FAILSAFE
// =========================================================================

// A. Load Action Scheduler
$action_scheduler_path = STARMUS_PATH . 'libraries/action-scheduler/action-scheduler.php';
if (file_exists($action_scheduler_path)) {
	require_once $action_scheduler_path;
}

// B. Load Composer Autoloader
$autoloader = STARMUS_PATH . 'vendor/autoload.php';

if (file_exists($autoloader)) {
	require_once $autoloader;
} else {
	add_action('admin_notices', function () {
		echo '<div class="notice notice-error"><p><strong>Starmus Audio Recorder Error:</strong> missing <code>vendor/autoload.php</code>. Please run <code>composer install</code>.</p></div>';
	});
}

// C. Manual Logger Failsafe (MUST BE HERE to catch errors below)
$logClassFile = STARMUS_PATH . 'src/helpers/StarmusLogger.php';
if (file_exists($logClassFile)) {
	// Manual require ensures Logger exists even if Composer fails
	require_once $logClassFile;

	$fqcn = 'Starisian\\Sparxstar\\Starmus\\helpers\\StarmusLogger';
	if (class_exists($fqcn) && ! class_exists('StarmusLogger')) {
		class_alias($fqcn, 'StarmusLogger');
	}

	if (defined('STARMUS_LOG_LEVEL') && method_exists($fqcn, 'set_min_level')) {
		$fqcn::set_min_level(STARMUS_LOG_LEVEL);
	}
}

// =========================================================================
// 3. PLUGIN INITIALIZATION
// =========================================================================

// Load Bundled SCF (Global Function)
function starmus_load_bundled_scf(): void
{
	if (class_exists('ACF')) {
		return;
	}

	$scf_main_file = STARMUS_PATH . 'vendor/secure-custom-fields/secure-custom-fields.php';

	if (file_exists($scf_main_file)) {
		if (! defined('STARMUS_ACF_PATH')) define('STARMUS_ACF_PATH', STARMUS_PATH . 'vendor/secure-custom-fields/');
		if (! defined('STARMUS_ACF_URL')) define('STARMUS_ACF_URL', STARMUS_URL . 'vendor/secure-custom-fields/');

		add_filter('acf/settings/path', fn() => STARMUS_ACF_PATH);
		add_filter('acf/settings/url', fn() => STARMUS_ACF_URL);
		add_filter('acf/settings/show_admin', '__return_false');
		add_filter('acf/settings/show_updates', '__return_false', 100);

		require_once $scf_main_file;
	}
}
add_action('plugins_loaded', 'starmus_load_bundled_scf', 5);

// ACF JSON Integration (Global Function)
function starmus_acf_json_integration(): void
{
	add_filter('acf/settings/save_json', fn() => STARMUS_PATH . 'acf-json');
	add_filter('acf/settings/load_json', function ($paths) {
		$paths[] = STARMUS_PATH . 'acf-json';
		return $paths;
	});
}
add_action('acf/init', 'starmus_acf_json_integration');

// Load i18n
require_once STARMUS_PATH . 'src/i18n/Starmusi18NLanguage.php';
new \Starisian\Sparxstar\Starmus\i18n\Starmusi18NLanguage();

/**
 * Initialize and run the main plugin application.
 * (Global Function)
 */
function starmus_run_plugin(): void
{
	// Check if ACF/SCF loaded
	if (! class_exists('ACF')) {
		if (! isset($_GET['activate'])) {
			add_action('admin_notices', function () {
				echo '<div class="notice notice-error"><p><strong>Starmus Error:</strong> Secure Custom Fields failed to load.</p></div>';
			});
		}
		return;
	}

	try {
		// Boot the Core (Fully Qualified Namespace)
		\Starisian\Sparxstar\Starmus\StarmusAudioRecorder::starmus_run();

		if (get_transient('starmus_flush_rewrite_rules')) {
			flush_rewrite_rules();
			delete_transient('starmus_flush_rewrite_rules');
		}
	} catch (\Throwable $e) {
		// Use Alias created in Failsafe block
		if (class_exists('StarmusLogger')) {
			\StarmusLogger::log($e);
		}
	}
}
// Since we are in Global Scope, __NAMESPACE__ is empty string.
// This effectively calls '\starmus_run_plugin' which is correct.
add_action('plugins_loaded', __NAMESPACE__ . '\\starmus_run_plugin', 20);


// =========================================================================
// 4. ACTIVATION HOOKS (Global Functions)
// =========================================================================

function starmus_on_activate(): void
{
	if (! file_exists(STARMUS_PATH . 'vendor/autoload.php')) {
		wp_die('Starmus Error: Composer dependencies missing. Please run `composer install`.');
	}

	starmus_load_bundled_scf();

	if (! class_exists('ACF') && ! file_exists(STARMUS_PATH . 'vendor/secure-custom-fields/secure-custom-fields.php')) {
		wp_die('Starmus Error: Secure Custom Fields plugin missing.');
	}

	try {
		if (class_exists('\Starisian\Sparxstar\Starmus\cron\StarmusCron')) {
			\Starisian\Sparxstar\Starmus\cron\StarmusCron::activate();
		}
	} catch (\Throwable $e) {
		if (class_exists('StarmusLogger')) \StarmusLogger::log($e);
	}

	set_transient('starmus_flush_rewrite_rules', true, 60);
}

function starmus_on_deactivate(): void
{
	try {
		if (class_exists('\Starisian\Sparxstar\Starmus\cron\StarmusCron')) {
			\Starisian\Sparxstar\Starmus\cron\StarmusCron::deactivate();
		}
		flush_rewrite_rules();
	} catch (\Throwable $e) {
	}
}

function starmus_on_uninstall(): void
{
	if (STARMUS_DELETE_ON_UNINSTALL) {
		$uninstall_file = STARMUS_PATH . 'uninstall.php';
		if (file_exists($uninstall_file)) {
			require_once $uninstall_file;
		}
	}
}

// Hook Registration
register_activation_hook(STARMUS_MAIN_FILE, 'starmus_on_activate');
register_deactivation_hook(STARMUS_MAIN_FILE, 'starmus_on_deactivate');
register_uninstall_hook(STARMUS_MAIN_FILE, 'starmus_on_uninstall');
