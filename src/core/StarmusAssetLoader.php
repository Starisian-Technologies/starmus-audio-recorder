<?php

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
 * @package Starmus
 * @version 3.1.0
 */

namespace Starisian\Sparxstar\Starmus\core;

if (!defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\includes\StarmusSubmissionHandler;

final class StarmusAssetLoader
{
    /**
     * Handle for the production bundle script.
     *
     * @var string
     */
    private const HANDLE_PROD_BUNDLE = 'starmus-app-bundle';

    /**
     * Handle for the main stylesheet.
     *
     * @var string
     */
    private const STYLE_HANDLE = 'starmus-audio-styles';

    /**
     * Constructor - Registers WordPress hooks for asset enqueueing.
     *
     * @return void
     */
    public function __construct()
    {
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
        try {
            if (is_admin() || !$this->is_starmus_page()) {
                return;
            }

            // Always use production assets - dev mode removed as unbundled modules don't function
            // Vendor libraries (tus, peaks) are now bundled into main app bundle
            // Enqueue bundled production assets
            $this->enqueue_production_assets();
            $this->enqueue_styles();
        } catch (\Throwable $throwable) {
            StarmusLogger::log('Fatal Error in StarmusAssetLoader::enqueue_frontend_assets', $throwable);
        }
    }

    /**
     * Checks if the current page contains any Starmus shortcodes that require assets.
     *
     * Scans the current post content for Starmus-specific shortcodes to determine
     * if frontend assets should be loaded.
     *
     * @return bool True if page contains Starmus shortcodes, false otherwise.
     */
    private function is_starmus_page(): bool
{
    try {
        global $post;

        if (!($post instanceof \WP_Post)) {
            StarmusLogger::log('StarmusAssetLoader: No WP_Post object found.');
            return false;
        }

        if (empty($post->post_content)) {
            StarmusLogger::log('StarmusAssetLoader: Post content empty for ID: ' . $post->ID);
            return false;
        }

        // Direct shortcode scan
        $found = has_shortcode($post->post_content, 'starmus_audio_recorder')
            || has_shortcode($post->post_content, 'starmus_audio_re_recorder')
            || has_shortcode($post->post_content, 'starmus_my_recordings')
            || has_shortcode($post->post_content, 'starmus_audio_editor');

        StarmusLogger::log(
            sprintf(
                'StarmusAssetLoader: Shortcode scan result for Post ID %d: %s',
                $post->ID,
                $found ? 'TRUE' : 'FALSE'
            )
        );

        return $found;
    } catch (\Throwable $throwable) {
        StarmusLogger::log('StarmusAssetLoader::is_starmus_page', $throwable);
        return false;
    }
}


    /**
     * Enqueues the single, bundled, and minified JavaScript file for production.
     *
     * This is a standard script, as the build process resolves all modules.
     * The bundle depends on the TUS.js vendor library for chunked uploads.
     * Localizes the script with server-side configuration data.
     */
    private function enqueue_production_assets(): void
    {
        try {
           wp_enqueue_script(
                self::HANDLE_PROD_BUNDLE,
                STARMUS_URL . 'assets/js/starmus-audio-recorder-script.bundle.min.js',
                [],
                $this->resolve_version(),
                true
            );

            wp_localize_script(self::HANDLE_PROD_BUNDLE, 'starmusConfig', $this->get_localization_data());
        } catch (\Throwable $throwable) {
            StarmusLogger::log('StarmusAssetLoader::enqueue_production_assets', $throwable);
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
            wp_enqueue_style(
                self::STYLE_HANDLE,
                STARMUS_URL . 'assets/css/starmus-audio-recorder-styles.min.css',
                [],
                $this->resolve_version()
            );
        } catch (\Throwable $throwable) {
            StarmusLogger::log('StarmusAssetLoader::enqueue_styles', $throwable);
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
            // Get settings instance
            $settings = new StarmusSettings();

            // Get allowed file types from settings (comma-separated string like 'mp3,wav,webm')
            $allowed_file_types = $settings->get('allowed_file_types', 'mp3,wav,webm');
            $allowed_types_arr = \array_filter(\array_map(trim(...), \explode(',', $allowed_file_types)));

            // Map extensions to MIME types
            $allowed_mimes = [];
            foreach ($allowed_types_arr as $ext) {
                $mime = StarmusSettings::get_allowed_mimes()[$ext] ?? null;
                if ($mime) {
                    $allowed_mimes[$ext] = $mime;
                }
            }

            // TUS endpoint from settings
            $tus_endpoint = \get_option('starmus_tus_endpoint', '');

            return [
                'endpoints' => [
                    'directUpload' => \esc_url_raw(\rest_url(StarmusSubmissionHandler::STARMUS_REST_NAMESPACE . '/upload-fallback')),
                    'tusUpload' => \esc_url_raw($tus_endpoint),
                ],
                'nonce' => \wp_create_nonce('wp_rest'),
                'user_id' => \get_current_user_id(),
                'allowedFileTypes' => $allowed_types_arr, // ['mp3', 'wav', 'webm']
                'allowedMimeTypes' => $allowed_mimes,     // ['mp3' => 'audio/mpeg', ...]
            ];
        } catch (\Throwable $throwable) {
            StarmusLogger::log('StarmusAssetLoader::get_localization_data', $throwable);
            return [
                'endpoints' => [
                    'directUpload' => '',
                    'tusUpload' => '',
                ],
                'nonce' => '',
                'user_id' => 0,
                'allowedFileTypes' => [],
                'allowedMimeTypes' => [],
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
        try {
            return (\defined('STARMUS_VERSION') && STARMUS_VERSION) ? STARMUS_VERSION : '1.0.0';
        } catch (\Throwable $throwable) {
            StarmusLogger::log('StarmusAssetLoader::resolve_version', $throwable);
            return '1.0.0';
        }
    }
}
