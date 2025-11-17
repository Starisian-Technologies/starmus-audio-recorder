<?php
/**
 * Unified, build-process-aware asset loader for Starmus Audio.
 *
 * @package Starmus
 * @version 2.0.0
 */

namespace Starisian\Sparxstar\Starmus\core;

if (!defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\includes\StarmusSubmissionHandler;

/**
 * Manages the enqueuing of all Starmus client-side assets.
 * - In development (WP_DEBUG), it loads individual modules with a full dependency chain.
 * - In production, it loads a single, minified bundle for optimal performance.
 */
class StarmusAssetLoader
{
    // --- Handles ---
    private const HANDLE_PROD_BUNDLE = 'starmus-app-bundle';
    private const HANDLE_DEV_HOOKS = 'starmus-dev-hooks';
    private const HANDLE_DEV_STATE = 'starmus-dev-state';
    private const HANDLE_DEV_RECORDER = 'starmus-dev-recorder';
    private const HANDLE_DEV_CORE = 'starmus-dev-core';
    private const HANDLE_DEV_UI = 'starmus-dev-ui';
    private const HANDLE_DEV_INTEGRATOR = 'starmus-dev-integrator';
    private const HANDLE_VENDOR_TUS = 'tus-js';
    private const STYLE_HANDLE = 'starmus-audio-styles';

    public function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
    }

    public function enqueue_frontend_assets(): void
    {
        try {
            if (is_admin()) {
                return;
            }

            global $post;
            $has_content = ($post instanceof \WP_Post) && !empty($post->post_content);
            $has_recorder = $has_content && has_shortcode($post->post_content, 'starmus_audio_recorder');

            // If no Starmus recorder is on the page, do not load any scripts.
            if (!$has_recorder) {
                return;
            }

            $is_development = defined('WP_DEBUG') && WP_DEBUG;

            // Enqueue shared vendor scripts first.
            wp_enqueue_script(self::HANDLE_VENDOR_TUS, STARMUS_URL . 'vendor/js/tus.min.js', [], '4.3.1', true);

            if (!$is_development) {
                $this->enqueue_production_assets();
            } else {
                $this->enqueue_development_assets();
            }

            $this->enqueue_styles();

        } catch (\Throwable $e) {
            StarmusLogger::log('Assets:enqueue_frontend_assets', $e);
        }
    }

    /**
     * Enqueues the single, bundled, and minified JS file for production.
     */
    private function enqueue_production_assets(): void
    {
        wp_enqueue_script(
            self::HANDLE_PROD_BUNDLE,
            STARMUS_URL . 'assets/js/starmus-app.bundle.min.js',
            [self::HANDLE_VENDOR_TUS], // The bundle depends on TUS.
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
        $base_uri = STARMUS_URL . 'src/js/'; // In dev, we load from `src`.
        $version = $this->resolve_version();

        // 1. Register foundational modules (no dependencies on other Starmus modules).
        wp_register_script(self::HANDLE_DEV_HOOKS, "{$base_uri}starmus-hooks.js", [], $version, true);
        wp_register_script(self::HANDLE_DEV_STATE, "{$base_uri}starmus-state-store.js", [], $version, true);

        // 2. Register the three core functional modules. They all depend on the foundation.
        $foundation_deps = [self::HANDLE_DEV_HOOKS, self::HANDLE_DEV_STATE];
        wp_register_script(self::HANDLE_DEV_RECORDER, "{$base_uri}starmus-recorder.js", $foundation_deps, $version, true);
        wp_register_script(self::HANDLE_DEV_CORE, "{$base_uri}starmus-core.js", $foundation_deps, $version, true);
        wp_register_script(self::HANDLE_DEV_UI, "{$base_uri}starmus-ui.js", $foundation_deps, $version, true);

        // 3. The integrator depends on ALL other modules and our vendor script.
        wp_register_script(self::HANDLE_DEV_INTEGRATOR, "{$base_uri}starmus-integrator.js", [
            self::HANDLE_DEV_HOOKS,
            self::HANDLE_DEV_STATE,
            self::HANDLE_DEV_RECORDER,
            self::HANDLE_DEV_CORE,
            self::HANDLE_DEV_UI,
            self::HANDLE_VENDOR_TUS,
        ], $version, true);

        // 4. Enqueue the final script in the chain. WordPress will resolve the entire tree.
        wp_enqueue_script(self::HANDLE_DEV_INTEGRATOR);

        // 5. Localize the integrator with our configuration data.
        wp_localize_script(self::HANDLE_DEV_INTEGRATOR, 'starmusConfig', $this->get_localization_data());
    }

    /**
     * Enqueues the stylesheet for the plugin.
     */
    private function enqueue_styles(): void
    {
        $version = $this->resolve_version();
        $is_development = defined('WP_DEBUG') && WP_DEBUG;
        
        $style_path = $is_development 
            ? 'src/css/starmus-audio-recorder-style.css'
            : 'assets/css/starmus.styles.min.css';

        wp_enqueue_style(self::STYLE_HANDLE, STARMUS_URL . $style_path, [], $version);
    }
    
    /**
     * Gathers and prepares all necessary server-side data for the client-side app.
     *
     * @return array The data to be passed via wp_localize_script.
     */
    private function get_localization_data(): array
    {
        // This is where you would get TUS endpoint from a settings page, for example.
        $tus_endpoint = get_option('starmus_tus_endpoint', '');

        return [
            'endpoints' => [
                'directUpload' => esc_url_raw(rest_url(StarmusSubmissionHandler::STARMUS_REST_NAMESPACE . '/upload-fallback')),
                'tusUpload' => esc_url_raw($tus_endpoint),
            ],
            'nonce' => wp_create_nonce('wp_rest'),
            'user_id' => get_current_user_id(),
        ];
    }

    private function resolve_version(): string
    {
        return (defined('STARMUS_VERSION') && STARMUS_VERSION) ? STARMUS_VERSION : '1.0.0';
    }
}
