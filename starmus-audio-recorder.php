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

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

// -------------------------------------------------------------------------
// 0. SHUTDOWN SAFETY NET
// -------------------------------------------------------------------------
register_shutdown_function(function () {
	$error = error_get_last();
	if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
		error_log('STARMUS FATAL CRASH: ' . print_r($error, true));
	}
});

// -------------------------------------------------------------------------
// 1. CONSTANTS & GUARDS
// -------------------------------------------------------------------------

if (defined('STARMUS_LOADED')) {
	return;
}

define('STARMUS_LOADED', true);
define('STARMUS_VERSION', '0.9.3');
define('STARMUS_MAIN_FILE', __FILE__);
define('STARMUS_PATH', plugin_dir_path(STARMUS_MAIN_FILE));
define('STARMUS_URL', plugin_dir_url(STARMUS_MAIN_FILE));
define('STARMUS_PLUGIN_PREFIX', 'starmus');
define('STARMUS_PLUGIN_DIR', plugin_dir_path(STARMUS_MAIN_FILE));

if (! defined('STARMUS_LOG_LEVEL')) define('STARMUS_LOG_LEVEL', 8);
if (! defined('STARMUS_TUS_ENDPOINT')) define('STARMUS_TUS_ENDPOINT', 'https://upload.sparxstar.com/files/');
if (! defined('STARMUS_R2_ENDPOINT')) define('STARMUS_R2_ENDPOINT', 'https://cdn.sparxstar.com/');
if (! defined('TUS_WEBHOOK_SECRET')) define('TUS_WEBHOOK_SECRET', '84d34624286938554e5e19d9fafe9f5da3562c4d1d443e02c186f8e44019406e');

// -------------------------------------------------------------------------
// 2. ENVIRONMENT & DEPENDENCIES
// -------------------------------------------------------------------------

function starmus_init_environment(): void
{
	error_log('Starmus Environment Init Started.');
	// A. Autoloader
	$autoloader = STARMUS_PATH . 'vendor/autoload.php';
	if (file_exists($autoloader)) {
		require_once $autoloader;
	} else {
		add_action('admin_notices', function () {
			echo '<div class="notice notice-error"><p>Starmus Error: <code>vendor/autoload.php</code> missing.</p></div>';
		});
		return;
	}

	// B. Logger Failsafe
	if (class_exists(\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::class)) {
		if (! class_exists('StarmusLogger')) {
			class_alias(\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::class, 'StarmusLogger');
		}
		\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::set_min_level(STARMUS_LOG_LEVEL);
	}
}
add_action('plugins_loaded', 'starmus_init_environment', 0);

function starmus_load_dependencies(): void
{
	error_log('Starmus Dependency Load Started.');
	try{
		require_once STARMUS_PATH . 'src/helpers/StarmusDependencies.php';

		// Delegate all heavy lifting to the Dependencies Class
		if (class_exists(\Starisian\Sparxstar\Starmus\helpers\StarmusDependencies::class)) {
			$success = \Starisian\Sparxstar\Starmus\helpers\StarmusDependencies::bootstrap_scf();
			if(! $success) {
				//deactivate plugin on failure.
				starmus_on_deactivate();
				error_log('Starmus Dependency Load Failed.');
				wp_die('Starmus Error: Failed to load dependencies. Check admin notices for details.');
			}
		}
	} catch (\Throwable $e) {
		// deactivate plugin on exception.
		starmus_on_deactivate();
		error_log('Starmus Dependency Load Error: ' . $e->getMessage());
		wp_die('Starmus Error: Exception during dependency load. Check error logs for details.');
	}
}
// Run early (Priority 1) so SCF loads before other plugins
add_action('plugins_loaded', 'starmus_load_dependencies', 1);


// -------------------------------------------------------------------------
// 3. MAIN PLUGIN BOOTSTRAP
// -------------------------------------------------------------------------

function starmus_boot_plugin(): void
{
	error_log('Starmus Boot Started.');
	try {

		// 2. Load i18n
		if (class_exists(\Starisian\Sparxstar\Starmus\i18n\Starmusi18NLanguage::class)) {
			new \Starisian\Sparxstar\Starmus\i18n\Starmusi18NLanguage();
		}

		// 3. Boot Orchestrator
		if (class_exists(\Starisian\Sparxstar\Starmus\StarmusAudioRecorder::class)) {
			\Starisian\Sparxstar\Starmus\StarmusAudioRecorder::starmus_run();
		} else {
			throw new RuntimeException('StarmusAudioRecorder class missing.');
		}

		// 4. Cleanup
		if (get_transient('starmus_flush_rewrite_rules')) {
			flush_rewrite_rules();
			delete_transient('starmus_flush_rewrite_rules');
		}
	} catch (\Throwable $e) {
		if (class_exists('StarmusLogger')) \StarmusLogger::log($e);
		error_log('Starmus Boot Error: ' . $e->getMessage());
	}
}
// Run at Priority 10 (Standard)
add_action('plugins_loaded', 'starmus_boot_plugin', 10);


// -------------------------------------------------------------------------
// 4. LIFECYCLE HOOKS
// -------------------------------------------------------------------------

function starmus_on_activate(): void
{
    error_log('Starmus Activation Started.');
	try {
	// Delegate checks to Dependencies Class
		starmus_load_dependencies();

		if (class_exists(\Starisian\Sparxstar\Starmus\cron\StarmusCron::class)) {
			\Starisian\Sparxstar\Starmus\cron\StarmusCron::activate();
		}
		set_transient('starmus_flush_rewrite_rules', true, 60);
	} catch (\Throwable $e) {
		error_log('Starmus Activation Error: ' . $e->getMessage());
	}
}

function starmus_on_deactivate(): void
{
	try {
		if (class_exists(\Starisian\Sparxstar\Starmus\cron\StarmusCron::class)) {
			\Starisian\Sparxstar\Starmus\cron\StarmusCron::deactivate();
		}
		flush_rewrite_rules();
	} catch (\Throwable $e) {
	}
}

function starmus_on_uninstall(): void
{
	if (defined('STARMUS_DELETE_ON_UNINSTALL') && STARMUS_DELETE_ON_UNINSTALL) {
		$file = STARMUS_PATH . 'uninstall.php';
		if (file_exists($file)) require_once $file;
	}
}

register_activation_hook(STARMUS_MAIN_FILE, 'starmus_on_activate');
register_deactivation_hook(STARMUS_MAIN_FILE, 'starmus_on_deactivate');
register_uninstall_hook(STARMUS_MAIN_FILE, 'starmus_on_uninstall');
