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
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// -------------------------------------------------------------------------
// 0. SHUTDOWN SAFETY NET
// -------------------------------------------------------------------------
register_shutdown_function( function() {
	$error = error_get_last();
	if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
		error_log( 'STARMUS FATAL CRASH: ' . print_r( $error, true ) );
	}
} );

// -------------------------------------------------------------------------
// 1. CONSTANTS
// -------------------------------------------------------------------------

if ( defined( 'STARMUS_LOADED' ) ) {
	return;
}

define( 'STARMUS_LOADED', true );
define( 'STARMUS_VERSION', '0.9.4' );
define( 'STARMUS_MAIN_FILE', __FILE__ );
define( 'STARMUS_PATH', plugin_dir_path( STARMUS_MAIN_FILE ) );
define( 'STARMUS_URL', plugin_dir_url( STARMUS_MAIN_FILE ) );
define( 'STARMUS_PLUGIN_PREFIX', 'starmus' );
define( 'STARMUS_PLUGIN_DIR', plugin_dir_path( STARMUS_MAIN_FILE ) );

if ( ! defined( 'STARMUS_LOG_LEVEL' ) ) define( 'STARMUS_LOG_LEVEL', 8 );
if ( ! defined( 'STARMUS_TUS_ENDPOINT' ) ) define( 'STARMUS_TUS_ENDPOINT', 'https://upload.sparxstar.com/files/' );
if ( ! defined( 'STARMUS_R2_ENDPOINT' ) ) define( 'STARMUS_R2_ENDPOINT', 'https://cdn.sparxstar.com/' );
if ( ! defined( 'TUS_WEBHOOK_SECRET' ) ) define( 'TUS_WEBHOOK_SECRET', '84d34624286938554e5e19d9fafe9f5da3562c4d1d443e02c186f8e44019406e' );

// -------------------------------------------------------------------------
// 2. ENVIRONMENT INITIALIZATION (Priority 0)
// -------------------------------------------------------------------------

function starmus_init_environment(): void {
	// A. Composer Autoloader
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
	if ( class_exists( \Starisian\Sparxstar\Starmus\helpers\StarmusLogger::class ) ) {
		if ( ! class_exists( 'StarmusLogger' ) ) {
			class_alias( \Starisian\Sparxstar\Starmus\helpers\StarmusLogger::class, 'StarmusLogger' );
		}
		\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::set_min_level( STARMUS_LOG_LEVEL );
	}
}
add_action( 'plugins_loaded', 'starmus_init_environment', 0 );


// -------------------------------------------------------------------------
// 3. DEPENDENCY LOADING (Priority 1)
// -------------------------------------------------------------------------

function starmus_load_bundled_deps(): void {
	// Delegate to Dependencies Class
	if ( class_exists( \Starisian\Sparxstar\Starmus\helpers\StarmusDependencies::class ) ) {
		\Starisian\Sparxstar\Starmus\helpers\StarmusDependencies::try_load_bundled_scf();
	}
}
add_action( 'plugins_loaded', 'starmus_load_bundled_deps', 1 );


// -------------------------------------------------------------------------
// 4. MAIN PLUGIN BOOTSTRAP (Priority 10)
// -------------------------------------------------------------------------

function starmus_boot_plugin(): void {
	try {
		// 1. Verify Safety
		if ( ! class_exists( \Starisian\Sparxstar\Starmus\helpers\StarmusDependencies::class ) ) return;

		if ( ! \Starisian\Sparxstar\Starmus\helpers\StarmusDependencies::is_safe_to_boot() ) {
			return; // Stop. Admin notice already registered by Dependencies class.
		}

		// 2. Load i18n
		if ( class_exists( \Starisian\Sparxstar\Starmus\i18n\Starmusi18NLanguage::class ) ) {
			new \Starisian\Sparxstar\Starmus\i18n\Starmusi18NLanguage();
		}

		// 3. ACF JSON Integration (Only runs if ACF loaded)
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
		if ( class_exists( \Starisian\Sparxstar\Starmus\helpers\StarmusLogger::class ) ) {
			\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::log( $e );
		} else {
			error_log( 'Starmus Boot Error: ' . $e->getMessage() );
		}
	}
}
add_action( 'plugins_loaded', 'starmus_boot_plugin', 10 );


// -------------------------------------------------------------------------
// 5. LIFECYCLE HOOKS
// -------------------------------------------------------------------------

function starmus_on_activate(): void {
	if ( ! file_exists( STARMUS_PATH . 'vendor/autoload.php' ) ) {
		wp_die( 'Starmus Error: Composer missing.' );
	}
	require_once STARMUS_PATH . 'vendor/autoload.php';

	// Delegate loading logic to class if available
	if ( class_exists( \Starisian\Sparxstar\Starmus\helpers\StarmusDependencies::class ) ) {
		\Starisian\Sparxstar\Starmus\helpers\StarmusDependencies::try_load_bundled_scf();

		if ( ! \Starisian\Sparxstar\Starmus\helpers\StarmusDependencies::is_safe_to_boot() ) {
			wp_die( 'Starmus Activation Error: Conflict with existing ACF/SCF or failed load.' );
		}
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
