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
 * @package           Starisian\Sparxstar\Starmus
 * @author            Starisian Technologies
 * @copyright         2023-2026 Starisian Technologies
 * @license           Starisian Technologies Proprietary
 *
 * @wordpress-plugin
 * Plugin Name:       Starmus Audio Recorder
 * Plugin URI:        https://starisian.com
 * Description:       Mobile-friendly audio recorder optimized for emerging markets.
 * Version:           0.9.2
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Text Domain:       starmus-audio-recorder
 * Domain Path:       /languages
 * Author:            Starisian Technologies
 * Author URI:        https://starisian.com
 * Update URI:        https://starism.com/sparxstar/starmus-audio-recorder/update
 */

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus;

if ( ! defined('ABSPATH')) {
    exit;
}

define('STARMUS_VERSION', '0.9.2');
define('STARMUS_MAIN_FILE', __FILE__);
define('STARMUS_PATH', plugin_dir_path(STARMUS_MAIN_FILE));
define('STARMUS_URL', plugin_dir_url(STARMUS_MAIN_FILE));
define('STARMUS_PLUGIN_PREFIX', 'starmus');
define('STARMUS_PLUGIN_DIR', plugin_dir_path(STARMUS_MAIN_FILE));
define('STARMUS_VENDOR_DIR', STARMUS_PATH . 'vendor/');

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
    define('STARMUS_REST_NAMESPACE', 'star-starmus-audio-recorder/v1');
}
if ( ! defined('STARMUS_DELETE_ON_UNINSTALL')) {
    define('STARMUS_DELETE_ON_UNINSTALL', false);
}

/**
 * Check for headers sent on init to prevent "headers already sent" errors later.
 *
 * @since 0.9.0
 */
add_action('init', function () {
    if (headers_sent($file, $line)) {
        error_log("HEADERS SENT from $file:$line");
    }
});

// -------------------------------------------------------------------------
// 2. COMPOSER AUTOLOAD (Deferred to Class)
// -------------------------------------------------------------------------

/**
 * Main Plugin Bootstrapper Class.
 *
 * Implements the Singleton pattern to ensure only one instance of the
 * initialization logic runs. Handles environment checks, SCF bootstrapping,
 * and hooking the orchestrator into WordPress.
 *
 * @since 0.9.0
 */
final class Starmus_Audio_Recorder
{
    /**
     * Plugin Version.
     *
     * @var string
     */
    private const VERSION              = STARMUS_VERSION;

    /**
     * Minimum PHP Requirement.
     *
     * @var string
     */
    private const MINIMUM_PHP_VERSION  = '8.2';

    /**
     * Minimum WordPress Requirement.
     *
     * @var string
     */
    private const MINIMUM_WP_VERSION   = '6.8';

    /**
     * Flag indicating if environment requirements are met.
     *
     * @var bool
     */
    private bool $requirements_met  = false;

    /**
     * Flag indicating if the autoloader has been explicitly loaded by the class.
     *
     * @var bool
     */
    private bool $autoloader_loaded = false;

    /**
     * Singleton Instance.
     *
     * @var Starmus_Audio_Recorder|null
     */
    private static ?Starmus_Audio_Recorder $instance = null;

    /**
     * Retrieve the singleton instance.
     *
     * @since 0.9.0
     * @return Starmus_Audio_Recorder The singleton instance.
     */
    public static function starmusGetInstance(): Starmus_Audio_Recorder
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private Constructor.
     *
     * Runs immediate checks and bootstrapping logic.
     *
     * @since 0.9.0
     */
    private function __construct()
    {
        $this->starmusCheckRequirements();
        $this->starmusBootSCF();
        $this->starmusConfigureSCF();
        $this->starmusRegisterHooks();
    }

    /**
     * Register core plugin hooks.
     *
     * Defers the main orchestrator boot to 'plugins_loaded'.
     *
     * @since 0.9.0
     * @return void
     */
    private function starmusRegisterHooks(): void
    {
        // Defer orchestrator boot to plugins_loaded if requirements met.
        if ($this->requirements_met) {
            add_action('plugins_loaded', [$this, 'starmusRun']);
        }

        // Register fatal error catcher
        register_shutdown_function([$this, 'starmusHandleShutdown']);
    }

    /**
     * Verify PHP and WordPress version requirements.
     *
     * Sets the internal $requirements_met flag.
     *
     * @since 0.9.0
     * @return void
     */
    private function starmusCheckRequirements(): void
    {
        global $wp_version;

        $php_ok = version_compare(PHP_VERSION, self::MINIMUM_PHP_VERSION, '>=');
        $wp_ok  = isset($wp_version) && version_compare($wp_version, self::MINIMUM_WP_VERSION, '>=');

        if ($php_ok && $wp_ok) {
            $this->requirements_met = true;
            return;
        }

        $this->requirements_met = false;
        $this->starmusHandleRequirementsFailed($php_ok, $wp_ok);
    }

    /**
     * Display admin notices if requirements are not met.
     *
     * @since 0.9.0
     * @param bool $php_ok Whether PHP version requirement is met.
     * @param bool $wp_ok Whether WP version requirement is met.
     * @return void
     */
    private function starmusHandleRequirementsFailed(bool $php_ok, bool $wp_ok): void
    {
        $messages = [];

        if ( ! $php_ok) {
            $messages[] = sprintf(
                /* translators: %s: Required PHP version */
                __('SPARXSTAR Starmus requires PHP version %s or higher.', 'starmus-audio-recorder'),
                self::MINIMUM_PHP_VERSION
            );
        }

        if ( ! $wp_ok) {
            $messages[] = sprintf(
                /* translators: %s: Required WordPress version */
                __('SPARXSTAR Starmus requires WordPress version %s or higher.', 'starmus-audio-recorder'),
                self::MINIMUM_WP_VERSION
            );
        }

        if ( ! empty($messages)) {
            add_action(
                'admin_notices',
                function () use ($messages): void {
                    printf(
                        '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
                        esc_html__('SPARXSTAR Starmus:', 'starmus-audio-recorder'),
                        wp_kses_post(implode(' ', $messages))
                    );
                }
            );
        }
    }

    /**
     * Handle shutdown to catch fatal errors.
     *
     * @since 0.9.0
     * @return void
     */
    public function starmusHandleShutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            error_log('[STARMUS FATAL] ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        }
    }

    /**
     * Boot the bundled Secure Custom Fields (SCF) plugin if not already loaded.
     *
     * Checks for the existence of the ACF class to avoid conflicts.
     *
     * @since 0.9.0
     * @return void
     */
    private function starmusBootSCF(): void
    {
        $scfFile = STARMUS_PATH . 'vendor/secure-custom-fields/secure-custom-fields.php';

        // -------------------------------------------------------------------------
        // 3. BUNDLE & BOOT SCF (If Not Loaded)
        // -------------------------------------------------------------------------
        if ( ! class_exists('ACF')) {
            error_log('Starmus Info: Booting bundled Secure Custom Fields plugin.');
            // Define path and URL to the bundled Secure Custom Fields plugin

            if (file_exists($scfFile)) {

                // 5. Load the Plugin
                require_once $scfFile;

                // 3. (Optional) Hide the SCF admin menu
                //add_filter('acf/settings/show_admin', '__return_true', 100);

                // 4. (Optional) Hide Updates
                //add_filter('acf/settings/show_updates', '__return_false', 100);

            } else {
                error_log('Starmus Error: Bundled SCF not found at ' . $scfFile);
            }
        }
    }

    /**
     * Configure Secure Custom Fields settings.
     *
     * Injects the local JSON save/load path for ACF/SCF.
     *
     * @since 0.9.0
     * @return void
     */
    private function starmusConfigureSCF(): void
    {
        $acfJSONPath = STARMUS_PATH . 'acf-json';
        if (class_exists('ACF') && file_exists($acfJSONPath)) {
            error_log('Starmus Info: Secure Custom Fields plugin loaded successfully.');
            // Configure ACF to use local JSON path
            add_filter(
                'acf/settings/load_json',
                function ($paths) {
                    // Append our custom path
                    $paths[] = STARMUS_PATH . 'acf-json';
                    return $paths;
                }
            );
        } else {
            error_log('Starmus Error: Secure Custom Fields plugin failed to load.');
        }
    }

    /**
     * Main execution entry point.
     *
     * Instantiates the core application orchestrator.
     *
     * @since 0.9.0
     * @return void
     */
    public function starmusRun(): void
    {
        if ( ! $this->requirements_met) {
            return;
        }

        if ( ! $this->starmusLoadAutoloader()) {
            return;
        }

        if ( ! class_exists(\Starisian\Sparxstar\Starmus\StarmusAudioRecorder::class)) {
            $this->starmusHandleOrchestratorNotFound();
            return;
        }

        try {
            // Boot the App Instance
            \Starisian\Sparxstar\Starmus\StarmusAudioRecorder::starmus_run();
        } catch (\Throwable $e) {
            error_log('SPARXSTAR Starmus Audio Recorder Boot Failed: ' . $e->getMessage());
        }
    }

    /**
     * Load the Composer Autoloader if not already loaded.
     *
     * @since 0.9.0
     * @return bool True if autoloader is present and loaded, false otherwise.
     */
    private function starmusLoadAutoloader(): bool
    {
        if ($this->autoloader_loaded) {
            return true;
        }

        $autoloader = STARMUS_VENDOR_DIR . 'autoload.php';

        if ( ! file_exists($autoloader)) {
            add_action(
                'admin_notices',
                function (): void {
                    printf(
                        '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
                        esc_html__('SPARXSTAR Starmus Audio Recorder:', 'starmus-audio-recorder'),
                        esc_html__('Composer autoloader not found. Please run "composer install".', 'starmus-audio-recorder')
                    );
                }
            );

            return false;
        }

        require_once $autoloader;
        $this->autoloader_loaded = true;

        return true;
    }

    /**
     * Handle the failure case where the main orchestrator class is missing.
     *
     * @since 0.9.0
     * @return void
     */
    private function starmusHandleOrchestratorNotFound(): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG && ( ! defined('WP_ENVIRONMENT_TYPE') || wp_get_environment_type() !== 'production')) {
            error_log('SPARXSTAR Starmus Audio Recorder: Main orchestrator class not found.');
        }

        add_action(
            'admin_notices',
            function (): void {
                printf(
                    '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
                    esc_html__('SPARXSTAR Starmus:', 'starmus-audio-recorder'),
                    esc_html__('Failed to load main orchestrator class.', 'starmus-audio-recorder')
                );
            }
        );
    }

    /**
     * Plugin Activation Hook.
     *
     * Triggers post type registration and cron scheduling.
     *
     * @since 0.9.0
     * @return void
     */
    public static function starmusOnActivate(): void
    {
        try {
            if (class_exists(\Starisian\Sparxstar\Starmus\core\StarmusPostTypeLoader::class)) {
                \Starisian\Sparxstar\Starmus\core\StarmusPostTypeLoader::sparxStarmusGetInstance();
            }
            if (class_exists(\Starisian\Sparxstar\Starmus\cron\StarmusCron::class)) {
                \Starisian\Sparxstar\Starmus\cron\StarmusCron::starmus_activate();
            }
        } catch (\Throwable $e) {
            error_log('Starmus Activation Error: ' . $e->getMessage());
        }
        flush_rewrite_rules();
    }

    /**
     * Plugin Deactivation Hook.
     *
     * Cleans up scheduled cron jobs.
     *
     * @since 0.9.0
     * @return void
     */
    public static function starmusOnDeactivate(): void
    {
        try {
            if (class_exists(\Starisian\Sparxstar\Starmus\cron\StarmusCron::class)) {
                \Starisian\Sparxstar\Starmus\cron\StarmusCron::starmus_deactivate();
            }
        } catch (\Throwable $e) {
            error_log('Starmus Deactivation Error: ' . $e->getMessage());
        }
        flush_rewrite_rules();
    }

    /**
     * Plugin Uninstall Hook.
     *
     * Handled by uninstall.php, but this method exists for potential manual invocation.
     *
     * @since 0.9.0
     * @return void
     */
    public static function starmusOnUninstall(): void
    {
        try {
            $file = STARMUS_PATH . 'uninstall.php';
            if (file_exists($file)) {
                require_once $file;
            }
        } catch (\Throwable $e) {
            error_log('Starmus uninstall error: ' . $e->getMessage());
        }
    }
}

register_activation_hook(__FILE__, [Starmus_Audio_Recorder::class, 'starmusOnActivate']);
register_deactivation_hook(__FILE__, [Starmus_Audio_Recorder::class, 'starmusOnDeactivate']);
register_uninstall_hook(__FILE__, [Starmus_Audio_Recorder::class, 'starmusOnUninstall']);

// Initialize the plugin
Starmus_Audio_Recorder::starmusGetInstance();
