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


if (! defined('ABSPATH')) exit;

// =========================================================================
//  1. BOOTSTRAP GUARD & CORE CONSTANTS
// =========================================================================
if (defined('STARMUS_LOADED')) {
    return;
}
define('STARMUS_LOADED', true);
define('STARMUS_VERSION', '0.8.5');
define('STARMUS_MAIN_FILE', __FILE__);
define('STARMUS_PATH', plugin_dir_path(STARMUS_MAIN_FILE));
define('STARMUS_URL', plugin_dir_url(STARMUS_MAIN_FILE));
define('STARMUS_PLUGIN_PREFIX', 'starmus');

// =========================================================================
//  2. RESTORED: APPLICATION & OVERRIDABLE CONFIGURATION CONSTANTS
// =========================================================================

// --- Logger Configuration ---
if (! defined('STARMUS_LOG_LEVEL')) {
    define('STARMUS_LOG_LEVEL', 'Warning');
}
if (! defined('STARMUS_LOG_FILE')) {
    define('STARMUS_LOG_FILE', '');
}

// --- Uninstall Safety ---
if (! defined('STARMUS_DELETE_ON_UNINSTALL')) {
    define('STARMUS_DELETE_ON_UNINSTALL', false);
}

// =========================================================================
//  2. COMPOSER AUTOLOADER
// =========================================================================
$autoloader = STARMUS_PATH . 'vendor/autoload.php';
if (! file_exists($autoloader)) {
    // deactivate plugin and alert user.
    deactivate_plugins(plugin_basename(__FILE__));
    add_action('admin_notices', function () {
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
function starmus_load_bundled_scf(): void
{
    // Do not proceed if ACF is already active
    if (class_exists('ACF')) {
        return;
    }

    try {
        $scf_main_file = STARMUS_PATH . 'vendor/wpackagist-plugin/secure-custom-fields/secure-custom-fields.php';

        if (file_exists($scf_main_file)) {
            // Define constants to identify your bundled version
            if (!defined('STARMUS_ACF_PATH')) {
                define('STARMUS_ACF_PATH', STARMUS_PATH . 'vendor/wpackagist-plugin/secure-custom-fields/');
            }
            if (!defined('STARMUS_ACF_URL')) {
                define('STARMUS_ACF_URL', STARMUS_URL . 'vendor/wpackagist-plugin/secure-custom-fields/');
            }

            // Set the path for ACF to load from (recommended by ACF)
            add_filter('acf/settings/path', function ($path) {
                return STARMUS_ACF_PATH;
            });

            // Set the URL for ACF assets
            add_filter('acf/settings/url', function ($url) {
                return STARMUS_ACF_URL;
            });

            // Hide the ACF admin menu since this is bundled
            add_filter('acf/settings/show_admin', '__return_false');
            add_filter('acf/settings/show_updates', '__return_false', 100);

            // Include the main SCF file
            require_once $scf_main_file;
        } else {
            error_log('Starmus Plugin: Bundled Secure Custom Fields file not found at: ' . $scf_main_file);
        }
    } catch (\Exception $e) {
        error_log('Starmus Plugin: Failed to load bundled Secure Custom Fields: ' . $e->getMessage());
        error_log('Starmus Plugin: Exception trace: ' . $e->getTraceAsString());
    } catch (\Error $e) {
        error_log('Starmus Plugin: Fatal error loading bundled Secure Custom Fields: ' . $e->getMessage());
        error_log('Starmus Plugin: Error trace: ' . $e->getTraceAsString());
    }
}
add_action('plugins_loaded', 'starmus_load_bundled_scf', 5);


// =========================================================================
//  4. MAIN PLUGIN INITIALIZATION (SINGLE ENTRY POINT)
// =========================================================================
/**
 * Initializes the main Starmus orchestrator class.
 * Hooked later to ensure all dependencies are loaded first.
 */
function starmus_run_plugin(): void
{
    // Final safety check: if ACF is still not available, stop and notify the admin.
    if (! class_exists('ACF')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Starmus Audio Recorder Error:</strong> The critical dependency "Secure Custom Fields" could not be loaded.</p></div>';
        });
        return;
    }
    try {
        // This is now the single, reliable entry point for the plugin's logic.
        \Starisian\Sparxstar\Starmus\StarmusAudioRecorder::starmus_run();
    } catch (\Exception $e) {
        error_log('Starmus Plugin: Failed to initialize main plugin class: ' . $e->getMessage());
    }
}
add_action('plugins_loaded', 'starmus_run_plugin', 20);

// =========================================================================
//  5. ACF JSON FIELD GROUP INTEGRATION
// =========================================================================
/**
 * Configures ACF to use this plugin's 'acf-json' directory for field groups.
 */
function starmus_acf_json_integration(): void
{
    try {
        add_filter('acf/settings/save_json', fn() => STARMUS_PATH . 'acf-json');
        add_filter('acf/settings/load_json', function ($paths) {
            unset($paths[0]);
            $paths[] = STARMUS_PATH . 'acf-json';
            return $paths;
        });
    } catch (\Exception $e) {
        error_log('Starmus Plugin: Failed to integrate ACF JSON fields: ' . $e->getMessage());
    }
}
add_action('acf/init', 'starmus_acf_json_integration');

// =========================================================================
//  6. ACTIVATION & DEACTIVATION HOOKS (SAFE VERSION)
// =========================================================================
/**
 * Safe activation handler. Checks for dependencies and flushes rewrite rules.
 */
function starmus_on_activate(): void
{
    try {
        // Ensure dependencies are available before running activation logic.
        starmus_load_bundled_scf();

        if (! class_exists('ACF')) {
            error_log('Starmus Plugin: Activation failed - ACF class not available after loading bundled SCF');
            // This is a safe deactivation method that doesn't use wp_die().
            deactivate_plugins(plugin_basename(STARMUS_MAIN_FILE));
            // Suppress the "Plugin activated" message.
            if (isset($_GET['activate'])) {
                unset($_GET['activate']);
            }
            // Set transient for admin notice
            set_transient('starmus_activation_error', 'Secure Custom Fields dependency could not be loaded.', 60);
            return;
        }

        // Manually trigger CPT registration from ACF JSON before flushing rewrite rules
        // This ensures the CPT is registered before we flush
        if (function_exists('acf_add_local_field_group')) {
            // Force ACF to load field groups from JSON
            $json_files = glob(STARMUS_PATH . 'acf-json/*.json');
            if ($json_files) {
                foreach ($json_files as $file) {
                    $json = json_decode(file_get_contents($file), true);
                    if ($json && isset($json['key'])) {
                        acf_add_local_field_group($json);
                    }
                }
            }
        }

        // Run cron activation
        \Starisian\Sparxstar\Starmus\cron\StarmusCron::activate();

        // Flush rewrite rules to register custom post types from ACF JSON
        // ACF will automatically load post types from acf-json on next init
        flush_rewrite_rules();

        error_log('Starmus Plugin: Activation successful');
    } catch (\Exception $e) {
        error_log('Starmus Plugin: Activation failed with exception: ' . $e->getMessage());
        error_log('Starmus Plugin: Exception trace: ' . $e->getTraceAsString());
        // Set transient for admin notice
        set_transient('starmus_activation_error', $e->getMessage(), 60);
        // Try to deactivate gracefully
        deactivate_plugins(plugin_basename(STARMUS_MAIN_FILE));
        if (isset($_GET['activate'])) {
            unset($_GET['activate']);
        }
    } catch (\Error $e) {
        error_log('Starmus Plugin: Activation failed with fatal error: ' . $e->getMessage());
        error_log('Starmus Plugin: Error trace: ' . $e->getTraceAsString());
        // Set transient for admin notice
        set_transient('starmus_activation_error', $e->getMessage(), 60);
        // Try to deactivate gracefully
        deactivate_plugins(plugin_basename(STARMUS_MAIN_FILE));
        if (isset($_GET['activate'])) {
            unset($_GET['activate']);
        }
    }
}

/**
 * Safe deactivation handler.
 */
function starmus_on_deactivate(): void
{
    try {
        \Starisian\Sparxstar\Starmus\cron\StarmusCron::deactivate();

        // ACF-registered post types will be automatically unregistered when plugin is inactive
        // Just flush rewrite rules to clean up permalinks
        flush_rewrite_rules();

        error_log('Starmus Plugin: Deactivation successful');
    } catch (\Exception $e) {
        error_log('Starmus Plugin: Deactivation failed with exception: ' . $e->getMessage());
        error_log('Starmus Plugin: Exception trace: ' . $e->getTraceAsString());
    } catch (\Error $e) {
        error_log('Starmus Plugin: Deactivation failed with fatal error: ' . $e->getMessage());
        error_log('Starmus Plugin: Error trace: ' . $e->getTraceAsString());
    }
}

/**
 * Uninstall handler for permanent cleanup.
 */
function starmus_on_uninstall(): void
{
    // This function is only called when a user with permission deletes the plugin.
    // Include the dedicated uninstall script for cleanup tasks.
    $uninstall_file = STARMUS_PATH . 'uninstall.php';
    if (file_exists($uninstall_file)) {
        require_once $uninstall_file;
    }
    // Perform any other cleanup not in the uninstall.php file.
    wp_clear_scheduled_hook('starmus_cleanup_temp_files');
    flush_rewrite_rules();
}

register_activation_hook(STARMUS_MAIN_FILE, 'starmus_on_activate');
register_deactivation_hook(STARMUS_MAIN_FILE, 'starmus_on_deactivate');
register_uninstall_hook(STARMUS_MAIN_FILE, 'starmus_on_uninstall');
