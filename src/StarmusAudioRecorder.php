<?php

/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2026 Starisian Technologies. All Rights Reserved.
 *
 * Main bootstrapper for the Starmus Audio Recorder plugin lifecycle.
 *
 * @file	  StarmusAudioRecorder.php
 *
 * @package   Starisian\Sparxstar\Starmus
 *
 * @author 	  Starisian Technologies (Max Barrett) <support@starisian.com>
 * @license   Starisian Technologies Proprietary License (STPD). See LICENSE.md.
 * @copyright Copyright (c) 2023-2026 Starisian Technologies. All rights reserved.
 *
 * @since     0.1.0
 *
 * @version   1.2.3
 */

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus;

use function add_action;
// Helpers
use function apply_filters;
// Config
use function class_exists;
use function file_exists;
// Interfaces
use function is_admin;

use LogicException;
use RuntimeException;
// Data Layer
use Starisian\Sparxstar\Starmus\admin\StarmusAdmin;
use Starisian\Sparxstar\Starmus\api\StarmusRESTHandler;
use Starisian\Sparxstar\Starmus\api\StarmusDataRESTHandler;
// Components
use Starisian\Sparxstar\Starmus\core\interfaces\IStarmusSettings;
use Starisian\Sparxstar\Starmus\core\StarmusAssetLoader;
use Starisian\Sparxstar\Starmus\core\StarmusPostTypeLoader;
use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\core\StarmusSubmissionHandler;
use Starisian\Sparxstar\Starmus\cron\StarmusCron;
use Starisian\Sparxstar\Starmus\data\interfaces\IStarmusAudioDAL;
use Starisian\Sparxstar\Starmus\data\interfaces\IStarmusProsodyDAL;
use Starisian\Sparxstar\Starmus\data\StarmusAudioDAL;
use Starisian\Sparxstar\Starmus\data\StarmusProsodyDAL;
use Starisian\Sparxstar\Starmus\frontend\StarmusShortcodeLoader;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\i18n\Starmusi18NLanguage;
use Starisian\Sparxstar\Starmus\includes\StarmusTusdHookHandler;
use Starisian\Sparxstar\Starmus\services\StarmusCLI;
use Starisian\Sparxstar\Starmus\services\StarmusEnhancedId3Service;
use Starisian\Sparxstar\Starmus\services\StarmusFileService;
use Starisian\Sparxstar\Starmus\services\StarmusPostProcessingService;
use Starisian\Sparxstar\Starmus\services\StarmusWaveformService;
use Throwable;

if ( ! \defined('ABSPATH')) {
    exit;
}

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
     */
    private ?IStarmusAudioDAL $dal = null;

    /**
     * Data Access Layer for Prosody Engine.
     *
     * @since 1.2.0
     */
    private ?IStarmusProsodyDAL $prosody_dal = null;

    /**
     * Plugin settings and configuration manager.
     *
     * Handles all plugin options, user preferences, and configuration values.
     * Must be initialized before other components that depend on settings.
     * Provides centralized access to WordPress options and plugin defaults.
     *
     * @since 0.1.0
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
     * @throws RuntimeException If settings initialization fails.
     *
     * @since  0.1.0
     */
    private function __construct()
    {
        error_log('Starmus Constructor Started.');

        try {
            // 1. Initialize Foundation (Settings & DALs)
            $this->init_settings_or_throw();

            // 2. Initialize Data Access Layers
            $this->init_dal();
            $this->init_prosody_dal();

            // 2.5 Initialize i18N
            $this->set_i18N();

            // 2.7 Initialize CLI
            $this->set_cli();

            // 3. Initialize Views (Admin & Shortcodes)
            $this->init_views();

            // 4. Initialize Components
            $this->init_components();

            // 5. Register Hooks
            $this->register_hooks();
        } catch (Throwable $throwable) {
            StarmusLogger::log(
                $throwable,
                ['component' => self::class, 'stage' => '__construct']
            );
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
        if ( ! self::$instance instanceof StarmusAudioRecorder) {
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
     */
    public static function starmus_run(): void
    {
        error_log('Starmus Run Started.');
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
     * @throws RuntimeException If StarmusSettings fails to initialize.
     */
    private function init_settings_or_throw(): void
    {
        try {
            if (class_exists(StarmusSettings::class)) {
                $this->settings = new StarmusSettings();
                StarmusLogger::info('Starmus Info: StarmusSettings initialized successfully.');
                return;
            }
        } catch (Throwable $throwable) {
            // Log handled below
            StarmusLogger::log($throwable);
        }

        throw new RuntimeException('StarmusSettings failed to initialize.');
    }

    /**
     * Initialize both Recorder and Prosody DALs.
     *
     * Applies 'starmus_register_dal' filter to allow DAL replacement.
     * Implements handshake mechanism using STARMUS_DAL_OVERRIDE_KEY for security.
     * Replacement DAL must implement IStarmusAudioDAL.
     *
     * Uses static singleton to prevent duplicate instantiation across the request.
     *
     * @since 1.2.0
     */
    private function init_dal(): void
    {
        // 1. Recorder DAL (With Override Filter)
        try {
            $default_recorder = new StarmusAudioDAL();

            $override_key      = \defined('STARMUS_DAL_OVERRIDE_KEY') ? STARMUS_DAL_OVERRIDE_KEY : null;
            $filtered_recorder = apply_filters('starmus_register_dal', $default_recorder, $override_key);

            if ($filtered_recorder instanceof StarmusAudioDAL) {
                // Handshake Validation
                if ($filtered_recorder !== $default_recorder) {
                    $expected = (string) ($override_key ?? '');
                    if ($expected && $filtered_recorder->get_registration_key() === $expected) {
                        $this->dal = $filtered_recorder;
                    } else {
                        StarmusLogger::warning('DAL Override rejected: Key mismatch.');
                        $this->dal = $default_recorder;
                    }
                } else {
                    $this->dal = $default_recorder;
                }
            } else {
                $this->dal = $default_recorder;
            }
            StarmusLogger::info('Starmus Info: StarmusAudioDAL initialized successfully.');
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            $this->dal = new StarmusAudioDAL(); // Fail safe
        }
    }
    /**
     * Initialize Prosody DAL.
     *
     * @return void
     */
    private function init_prosody_dal(): void
    {
        try {
            if (class_exists(StarmusProsodyDAL::class)) {
                $this->prosody_dal = new StarmusProsodyDAL();
                StarmusLogger::info('Starmus Info: StarmusProsodyDAL initialized successfully.');
                return;
            }
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
    }
    /**
     * Set up internationalization support.
     *
     * @return void
     */
    private function set_i18N(): void
    {
        try {
            if (class_exists(Starmusi18NLanguage::class)) {
                $i18n = new Starmusi18NLanguage();
                $i18n->register_hooks();
                StarmusLogger::info('Starmus Info: Starmusi18NLanguage initialized successfully.');
                return;
            }
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
    }
    /**
     * Initialize WP-CLI commands if WP-CLI is available.
     *
     * Registers StarmusCLI commands for command-line management of the plugin.
     * Ensures WP-CLI context and class existence before instantiation.
     *
     * @since  0.8.5
     */
    private function set_cli(): void
    {
        try {
            if (class_exists(StarmusCLI::class) && \defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
                $cli = new StarmusCLI($this->get_DAL(), $this->getSettings());
                $cli->register_hooks();
                StarmusLogger::info('Starmus Info: StarmusCLI initialized successfully.');
                return;
            }
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
    }
    /**
     * Initializes frontend and admin classes
     *
     * @return void
     */
    private function init_views(): void
    {
        try {

            // Admin
            if (class_exists(StarmusAdmin::class) && is_admin()) {
                new StarmusAdmin($this->get_DAL(), $this->getSettings());
                StarmusLogger::info('Starmus Info: StarmusAdmin initialized successfully.');
                return;
            }

            // Shortcodes
            if (class_exists(StarmusShortcodeLoader::class)) {
                new StarmusShortcodeLoader($this->get_DAL(), $this->getSettings(), $this->get_ProsodyDAL());
                StarmusLogger::info('Starmus Info: StarmusShortcodeLoader initialized successfully.');
                return;
            }
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
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
     */
    private function init_components(): void
    {
        try {
            StarmusLogger::info('[Starmus] === init_components() STARTING ===');

            // Post Types (Must load early)
            if (class_exists(StarmusPostTypeLoader::class)) {
                StarmusPostTypeLoader::sparxStarmusGetInstance();
            }

            // Assets
            if (class_exists(StarmusAssetLoader::class)) {
                new StarmusAssetLoader($this->getSettings());
            }

            // Submission Logic
            if (class_exists(StarmusTusdHookHandler::class) && class_exists(StarmusSubmissionHandler::class)) {
                $submission_handler = new StarmusSubmissionHandler($this->get_DAL(), $this->getSettings());
                $tus_hook_handler   = new StarmusTusdHookHandler($submission_handler);
                $tus_hook_handler->register_hooks();
            }

            // REST API
            if (class_exists(StarmusRESTHandler::class)) {
                new StarmusRESTHandler($this->get_DAL(), $this->getSettings());
            }

            // Async Data Loading REST API (Retrieval)
            if (class_exists(StarmusDataRESTHandler::class)) {
                $data_rest = new StarmusDataRESTHandler();
                $data_rest->init();
            }

            // Services
            $file_service            = null;
            $id3_service             = null;
            $waveform_service        = null;
            $post_processing_service = null;

            if (class_exists(StarmusFileService::class)) {
                $file_service = new StarmusFileService($this->get_DAL());
                $file_service->register_compatibility_hooks();
            }

            if (class_exists(StarmusEnhancedId3Service::class)) {
                $id3_service = new StarmusEnhancedId3Service();
            }

            if (class_exists(StarmusWaveformService::class)) {
                $waveform_service = new StarmusWaveformService($this->get_DAL(), $file_service);
            }

            if (class_exists(StarmusPostProcessingService::class)) {
                $post_processing_service = new StarmusPostProcessingService($this->get_DAL(), $file_service, $waveform_service, $id3_service);
            }

            // Cron Jobs
            if (class_exists(StarmusCron::class)) {
                $cron = new StarmusCron($waveform_service, $post_processing_service);
                $cron->register_hooks();
            }

            StarmusLogger::info('[Starmus] === init_components() COMPLETE ===');
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
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
     */
    private function register_hooks(): void
    {
        if ($this->hooksRegistered) {
            return;
        }

        try {

            if (\defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
                $cli_path = STARMUS_PATH . 'src/cli/';
                if (file_exists($cli_path . 'StarmusCLI.php')) {
                    require_once $cli_path . 'StarmusCLI.php';
                }
            }

            if (is_admin()) {
                add_action('admin_notices', $this->displayRuntimeErrorNotice(...));
            }

            $this->hooksRegistered = true;
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
    }

    /**
     * Retrieve the active Data Access Layer implementation.
     *
     * Exposes the DAL instance for consumers that require direct persistence
     * access while preserving the singleton DAL contract.
     *
     * @since 0.1.0
     *
     * @return IStarmusAudioDAL|null Active DAL instance or null when unavailable.
     */
    public function get_DAL(): ?IStarmusAudioDAL
    {
        return $this->dal;
    }

    /**
     * Retrieve the active Prosody DAL implementation.
     *
     * @since 1.2.0
     */
    public function get_ProsodyDAL(): ?IStarmusProsodyDAL
    {
        return $this->prosody_dal;
    }

    /**
     * Compile audio description from form data.
     */
    public function getSettings(): ?IStarmusSettings
    {
        return $this->settings;
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
     */
    public function displayRuntimeErrorNotice(): void
    {
        try {
            if ($this->runtimeErrors === [] || ! current_user_can('manage_options')) {
                return;
            }

            $unique = array_unique($this->runtimeErrors);

            if (class_exists(StarmusLogger::class)) {
                StarmusLogger::log('Starmus Runtime Errors:', $unique);
            }

            foreach ($unique as $msg) {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Starmus:</strong> ' . esc_html($msg) . '</p></div>';
            }
        } catch (Throwable) {
            // Squelch
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
        throw new LogicException('Cloning forbidden.');
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
        throw new LogicException('Unserializing forbidden.');
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
        throw new LogicException('Serializing forbidden.');
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
        throw new LogicException('Serialization forbidden.');
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
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Unserialization forbidden.');
    }
}
