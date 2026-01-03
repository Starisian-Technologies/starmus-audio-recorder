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
 * Version:           0.9.3
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Text Domain:       starmus-audio-recorder
 */

declare(strict_types=1);

use Starisian\Sparxstar\SCF\Sparxstar_SCF_Runtime;

if (!defined('ABSPATH')) {
    exit;
}

// -------------------------------------------------------------------------
// 0. FATAL ERROR CATCHER (The "Safety Net")
// -------------------------------------------------------------------------
register_shutdown_function(static function (): void {
    $error = error_get_last();
    // Only log actual fatal errors, not warnings/notices
	if(!empty($error) && is_array($error)){
    	error_log('[STARMUS FATAL] File: ' . $error['file'] . ' Line: ' . $error['line'] . ' Msg: ' . $error['message']);
	}
});

/* -------------------------------------------------------------------------
 * 1. CONSTANTS
 * ------------------------------------------------------------------------- */
define('STARMUS_VERSION', '0.9.3');
define('STARMUS_MAIN_FILE', __FILE__);
define('STARMUS_PATH', plugin_dir_path(__FILE__));
define('STARMUS_URL', plugin_dir_url(__FILE__));

// Configuration / Overrides (Define in wp-config.php to override)
if (!defined('STARMUS_LOG_LEVEL')) {
    define('STARMUS_LOG_LEVEL', 8);
}
if (!defined('STARMUS_TUS_ENDPOINT')) {
    define('STARMUS_TUS_ENDPOINT', 'https://upload.sparxstar.com/files/');
}
if (!defined('STARMUS_R2_ENDPOINT')) {
    define('STARMUS_R2_ENDPOINT', 'https://cdn.sparxstar.com/');
}
if (!defined('TUS_WEBHOOK_SECRET')) {
    define('TUS_WEBHOOK_SECRET', 'CHANGE_ME');
}

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
                add_action('admin_notices', static function() {
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
add_action('plugins_loaded', static function (): void {
    try {
        // Safe check for Runtime existence
        if (!class_exists(Sparxstar_SCF_Runtime::class)) {
            return;
        }

        // Register Source
        Sparxstar_SCF_Runtime::register_source(
            'starmus-audio-recorder',
            STARMUS_PATH . 'vendor/advanced-custom-fields/secure-custom-fields/',
            STARMUS_URL . 'vendor/advanced-custom-fields/secure-custom-fields/'
        );

        // Auto-heal activation state
        if (!Sparxstar_SCF_Runtime::is_active_on_current_site()) {
            Sparxstar_SCF_Runtime::activate_site();
        }

        // Register JSON (Syntax verified)
        add_action('sparxstar_scf/loaded', static function (): void {
            add_filter('acf/settings/load_json', static function (array $paths): array {
                $paths[] = STARMUS_PATH . 'acf-json';
                return $paths;
            });
        });

    } catch (\Throwable $e) {
        error_log('Starmus Infrastructure Failed: ' . $e->getMessage());
    }
}, 5);

// -------------------------------------------------------------------------
// 3. APP BOOT (Priority 10)
// -------------------------------------------------------------------------
add_action('plugins_loaded', static function (): void {
    try {
        // Dependency Check
        if (!class_exists('ACF') && !function_exists('acf_add_local_field_group')) {
            // Log warning but do NOT throw fatal
            error_log('Starmus Warning: SCF/ACF not loaded. Plugin features disabled.');
            return;
        }

        // Boot Main Class
        if (class_exists(\Starisian\Sparxstar\Starmus\StarmusAudioRecorder::class)) {
            $instance = \Starisian\Sparxstar\Starmus\StarmusAudioRecorder::starmus_get_instance();
            $instance::starmus_run();
        } else {
            throw new \RuntimeException('Main class StarmusAudioRecorder not found.');
        }

    } catch (\Throwable $e) {
        error_log('Starmus App Boot Failed: ' . $e->getMessage());
        // User-facing generic error (optional)
        if (is_admin()) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>Starmus Audio failed to start. Check logs.</p></div>';
            });
        }
    }
}, 10);

// -------------------------------------------------------------------------
// 4. LIFECYCLE
// -------------------------------------------------------------------------
function starmus_on_activate(){
    try {
        if (class_exists(Sparxstar_SCF_Runtime::class)) {
            Sparxstar_SCF_Runtime::activate_site();
        }
        // ... other activation logic
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
