<?php

declare(strict_types=1);
/**
 * Unified, build-process-aware, and ES-Module-aware asset loader for the Starmus Audio System.
 *
 * This class is the sole authority for enqueuing all Starmus client-side assets.
 *
 * - In production (WP_DEBUG is false), it loads a single, minified, bundled file for performance.
 * - In development (WP_DEBUG is true), it loads the 'starmus-integrator.js' as a native
 *   ES Module (`type="module"`), allowing the browser to handle the dependency tree for
 *   the best possible debugging experience.
 *
 * @package Starisian\Sparxstar\Starmus\core
 *
 * @version 0.9.2
 */

namespace Starisian\Sparxstar\Starmus\core;

use function array_filter;
use function array_map;
use function array_values;
use function defined;
use function explode;
use function is_admin;

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

use function str_replace;

use Throwable;

use function trim;
use function wp_create_nonce;

if ( ! \defined('ABSPATH')) {
    exit;
}

final class StarmusAssetLoader
{
    /**
     * Handle for the production bundle script.
     *
     * @var string
     */
    private const HANDLE_PROD_BUNDLE = 'starmus-audio-recorder-script.bundle';

    /**
     * Handle for the main stylesheet.
     *
     * @var string
     */
    private const STYLE_HANDLE = 'starmus-audio-recorder-styles';

    /**
     * StarmusSettings settings object
     */
    private ?StarmusSettings $settings = null;

    /**
     * Editor data to be localized, set by shortcode loader
     *
     * @var array<string, mixed>|null
     */
    private static ?array $editor_data = null;

    /**
     * Constructor - Registers WordPress hooks for asset enqueueing.
     *
     * @param StarmusSettings $settings The settings instance to use for configuration.
     */
    public function __construct(StarmusSettings $settings)
    {
        $this->settings = $settings;
        $this->register_hooks();
    }

    /**
     * Register enqueue hooks with WordPress.
     */
    private function register_hooks(): void
    {
        // PHP 8.1+ First-class callable syntax
        add_action('wp_enqueue_scripts', $this->enqueue_frontend_assets(...));
    }

    /**
     * Enqueues frontend assets for Starmus pages.
     *
     * Only loads assets on frontend pages that contain Starmus shortcodes.
     * Always uses production-optimized bundled assets for performance.
     */
    public function enqueue_frontend_assets(): void
    {
        // Log entry to verify method access
        if (class_exists(StarmusLogger::class)) {
            StarmusLogger::info('[Starmus AssetLoader] enqueue_frontend_assets() called');
        }

        if (is_admin()) {
            return;
        }

        $this->enqueue_production_assets();
        $this->enqueue_styles();
        $this->enqueue_app_mode_assets();
    }

    public function enqueue_re_recorder_assets(): void
    {
        wp_localize_script(
            'starmus-audio-recorder-script.bundle',
            'STARMUS_RERECORDER_DATA',
            ['mode' => 'rerecorder']
        );
    }

    /**
     * Set editor data to be localized when assets are enqueued.
     * Called by shortcode loader with specific editor context.
     */
    public static function set_editor_data(array $editor_data): void
    {
        self::$editor_data = $editor_data;
    }

    /**
     * Enqueues App Mode (Fullscreen) assets globally.
     */
    private function enqueue_app_mode_assets(): void
    {
        $url     = \defined('STARMUS_URL') ? STARMUS_URL : '';
        $version = $this->resolve_version();

        wp_enqueue_style(
            'sparxstar-app-mode-css',
            $url . 'src/css/sparxstar-starmus-app-mode.css',
            [],
            $version
        );

        wp_enqueue_script(
            'sparxstar-app-mode-js',
            $url . 'src/js/app-mode/sparxstar-starmus-app-mode.js',
            [],
            $version,
            true
        );
    }

    /**
     * Enqueues the single, bundled, and minified JavaScript file for production.
     *
     * This is a standard script, as the build process resolves all modules.
     * Optionally depends on SPARXSTAR environment checker if available for optimal
     * device detection, but gracefully falls back to internal detection after 2s timeout.
     * Localizes the script with server-side configuration data.
     */
    private function enqueue_production_assets(): void
    {
        try {
            $url     = \defined('STARMUS_URL') ? STARMUS_URL : '';
            $js_path = $url . 'assets/js/starmus-audio-recorder-script.bundle.min.js';

            StarmusLogger::info('[Starmus AssetLoader] Enqueueing JS: ' . $js_path);

            $dependencies = [];
            if (wp_script_is('sparxstar-user-environment-check-app', 'registered')) {
                $dependencies[] = 'sparxstar-user-environment-check-app';
                if (wp_script_is('sparxstar-error-reporter', 'registered')) {
                    $dependencies[] = 'sparxstar-error-reporter';
                }
            }

            wp_enqueue_script(
                self::HANDLE_PROD_BUNDLE,
                $js_path,
                $dependencies,
                $this->resolve_version(),
                true
            );

            // Add type="module" (PHP 8.2 Strict Closure)
            add_filter(
                'script_loader_tag',
                function (string $tag, string $handle): string {
                    if ($handle === self::HANDLE_PROD_BUNDLE) {
                        return str_replace('<script ', '<script type="module" ', $tag);
                    }

                    return $tag;
                },
                10,
                2
            );

            // Get Config safely
            $config = $this->get_localization_data();

            // Localize Scripts
            wp_localize_script(self::HANDLE_PROD_BUNDLE, 'starmusConfig', $config);

            // Resolve optional recording ID from context (e.g. Consent Handoff)
            $recording_id = filter_input(INPUT_GET, 'starmus_recording_id', FILTER_SANITIZE_NUMBER_INT);
            if ( ! $recording_id && isset(self::$editor_data['post_id'])) {
                $recording_id = self::$editor_data['post_id'];
            }

            wp_localize_script(
                self::HANDLE_PROD_BUNDLE,
                'STARMUS_BOOTSTRAP',
                [
                    'version'     => $this->resolve_version(),
                    'config'      => $config,
                    'env'         => \defined('WP_ENV') ? WP_ENV : 'production',
                    'postId'      => get_the_ID() ?: 0,
                    'recordingId' => $recording_id ? (int) $recording_id : 0, // Injected for workflow handoff
                    'restUrl'     => esc_url_raw(rest_url()),
                    'homeUrl'     => esc_url_raw(home_url('/')),
                    'sparxstar'   => [
                        'available'       => wp_script_is('sparxstar-user-environment-check-app', 'registered'),
                        'error_reporting' => wp_script_is('sparxstar-error-reporter', 'registered'),
                        'timeout'         => 2000,
                    ],
                ]
            );

            // MEMORY SAFETY: Garbage collect before processing editor data which might be huge
            if (\function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            $default_editor_data = [
                'enabled'     => false,
                'post_id'     => get_the_ID() ?: 0,
                'mode'        => 'recorder',
                'audio'       => null,
                'waveform'    => [],
                'transcript'  => '',
                'annotations' => [],
            ];

            // SECURITY/PERFORMANCE: If we are not in editor mode but editor data is set with huge blobs,
            // we should sanitize it. If self::$editor_data is used, we check its size.
            $final_editor_data = self::$editor_data ?? $default_editor_data;

            // Ensure heavy keys are stripped to protect memory ONLY if they are huge
            // These should be loaded via REST API (StarmusDataRESTHandler) normally, but
            // allowed for small data (short recordings).

            // 1. Waveform
            if (isset($final_editor_data['waveform_json'])) {
                $should_strip = false;
                if (is_array($final_editor_data['waveform_json']) && count($final_editor_data['waveform_json']) > 5000) {
                    $should_strip = true;
                } elseif (is_string($final_editor_data['waveform_json']) && strlen($final_editor_data['waveform_json']) > 100000) {
                    $should_strip = true;
                }

                if ($should_strip) {
                    $final_editor_data['waveform_json'] = null;
                }
            }

            // 2. Transcription
            if (isset($final_editor_data['transcription_json'])) {
                if (is_string($final_editor_data['transcription_json']) && strlen($final_editor_data['transcription_json']) > 200000) {
                    $final_editor_data['transcription_json'] = null;
                }
            }

            // 3. Environment Data (Usually small)
            if (isset($final_editor_data['environment_data'])) {
                // Only strip if suspiciously large
                if (is_string($final_editor_data['environment_data']) && strlen($final_editor_data['environment_data']) > 50000) {
                    $final_editor_data['environment_data'] = null;
                }
            }

            // 4. Transcript (from ShortcodeLoader)
            if (isset($final_editor_data['transcript']) && is_array($final_editor_data['transcript'])) {
                // If massive array of words, strip it
                if (count($final_editor_data['transcript']) > 5000) {
                    $final_editor_data['transcript'] = [];
                }
            }

            if (isset($final_editor_data['annotations']) && is_array($final_editor_data['annotations'])) {
                if (count($final_editor_data['annotations']) > 2000) {
                    $final_editor_data['annotations'] = [];
                }
            }

            // Nothing simple in PHP side. We rely on GC.

            wp_localize_script(
                self::HANDLE_PROD_BUNDLE,
                'STARMUS_EDITOR_DATA',
                $final_editor_data
            );

            // Cleanup
            if (\function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        } catch (Throwable $throwable) {
            // This is where your error happened.
            // We ensure StarmusLogger is available via the 'use' statement at the top.
            StarmusLogger::error('[Starmus AssetLoader] Enqueue ERROR: ' . $throwable->getMessage());
            StarmusLogger::log($throwable);
        }
    }

    /**
     * Enqueues the minified stylesheet for the plugin.
     *
     * Loads the production-optimized CSS bundle with cache-busting version.
     */
    private function enqueue_styles(): void
    {
        try {
            $url      = \defined('STARMUS_URL') ? STARMUS_URL : '';
            $css_path = $url . 'assets/css/starmus-audio-recorder-styles.min.css';

            wp_enqueue_style(
                self::STYLE_HANDLE,
                $css_path,
                [],
                $this->resolve_version()
            );
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
    }

    /**
     * Gathers and prepares all necessary server-side data for the client-side app.
     *
     * Builds the configuration object that's localized to JavaScript, including:
     * - REST API endpoints for uploads
     * - Authentication nonce
     * - Current user ID
     * - Allowed file types and MIME types from settings
     *
     * @return array<string, mixed> Configuration array with endpoints, nonce, user_id, and file type settings.
     *                              Returns safe defaults on error.
     */
    private function get_localization_data(): array
    {
        try {
            // CRITICAL FIX: The logic bug in the previous version caused the crash.
            // Replaced incorrect ternary logic with Null Coalescing Operator.
            $settings = $this->settings ?? new StarmusSettings();

            // Parse allowed file types (PHP 8.1+ syntax)
            $allowed_string = (string) $settings->get('allowed_file_types', 'mp3,wav,webm');
            $allowed_types  = array_values(
                array_filter(
                    array_map(trim(...), explode(',', $allowed_string)),
                    fn(string $v): bool => $v !== ''
                )
            );

            // Safely map MIME types
            $allowed_mimes = [];
            $all_mimes     = StarmusSettings::get_allowed_mimes();
            foreach ($allowed_types as $ext) {
                if (isset($all_mimes[$ext])) {
                    $allowed_mimes[$ext] = $all_mimes[$ext];
                }
            }

            $tus_endpoint = (string) $settings->get('tus_endpoint', 'https://contribute.sparxstar.com/files/');
            $speech_lang  = (string) $settings->get('speech_recognition_lang', 'en-US');
            $slug         = (string) $settings->get('my_recordings_page_slug', 'my-submissions');
            $namespace    = \defined('STARMUS_REST_NAMESPACE') ? STARMUS_REST_NAMESPACE : 'starmus/v1';

            return [
                'endpoints' => [
                    'directUpload' => esc_url_raw(rest_url($namespace . '/upload-fallback')),
                    'tusUpload'    => esc_url_raw($tus_endpoint),
                ],
                'nonce'                 => wp_create_nonce('wp_rest'),
                'user_id'               => get_current_user_id(),
                'allowedFileTypes'      => $allowed_types,
                'allowedMimeTypes'      => $allowed_mimes,
                'speechRecognitionLang' => sanitize_text_field($speech_lang),
                'myRecordingsUrl'       => esc_url_raw(home_url('/' . $slug . '/')),
            ];
        } catch (Throwable $throwable) {
            // Fallback if settings completely fail
            StarmusLogger::log($throwable);
            return [
                'endpoints'             => ['directUpload' => '', 'tusUpload' => ''],
                'nonce'                 => '',
                'user_id'               => 0,
                'allowedFileTypes'      => [],
                'allowedMimeTypes'      => [],
                'speechRecognitionLang' => 'en-US',
                'myRecordingsUrl'       => '',
            ];
        }
    }

    /**
     * Resolves the asset version number for cache-busting.
     *
     * Uses the STARMUS_VERSION constant if defined, otherwise falls back to 1.0.0.
     * This version is appended to asset URLs to invalidate browser caches on updates.
     *
     * @return string Version string for cache-busting.
     */
    private function resolve_version(): string
    {
        return (\defined('STARMUS_VERSION') && STARMUS_VERSION) ? (string) STARMUS_VERSION : '1.0.0';
    }
}
