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

use Starisian\Sparxstar\install\SCF\Sparxstar_SCF_Runtime;
use Starisian\Sparxstar\Starmus\StarmusAudioRecorder;
use Starisian\Sparxstar\Starmus\cron\StarmusCron;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

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
// 2. AUTOLOAD (Priority 0)
// -------------------------------------------------------------------------
add_action('plugins_loaded', static function (): void {
    try {
        $autoloader = STARMUS_PATH . 'vendor/autoload.php';
        if (file_exists($autoloader)) {
            require_once $autoloader;
        } elseif (is_admin()) {
            add_action('admin_notices', static function() {
                echo '<div class="notice notice-error"><p>Starmus Critical: vendor/autoload.php missing. Run composer install.</p></div>';
            });
        }
    } catch (\Throwable $e) {
        error_log('Starmus Autoload Failed: ' . $e->getMessage());
        // Cannot use StarmusLogger here as autoloader might have failed
    }
}, 0);

// -------------------------------------------------------------------------
// 3. INFRASTRUCTURE & DEPENDENCY REGISTRATION (Priority 5)
// -------------------------------------------------------------------------
add_action('plugins_loaded', static function (): void {
    try {
        // 1. Check for Runtime Presence
        if (!class_exists(Sparxstar_SCF_Runtime::class)) {
            return;
        }

        // 2. Register Source
        // METHOD NAME UPDATED: sparx_scf_register_source -> sparx_scf_register_scf_source
        $vendor_path = STARMUS_PATH . 'vendor/secure-custom-fields/';
        $vendor_url  = STARMUS_URL . 'vendor/secure-custom-fields/';

        Sparxstar_SCF_Runtime::sparx_scf_register_scf_source(
            'starmus-audio',
            $vendor_path,
            $vendor_url
        );

        // 3. Register JSON (Only listens if Runtime successfully loads SCF)
        add_action('sparx_scf_loaded', static function (): void {
            add_filter('acf/settings/load_json', static function (array $paths): array {
                $paths[] = STARMUS_PATH . 'acf-json';
                return $paths;
            });
        });

    } catch (\Throwable $e) {
        StarmusLogger::log($e);
        error_log('Starmus Infrastructure Failed: ' . $e->getMessage());
    }
}, 5);

// -------------------------------------------------------------------------
// 4. APP BOOT (Priority 10)
// -------------------------------------------------------------------------
add_action('plugins_loaded', static function (): void {
    try {
        // Ensure Autoload happened
        if (!class_exists(\Starisian\Sparxstar\Starmus\StarmusAudioRecorder::class)) {
            return;
        }

        // Boot the App
        $instance = \Starisian\Sparxstar\Starmus\StarmusAudioRecorder::starmus_get_instance();
        $instance::starmus_run();

    } catch (\Throwable $e) {
        StarmusLogger::log($e);
        error_log('Starmus App Boot Failed: ' . $e->getMessage());
        if (is_admin()) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>Starmus Audio failed to start. Check error logs.</p></div>';
            });
        }
    }
}, 10);

// -------------------------------------------------------------------------
// 5. LIFECYCLE MANAGEMENT
// -------------------------------------------------------------------------

/**
 * ACTIVATION: Enforce Requirements.
 * Starmus requires SCF. If Starmus is activated, ensure SCF is physically active.
 */
function starmus_on_activate(): void
{
    try {
        if (class_exists(Sparxstar_SCF_Runtime::class)) {
            // UPDATED: sparx_scf_activate_plugin -> sparx_scf_activate_scf
            Sparxstar_SCF_Runtime::sparx_scf_activate_scf();
        }
        flush_rewrite_rules();
    } catch (\Throwable $e) {
        StarmusLogger::log($e);
        error_log('Starmus Activation Error: ' . $e->getMessage());
    }
}

/**
 * DEACTIVATION: Clean up self, leave shared infrastructure alone.
 */
function starmus_on_deactivate(): void
{
    try {
        if (class_exists(\Starisian\Sparxstar\Starmus\cron\StarmusCron::class)) {
            \Starisian\Sparxstar\Starmus\cron\StarmusCron::deactivate();
        }
        flush_rewrite_rules();

        if (class_exists(Sparxstar_SCF_Runtime::class)) {
            // UPDATED: sparx_scf_deactivate_plugin -> sparx_scf_deactivate_scf
            Sparxstar_SCF_Runtime::sparx_scf_deactivate_scf();
        }

    } catch (\Throwable $e) {
        StarmusLogger::log($e);
        error_log('Starmus Deactivation Error: ' . $e->getMessage());
    }
}

/**
 * UNINSTALL: Clean up data, leave shared infrastructure alone.
 */
function starmus_on_uninstall(): void
{
	try{
		// Optional: Remove SCF runtime state if you want to clean up completely
		if (class_exists(Sparxstar_SCF_Runtime::class)) {
			Sparxstar_SCF_Runtime::uninstall_site();
		}

		if (defined('STARMUS_DELETE_ON_UNINSTALL') && STARMUS_DELETE_ON_UNINSTALL) {
			$file = STARMUS_PATH . 'uninstall.php';
			if (file_exists($file)) {
				require_once $file;
			}
		}
            StarmusLogger::log($e);
	}catch (Throwable $e) {
			error_log('Starmus uninstall error: ' . $e->getMessage());
		}
}

register_activation_hook(__FILE__, 'starmus_on_activate');
register_deactivation_hook(__FILE__, 'starmus_on_deactivate');
register_uninstall_hook(__FILE__, 'starmus_on_uninstall');
