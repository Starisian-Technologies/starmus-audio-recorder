<?php
/**
 * Plugin Name:       Starmus Audio Recorder
 * Plugin URI:        https://github.com/Starisian-Technologies/starmus-audio-recorder
 * Description:       Adds a mobile-friendly MP3 audio recorder for oral history submission in low-bandwidth environments.
 * Version:           0.7.6
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Tested up to:      6.5
 * Author:            Starisian Technologies (Max Barrett)
 * Author URI:        https://starisian.com
 * Text Domain:       starmus-audio-recorder
 * Domain Path:       /languages
 * License:           LicenseRef-Starisian-Technologies-Proprietary
 * License URI:       https://github.com/Starisian-Technologies/starmus-audio-recorder/LICENSE.md
 * Update URI:        https://github.com/Starisian-Technologies/starmus-audio-recorder.git
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Absolute filesystem path to the plugin directory. */
define('STARMUS_PATH', plugin_dir_path(__FILE__));
/** Public URL to the plugin directory. */
define('STARMUS_URL', plugin_dir_url(__FILE__));
/** Main plugin file reference used for WordPress hooks. */
define('STARMUS_MAIN_FILE', __FILE__);
/** Directory path alias kept for backward compatibility. */
define('STARMUS_MAIN_DIR', plugin_dir_path(__FILE__));
/** Human readable plugin name displayed in WordPress admin. */
define('STARMUS_PLUGIN_NAME', 'Starmus Audio Recorder');
/** Shared prefix applied to option keys, actions, and filters. */
define('STARMUS_PLUGIN_PREFIX', 'starmus');
/** Current plugin semantic version string. */
define('STARMUS_VERSION', '0.7.6');
/**
 * Default severity threshold used when the host environment does not define one.
 */
if (!defined('STARMUS_LOG_LEVEL')) {
        define('STARMUS_LOG_LEVEL', 'Warning');
}
/**
 * Optional custom log file path honoured by the shared logger when configured.
 */
if (!defined('STARMUS_LOG_FILE')) {
        define('STARMUS_LOG_FILE', '');
}

/**
 * Load Composer's autoloader if it is available.
 *
 * Guarding the include prevents fatal errors during early bootstrap when the
 * vendor directory has not been installed (for example, during a manual
 * deployment that omits development assets).
 */
/** Absolute path to the Composer autoloader file. */

$starmus_autoload_path = STARMUS_PATH . 'vendor/autoload.php';
if ( file_exists( $starmus_autoload_path ) ) {
	require_once $starmus_autoload_path;
} else {
	error_log( 'Starmus Plugin: Missing vendor/autoload.php; aborting plugin bootstrap.' );
	return;
}

// =========================================================================
// START: ACF/SCF FIELD GROUP INTEGRATION
// =========================================================================

/**
 * Configure ACF/SCF to use this plugin's 'acf-json' directory as the
 * single source of truth for all field group definitions.
 *
 * This makes the plugin fully portable and removes the need for manual
 * import/export of field groups.
 */
add_action('acf/init', 'starmus_acf_json_integration');
function starmus_acf_json_integration() {

    // Set the path for saving JSON files.
    add_filter('acf/settings/save_json', function( $path ) {
        // This forces ACF to save any UI changes back to this plugin's folder.
        return plugin_dir_path( __FILE__ ) . 'acf-json';
    });

    // Set the path for loading JSON files.
    add_filter('acf/settings/load_json', function( $paths ) {
        // Remove the default path (which is in the theme).
        unset($paths[0]);

        // Add this plugin's folder as the new path.
        $paths[] = plugin_dir_path( __FILE__ ) . 'acf-json';

        return $paths;
    });

    // Hide the ACF admin menu.
    // Since fields are managed in code (JSON), this prevents clients from
    // making UI edits that would be out of sync with the version-controlled files.
    if ( 'production' === wp_get_environment_type() ) {
        add_filter( 'acf/settings/show_admin', '__return_false' );
    }
}

// =========================================================================
// END: ACF/SCF FIELD GROUP INTEGRATION
// =========================================================================


/**
 * Check for SCF/ACF dependency.
 * Note: Your original plugin already does this well, but this is a streamlined check.
 */
if ( ! class_exists( 'ACF' ) ) {
	// If Composer is used at the project level, ACF should already be loaded.
	// This code is a fallback for when SCF/ACF is bundled inside this plugin's vendor directory.
	$bundled_scf_path = STARMUS_PATH . 'vendor/wpackagist-plugin/secure-custom-fields/secure-custom-fields.php';
	if ( file_exists( $bundled_scf_path ) ) {
		// You may need to uncomment this line if SCF is not loaded by a parent project.
		// require_once $bundled_scf_path;
	}
}


/**
 * Plugin Activation Hook.
 * Sets up the plugin on activation by flushing rewrite rules.
 * The 'audio-recording' CPT will be registered on 'init'.
 *
 * @since 0.1.0
 */

function starmus_activate(): void {
	if ( ! class_exists('ACF') ) {
		deactivate_plugins( plugin_basename( STARMUS_MAIN_FILE ) );
		wp_die( __( 'Starmus Audio Recorder requires Secure Custom Fields (or ACF PRO) to be installed and activated.', 'starmus-audio-recorder' ) );
		return;
	}
	// CPTs are registered on init, so we just need to flush rewrite rules here.
	flush_rewrite_rules();
}

/**
 * Deactivation hook for the Starmus Audio Recorder plugin.
 *
 * @return  void
 * @since 0.1.0
 */
function starmus_deactivate(): void {
	// Unregister the post type so the rules are no longer in memory.
	unregister_post_type( 'audio-recording' );
	// Clear the permalinks to remove our post type's rules from the database.
	flush_rewrite_rules();
	error_log( 'Starmus Plugin: Deactivation completed, CPT unregistered and rewrite rules flushed' );
}

/**
 * Plugin Uninstall Hook.
 *
 * @since 0.1.0
 */
function starmus_uninstall(): void {
	$file = STARMUS_PATH . 'uninstall.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
	wp_clear_scheduled_hook( 'starmus_cleanup_temp_files' );
	flush_rewrite_rules();
	error_log( 'Starmus Plugin: Uninstall completed, scheduled hooks cleared and rewrite rules flushed' );
}

// Register Plugin Lifecycle Hooks.
register_activation_hook( STARMUS_MAIN_FILE, 'starmus_activate' );
register_deactivation_hook( STARMUS_MAIN_FILE, 'starmus_deactivate' );
register_uninstall_hook( STARMUS_MAIN_FILE, 'starmus_uninstall' );

// Your existing cron activation/deactivation hooks are good.
register_activation_hook( __FILE__, array( \Starisian\Sparxstar\Starmus\cron\StarmusCron::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Starisian\Sparxstar\Starmus\cron\StarmusCron::class, 'deactivate' ) );

// Register Plugin Lifecycle Hooks.
register_activation_hook(STARMUS_MAIN_FILE, 'starmus_activate');
register_deactivation_hook(STARMUS_MAIN_FILE, 'starmus_deactivate');
register_uninstall_hook(STARMUS_MAIN_FILE, 'starmus_uninstall');
// Starmus Cron activation / deactivation
register_activation_hook(__FILE__, [ \Starisian\Sparxstar\Starmus\cron\StarmusCron::class, 'activate' ]);
register_deactivation_hook(__FILE__, [ \Starisian\Sparxstar\Starmus\cron\StarmusCron::class, 'deactivate' ]);
// Initialize the plugin once all other plugins are loaded.
add_action('plugins_loaded', [\Starisian\Sparxstar\Starmus\StarmusAudioRecorder::class, 'starmus_run']);

// Bootstrap plugin services during WordPress init lifecycle.
add_action('init', [Starisian\Sparxstar\Starmus\StarmusAudioRecorder::class, 'starmus_init_plugin']);
