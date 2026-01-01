<?php

/**
 * SPARXSTAR Starmus Audio
 *
 * @package Starisian\Sparxstar\Starmus
 * @author Starisian Technologies (Max Barrett) <support@starisian.com>
 * @license Starisian Technologies Proprietary License
 * @copyright Copyright (c) 2023-2026 Starisian Technologies. All rights reserved.
 * @version 0.9.2
 *
 *
 * Plugin Name:       Starmus Audio Recorder
 * Plugin URI:        https://github.com/Starisian-Technologies/starmus-audio-recorder
 * Description:       Adds a mobile-friendly MP3 audio recorder for oral history submission.
 * Version:           0.9.2
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
define('STARMUS_VERSION', '0.9.2');

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

if (!defined('STRARMUS_REST_ENDPOINT')) {
	define('STRARMUS_REST_ENDPOINT', 'star-starmus-audio-recorder/v1');
}

if (!defined('STARMUS_TUS_ENDPOINT')) {
	define('STARMUS_TUS_ENDPOINT', 'https://upload.sparxstar.com/files/');
}

if (! defined('TUS_WEBHOOK_SECRET')) {
	define('TUS_WEBHOOK_SECRET', '84d34624286938554e5e19d9fafe9f5da3562c4d1d443e02c186f8e44019406e');
}

if(!defined('STARMUS_R2_ENDPOINT')){
	define('STARMUS_R2_ENDPOINT', 'https://cdn.sparxstar.com/');
}

// =========================================================================
// 2. DEPENDENCIES & LOGGER FAILSAFE
// =========================================================================

// Load Composer Autoloader
$autoloader = STARMUS_PATH . 'vendor/autoload.php';

if ( file_exists( $autoloader ) ) {
	require_once $autoloader;
} else {
	// Graceful degradation if composer missing
	add_action( 'admin_notices', function() {
		echo '<div class="notice notice-error"><p><strong>Starmus Error:</strong> vendor/autoload.php missing.</p></div>';
	} );
}

// Manual Logger Failsafe (Must happen after autoloader or as fallback)
$logClassFile = STARMUS_PATH . 'src/helpers/StarmusLogger.php';
if ( file_exists( $logClassFile ) ) {
	require_once $logClassFile;

	$fqcn = 'Starisian\\Sparxstar\\Starmus\\helpers\\StarmusLogger';
	if ( class_exists( $fqcn ) && ! class_exists( 'StarmusLogger' ) ) {
		class_alias( $fqcn, 'StarmusLogger' );
	}

	if ( defined( 'STARMUS_LOG_LEVEL' ) && method_exists( $fqcn, 'set_min_level' ) ) {
		$fqcn::set_min_level( STARMUS_LOG_LEVEL );
	}
}

// Load Action Scheduler
if ( file_exists( STARMUS_PATH . 'libraries/action-scheduler/action-scheduler.php' ) ) {
	require_once STARMUS_PATH . 'libraries/action-scheduler/action-scheduler.php';
}

// =========================================================================
// 3. PLUGIN INITIALIZATION
// =========================================================================

// Load i18n
require_once STARMUS_PATH . 'src/i18n/Starmusi18NLanguage.php';
new \Starisian\Sparxstar\Starmus\i18n\Starmusi18NLanguage();

// Define Main Runner
function starmus_run_plugin(): void {
	// Check for ACF/SCF
	if ( ! class_exists( 'ACF' ) ) {
		// Attempt to load bundled SCF if missing
		$scf = STARMUS_PATH . 'vendor/secure-custom-fields/secure-custom-fields.php';
		if ( file_exists( $scf ) ) {
			// Configure SCF Paths
			add_filter( 'acf/settings/path', fn() => STARMUS_PATH . 'vendor/secure-custom-fields/' );
			add_filter( 'acf/settings/url', fn() => STARMUS_URL . 'vendor/secure-custom-fields/' );
			add_filter( 'acf/settings/show_admin', '__return_false' );
			require_once $scf;
		}
	}

	// Verify ACF loaded successfully
	if ( ! function_exists( 'acf_get_instance' ) ) {
		return; // Stop silently if dependencies failed
	}

	// Configure ACF JSON
	add_filter( 'acf/settings/save_json', fn() => STARMUS_PATH . 'acf-json' );
	add_filter( 'acf/settings/load_json', function( $paths ) {
		$paths[] = STARMUS_PATH . 'acf-json';
		return $paths;
	} );

	// Boot the Core
	try {
		\Starisian\Sparxstar\Starmus\StarmusAudioRecorder::starmus_run();

		if ( get_transient( 'starmus_flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
			delete_transient( 'starmus_flush_rewrite_rules' );
		}
	} catch ( \Throwable $e ) {
		if ( class_exists( 'StarmusLogger' ) ) {
			\StarmusLogger::log( $e );
		}
	}
}

// Hook into WordPress (Priority 20 to let other plugins load)
add_action( 'plugins_loaded', __NAMESPACE__ . '\\starmus_run_plugin', 20 );

// =========================================================================
// 4. ACTIVATION HOOKS
// =========================================================================

function starmus_on_activate(): void {
	if ( ! file_exists( STARMUS_PATH . 'vendor/autoload.php' ) ) {
		wp_die( 'Starmus Error: Run composer install.' );
	}

	try {
		// Initialize Cron if class exists
		if ( class_exists( '\Starisian\Sparxstar\Starmus\cron\StarmusCron' ) ) {
			\Starisian\Sparxstar\Starmus\cron\StarmusCron::activate();
		}
	} catch ( \Throwable $e ) {}

	set_transient( 'starmus_flush_rewrite_rules', true, 60 );
}

function starmus_on_deactivate(): void {
	try {
		if ( class_exists( '\Starisian\Sparxstar\Starmus\cron\StarmusCron' ) ) {
			\Starisian\Sparxstar\Starmus\cron\StarmusCron::deactivate();
		}
		flush_rewrite_rules();
	} catch ( \Throwable $e ) {}
}

function starmus_on_uninstall(): void {
	if ( STARMUS_DELETE_ON_UNINSTALL ) {
		try {
			$uninstallFile = STARMUS_PATH . 'uninstall.php';
			if ( file_exists( $uninstallFile ) ) {
				require_once $uninstallFile;
			}
		} catch ( \Throwable $e ) {}
	}
}

register_activation_hook( STARMUS_MAIN_FILE, __NAMESPACE__ . '\\starmus_on_activate' );
register_deactivation_hook( STARMUS_MAIN_FILE, __NAMESPACE__ . '\\starmus_on_deactivate' );
register_activation_hook( STARMUS_MAIN_FILE, __NAMESPACE__ . '\\starmus_on_uninstall' );
