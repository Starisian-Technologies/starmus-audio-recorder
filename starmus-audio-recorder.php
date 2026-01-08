<?php

/**
 * SPARXSTAR Starmus Audio
 *
 * Production bootloader for the Starmus Audio Recorder plugin.
 *
 * Responsibilities:
 * - Load Composer autoloader
 * - Initialize logging
 * - Gate plugin execution on SparxStar SCF Runtime state
 * - Boot core Starmus services
 *
 * Non-Responsibilities:
 * - SCF lifecycle or version management
 * - Schema installation or registration (JSON is installer)
 * - ACF / SCF internals probing
 *
 * @package   Starisian\Sparxstar\Starmus
 * @author    Starisian Technologies
 * @license   Starisian Technologies Proprietary
 * @version   0.9.3
 *
 * Plugin Name:       Starmus Audio Recorder
 * Description:       Mobile-friendly audio recorder for optimized for emerging markets.
 * Version:           0.9.2
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Text Domain:       starmus-audio-recorder
 * Update URI:        https://starism.com/sparxstar/starmus-audio-recorder/update
 * GitHub Plugin URI:  Starisian-Technologies/starmus-audio-recorder
 * Domain Path:       /languages
 * Requires Plugins:  secure-custom-fields/secure-custom-fields, Starisian-Technologies/sparxstar-secure-custom-fields-runtime
 *
 * SPARXSTAR-SCF-Runtime MU-Plugin available at:
 * https://github.com/Starisian-Technologies/sparxstar-secure-custom-fields-runtime
 *
 *  Copyright (c) 2023-2024 Starisian Technologies (https://starisian.com)
 *
 * create a secret key using openssl:
 *
 * Set the tus key to use for webhook validation:
 * tusd \
 *  -hooks-http "https://contribute.sparxstar.com/wp-json/starmus/v1/hook" \
 *  -hooks-http-header "x-starmus-secret: Y84d34624286938554e5e19d9fafe9f5da3562c4d1d443e02c186f8e44019406e" \
 *  -hooks-enabled-events "post-finish"
 *
 * define( 'STARMUS_TUS_WEBHOOK_SECRET', 'YOUR_SECRET_STRING' );
 */

declare(strict_types=1);

use Throwable;
use ACF;
use function register_shutdown_function;
use function register_activation_hook;
use function register_deactivation_hook;
use function register_uninstall_hook;
use function add_action;
use function file_exists;
use function class_exists;
use function error_log;
use function defined;
use function define;
use function is_admin;
use function plugin_dir_path;
use function plugin_dir_url;
use function flush_rewrite_rules;
use function in_array;
use function add_filter;
use function include_json;

if ( ! defined('ABSPATH')) {
    exit;
}


// -------------------------------------------------------------------------
// 0. FATAL ERROR CATCHER (The "Safety Net")
// -------------------------------------------------------------------------
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[STARMUS FATAL] ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
    }
});

/* -------------------------------------------------------------------------
 * 1. CONSTANTS
 * ------------------------------------------------------------------------- */
define('STARMUS_VERSION', '0.9.2');
define('STARMUS_MAIN_FILE', __FILE__);
define('STARMUS_PATH', plugin_dir_path(STARMUS_MAIN_FILE));
define('STARMUS_URL', plugin_dir_url(STARMUS_MAIN_FILE));
define('STARMUS_PLUGIN_PREFIX', 'starmus');
define('STARMUS_PLUGIN_DIR', plugin_dir_path(STARMUS_MAIN_FILE));

if ( ! defined('STARMUS_LOG_LEVEL')) {
    define('STARMUS_LOG_LEVEL', 8);
}
if ( ! defined('STARMUS_TUS_ENDPOINT')) {
    define('STARMUS_TUS_ENDPOINT', 'https://upload.sparxstar.com/files/');
}
if ( ! defined('STARMUS_R2_ENDPOINT')) {
    define('STARMUS_R2_ENDPOINT', 'https://cdn.sparxstar.com/');
}
if ( ! defined('STARMUS_TUS_WEBHOOK_SECRET')) {
    define('STARMUS_TUS_WEBHOOK_SECRET', '84d34624286938554e5e19d9fafe9f5da3562c4d1d443e02c186f8e44019406e');
}
if ( ! defined('STARMUS_REST_NAMESPACE')) {
    define('STARMUS_REST_NAMESPACE', 'starmus/v1');
}
if ( ! defined('STARMUS_DELETE_ON_UNINSTALL')) {
    define('STARMUS_DELETE_ON_UNINSTALL', false);
}


// -------------------------------------------------------------------------
// 2. COMPOSER AUTOLOAD (Immediate)
// -------------------------------------------------------------------------
$starmus_autoloader = STARMUS_PATH . 'vendor/autoload.php';

if (file_exists($starmus_autoloader)) {
    error_log('Starmus Info: Loading Composer autoloader.');
    require_once $starmus_autoloader;
} else {
    // Graceful fail in Admin if Composer not run
    if (is_admin() && ! defined('DOING_AJAX')) {
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-error"><p>Starmus Critical: vendor/autoload.php missing. Run composer install.</p></div>';
        });
    }
    // Stop execution to prevent fatals further down
    return;
}

// -------------------------------------------------------------------------
// 3. SECURE CUSTOM FIELDS BOOTSTRAP (Immediate)
// -------------------------------------------------------------------------
// Based on official SCF Composer documentation.
// We check !class_exists('ACF') to ensure we don't crash if the standard plugin is active.

if(! class_exists('ACF'))  {
	  error_log('Starmus Info: Booting bundled Secure Custom Fields plugin.');
    // Define path and URL to the bundled Secure Custom Fields plugin
	if(! is_dir(STARMUS_PATH . 'vendor/secure-custom-fields/')) {
		error_log('Starmus Error: Bundled SCF directory not found at ' . STARMUS_PATH . 'vendor/secure-custom-fields/');
		return;
	}
    // Uses 'vendor/secure-custom-fields/' per your composer.json "installer-paths"
    if ( ! defined('SPARXSTAR_SCF_PATH')) {
        define('SPARXSTAR_SCF_PATH', STARMUS_PATH . 'vendor/secure-custom-fields/');
    }
    if ( ! defined('SPARXSTAR_SCF_URL')) {
        define('SPARXSTAR_SCF_URL', STARMUS_URL . 'vendor/secure-custom-fields/');
    }

    if (file_exists(SPARXSTAR_SCF_PATH . 'secure-custom-fields.php')) {

        // 5. Load the Plugin
        require_once SPARXSTAR_SCF_PATH . 'secure-custom-fields.php';

        // 3. (Optional) Hide the SCF admin menu
        //add_filter('acf/settings/show_admin', '__return_true', 100);

        // 4. (Optional) Hide Updates
        //add_filter('acf/settings/show_updates', '__return_false', 100);


    } else {
        error_log('Starmus Error: Bundled SCF not found at ' . SPARXSTAR_SCF_PATH);
    }
}
if (file_exists(STARMUS_PATH . '/acf-json') && is_dir(STARMUS_PATH . '/acf-json')) {
    error_log('Starmus Info: Secure Custom Fields plugin loaded successfully.');
    // -------------------------------------------------------------------------
    // 4. JSON CONFIGURATION (Install CPTs/Fields)
    // -------------------------------------------------------------------------
    try{
			add_filter(
			'acf/settings/load_json',
			function ($paths) {
				// Append our custom path
				$paths[] = STARMUS_PATH . '/acf-json';
				return $paths;
			});

        error_log('Starmus Info: ACF JSON configuration path added: ' . STARMUS_PATH . '/acf-json');
    } catch (\Throwable $e) {
        error_log('Starmus Error: Failed to add ACF JSON configuration path: ' . $e->getMessage());
    }

} else {
    error_log('Starmus Error: Secure Custom Fields plugin failed to load.');
}



// -------------------------------------------------------------------------
// 5. APP BOOT (Plugins Loaded)
// -------------------------------------------------------------------------
add_action('plugins_loaded', static function (): void {
    try {
        if ( ! class_exists(\Starisian\Sparxstar\Starmus\StarmusAudioRecorder::class)) {
            return;
        }
        error_log('Starmus Info: Initializing Starmus Audio Recorder plugin.');
        // Boot the App Instance
        \Starisian\Sparxstar\Starmus\StarmusAudioRecorder::starmus_get_instance();
    } catch (\Throwable $e) {
        error_log('Starmus App Boot Failed: ' . $e->getMessage());
    }
}, );

// -------------------------------------------------------------------------
// 6. LIFECYCLE MANAGEMENT
// -------------------------------------------------------------------------

/**
 * ACTIVATION
 */
function starmus_on_activate(): void
{
    try {
        if (class_exists(\Starisian\Sparxstar\Starmus\cron\StarmusCron::class)) {
            \Starisian\Sparxstar\Starmus\cron\StarmusCron::activate();
        }
        flush_rewrite_rules();
    } catch (\Throwable $e) {
        error_log('Starmus Activation Error: ' . $e->getMessage());
    }
}

/**
 * DEACTIVATION
 */
function starmus_on_deactivate(): void
{
    try {
        if (class_exists(\Starisian\Sparxstar\Starmus\cron\StarmusCron::class)) {
            \Starisian\Sparxstar\Starmus\cron\StarmusCron::deactivate();
        }
        flush_rewrite_rules();
    } catch (\Throwable $e) {
        error_log('Starmus Deactivation Error: ' . $e->getMessage());
    }
}

/**
 * UNINSTALL
 */
function starmus_on_uninstall(): void
{
    try {

        if (defined('STARMUS_DELETE_ON_UNINSTALL') && STARMUS_DELETE_ON_UNINSTALL && WP_UNINSTALL_PLUGIN) {
            $file = STARMUS_PATH . 'uninstall.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    } catch (\Throwable $e) {
        error_log('Starmus uninstall error: ' . $e->getMessage());
    }
}

register_activation_hook(__FILE__, 'starmus_on_activate');
register_deactivation_hook(__FILE__, 'starmus_on_deactivate');
register_uninstall_hook(__FILE__, 'starmus_on_uninstall');
