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
 * @version 0.8.5
 */

namespace Starisian\Sparxstar\Starmus;

if (! \defined('ABSPATH')) {
    exit;
}

use function current_user_can;
use function is_admin;
// Core + services you actually use here
use function load_plugin_textdomain;

use LogicException;

use function plugin_basename;

use Starisian\Sparxstar\Starmus\admin\StarmusAdmin;
use Starisian\Sparxstar\Starmus\api\StarmusRESTHandler;
// Admin/UI/Assets
use Starisian\Sparxstar\Starmus\core\StarmusAssetLoader;
use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;
use Starisian\Sparxstar\Starmus\core\StarmusSettings;
// if directly referenced (not required here)
// if directly referenced (not required here)

// REST layer
use Starisian\Sparxstar\Starmus\frontend\StarmusShortcodeLoader;
// Cron

// WP functions used (for clarity in static analysis)
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\includes\StarmusSubmissionHandler;
use Starisian\Sparxstar\Starmus\includes\StarmusTusdHookHandler;
use Throwable;
use RuntimeException;

/**
 * Main plugin bootstrapper.
 *
 * Responsibilities:
 * - Initialize settings early.
 * - Instantiate/wire dependent components (Admin, Assets, UI, REST).
 * - Register global hooks once.
 * - Defer heavy logic to dedicated classes.
 */
final class StarmusAudioRecorder
{
    /** Capability allowing users to edit uploaded audio. */
    public const STARMUS_CAP_EDIT_AUDIO = 'starmus_edit_audio';

    /** Capability allowing users to create new recordings. */
    public const STARMUS_CAP_RECORD_AUDIO = 'starmus_record_audio';

    /** Singleton instance. */
    private static ?StarmusAudioRecorder $instance = null;

    /** Collected runtime errors for admin notice. */
    /** @var array<string, mixed> */
    private array $runtimeErrors = [];

    /** Whether we've registered WordPress hooks (guard). */
    private bool $hooksRegistered = false;

    /**
     * Data Access Layer instance.
     *
     * @var \Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface|null
     */
    private ?core\interfaces\StarmusAudioRecorderDALInterface $DAL = null;

    /**
     * Settings service (must be ready before other deps).
     *
     * @var StarmusSettings|null
     */
    private ?StarmusSettings $settings = null;

    /**
     * Private constructor for singleton pattern.
     *
     * Initializes the plugin in the following order:
     * 1. Configures logger (minimum level and file path)
     * 2. Sets up DAL (Data Access Layer) instance
     * 3. Initializes settings or throws exception
     * 4. Instantiates component dependencies
     * 5. Registers WordPress hooks
     *
     * @throws \RuntimeException If settings initialization fails.
     *
     * @return void
     */
    private function __construct()
    {
        // Example: Only log messages of WARNING level or higher
        StarmusLogger::setMinLogLevel(STARMUS_LOG_LEVEL);
        if (STARMUS_LOG_FILE) {
            // Example: Log to a specific file (overrides the default daily file in uploads)
            StarmusLogger::setLogFilePath(ABSPATH . STARMUS_LOG_FILE);
        }

        $this->set_DAL();
        $this->init_settings_or_throw();
        $this->init_components();
        $this->register_hooks();

        if (($this->DAL instanceof \Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface ? $this->DAL::class : self::class) !== \Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL::class) {
            StarmusLogger::info('StarmusAudioRecorder', 'DAL initialized to: ' . ($this->DAL instanceof \Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface ? $this->DAL::class : self::class));
        }
    }

    /**
     * Get singleton instance of the plugin.
     *
     * Creates and returns the single instance of this class.
     * Subsequent calls return the same instance.
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
     * Bootstraps the plugin by ensuring singleton instance is created.
     * This is the method called by the 'plugins_loaded' action hook.
     */
    public static function starmus_run(): void
    {
        self::starmus_get_instance();
    }

    /**
     * Check whether Secure Custom Fields (SCF) or Advanced Custom Fields (ACF) is active.
     *
     * The recorder relies on one of these field frameworks to manage submission metadata.
     * SCF exposes ACF-style global utility functions such as acf_get_instance().
     * This is the authoritative presence signal.
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
     * Validates that StarmusSettings class exists via autoloader,
     * creates instance, and throws exception on failure to prevent
     * silent null returns that could cause downstream issues.
     *
     * @throws \RuntimeException If StarmusSettings fails to initialize.
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
            StarmusLogger::error('StarmusAudioRecorder', $throwable, ['context' => 'Settings Init']);
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
            StarmusLogger::error('StarmusAudioRecorder', $throwable, ['context' => 'DAL filter']);
            $dal_singleton = $default_dal; // Store the default DAL in the singleton
            $this->DAL     = $dal_singleton;
            return;
        }

        // Must implement our interface.
        if (!($filtered_dal instanceof \Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface)) {
            StarmusLogger::error('StarmusAudioRecorder', 'Invalid DAL replacement: must implement StarmusAudioRecorderDALInterface.');
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
     * Register cross-cutting hooks once.
     * Note: most components self-register inside their constructors.
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
                $cli_path = \STARMUS_PLUGIN_DIR . 'src/cli/';
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
            StarmusLogger::error('StarmusAudioRecorder', $throwable, ['context' => 'register_hooks']);
        }
    }

    /**
     * Admin notice for collected runtime errors.
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
            StarmusLogger::error('StarmusAudioRecorder', $throwable, ['context' => 'displayRuntimeErrorNotice']);
        }
    }

    /**
     * Strictly prevent cloning.
     *
     * @throws LogicException Always.
     */
    public function __clone()
    {
        throw new LogicException('Cloning of ' . self::class . ' is not allowed.');
    }

    /**
     * Strictly prevent (un)serialization.
     *
     * @throws LogicException Always.
     */
    public function __wakeup()
    {
        throw new LogicException('Unserializing of ' . self::class . ' is not allowed.');
    }

    public function __sleep(): array
    {
        throw new LogicException('Serializing of ' . self::class . ' is not allowed.');
    }

    public function __serialize(): array
    {
        throw new LogicException('Serialization of ' . self::class . ' is not allowed.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Unserialization of ' . self::class . ' is not allowed.');
    }
}
