<?php

/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * Main bootstrapper for the Starmus Audio Recorder plugin lifecycle.
 *
 * @package   Starisian\Sparxstar\Starmus
 *
 * @since     0.1.0
 *
 * @version 0.9.2
 */
namespace Starisian\Sparxstar\Starmus;

if (! \defined('ABSPATH')) {
    exit;
}

use function current_user_can;
use function is_admin;
// Admin/UI/Assets
use function load_plugin_textdomain;

use LogicException;

use function plugin_basename;

// if directly referenced (not required here)
// REST layer
use Starisian\Sparxstar\Starmus\admin\StarmusAdmin;
// Cron

// WP functions used (for clarity in static analysis)
use Starisian\Sparxstar\Starmus\api\StarmusRESTHandler;
use Starisian\Sparxstar\Starmus\core\StarmusAssetLoader;
use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;
use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\frontend\StarmusShortcodeLoader;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\includes\StarmusSubmissionHandler;
use Starisian\Sparxstar\Starmus\includes\StarmusTusdHookHandler;
use Throwable;

/**
 * Main plugin bootstrapper and singleton entry point.
 *
 * This class coordinates the initialization and lifecycle of all plugin components.
 * It implements the singleton pattern to ensure only one instance exists per request.
 *
 * Responsibilities:
 * - Initialize settings and DAL (Data Access Layer) early in the WordPress lifecycle
 * - Instantiate and wire dependent components (Admin, Assets, UI, REST)
 * - Register global WordPress hooks once
 * - Defer heavy business logic to dedicated service classes
 * - Provide plugin-wide capability constants
 * - Handle runtime error collection and display
 *
 * Usage:
 * ```php
 * // Called from main plugin file
 * StarmusAudioRecorder::starmus_run();
 *
 * // Access singleton instance
 * $plugin = StarmusAudioRecorder::starmus_get_instance();
 * ```
 *
 * @package Starisian\Sparxstar\Starmus
 *
 * @since   0.1.0
 */
final class StarmusAudioRecorder
{
    /**
     * Capability allowing users to edit uploaded audio recordings.
     *
     * Users with this capability can modify existing audio recordings,
     * update metadata, and manage recording lifecycle.
     *
     * @since 0.1.0
     *
     * @var string
     */
    public const STARMUS_CAP_EDIT_AUDIO = 'starmus_edit_audio';

    /**
     * Capability allowing users to create new audio recordings.
     *
     * Users with this capability can access the recorder interface,
     * submit new recordings, and initiate recording sessions.
     *
     * @since 0.1.0
     *
     * @var string
     */
    public const STARMUS_CAP_RECORD_AUDIO = 'starmus_record_audio';

    /**
     * Singleton instance of the plugin.
     *
     * Stores the single instance of this class to implement the singleton pattern.
     * Prevents multiple plugin initializations within the same request.
     *
     * @since 0.1.0
     *
     * @var StarmusAudioRecorder|null
     */
    private static ?StarmusAudioRecorder $instance = null;

    /**
     * Collection of runtime errors for admin display.
     *
     * Stores non-fatal errors encountered during plugin execution
     * that should be displayed to administrators as notices.
     * Errors are deduplicated before display.
     *
     * @since 0.1.0
     *
     * @var array<string, mixed>
     */
    private array $runtimeErrors = [];

    /**
     * WordPress hooks registration guard flag.
     *
     * Prevents duplicate hook registration when the same instance
     * might be accessed multiple times during a request.
     *
     * @since 0.1.0
     *
     * @var bool
     */
    private bool $hooksRegistered = false;

    /**
     * Data Access Layer instance for database operations.
     *
     * Provides abstraction layer for all plugin database interactions.
     * Supports dependency injection through filters for testing and extensibility.
     * Uses singleton pattern to ensure consistent data layer across components.
     *
     * @since 0.1.0
     *
     * @var \Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface|null
     */
    private ?core\interfaces\StarmusAudioRecorderDALInterface $DAL = null;

    /**
     * Plugin settings and configuration manager.
     *
     * Handles all plugin options, user preferences, and configuration values.
     * Must be initialized before other components that depend on settings.
     * Provides centralized access to WordPress options and plugin defaults.
     *
     * @since 0.1.0
     *
     * @var StarmusSettings|null
     */
    private ?StarmusSettings $settings = null;

    /**
     * Private constructor for singleton pattern.
     *
     * Initializes the plugin in the following order to ensure proper dependency resolution:
     * 1. Configures logger with minimum level and optional custom file path
     * 2. Sets up DAL (Data Access Layer) with singleton pattern and filter support
     * 3. Initializes settings service or throws exception on failure
     * 4. Instantiates all dependent components (Admin, Assets, REST, etc.)
     * 5. Registers global WordPress hooks
     *
     * The initialization order is critical - settings must be available before
     * component instantiation, and DAL must be ready before settings.
     *
     * @throws \RuntimeException If settings initialization fails.
     *
     * @since  0.1.0
     */
    private function __construct()
    {
        try {
            $this->set_DAL();
            $this->init_settings_or_throw();
            $this->init_components();
            $this->register_hooks();
        } catch (\Throwable $throwable) {
            error_log($throwable->getMessage());
        }
        if (($this->DAL instanceof \Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface ? $this->DAL::class : self::class) !== \Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL::class) {
            StarmusLogger::info('StarmusAudioRecorder', 'DAL initialized to: ' . ($this->DAL instanceof \Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface ? $this->DAL::class : self::class));
        }
    }

    /**
     * Get singleton instance of the plugin.
     *
     * Creates and returns the single instance of this class using lazy initialization.
     * Subsequent calls return the same instance without re-initialization.
     * This is the primary way to access the plugin instance throughout the codebase.
     *
     * @since  0.1.0
     *
     * @return StarmusAudioRecorder The singleton instance.
     */
    public static function starmus_get_instance(): StarmusAudioRecorder
    {
        if (!self::$instance instanceof \Starisian\Sparxstar\Starmus\StarmusAudioRecorder) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Entry point called from main plugin file.
     *
     * Bootstraps the plugin by ensuring the singleton instance is created and initialized.
     * This method is called by the 'plugins_loaded' WordPress action hook with priority 20
     * to ensure SCF/ACF (priority 5) is already loaded.
     *
     * This is the main entry point for the entire plugin and should only be called once.
     *
     * @since 0.1.0
     *
     * @return void
     */
    public static function starmus_run(): void
    {
        self::starmus_get_instance();
    }

    /**
     * Check whether Secure Custom Fields (SCF) or Advanced Custom Fields (ACF) is active.
     *
     * The recorder relies on one of these field frameworks to manage submission metadata
     * and custom field definitions. SCF exposes ACF-compatible global utility functions
     * such as acf_get_instance(), which serves as the authoritative presence signal.
     *
     * This check is performed before plugin initialization to ensure graceful failure
     * if the required dependency is not available.
     *
     * @since  0.1.0
     *
     * @return bool True when a supported field framework is available, false otherwise.
     */
    public static function check_field_plugin_dependency(): bool
    {
        return \function_exists('acf_get_instance');
    }

    /**
     * Ensure settings are instantiated before anything else.
     *
     * Validates that StarmusSettings class exists via autoloader, creates instance,
     * and throws exception on failure to prevent silent null returns that could
     * cause downstream issues in dependent components.
     *
     * Settings must be available before component initialization since most
     * components require configuration values during their setup.
     *
     * @since  0.1.0
     *
     * @throws \RuntimeException If StarmusSettings fails to initialize.
     *
     * @return void
     */
    private function init_settings_or_throw(): void
    {
        try {
            // Autoloader should resolve this class. Namespaced class_exists is safest.
            if (class_exists(\Starisian\Sparxstar\Starmus\core\StarmusSettings::class)) {
                $this->settings = new StarmusSettings();
                return;
            }
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
        }

        // If we reach here, settings is not available — fail loudly to avoid null downstream.
        throw new \RuntimeException('StarmusSettings failed to initialize.');
    }

    /**
     * Initialize and set the Data Access Layer (DAL) with singleton pattern and filter support.
     *
     * Applies 'starmus_register_dal' filter to allow DAL replacement.
     * Implements handshake mechanism using STARMUS_DAL_OVERRIDE_KEY for security.
     * Replacement DAL must implement StarmusAudioRecorderDALInterface and
     * return the same key from get_registration_key().
     *
     * Uses static singleton to prevent duplicate instantiation across the request.
     */
    private function set_DAL(): void
    {
        // This is the class property for this specific instance.
        if ($this->DAL instanceof \Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface) {
            return; // Already set on this object.
        }

        // --- SINGLETON FIX STARTS HERE ---
        // This is the static property that persists across the entire request.
        // If it's already been created, reuse it and stop.
        static $dal_singleton = null;

        if ($dal_singleton instanceof \Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface) {
            $this->DAL = $dal_singleton;
            return;
        }

        // --- SINGLETON FIX ENDS HERE ---

        // If we've reached here, the DAL has not been created yet in this request.
        // We will now create it.

        try {
            $default_dal = new StarmusAudioRecorderDAL();

            $override_key = \defined('STARMUS_DAL_OVERRIDE_KEY') ? STARMUS_DAL_OVERRIDE_KEY : null;
            $filtered_dal = apply_filters('starmus_register_dal', $default_dal, $override_key);
        } catch (\Throwable $throwable) {
            error_log($throwable->getMessage());
            $dal_singleton = $default_dal; // Store the default DAL in the singleton
            $this->DAL     = $dal_singleton;
            return;
        }

        // Must implement our interface.
        if (!($filtered_dal instanceof \Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface)) {
            error_log('StarmusAudioRecorder: Invalid DAL replacement - must implement StarmusAudioRecorderDALInterface');
            $dal_singleton = $default_dal; // Store the default DAL in the singleton
            $this->DAL     = $dal_singleton;
            return;
        }

        // Handshake: the replacement must present the same key we expect.
        $provided = $filtered_dal->get_registration_key();
        $expected = (string) (\defined('STARMUS_DAL_OVERRIDE_KEY') ? STARMUS_DAL_OVERRIDE_KEY : '');

        if ($expected !== '' && $provided === $expected) {
            // Handshake successful. Use the filtered DAL.
            $dal_singleton = $filtered_dal;
        } else {
            // Handshake failed or was not attempted. Use the default DAL.
            if ($filtered_dal !== $default_dal) {
                StarmusLogger::warning('StarmusAudioRecorder', 'Unauthorized or misconfigured DAL replacement attempt rejected.');
            }

            $dal_singleton = $default_dal;
        }

        // Finally, assign the one true instance to our class property.
        $this->DAL = $dal_singleton;
    }

    /**
     * Instantiate components that depend on settings and environment.
     *
     * Initializes all plugin components in the correct order:
     * - Admin interface (only in admin context)
     * - Asset loader for CSS/JS management
     * - TUSD webhook handler for upload processing
     * - REST API endpoints
     * - Shortcode loader for frontend display
     *
     * Each component receives DAL and settings dependencies through constructor injection.
     * Components are responsible for self-registering their WordPress hooks.
     *
     * @since 0.1.0
     *
     * @return void
     */
    private function init_components(): void
    {
        error_log('[Starmus] === init_components() STARTING ===');

        // global services
        //(new StarPrivateSlugPrefix())->star_boot();

        // Admin
        if (is_admin()) {
            error_log('[Starmus] Loading StarmusAdmin...');
            new StarmusAdmin($this->DAL, $this->settings);
        }

        // Assets
        error_log('[Starmus] Loading StarmusAssetLoader...');
        new StarmusAssetLoader();

        // TUSD webhook handler
        error_log('[Starmus] Loading StarmusTusdHookHandler...');
        $submission_handler = new StarmusSubmissionHandler($this->DAL, $this->settings);
        $tusd_hook_handler  = new StarmusTusdHookHandler($submission_handler);
        $tusd_hook_handler->register_hooks();

        // REST API
        error_log('[Starmus] Loading StarmusRESTHandler...');
        new StarmusRESTHandler($this->DAL, $this->settings);

        // SHORTCODES - THIS WAS MISSING!!!
        error_log('[Starmus] Loading StarmusShortcodeLoader...');
        new StarmusShortcodeLoader($this->DAL, $this->settings);

        error_log('[Starmus] === init_components() COMPLETE ===');
    }

    /**
     * Register cross-cutting WordPress hooks once.
     *
     * Handles plugin-wide hooks that don't belong to specific components:
     * - Text domain loading for internationalization
     * - WP-CLI command registration (if available)
     * - Admin error notices display
     *
     * Uses guard flag to prevent duplicate registration if called multiple times.
     * Most component-specific hooks are self-registered within component constructors.
     *
     * @since 0.1.0
     *
     * @return void
     */
    private function register_hooks(): void
    {

        if ($this->hooksRegistered) {
            return;
        }

        try {
            // Load translations on the 'init' hook.
            add_action('init', function (): void {
                load_plugin_textdomain(
                    'starmus-audio-recorder',
                    false,
                    \dirname(plugin_basename(STARMUS_MAIN_FILE)) . '/languages/'
                );
            });

            // WP-CLI commands (optional). Load your CLI files and register commands here.
            if (\defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
                $cli_path = plugin_dir_path(\STARMUS_MAIN_FILE) . 'src/cli/';
                if (file_exists($cli_path . 'StarmusCLI.php') && file_exists($cli_path . 'StarmusCacheCommand.php')) {
                    require_once $cli_path . 'StarmusCLI.php';
                    \WP_CLI::add_command('starmus', 'Starmus\\cli\\StarmusCLI');
                    \WP_CLI::add_command('starmus cache', 'Starmus\\cli\\StarmusCacheCommand');
                }
            }

            // Admin runtime error banner
            if (is_admin()) {
                add_action('admin_notices', $this->displayRuntimeErrorNotice(...));
            }

            $this->hooksRegistered = true;
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
        }
    }

    /**
     * Display collected runtime errors as admin notices.
     *
     * Shows non-fatal errors that occurred during plugin execution as dismissible
     * admin notices. Only visible to users with 'manage_options' capability.
     * Errors are deduplicated before display to prevent notice spam.
     *
     * Called via 'admin_notices' action hook in admin context only.
     *
     * @since 0.1.0
     *
     * @return void
     */
    public function displayRuntimeErrorNotice(): void
    {
        try {
            if ($this->runtimeErrors === [] || ! current_user_can('manage_options')) {
                return;
            }

            $unique = array_unique($this->runtimeErrors);
            foreach ($unique as $msg) {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Starmus Audio Recorder:</strong><br>' .
                    esc_html($msg) .
                    '</p></div>';
            }
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
        }
    }

    /**
     * Strictly prevent cloning of singleton instance.
     *
     * Ensures singleton pattern integrity by preventing object cloning,
     * which would create multiple instances and violate the singleton contract.
     *
     * @since  0.1.0
     *
     * @throws LogicException Always - cloning is not allowed.
     *
     * @return void
     */
    public function __clone()
    {
        throw new LogicException('Cloning of ' . self::class . ' is not allowed.');
    }

    /**
     * Strictly prevent unserialization of singleton instance.
     *
     * Prevents recreation of singleton instance through unserialization,
     * which would bypass the singleton pattern and constructor logic.
     *
     * @since  0.1.0
     *
     * @throws LogicException Always - unserialization is not allowed.
     *
     * @return void
     */
    public function __wakeup()
    {
        throw new LogicException('Unserializing of ' . self::class . ' is not allowed.');
    }

    /**
     * Strictly prevent serialization of singleton instance.
     *
     * Prevents singleton instance from being serialized, which could
     * lead to unserialization and violation of the singleton pattern.
     *
     * @since  0.1.0
     *
     * @throws LogicException Always - serialization is not allowed.
     *
     * @return array Never returns - always throws exception.
     */

    public function __sleep(): array
    {
        throw new LogicException('Serializing of ' . self::class . ' is not allowed.');
    }

    /**
     * Strictly prevent serialization of singleton instance (PHP 7.4+ method).
     *
     * Modern PHP serialization prevention method that works alongside __sleep().
     * Prevents singleton instance from being serialized using the newer PHP API.
     *
     * @since  0.8.5
     *
     * @throws LogicException Always - serialization is not allowed.
     *
     * @return array Never returns - always throws exception.
     */
    public function __serialize(): array
    {
        throw new LogicException('Serialization of ' . self::class . ' is not allowed.');
    }

    /**
     * Strictly prevent unserialization of singleton instance (PHP 7.4+ method).
     *
     * Modern PHP unserialization prevention method that works alongside __wakeup().
     * Prevents recreation of singleton instance through the newer PHP unserialize API.
     *
     * @since  0.8.5
     *
     * @param array<string, mixed> $data Serialized data (ignored).
     *
     * @throws LogicException Always - unserialization is not allowed.
     *
     * @return void
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Unserialization of ' . self::class . ' is not allowed.');
    }
}
