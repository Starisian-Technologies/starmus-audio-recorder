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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
error_log('STARMUS STARTED');

// -------------------------------------------------------------------------
// 0. SHUTDOWN SAFETY NET
// -------------------------------------------------------------------------
register_shutdown_function( function() {
	$error = error_get_last();
	if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
		error_log( 'STARMUS FATAL CRASH: ' . print_r( $error, true ) );
	}
} );

function starmus_shutdown_callback(): void
{
	// This code will run after WordPress has finished rendering the page
	// and just before PHP terminates the script.
	$error = error_get_last();
	if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
		error_log('STARMUS FATAL CRASH: ' . print_r($error, true));
	}
	error_log('WordPress script is shutting down. Goodbye!');

	// You can perform cleanup tasks here, like:
	// * Logging data
	// * Priming a cache for the next request
	// * Handling fatal errors that might have occurred
}

add_action('shutdown', 'starmus_shutdown_callback');

// -------------------------------------------------------------------------
// 1. CONSTANTS & GUARDS
// -------------------------------------------------------------------------

define( 'STARMUS_LOADED', true );
define( 'STARMUS_VERSION', '0.9.4' );
define( 'STARMUS_MAIN_FILE', __FILE__ );
define( 'STARMUS_PATH', plugin_dir_path( STARMUS_MAIN_FILE ) );
define( 'STARMUS_URL', plugin_dir_url( STARMUS_MAIN_FILE ) );
define( 'STARMUS_PLUGIN_PREFIX', 'starmus' );
define( 'STARMUS_PLUGIN_DIR', plugin_dir_path( STARMUS_MAIN_FILE ) );

// Config Defaults
if ( ! defined( 'STARMUS_LOG_LEVEL' ) ) define( 'STARMUS_LOG_LEVEL', 8 );
if ( ! defined( 'STARMUS_TUS_ENDPOINT' ) ) define( 'STARMUS_TUS_ENDPOINT', 'https://upload.sparxstar.com/files/' );
if ( ! defined( 'STARMUS_R2_ENDPOINT' ) ) define( 'STARMUS_R2_ENDPOINT', 'https://cdn.sparxstar.com/' );
if ( ! defined( 'TUS_WEBHOOK_SECRET' ) ) define( 'TUS_WEBHOOK_SECRET', '84d34624286938554e5e19d9fafe9f5da3562c4d1d443e02c186f8e44019406e' );

// -------------------------------------------------------------------------
// 2. ENVIRONMENT INITIALIZATION (Priority 0)
// -------------------------------------------------------------------------

/**
 * Loads Composer Autoloader and sets up Logger aliases.
 * Runs at Priority 0 to ensure classes are available before booting.
 */
function starmus_init_environment(): void {
	// A. Composer Autoloader
	error_log('STARMUS INIT ENVIRONMENT');
	$autoloader = STARMUS_PATH . 'vendor/autoload.php';
	if ( file_exists( $autoloader ) ) {
		require_once $autoloader;
	} else {
		add_action( 'admin_notices', function() {
			echo '<div class="notice notice-error"><p>Starmus Error: <code>vendor/autoload.php</code> missing.</p></div>';
		} );
		return;
	}

	// B. Logger Failsafe & Alias
	// Using FQCN (Fully Qualified Class Name) to avoid illegal file-scope `use`.
	if ( class_exists( \Starisian\Sparxstar\Starmus\helpers\StarmusLogger::class ) ) {
		if ( ! class_exists( 'StarmusLogger' ) ) {
			class_alias( \Starisian\Sparxstar\Starmus\helpers\StarmusLogger::class, 'StarmusLogger' );
		}
		\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::set_min_level( STARMUS_LOG_LEVEL );
	}
}
// Run early to prepare environment
add_action( 'plugins_loaded', 'starmus_init_environment', 0 );


// -------------------------------------------------------------------------
// 3. MAIN PLUGIN BOOTSTRAP (Priority 10)
// -------------------------------------------------------------------------

/**
 * Boots the main plugin logic.
 * Checks dependencies and initializes the Orchestrator.
 */
function starmus_boot_plugin(): void {
	error_log('STARMUS BOOT PLUGIN');
	try {
		// 1. Dependency Detection (Safe method)
		if ( ! class_exists( \Starisian\Sparxstar\Starmus\helpers\StarmusDependencies::class ) ) {
			return; // Autoloader failed
		}

		if ( ! \Starisian\Sparxstar\Starmus\helpers\StarmusDependencies::check_critical_dependencies() ) {
			return; // Missing ACF/SCF
		}

		// 2. Load i18n
		if ( class_exists( \Starisian\Sparxstar\Starmus\i18n\Starmusi18NLanguage::class ) ) {
			new \Starisian\Sparxstar\Starmus\i18n\Starmusi18NLanguage();
		}

		// 3. ACF JSON Integration (Guarded)
		if ( function_exists( 'acf_get_instance' ) ) {
			add_filter( 'acf/settings/save_json', fn() => STARMUS_PATH . 'acf-json' );
			add_filter( 'acf/settings/load_json', function( $paths ) {
				$paths[] = STARMUS_PATH . 'acf-json';
				return $paths;
			} );
		}

		// 4. Boot Core Orchestrator
		if ( class_exists( \Starisian\Sparxstar\Starmus\StarmusAudioRecorder::class ) ) {
			\Starisian\Sparxstar\Starmus\StarmusAudioRecorder::starmus_run();
		} else {
			throw new RuntimeException( 'StarmusAudioRecorder class missing.' );
		}

		// 5. Cleanup
		if ( get_transient( 'starmus_flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
			delete_transient( 'starmus_flush_rewrite_rules' );
		}

	} catch ( Throwable $e ) {
		// Safe logging via FQCN
		if ( class_exists( \Starisian\Sparxstar\Starmus\helpers\StarmusLogger::class ) ) {
			\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::log( $e );
		} else {
			error_log( 'Starmus Boot Error: ' . $e->getMessage() );
		}
	}
}
// Run at standard priority
add_action( 'plugins_loaded', 'starmus_boot_plugin', 10 );


// -------------------------------------------------------------------------
// 4. LIFECYCLE HOOKS
// -------------------------------------------------------------------------

function starmus_on_activate(): void {
	error_log('STARMUS ACTIVATING');
	// Safe load of autoloader to ensure classes exist during activation
	if ( ! class_exists( \Starisian\Sparxstar\Starmus\helpers\StarmusDependencies::class ) ) {
		if ( file_exists( STARMUS_PATH . 'vendor/autoload.php' ) ) {
			require_once STARMUS_PATH . 'vendor/autoload.php';
		} else {
			wp_die( 'Starmus Error: Composer missing.' );
		}
	}

	// Check dependencies using the helper class
	if ( ! \Starisian\Sparxstar\Starmus\helpers\StarmusDependencies::check_critical_dependencies() ) {
		wp_die( 'Starmus Error: Secure Custom Fields (or ACF) is required.' );
	}

	try {
		if ( class_exists( \Starisian\Sparxstar\Starmus\cron\StarmusCron::class ) ) {
			\Starisian\Sparxstar\Starmus\cron\StarmusCron::activate();
		}
		set_transient( 'starmus_flush_rewrite_rules', true, 60 );
	} catch ( Throwable $e ) {
		error_log( 'Starmus Activation Error: ' . $e->getMessage() );
	}
}

function starmus_on_deactivate(): void {
	try {
		if ( class_exists( \Starisian\Sparxstar\Starmus\cron\StarmusCron::class ) ) {
			\Starisian\Sparxstar\Starmus\cron\StarmusCron::deactivate();
		}
		flush_rewrite_rules();
	} catch ( Throwable $e ) { }
}

function starmus_on_uninstall(): void {
	if ( defined( 'STARMUS_DELETE_ON_UNINSTALL' ) && STARMUS_DELETE_ON_UNINSTALL ) {
		$file = STARMUS_PATH . 'uninstall.php';
		if ( file_exists( $file ) ) require_once $file;
	}
}

register_activation_hook( STARMUS_MAIN_FILE, 'starmus_on_activate' );
register_deactivation_hook( STARMUS_MAIN_FILE, 'starmus_on_deactivate' );
register_uninstall_hook( STARMUS_MAIN_FILE, 'starmus_on_uninstall' );
