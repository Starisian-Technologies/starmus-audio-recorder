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
    // --- Version and Handles ---
    private const VERSION = '3.1.0';

    // --- Production Handle ---
    private const HANDLE_PROD_BUNDLE = 'starmus-app-bundle';

    // --- Development Handle (The ES Module Entry Point) ---
    private const HANDLE_DEV_INTEGRATOR = 'starmus-dev-integrator-module';

    // --- Vendor & Style Handles ---
    private const HANDLE_VENDOR_TUS = 'tus-js';
    private const STYLE_HANDLE = 'starmus-audio-styles';

    public function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
    }

    public function enqueue_frontend_assets(): void
    {
        try {
            if (is_admin() || !$this->is_starmus_page()) {
                return;
            }

            $is_development = defined('WP_DEBUG') && WP_DEBUG;

            // Enqueue vendor scripts. These are standard scripts, not modules.
            // The ES modules will assume these globals (like `tus`) are available.
            wp_enqueue_script(self::HANDLE_VENDOR_TUS, STARMUS_URL . 'assets/js/vendor/tus.min.js', [], '4.3.1', true);

            if (!$is_development) {
                $this->enqueue_production_assets();
            } else {
                $this->enqueue_development_assets();
            }

            $this->enqueue_styles($is_development);
        } catch (\Throwable $e) {
            StarmusLogger::log('Fatal Error in StarmusAssetLoader::enqueue_frontend_assets', $e);
        }
    }

    /**
     * Checks if the current page contains any Starmus shortcodes that require assets.
     */
    private function is_starmus_page(): bool
    {
        global $post;
        if (!($post instanceof \WP_Post) || empty($post->post_content)) {
            return false;
        }

        return has_shortcode($post->post_content, 'starmus_audio_recorder')
            || has_shortcode($post->post_content, 'starmus_audio_re_recorder')
            || has_shortcode($post->post_content, 'starmus_my_recordings')
            || has_shortcode($post->post_content, 'starmus_audio_editor');
    }

    /**
     * Enqueues the single, bundled, and minified JavaScript file for production.
     * This is a standard script, as the build process resolves all modules.
     */
    private function enqueue_production_assets(): void
    {
        wp_enqueue_script(
            self::HANDLE_PROD_BUNDLE,
            STARMUS_URL . 'assets/js/starmus-app.bundle.min.js',
            [self::HANDLE_VENDOR_TUS], // The bundle depends on the TUS global.
            $this->resolve_version(),
            true
        );

        wp_localize_script(self::HANDLE_PROD_BUNDLE, 'starmusConfig', $this->get_localization_data());
    }

    /**
     * Enqueues the main integrator script as a native ES Module for development.
     * The browser will then handle fetching all imported dependencies.
     */
    private function enqueue_development_assets(): void
    {
        $base_uri = STARMUS_URL . 'src/js/';

        // 1. Enqueue ONLY the entry point script.
        wp_enqueue_script(
            self::HANDLE_DEV_INTEGRATOR,
            "{$base_uri}starmus-integrator.js",
            [self::HANDLE_VENDOR_TUS], // It still depends on vendor scripts to be loaded first.
            $this->resolve_version(),
            true
        );

        // 2. THIS IS THE CRITICAL STEP: Add the 'type' => 'module' attribute.
        // This tells WordPress to render <script type="module">.
        wp_script_add_data(self::HANDLE_DEV_INTEGRATOR, 'type', 'module');

        // 3. Localize the data to the main module entry point.
        wp_localize_script(self::HANDLE_DEV_INTEGRATOR, 'starmusConfig', $this->get_localization_data());
    }

    /**
     * Enqueues the stylesheet for the plugin.
     */
    private function enqueue_styles(bool $is_development): void
    {
        wp_enqueue_style(
            self::STYLE_HANDLE,
            STARMUS_URL . 'assets/css/starmus-styles.min.css',
            [],
            $this->resolve_version()
        );
    }

    /**
     * Gathers and prepares all necessary server-side data for the client-side app.
     */
    private function get_localization_data(): array
    {
        // Get settings instance
        $settings = new StarmusSettings();

        // Get allowed file types from settings (comma-separated string like 'mp3,wav,webm')
        $allowed_file_types = $settings->get('allowed_file_types', 'mp3,wav,webm');
        $allowed_types_arr = \array_filter(\array_map('trim', \explode(',', $allowed_file_types)));

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
    }

    /**
     * Resolves the asset version number for cache-busting.
     */
    private function resolve_version(): string
    {
        return (\defined('STARMUS_VERSION') && STARMUS_VERSION) ? STARMUS_VERSION : '1.0.0';
    }
}
