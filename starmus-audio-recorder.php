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
 *  -hooks-http "https://contribute.sparxstar.com/wp-json/starmus/v1/hook" \
 *  -hooks-http-header "x-starmus-secret: Y84d34624286938554e5e19d9fafe9f5da3562c4d1d443e02c186f8e44019406e" \
 *  -hooks-enabled-events "post-finish"
 *
 * define( 'STARMUS_TUS_WEBHOOK_SECRET', 'YOUR_SECRET_STRING' );
 */

declare(strict_types=1);

use Starisian\Sparxstar\SCF\Sparxstar_SCF_Runtime;

if ( ! defined('ABSPATH')) {
    exit;
}

// -------------------------------------------------------------------------
// 0. FATAL ERROR CATCHER (The "Safety Net")
// -------------------------------------------------------------------------
register_shutdown_function(static function (): void {
    $error = error_get_last();
    // Only log actual fatal errors, not warnings/notices
    if ( ! empty($error) && is_array($error)) {
        error_log('[STARMUS FATAL] File: ' . $error['file'] . ' Line: ' . $error['line'] . ' Msg: ' . $error['message']);
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
// Remove duplicate defines - already defined above

// -------------------------------------------------------------------------
// 1. AUTOLOAD (Priority 0)
// -------------------------------------------------------------------------
add_action('plugins_loaded', static function (): void {
    try {
        $autoloader = STARMUS_PATH . 'vendor/autoload.php';
        if (file_exists($autoloader)) {
            require_once $autoloader;
        } else {
            // Only alert admins, don't crash users
            if (is_admin()) {
                add_action('admin_notices', static function () {
                    echo '<div class="notice notice-error"><p>Starmus Error: vendor/autoload.php missing.</p></div>';
                });
            }
        }
    } catch (\Throwable $e) {
        error_log('Starmus Autoload Failed: ' . $e->getMessage());
    }
}, 0);

// -------------------------------------------------------------------------
// 2. INFRASTRUCTURE (Priority 5)
// -------------------------------------------------------------------------

function starmus_boot_plugin(): void
{
    error_log('Starmus Boot Started.');
    try {

        // 2. Load i18n
        if (class_exists(\Starisian\Sparxstar\Starmus\i18n\Starmusi18NLanguage::class)) {
            error_log('Starmus i18n Load Started.');
            $language = new \Starisian\Sparxstar\Starmus\i18n\Starmusi18NLanguage();
        }

        // 3. Boot Orchestrator
        if (class_exists(\Starisian\Sparxstar\Starmus\StarmusAudioRecorder::class)) {
            error_log('Starmus Core Boot Started.');
            $starmus = \Starisian\Sparxstar\Starmus\StarmusAudioRecorder::starmus_get_instance();
            \Starisian\Sparxstar\Starmus\StarmusAudioRecorder::starmus_run();
        } else {
            throw new RuntimeException('StarmusAudioRecorder class missing.');
        }
        error_log('Starmus Boot Completed.');
        // 4. Cleanup
        if (get_transient('starmus_flush_rewrite_rules')) {
            flush_rewrite_rules();
            delete_transient('starmus_flush_rewrite_rules');
        }
    } catch (\Throwable $e) {
        if (class_exists('StarmusLogger')) {
            \StarmusLogger::log($e);
        }
        error_log('Starmus Boot Error: ' . $e->getMessage());
    }
}
// Run at Priority 10 (Standard)
add_action('plugins_loaded', 'starmus_boot_plugin', 10);


// -------------------------------------------------------------------------
// 4. LIFECYCLE
// -------------------------------------------------------------------------

function starmus_on_activate(): void
{
    error_log('Starmus Activation Started.');
    try {
        // Delegate checks to Dependencies Class
        if (class_exists('\Starisian\Sparxstar\Starmus\helpers\StarmusDependencies')) {
            \Starisian\Sparxstar\Starmus\helpers\StarmusDependencies::bootstrap_scf();
        }

        if (class_exists('\Starisian\Sparxstar\Starmus\cron\StarmusCron')) {
            \Starisian\Sparxstar\Starmus\cron\StarmusCron::activate();
        }

        if (class_exists('Sparxstar_SCF_Runtime')) {
            Sparxstar_SCF_Runtime::activate_site();
        }

        set_transient('starmus_flush_rewrite_rules', true, 60);
    } catch (\Throwable $e) {
        error_log('Starmus Activation Error: ' . $e->getMessage());
    }
}


function starmus_on_deactivate(): void
{
    try {
        if (class_exists(\Starisian\Sparxstar\Starmus\cron\StarmusCron::class)) {
            \Starisian\Sparxstar\Starmus\cron\StarmusCron::deactivate();
        }
        flush_rewrite_rules();

        /**
         * NOTE: We do NOT deactivate the SCF Runtime here.
         * Other plugins might still need it. We only remove our Cron/Rules.
         */
    } catch (Throwable $e) {
        error_log('Starmus deactivation error: ' . $e->getMessage());
    }
}

function starmus_on_uninstall(): void
{
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
}

register_activation_hook(__FILE__, 'starmus_on_activate');
register_deactivation_hook(__FILE__, 'starmus_on_deactivate');
register_uninstall_hook(__FILE__, 'starmus_on_uninstall');

// -------------------------------------------------------------------------
// END OF FILE
// -------------------------------------------------------------------------
