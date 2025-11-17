<?php
/**
 * Unified, build-process-aware asset loader for the Starmus Audio System.
 *
 * This class is the sole authority for enqueuing all Starmus client-side assets.
 * It is architecturally aligned with the six-module Starmus JS system and the
 * SPARXSTAR User Environment Check plugin.
 *
 * - In production, it loads a single, minified bundle for optimal performance.
 * - In development (WP_DEBUG), it loads individual modules with a full dependency
 *   chain for easy debugging and source mapping.
 *
 * @package Starmus
 * @version 3.0.0
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
    private const VERSION = '3.0.0';
    private const TEXT_DOMAIN = 'starmus-audio';

    // --- Production Handle ---
    private const HANDLE_PROD_BUNDLE = 'starmus-app-bundle';

    // --- Development Handles (The 6-File Architecture) ---
    private const HANDLE_DEV_HOOKS = 'starmus-dev-hooks';
    private const HANDLE_DEV_STATE = 'starmus-dev-state';
    private const HANDLE_DEV_RECORDER = 'starmus-dev-recorder';
    private const HANDLE_DEV_CORE = 'starmus-dev-core';
    private const HANDLE_DEV_UI = 'starmus-dev-ui';
    private const HANDLE_DEV_INTEGRATOR = 'starmus-dev-integrator';

    // --- Vendor & Style Handles ---
    private const HANDLE_VENDOR_TUS = 'tus-js';
    private const STYLE_HANDLE = 'starmus-audio-styles';

    public function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
    }

    /**
     * Main entry point for enqueuing all frontend assets.
     */
    public function enqueue_frontend_assets(): void
    {
        try {
            if (is_admin() || !$this->is_starmus_page()) {
                return;
            }

            $is_development = defined('WP_DEBUG') && WP_DEBUG;

            // Enqueue shared vendor scripts required by Starmus.
            wp_enqueue_script(self::HANDLE_VENDOR_TUS, STARMUS_URL . 'vendor/js/tus.min.js', [], '4.3.1', true);

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
     * Checks if the current page contains any Starmus shortcodes.
     *
     * @return bool True if a Starmus shortcode is found, false otherwise.
     */
    private function is_starmus_page(): bool
    {
        global $post;
        if (!($post instanceof \WP_Post) || empty($post->post_content)) {
            return false;
        }

        // The expanded conditional gate: load assets if any of these are present.
        return has_shortcode($post->post_content, 'starmus_audio_recorder')
            || has_shortcode($post->post_content, 'starmus_my_recordings')
            || has_shortcode($post->post_content, 'starmus_audio_editor');
    }

    /**
     * Enqueues the single, bundled, and minified JavaScript file for production.
     */
    private function enqueue_production_assets(): void
    {
        wp_enqueue_script(
            self::HANDLE_PROD_BUNDLE,
            STARMUS_URL . 'assets/js/starmus-app.bundle.min.js',
            [self::HANDLE_VENDOR_TUS],
            $this->resolve_version(),
            true
        );

        // Localize the main bundle.
        wp_localize_script(self::HANDLE_PROD_BUNDLE, 'starmusConfig', $this->get_localization_data());
    }

    /**
     * Enqueues all six individual Starmus modules with the correct dependency graph for development.
     */
    private function enqueue_development_assets(): void
    {
        $base_uri = STARMUS_URL . 'src/js/';
        $version = $this->resolve_version();

        // 1. Register foundational modules.
        wp_register_script(self::HANDLE_DEV_HOOKS, "{$base_uri}starmus-hooks.js", [], $version, true);
        wp_register_script(self::HANDLE_DEV_STATE, "{$base_uri}starmus-state-store.js", [], $version, true);

        // 2. Register core functional modules, which depend on the foundation.
        $foundation_deps = [self::HANDLE_DEV_HOOKS, self::HANDLE_DEV_STATE];
        wp_register_script(self::HANDLE_DEV_RECORDER, "{$base_uri}starmus-recorder.js", $foundation_deps, $version, true);
        wp_register_script(self::HANDLE_DEV_CORE, "{$base_uri}starmus-core.js", $foundation_deps, $version, true);
        wp_register_script(self::HANDLE_DEV_UI, "{$base_uri}starmus-ui.js", $foundation_deps, $version, true);

        // 3. The integrator depends on all other modules and vendor scripts.
        $integrator_deps = [
            self::HANDLE_DEV_HOOKS,
            self::HANDLE_DEV_STATE,
            self::HANDLE_DEV_RECORDER,
            self::HANDLE_DEV_CORE,
            self::HANDLE_DEV_UI,
            self::HANDLE_VENDOR_TUS,
        ];
        wp_register_script(self::HANDLE_DEV_INTEGRATOR, "{$base_uri}starmus-integrator.js", $integrator_deps, $version, true);

        // 4. Enqueue the final script in the chain. WordPress resolves the entire tree.
        wp_enqueue_script(self::HANDLE_DEV_INTEGRATOR);

        // 5. Localize the integrator with the configuration data.
        wp_localize_script(self::HANDLE_DEV_INTEGRATOR, 'starmusConfig', $this->get_localization_data());
    }

    /**
     * Enqueues the stylesheet for the plugin, respecting the development/production environment.
     *
     * @param bool $is_development True if in a development environment.
     */
    private function enqueue_styles(bool $is_development): void
    {
        $version = $this->resolve_version();
        
        $style_path = $is_development 
            ? 'src/css/starmus-audio-recorder-style.css'
            : 'assets/css/starmus.styles.min.css';

        wp_enqueue_style(self::STYLE_HANDLE, STARMUS_URL . $style_path, [], $version);
    }
    
    /**
     * Gathers and prepares all necessary server-side data to be passed to the client-side app.
     * This data serves as the "bridge" to the SPARXSTAR Environment plugin.
     *
     * @return array The data to be passed via wp_localize_script.
     */
    private function get_localization_data(): array
    {
        // Fetch the TUS endpoint from a WordPress option for configurability.
        $tus_endpoint = get_option('starmus_tus_endpoint', '');

        // This filter allows other plugins to add/modify data passed to Starmus.
        return apply_filters('starmus_js_config_data', [
            'endpoints' => [
                'directUpload' => esc_url_raw(rest_url(StarmusSubmissionHandler::STARMUS_REST_NAMESPACE . '/upload-fallback')),
                'tusUpload' => esc_url_raw($tus_endpoint),
            ],
            'nonce' => wp_create_nonce('wp_rest'),
            'user_id' => get_current_user_id(),
            // Add any other configuration Starmus might need in the future here.
        ]);
    }

    /**
     * Resolves the asset version number for cache-busting.
     *
     * @return string The asset version.
     */
    private function resolve_version(): string
    {
        return (defined('STARMUS_VERSION') && STARMUS_VERSION) ? STARMUS_VERSION : '3.0.0';
    }
}
