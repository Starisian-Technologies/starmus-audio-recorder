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

// --- Logger Configuration ---
if (! defined('STARMUS_LOG_LEVEL')) {
    define('STARMUS_LOG_LEVEL', 'Warning');
}
if (! defined('STARMUS_LOG_FILE')) {
    define('STARMUS_LOG_FILE', '');
}
if (! defined('STARMUS_DELETE_ON_UNINSTALL')) {
    define('STARMUS_DELETE_ON_UNINSTALL', false);
}

// =========================================================================
//  2. COMPOSER AUTOLOADER
// =========================================================================
$autoloader = STARMUS_PATH . 'vendor/autoload.php';

if (! file_exists($autoloader)) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>Starmus Audio Recorder Error:</strong> missing <code>vendor/autoload.php</code>. Please run <code>composer install</code>.</p></div>';
    });
    // Do not return here; let the activation hook handle the hard stop if this is an activation attempt.
} else {
    require_once $autoloader;
}

// =========================================================================
//  3. BUNDLED SCF DEPENDENCY LOADER
// =========================================================================
/**
 * Loads the bundled Secure Custom Fields plugin.
 */
function starmus_load_bundled_scf(): void
{
    // If ACF/SCF is already active from another plugin, do nothing.
    if (class_exists('ACF')) {
        return;
    }

    $scf_main_file = STARMUS_PATH . 'vendor/secure-custom-fields/secure-custom-fields.php';

    if (file_exists($scf_main_file)) {
        if (!defined('STARMUS_ACF_PATH')) {
            define('STARMUS_ACF_PATH', STARMUS_PATH . 'vendor/secure-custom-fields/');
        }
        if (!defined('STARMUS_ACF_URL')) {
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
function starmus_acf_json_integration(): void
{
    add_filter('acf/settings/save_json', fn() => STARMUS_PATH . 'acf-json');
    add_filter('acf/settings/load_json', function ($paths) {
        // Remove original path (optional)
        // unset($paths[0]); 
        
        // Append our path
        $paths[] = STARMUS_PATH . 'acf-json';
        return $paths;
    });
}
add_action('acf/init', 'starmus_acf_json_integration');


// =========================================================================
//  5. MAIN PLUGIN INITIALIZATION
// =========================================================================
/**
 * Run the Main App. 
 * Priority 20 ensures SCF (Priority 5) is loaded.
 */
function starmus_run_plugin(): void
{
    // Check if ACF class exists now that plugins_loaded(5) has fired.
    if (! class_exists('ACF')) {
        // Only show error if we are NOT in the middle of activating/deactivating
        if (!isset($_GET['activate'])) {
             add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>Starmus Error:</strong> Secure Custom Fields failed to load.</p></div>';
            });
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
 * Strict Activation Hook.
 * If dependencies are missing, we KILL the activation process with wp_die().
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
 * Deactivation Hook
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
 * Uninstall Hook
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