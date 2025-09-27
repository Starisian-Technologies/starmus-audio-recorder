<?php
/**
 * Unified asset loader for Starmus Audio.
 *
 * @package Starmus
 * @version 0.7.5
 * @since  0.7.5
 */

namespace Starmus\includes;

if (!defined('ABSPATH')) {
    exit;
}

use Starmus\helpers\StarmusLogger;

class StarmusAssetLoader
{

    /**
     * Boot hooks.
     */
    public function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    /**
     * Enqueue styles/scripts on frontend conditionally.
     */
    public function enqueue_frontend_assets(): void
    {
        try {
            if (is_admin()) {
                return;
            }

            global $post;
            if (!is_a($post, 'WP_Post') || empty($post->post_content)) {
                return;
            }

            // Detect shortcodes.
            $has_recorder = has_shortcode($post->post_content, 'starmus_audio_recorder_form');
            $has_list = has_shortcode($post->post_content, 'starmus_my_recordings');

            if (!$has_recorder && !$has_list) {
                return;
            }

            // Unified CSS for all views.
            wp_enqueue_style(
                'starmus-audio-styles',
                trailingslashit(STARMUS_URL) . 'assets/css/starmus-audio-plugin.css',
                [],
                defined('STARMUS_VERSION') ? STARMUS_VERSION : '1.2.0'
            );

            // Scripts: recorder only.
            if ($has_recorder) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    wp_enqueue_script('starmus-hooks', trailingslashit(STARMUS_URL) . 'src/js/starmus-audio-recorder-hooks.js', [], STARMUS_VERSION, true);
                    wp_enqueue_script('starmus-recorder-module', trailingslashit(STARMUS_URL) . 'src/js/starmus-audio-recorder-module.js', ['starmus-hooks'], STARMUS_VERSION, true);
                    wp_enqueue_script('starmus-submissions-handler', trailingslashit(STARMUS_URL) . 'src/js/starmus-audio-recorder-submissions-handler.js', ['starmus-hooks'], STARMUS_VERSION, true);
                    wp_enqueue_script('starmus-ui-controller', trailingslashit(STARMUS_URL) . 'src/js/starmus-audio-recorder-ui-controller.js', ['starmus-hooks', 'starmus-recorder-module', 'starmus-submissions-handler'], STARMUS_VERSION, true);
                } else {
                    wp_enqueue_script('starmus-app', trailingslashit(STARMUS_URL) . 'assets/js/starmus-app.min.js', [], STARMUS_VERSION, true);
                }

                wp_enqueue_script('tus-js', trailingslashit(STARMUS_URL) . 'vendor/js/tus.min.js', [], '4.3.1', true);
            }
        } catch (\Throwable $e) {
            StarmusLogger::log('Assets:enqueue_frontend_assets', $e);
        }
    }

    /**
     * Enqueue admin styles for detail/editor screens.
     */
    public function enqueue_admin_assets(string $hook): void
    {
        try {
            // Only load on CPT edit + single view.
            global $post_type;
            if ($post_type !== 'audio-recording') {
                return;
            }

            wp_enqueue_style(
                'starmus-audio-styles',
                trailingslashit(STARMUS_URL) . 'assets/css/starmus-audio-plugin.css',
                [],
                defined('STARMUS_VERSION') ? STARMUS_VERSION : '1.2.0'
            );
        } catch (\Throwable $e) {
            StarmusLogger::log('Assets:enqueue_admin_assets', $e);
        }
    }
}
