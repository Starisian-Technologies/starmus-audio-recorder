<?php

/**
 * Adaptive Asset Loader for Starmus Audio Recorder.
 *
 * Handles server-side environment detection and intelligent script enqueuing.
 * Uses modern Client Hints with fallbacks to cookies and User-Agent.
 *
 * @package    Starmus
 * @subpackage Includes
 * @version    1.0.0
 * @since      0.9.0
 * @author     Starisian Technologies
 */

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\includes;

if (! defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

/**
 * Handles adaptive loading of Starmus scripts and styles.
 *
 * Detects user environment on server-side and passes optimization
 * profile to client-side scripts for adaptive recording settings.
 *
 * @since 0.9.0
 */
final class StarmusAdaptiveAssetLoader
{

    /**
     * Registers WordPress hooks for enqueuing assets.
     *
     * @since 0.9.0
     */
    public function register_hooks(): void
    {
        add_action('wp_enqueue_scripts', $this->enqueue_starmus_scripts(...));
    }

    /**
     * Determines user environment profile based on server data.
     *
     * Uses layered detection strategy:
     * 1. Modern Client Hints (HTTP_DOWNLINK, HTTP_SAVE_DATA)
     * 2. Fallback to cookies set by SPARXSTAR
     * 3. Basic User-Agent detection as last resort
     *
     * @since 0.9.0
     * @return string Profile: 'default', 'low_spec', or 'very_low_spec'
     */
    private function get_environment_profile(): string
    {
        // Strategy 1: Modern Client Hints (most efficient and accurate).
        $network_speed  = isset($_SERVER['HTTP_DOWNLINK']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_DOWNLINK'])) : null;
        $save_data_mode = isset($_SERVER['HTTP_SAVE_DATA']) && 'on' === $_SERVER['HTTP_SAVE_DATA'];

        if ($save_data_mode || ($network_speed && (float) $network_speed < 0.5)) {
            StarmusLogger::debug('AdaptiveAssetLoader', 'Profile: very_low_spec (Client Hints)', ['downlink' => $network_speed]);
            return 'very_low_spec';
        }

        if ($network_speed && (float) $network_speed < 1.5) {
            StarmusLogger::debug('AdaptiveAssetLoader', 'Profile: low_spec (Client Hints)', ['downlink' => $network_speed]);
            return 'low_spec';
        }

        // Strategy 2: Fallback to cookie set by SPARXSTAR JavaScript.
        $sparxstar_cookie = isset($_COOKIE['sparxstar_network_profile'])
            ? sanitize_text_field(wp_unslash($_COOKIE['sparxstar_network_profile']))
            : null;

        if (in_array($sparxstar_cookie, ['2g', 'slow-2g'], true)) {
            StarmusLogger::debug('AdaptiveAssetLoader', 'Profile: very_low_spec (Cookie)', ['cookie' => $sparxstar_cookie]);
            return 'very_low_spec';
        }

        if ('3g' === $sparxstar_cookie) {
            StarmusLogger::debug('AdaptiveAssetLoader', 'Profile: low_spec (Cookie)', ['cookie' => $sparxstar_cookie]);
            return 'low_spec';
        }

        // Strategy 3: Basic User-Agent check as last resort.
        $user_agent = isset($_SERVER['HTTP_USER_AGENT'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
            : '';

        if (preg_match('/(Android|iPhone|iPod|Mobile)/i', $user_agent)) {
            StarmusLogger::debug('AdaptiveAssetLoader', 'Profile: low_spec (User-Agent)', ['ua' => substr($user_agent, 0, 50)]);
            return 'low_spec';
        }

        StarmusLogger::debug('AdaptiveAssetLoader', 'Profile: default (No constraints detected)');
        return 'default';
    }

    /**
     * Enqueues Starmus scripts with adaptive optimization profile.
     *
     * Only loads on pages with recorder shortcode or post type.
     * Passes server-determined profile to JavaScript optimizer.
     *
     * @since 0.9.0
     */
    public function enqueue_starmus_scripts(): void
    {
        if (is_admin()) {
            return;
        }

        global $post;
        $has_content  = ($post instanceof \WP_Post) && ! empty($post->post_content);
        $has_recorder = $has_content && has_shortcode($post->post_content, 'starmus_audio_recorder_form');

        // Only load on pages with the recorder shortcode.
        if (! $has_recorder) {
            return;
        }

        // Determine optimization profile.
        $profile = $this->get_environment_profile();

        $version = $this->resolve_version();
        $base_url = trailingslashit(STARMUS_URL);

        // Enqueue core Starmus scripts (if not already enqueued elsewhere).
        if (! wp_script_is('starmus-hooks', 'enqueued')) {
            wp_enqueue_script(
                'starmus-hooks',
                $base_url . 'assets/js/starmus-app.min.js',
                [],
                $version,
                true
            );
        }

        // Note: SPARXSTAR plugin should enqueue its own 'sparxstar-main' handle.
        // We list it as a dependency but don't enqueue it ourselves.

        // Enqueue the environment optimizer script with proper dependencies.
        wp_enqueue_script(
            'starmus-environment-optimizer',
            $base_url . 'assets/js/starmus-environment-optimizer.min.js',
            ['starmus-hooks'], // Depends on StarmusHooks; sparxstar-main is optional
            $version,
            true
        );

        // Pass the server-determined profile to JavaScript.
        wp_localize_script(
            'starmus-environment-optimizer',
            'starmusServerConfig',
            [
                'optimizationProfile' => $profile,
                'debug'               => defined('WP_DEBUG') && WP_DEBUG,
            ]
        );

        StarmusLogger::info(
            'AdaptiveAssetLoader',
            'Environment optimizer enqueued',
            [
                'profile' => $profile,
                'post_id' => $post->ID ?? 0,
            ]
        );
    }

    /**
     * Resolves the current plugin version for cache busting.
     *
     * @since 0.9.0
     * @return string Version string
     */
    private function resolve_version(): string
    {
        return (defined('STARMUS_VERSION') && STARMUS_VERSION) ? STARMUS_VERSION : '0.9.0';
    }
}
