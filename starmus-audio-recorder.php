<?php
/**
 * Plugin Name:       Starmus Audio Recorder
 * Plugin URI:        https://github.com/Starisian-Technologies/starmus-audio-recorder
 * Description:       Adds a mobile-friendly MP3 audio recorder for oral history submission.
 * Version:           0.8.5
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Starisian Technologies (Max Barrett)
 * Author URI:        https://starisian.com
 * Text Domain:       starmus-audio-recorder
 * Domain Path:       /languages
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) exit;

// =========================================================================
//  1. BOOTSTRAP GUARD & CORE CONSTANTS
// =========================================================================
if ( defined( 'STARMUS_LOADED' ) ) {
    return;
}
define( 'STARMUS_LOADED', true );
define( 'STARMUS_VERSION', '0.8.5' );
define( 'STARMUS_MAIN_FILE', __FILE__ );
define( 'STARMUS_PATH', plugin_dir_path( STARMUS_MAIN_FILE ) );
define( 'STARMUS_URL', plugin_dir_url( STARMUS_MAIN_FILE ) );
define( 'STARMUS_PLUGIN_PREFIX', 'starmus' );

// =========================================================================
//  2. RESTORED: APPLICATION & OVERRIDABLE CONFIGURATION CONSTANTS
// =========================================================================

// --- Logger Configuration ---
if ( ! defined( 'STARMUS_LOG_LEVEL' ) ) {
    define( 'STARMUS_LOG_LEVEL', 'Warning' );
}
if ( ! defined( 'STARMUS_LOG_FILE' ) ) {
    define( 'STARMUS_LOG_FILE', '' );
}

// --- AWS Integration (assumed shared with AIWA SWM) ---
if ( ! defined( 'AIWA_AWS_ACCESS_KEY_ID' ) ) {
	define( 'AIWA_AWS_ACCESS_KEY_ID', 'YOUR_KEY' );
}
if ( ! defined( 'AIWA_AWS_SECRET_ACCESS_KEY' ) ) {
	define( 'AIWA_AWS_SECRET_ACCESS_KEY', 'YOUR_SECRET' );
}
if ( ! defined( 'AIWA_AWS_REGION' ) ) {
	define( 'AIWA_AWS_REGION', 'us-east-2' );
}
if ( ! defined( 'AIWA_SAGEMAKER_ENDPOINT' ) ) {
	define( 'AIWA_SAGEMAKER_ENDPOINT', 'https://runtime.sagemaker.us-east-2.amazonaws.com/endpoints/aiwa-transcriber/invocations' );
}

// --- Application URLs & Settings (assumed shared with AIWA SWM) ---
if ( ! defined( 'AIWA_CURRENT_TERMS_VERSION' ) ) {
	define( 'AIWA_CURRENT_TERMS_VERSION', '1.0' );
}
if ( ! defined( 'AIWA_SWM_CONSENT_URL' ) ) {
	define( 'AIWA_SWM_CONSENT_URL', home_url( '/' ) );
}
if ( ! defined( 'AIWA_SWM_PROFILE_URL' ) ) {
	define( 'AIWA_SWM_PROFILE_URL', home_url( '/' ) );
}
if ( ! defined( 'AIWA_SWM_SUBMISSIONS_URL' ) ) {
	define( 'AIWA_SWM_SUBMISSIONS_URL', '/star-my-submissions' );
}
if ( ! defined( 'AIWA_SWM_DELETE_ON_UNINSTALL' ) ) {
	define( 'AIWA_SWM_DELETE_ON_UNINSTALL', false );
}

// =========================================================================
//  2. COMPOSER AUTOLOADER
// =========================================================================
$autoloader = STARMUS_PATH . 'vendor/autoload.php';
if ( ! file_exists( $autoloader ) ) {
    // deactivate plugin and alert user.
    deactivate_plugins( plugin_basename(__FILE__) );
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>Starmus Audio Recorder Error:</strong> Plugin deactivated as dependencies are missing. Please run `composer install`.</p></div>';
    });
    return;
}
require_once $autoloader;

// =========================================================================
//  3. BUNDLED SCF DEPENDENCY LOADER
// =========================================================================
/**
 * Loads the bundled Secure Custom Fields plugin if it's not already available.
 * Hooked early to ensure ACF is ready for other plugins and themes.
 */
function starmus_load_bundled_scf(): void {
    if ( class_exists( 'ACF' ) ) {
        return;
    }

    $scf_main_file = STARMUS_PATH . 'vendor/wpackagist-plugin/secure-custom-fields/secure-custom-fields.php';
    if ( file_exists( $scf_main_file ) ) {
        // Define ACF constants so the bundled plugin knows its location.
        define('ACF_PATH', dirname($scf_main_file) . '/');
        define('ACF_URL', STARMUS_URL . 'vendor/wpackagist-plugin/secure-custom-fields/');
        require_once $scf_main_file;

        // As this plugin bundles SCF, hide the admin menu to prevent user confusion.
        add_filter('acf/settings/show_admin', '__return_false');
        add_filter('acf/settings/show_updates', '__return_false', 100);
    }
}
add_action( 'plugins_loaded', 'starmus_load_bundled_scf', 5 );
add_filter('acf/settings/save_json', fn() => STARMUS_PATH . 'acf-json');
add_filter('acf/settings/load_json', function ($paths) {
    unset($paths[0]);
    $paths[] = STARMUS_PATH . 'acf-json';
    return $paths;
});


// =========================================================================
//  4. MAIN PLUGIN INITIALIZATION (SINGLE ENTRY POINT)
// =========================================================================
/**
 * Initializes the main Starmus orchestrator class.
 * Hooked later to ensure all dependencies are loaded first.
 */
function starmus_run_plugin(): void {
    // Final safety check: if ACF is still not available, stop and notify the admin.
    if ( ! class_exists('ACF') ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Starmus Audio Recorder Error:</strong> The critical dependency "Secure Custom Fields" could not be loaded.</p></div>';
        });
        return;
    }
    // This is now the single, reliable entry point for the plugin's logic.
    \Starisian\Sparxstar\Starmus\StarmusAudioRecorder::starmus_run();
}
add_action( 'plugins_loaded', 'starmus_run_plugin', 20 );

// =========================================================================
//  5. ACF JSON FIELD GROUP INTEGRATION
// =========================================================================
/**
 * Configures ACF to use this plugin's 'acf-json' directory for field groups.
 */
function starmus_acf_json_integration(): void {
    add_filter('acf/settings/save_json', fn() => STARMUS_PATH . 'acf-json');
    add_filter('acf/settings/load_json', function ($paths) {
        unset($paths[0]);
        $paths[] = STARMUS_PATH . 'acf-json';
        return $paths;
    });
}
add_action( 'acf/init', 'starmus_acf_json_integration' );

// =========================================================================
//  6. ACTIVATION & DEACTIVATION HOOKS (SAFE VERSION)
// =========================================================================
/**
 * Safe activation handler. Checks for dependencies and flushes rewrite rules.
 */
function starmus_on_activate(): void {
    // Ensure dependencies are available before running activation logic.
    starmus_load_bundled_scf();
    if ( ! class_exists( 'ACF' ) ) {
        // This is a safe deactivation method that doesn't use wp_die().
        deactivate_plugins( plugin_basename( STARMUS_MAIN_FILE ) );
        // Suppress the "Plugin activated" message.
        if ( isset( $_GET['activate'] ) ) {
            unset( $_GET['activate'] );
        }
        return;
    }
    \Starisian\Sparxstar\Starmus\cron\StarmusCron::activate();
    flush_rewrite_rules();
}

/**
 * Safe deactivation handler.
 */
function starmus_on_deactivate(): void {
    \Starisian\Sparxstar\Starmus\cron\StarmusCron::deactivate();
    unregister_post_type( 'audio-recording' );
    flush_rewrite_rules();
}

/**
 * Uninstall handler for permanent cleanup.
 */
function starmus_on_uninstall(): void {
    // This function is only called when a user with permission deletes the plugin.
    // Include the dedicated uninstall script for cleanup tasks.
    $uninstall_file = STARMUS_PATH . 'uninstall.php';
    if ( file_exists( $uninstall_file ) ) {
        require_once $uninstall_file;
    }
    // Perform any other cleanup not in the uninstall.php file.
    wp_clear_scheduled_hook( 'starmus_cleanup_temp_files' );
    flush_rewrite_rules();
}

register_activation_hook( STARMUS_MAIN_FILE, 'starmus_on_activate' );
register_deactivation_hook( STARMUS_MAIN_FILE, 'starmus_on_deactivate' );
register_uninstall_hook( STARMUS_MAIN_FILE, 'starmus_on_uninstall' );
