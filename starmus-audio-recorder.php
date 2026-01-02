<?php

declare(strict_types=1);

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
 * Text Domain:       starmus-audio-recorder
 * Domain Path:       /languages
 * Requires Plugins:  secure-custom-fields/secure-custom-fields.php
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =========================================================================
// 1. BOOTSTRAP GUARD & CORE CONSTANTS
// =========================================================================

if ( defined( 'STARMUS_LOADED' ) ) {
	return;
}

define( 'STARMUS_LOADED', true );
define( 'STARMUS_VERSION', '0.9.3' );
define( 'STARMUS_MAIN_FILE', __FILE__ );
define( 'STARMUS_PATH', plugin_dir_path( STARMUS_MAIN_FILE ) );
define( 'STARMUS_URL', plugin_dir_url( STARMUS_MAIN_FILE ) );
define( 'STARMUS_PLUGIN_PREFIX', 'starmus' );
define( 'STARMUS_PLUGIN_DIR', plugin_dir_path( STARMUS_MAIN_FILE ) );

// --- Config Defaults ---
if ( ! defined( 'STARMUS_LOG_LEVEL' ) ) define( 'STARMUS_LOG_LEVEL', 8 );
if ( ! defined( 'STARMUS_DELETE_ON_UNINSTALL' ) ) define( 'STARMUS_DELETE_ON_UNINSTALL', false );
if ( ! defined( 'STARMUS_CRON_INTERNAL' ) ) define( 'STARMUS_CRON_INTERNAL', true );
if ( ! defined( 'STARMUS_CRON_WP' ) ) define( 'STARMUS_CRON_WP', false );
if ( ! defined( 'STRARMUS_REST_ENDPOINT' ) ) define( 'STRARMUS_REST_ENDPOINT', 'star-starmus-audio-recorder/v1' );
if ( ! defined( 'STARMUS_TUS_ENDPOINT' ) ) define( 'STARMUS_TUS_ENDPOINT', 'https://upload.sparxstar.com/files/' );
if ( ! defined( 'STARMUS_R2_ENDPOINT' ) ) define( 'STARMUS_R2_ENDPOINT', 'https://cdn.sparxstar.com/' );

if ( ! defined( 'TUS_WEBHOOK_SECRET' ) ) {
	define( 'TUS_WEBHOOK_SECRET', '84d34624286938554e5e19d9fafe9f5da3562c4d1d443e02c186f8e44019406e' );
}

// =========================================================================
// 2. SAFETY LOADERS (Wrapped in functions to prevent global crashes)
// =========================================================================

/**
 * Loads the logger manually to ensure we can report errors if Composer fails.
 */
function starmus_load_logger_failsafe(): void {
	$logClassFile = STARMUS_PATH . 'src/helpers/StarmusLogger.php';

	if ( file_exists( $logClassFile ) && ! class_exists( 'StarmusLogger' ) ) {
		try {
			require_once $logClassFile;

			$fqcn = 'Starisian\\Sparxstar\\Starmus\\helpers\\StarmusLogger';
			if ( class_exists( $fqcn ) ) {
				class_alias( $fqcn, 'StarmusLogger' );
				if ( defined( 'STARMUS_LOG_LEVEL' ) && method_exists( $fqcn, 'set_min_level' ) ) {
					$fqcn::set_min_level( STARMUS_LOG_LEVEL );
				}
			}
		} catch ( Throwable $e ) {
			error_log( 'Starmus: Critical Logger Failsafe Error: ' . $e->getMessage() );
		}
	}
}
// Execute immediately so Logger is available for subsequent steps
starmus_load_logger_failsafe();


/**
 * Loads core dependencies (Composer & Action Scheduler).
 */
function starmus_load_dependencies(): void {
	try {
		// 1. Composer Autoloader
		$autoloader = STARMUS_PATH . 'vendor/autoload.php';
		if ( file_exists( $autoloader ) ) {
			require_once $autoloader;
		} else {
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-error"><p><strong>Starmus Error:</strong> vendor/autoload.php missing. Run composer install.</p></div>';
			} );
		}

		// 2. Action Scheduler
		$as_path = STARMUS_PATH . 'libraries/action-scheduler/action-scheduler.php';
		if ( file_exists( $as_path ) ) {
			require_once $as_path;
		}
	} catch ( Throwable $e ) {
		if ( class_exists( 'StarmusLogger' ) ) {
			\StarmusLogger::log( $e );
		} else {
			error_log( 'Starmus Dependency Load Error: ' . $e->getMessage() );
		}
	}
}
// Execute immediately
starmus_load_dependencies();


// =========================================================================
// 3. PLUGIN INITIALIZATION
// =========================================================================

// Load Bundled SCF (Secure Custom Fields)
function starmus_load_bundled_scf(): void {
	try {
		if ( class_exists( 'ACF' ) ) {
			return;
		}

		$scf = STARMUS_PATH . 'vendor/secure-custom-fields/secure-custom-fields.php';
		if ( file_exists( $scf ) ) {
			if ( ! defined( 'STARMUS_ACF_PATH' ) ) define( 'STARMUS_ACF_PATH', STARMUS_PATH . 'vendor/secure-custom-fields/' );
			if ( ! defined( 'STARMUS_ACF_URL' ) ) define( 'STARMUS_ACF_URL', STARMUS_URL . 'vendor/secure-custom-fields/' );

			add_filter( 'acf/settings/path', fn() => STARMUS_ACF_PATH );
			add_filter( 'acf/settings/url', fn() => STARMUS_ACF_URL );
			add_filter( 'acf/settings/show_admin', '__return_false' );
			add_filter( 'acf/settings/show_updates', '__return_false', 100 );

			require_once $scf;
		}
	} catch ( Throwable $e ) {
		if ( class_exists( 'StarmusLogger' ) ) \StarmusLogger::log( $e );
	}
}
add_action( 'plugins_loaded', 'starmus_load_bundled_scf', 5 );

// ACF JSON Integration
function starmus_acf_json_integration(): void {
	add_filter( 'acf/settings/save_json', fn() => STARMUS_PATH . 'acf-json' );
	add_filter( 'acf/settings/load_json', function ( $paths ) {
		$paths[] = STARMUS_PATH . 'acf-json';
		return $paths;
	} );
}
add_action( 'acf/init', 'starmus_acf_json_integration' );

/**
 * MAIN PLUGIN RUNNER
 * Handles i18n, Core Bootstrapping, and Rewrite Rules.
 */
function starmus_run_plugin(): void {
	// 1. Check Dependencies
	if ( ! class_exists( 'ACF' ) && ! function_exists( 'acf_get_instance' ) ) {
		if ( ! isset( $_GET['activate'] ) ) {
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-error"><p><strong>Starmus Error:</strong> Secure Custom Fields failed to load.</p></div>';
			} );
		}
		return;
	}

	try {
		// 2. Load i18n (Now protected inside try/catch)
		if ( file_exists( STARMUS_PATH . 'src/i18n/Starmusi18NLanguage.php' ) ) {
			require_once STARMUS_PATH . 'src/i18n/Starmusi18NLanguage.php';
			if ( class_exists( '\Starisian\Sparxstar\Starmus\i18n\Starmusi18NLanguage' ) ) {
				new \Starisian\Sparxstar\Starmus\i18n\Starmusi18NLanguage();
			}
		}

		// 3. Boot Orchestrator
		if ( class_exists( '\Starisian\Sparxstar\Starmus\StarmusAudioRecorder' ) ) {
			\Starisian\Sparxstar\Starmus\StarmusAudioRecorder::starmus_run();
		} else {
			throw new RuntimeException( 'StarmusAudioRecorder class missing.' );
		}

		// 4. Handle Rewrites
		if ( get_transient( 'starmus_flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
			delete_transient( 'starmus_flush_rewrite_rules' );
		}

	} catch ( Throwable $e ) {
		if ( class_exists( 'StarmusLogger' ) ) {
			\StarmusLogger::log( $e );
		} else {
			error_log( 'Starmus Critical Boot Error: ' . $e->getMessage() );
		}
	}
}
add_action( 'plugins_loaded', 'starmus_run_plugin', 20 );


// =========================================================================
// 4. ACTIVATION HOOKS
// =========================================================================

function starmus_on_activate(): void {
	if ( ! file_exists( STARMUS_PATH . 'vendor/autoload.php' ) ) {
		wp_die( 'Starmus Error: Composer dependencies missing.' );
	}

	starmus_load_bundled_scf();

	if ( ! class_exists( 'ACF' ) && ! file_exists( STARMUS_PATH . 'vendor/secure-custom-fields/secure-custom-fields.php' ) ) {
		wp_die( 'Starmus Error: Secure Custom Fields plugin missing.' );
	}

	try {
		if ( class_exists( '\Starisian\Sparxstar\Starmus\cron\StarmusCron' ) ) {
			\Starisian\Sparxstar\Starmus\cron\StarmusCron::activate();
		}
	} catch ( Throwable $e ) {
		if ( class_exists( 'StarmusLogger' ) ) \StarmusLogger::log( $e );
	}

	set_transient( 'starmus_flush_rewrite_rules', true, 60 );
}

function starmus_on_deactivate(): void {
	try {
		if ( class_exists( '\Starisian\Sparxstar\Starmus\cron\StarmusCron' ) ) {
			\Starisian\Sparxstar\Starmus\cron\StarmusCron::deactivate();
		}
		flush_rewrite_rules();
	} catch ( Throwable $e ) {
		// Silent fail on deactivate
	}
}

function starmus_on_uninstall(): void {
	if ( STARMUS_DELETE_ON_UNINSTALL ) {
		$uninstall_file = STARMUS_PATH . 'uninstall.php';
		if ( file_exists( $uninstall_file ) ) {
			require_once $uninstall_file;
		}
	}
}

register_activation_hook( STARMUS_MAIN_FILE, 'starmus_on_activate' );
register_deactivation_hook( STARMUS_MAIN_FILE, 'starmus_on_deactivate' );
register_uninstall_hook( STARMUS_MAIN_FILE, 'starmus_on_uninstall' );
