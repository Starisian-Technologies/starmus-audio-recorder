<?php

/**
 * Plugin Name:       Starmus Audio Recorder
 * Plugin URI:        https://github.com/Starisian-Technologies/starmus-audio-recorder
 * Description:       Adds a mobile-friendly MP3 audio recorder for oral history submission.
 * Version:           0.9.2
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Starisian Technologies (Max Barrett)
 * Author URI:        https://starisian.com
 * Text Domain:       starmus-audio-recorder
 * Domain Path:       /languages
 *
 * @package Starisian\Sparxstar\Starmus
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
    define('STARMUS_LOG_LEVEL', 'debug');
}

/** @var string Custom log file path (empty uses WordPress default) */
if (! defined('STARMUS_LOG_FILE')) {
    define('STARMUS_LOG_FILE', '');
}

/** @var bool Whether to delete all data on plugin uninstall */
if (! defined('STARMUS_DELETE_ON_UNINSTALL')) {
    define('STARMUS_DELETE_ON_UNINSTALL', false);
}

// =========================================================================
//  2. ACTION SCHEDULER LIBRARY
// =========================================================================

/**
 * Load Action Scheduler library for background job processing.
 *
 * Action Scheduler provides reliable background job processing for WordPress.
 * It automatically handles version negotiation if multiple versions are present
 * (e.g., from WooCommerce, other plugins, etc.). We load it early to ensure
 * availability for our cron and background processing needs.
 */
$action_scheduler_path = STARMUS_PATH . 'libraries/action-scheduler/action-scheduler.php';
if (file_exists($action_scheduler_path)) {
    require_once $action_scheduler_path;
}

// =========================================================================
//  3. COMPOSER AUTOLOADER
// =========================================================================

/**
 * Load Composer autoloader for PSR-4 class loading.
 *
 * The autoloader is required for all plugin classes and dependencies.
 * If missing, we show an admin notice but don't completely halt execution
 * to allow for graceful degradation during development.
 */
$autoloader = STARMUS_PATH . 'vendor/autoload.php';

if (! file_exists($autoloader)) {
    add_action(
        'admin_notices',
        function () {
            echo '<div class="notice notice-error"><p><strong>Starmus Audio Recorder Error:</strong> missing <code>vendor/autoload.php</code>. Please run <code>composer install</code>.</p></div>';
        }
    );
    // Do not return here; let the activation hook handle the hard stop if this is an activation attempt.
} else {
    require_once $autoloader;

    // APPLY CONFIGURED LOG LEVEL (this was missing)
    if (defined('STARMUS_LOG_LEVEL')) {
        \Starisian\Sparxstar\Starmus\helpers\StarmusLogger::setMinLogLevel(STARMUS_LOG_LEVEL);
    }
    Starisian\Sparxstar\Starmus\helpers\StarmusLogger::log('Bootstrap', 'Composer autoloader loaded successfully.', [], 'INFO');
}

// =========================================================================
//  3. BUNDLED SCF DEPENDENCY LOADER
// =========================================================================
/**
 * Loads the bundled Secure Custom Fields plugin if ACF is not already active.
 *
 * This function checks if ACF/SCF is already loaded from another plugin.
 * If not, it loads our bundled version and configures the appropriate paths.
 * Called early on 'plugins_loaded' hook with priority 5.
 *
 * @since 0.8.5
 * @return void
 */
function starmus_load_bundled_scf(): void
{
    // If ACF/SCF is already active from another plugin, do nothing.
    if (class_exists('ACF')) {
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
}
// Load Bundled SCF early (Priority 5)
add_action('plugins_loaded', 'starmus_load_bundled_scf', 5);


// =========================================================================
//  4. ACF JSON INTEGRATION
// =========================================================================
/**
 * Configure ACF JSON save and load paths for field definitions.
 *
 * Sets up ACF to save field group configurations to our plugin's acf-json
 * directory and load field definitions from the same location. This ensures
 * field definitions are version-controlled and portable.
 *
 * @since 0.8.5
 * @return void
 */
function starmus_acf_json_integration(): void
{
    add_filter('acf/settings/save_json', fn() => STARMUS_PATH . 'acf-json');
    add_filter(
        'acf/settings/load_json',
        function ($paths) {
            // Append our path
            $paths[] = STARMUS_PATH . 'acf-json';
            return $paths;
        }
    );
}
add_action('acf/init', 'starmus_acf_json_integration');


// =========================================================================
//  5. MAIN PLUGIN INITIALIZATION
// =========================================================================

// Initialize multi-language support
require_once __DIR__ . '/src/i18n/Starmusi18NLanguage.php';
new \Starisian\Sparxstar\Starmus\i18n\Starmusi18NLanguage();

/**
 * Initialize and run the main plugin application.
 *
 * This is the main entry point for the plugin, called on 'plugins_loaded'
 * with priority 20 to ensure SCF (priority 5) is already loaded.
 * Performs dependency checks and initializes the core plugin class.
 *
 * @since 0.8.5
 * @return void
 */
function starmus_run_plugin(): void
{
    // Check if ACF class exists now that plugins_loaded(5) has fired.
    if (! class_exists('ACF')) {
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
        \Starisian\Sparxstar\Starmus\StarmusAudioRecorder::starmus_run();

        // Check if we need to flush rewrite rules (set during activation)
        if (get_transient('starmus_flush_rewrite_rules')) {
            flush_rewrite_rules();
            delete_transient('starmus_flush_rewrite_rules');
        }
    } catch (\Throwable $e) {
        error_log('Starmus Plugin Init Error: ' . $e->getMessage());
    }
}
add_action('plugins_loaded', 'starmus_run_plugin', 20);


// =========================================================================
//  6. ACTIVATION & DEACTIVATION (STRICT MODE)
// =========================================================================
/**
 * Plugin activation hook with strict dependency checking.
 *
 * Performs comprehensive checks for required dependencies including:
 * - Composer autoloader
 * - Secure Custom Fields plugin
 * - Internal cron system activation
 *
 * If any critical dependency is missing, the activation is halted with wp_die().
 * Schedules a rewrite rules flush for the next page load to ensure CPTs are registered.
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

    // 2. Force load SCF just for this check (since plugins_loaded hasn't fired for this request yet)
    starmus_load_bundled_scf();

    // 3. Verify ACF/SCF loaded
    if (! class_exists('ACF') && ! file_exists(STARMUS_PATH . 'vendor/secure-custom-fields/secure-custom-fields.php')) {
        wp_die('Starmus Error: Secure Custom Fields plugin missing from vendor folder.');
    }

    // 4. Trigger internal activation logic
    try {
        \Starisian\Sparxstar\Starmus\cron\StarmusCron::activate();
    } catch (\Throwable $e) {
        error_log('Starmus Cron Activation Error: ' . $e->getMessage());
    }

    // 5. Request a rewrite flush on the NEXT page load.
    // We do this because CPTs registered via ACF JSON are not registered
    // at this exact moment of activation. They load on 'init'.
    set_transient('starmus_flush_rewrite_rules', true, 60);
}

/**
 * Plugin deactivation hook.
 *
 * Safely deactivates the plugin by:
 * - Deactivating cron jobs
 * - Flushing rewrite rules to clean up custom post type URLs
 *
 * Catches and silently handles any errors during deactivation to prevent
 * WordPress admin from showing error messages during plugin deactivation.
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
 * Handles complete plugin removal by loading and executing the uninstall.php
 * script which contains the actual cleanup logic. This separation keeps
 * the uninstall logic organized and testable.
 *
 * @since 0.8.5
 * @return void
 * @see uninstall.php For the actual uninstall implementation
 */
function starmus_on_uninstall(): void
{
    $uninstall_file = STARMUS_PATH . 'uninstall.php';
    if (file_exists($uninstall_file)) {
        require_once $uninstall_file;
    }
}

register_activation_hook(STARMUS_MAIN_FILE, 'starmus_on_activate');
register_deactivation_hook(STARMUS_MAIN_FILE, 'starmus_on_deactivate');
register_uninstall_hook(STARMUS_MAIN_FILE, 'starmus_on_uninstall');
