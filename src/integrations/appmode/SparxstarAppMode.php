<?php

/**
 * SPARXSTAR App Mode
 *
 * @file        sparxstar-app-mode.php
 *
 * @author      Starisian Technologies (Max Barrett) <support@starisian.com>
 * @license     MIT License
 * @copyright   Copyright (c) 2026 Starisian Technologies
 *
 * Version:     1.2.1
 * Author:      Starisian Technologies (Max Barrett) <support@starisian.com>
 * Text Domain: sparxstar-app-mode
 */

namespace Starisian\Sparxstar\Starmus\integrations\appmode;

// exit if not WP
\defined('ABSPATH') || exit;

final class SparxstarAppMode
{
    private bool $assets_enqueued = false;

    public function __construct()
    {
        // register hooks
        $this->sparxstarRegisterHooks();
        // register shortcodes
        $this->sparxstarRegisterShortcodes();
    }

    private function sparxstarRegisterHooks(): void
    {
        // Assets are enqueued only when the shortcode renders.
    }

    private function sparxstarRegisterShortcodes(): void
    {
        // Optional: Shortcode for easy usage in editors.
        add_shortcode('sparxstar_app_mode', $this->sparxstarRenderShortcode(...));
    }

    /**
     * Helper to resolve asset paths and URLs, preferring minified versions.
     *
     * @param string $relative_dir Relative directory from this file (e.g., '../../css/').
     * @param string $filename Base filename (e.g., 'style.css').
     * @param string $min_filename Optional minified filename.
     *
     * @return array{url: string, version: string|false}
     */
    private function sparxstarGetAsset(string $relative_dir, string $filename, string $min_filename = ''): array
    {
        $base_dir_path = plugin_dir_path(__FILE__) . $relative_dir;
        $base_dir_url = plugin_dir_url(__FILE__) . $relative_dir;

        // 1. Check Minified
        if ( $min_filename !== '' && $min_filename !== '0' && \file_exists($base_dir_path . $min_filename)) {
            return [
                'url' => $base_dir_url . $min_filename,
                'version' => (string) \filemtime($base_dir_path . $min_filename),
            ];
        }

        // 2. Check Standard
        if (file_exists($base_dir_path . $filename)) {
            return [
                'url' => $base_dir_url . $filename,
                'version' => (string) \filemtime($base_dir_path . $filename),
            ];
        }

        // 3. Fallback
        return [
            'url' => '',
            'version' => false,
        ];
    }

    /**
     * Enqueue JS and CSS with cache busting
     */
    public function sparxstarEnqueueAssets(): void
    {
        if ($this->assets_enqueued) {
            return;
        }

        $this->assets_enqueued = true;

        // Resolve CSS (minified assets first, source fallback)
        $css_asset = $this->sparxstarGetAsset(
            '../../../assets/css/',
            'sparxstar-app-mode.css',
            'sparxstar-app-mode.min.css'
        );

        if ($css_asset['url'] === '') {
            $css_asset = $this->sparxstarGetAsset(
                '../../css/',
                'spparxstar-app-mode.css',
                'sparxstar-app-mode.min.css'
            );
        }

        // Resolve JS (minified assets first, source fallback)
        $js_asset = $this->sparxstarGetAsset(
            '../../../assets/js/',
            'sparxstar-app-mode.js',
            'sparxstar-app-mode.min.js'
        );

        if ($js_asset['url'] === '') {
            $js_asset = $this->sparxstarGetAsset(
                '../../js/appmode/',
                'sparxstar-app-mode.js',
                'sparxstar-app-mode.min.js'
            );
        }

        // 1. Enqueue CSS
        if ( ! empty($css_asset['url'])) {
            wp_enqueue_style(
                'sparxstar-app-mode',
                $css_asset['url'],
                [],
                $css_asset['version'] ?: '1.2.1'
            );
        }

        // 2. Enqueue JS (In Footer = true)
        if ( ! empty($js_asset['url'])) {
            wp_enqueue_script(
                'sparxstar-app-mode',
                $js_asset['url'],
                [], // No jQuery dependency
                $js_asset['version'] ?: '1.2.1',
                true // Load in footer
            );
        }
    }

    public function sparxstarAppMode(array $atts, string $content): string
    {
        $this->sparxstarEnqueueAssets();

        return sprintf(
            '<div class="sparxstar-app-mode %s" aria-hidden="false">%s</div>',
            esc_attr($atts['class'] ?? ''),
            $content
        );
    }

    /**
     * Optional Shortcode: [sparxstar_app]Content[/sparxstar_app]
     */
    public function sparxstarRenderShortcode(array $atts = [], ?string $content = null): string
    {
        $atts = shortcode_atts([
            'class' => '',
        ], $atts, 'sparxstar_app_mode');

        $inner = '';

        if ($content !== null && $content !== '') {
            $inner = do_shortcode(shortcode_unautop($content));
        }

        // THIS is the programmatic execution you wanted
        return $this->sparxstarAppMode($atts, $inner);
    }
}
