<?php

/**
 * Template Loaders Helper class
 *
 * @package Starisian\Sparxstar\Starmus\helpers
 *
 * @version 0.8.5
 *
 * @since 0.7.4
 */

namespace Starisian\Sparxstar\Starmus\helpers;

class StarmusTemplateLoaderHelper
{
    /**
     * Securely render a template for logged-in users only.
     *
     * @param string $template Path or slug of the template to render.
     * @param array $args Variables to pass into the template.
     * @param string $user_group Optional. User group required. Defaults to 'admin'.
     *
     * @return string Rendered HTML output.
     */
    public static function secure_render_template(string $template, array $args = [], string $user_group = 'admin'): string
    {
        if (! is_user_logged_in()) {
            return '<p>' . esc_html__('You must be logged in to record audio.', 'starmus-audio-recorder') . '</p>';
        }

        do_action('starmus_before_secure_template_render');

        $is_admin = current_user_can('administrator') || current_user_can('manage_options') || current_user_can('super_admin');

        $secure_flag = '<script>window.isStarmusAdmin = ' . ($is_admin ? 'true' : 'false') . ';</script>';

        return $secure_flag . self::render_template($template, $args);
    }

    /**
     * Run after rendering a template.
     */
    private static function post_template_render(string $output): string
    {
        do_action('starmus_after_template_render');
        return $output;
    }

    /**
     * Render a PHP template file with variables.
     *
     * @param string $template Full template path.
     * @param array $args Variables.
     */
    private static function render(string $template, array $args): string
    {
        ob_start();
        extract($args, EXTR_SKIP);

        include $template;
        return ob_get_clean();
    }

    /**
     * Render the editor template with provided arguments.
     *
     * @param string $template Template file to be rendered.
     * @param array $args Data exposed to the template during rendering.
     *
     * @return string Rendered markup or error notice.
     */
    public static function render_template(string $template, array $args = []): string
    {
        try {
            error_log('[StarmusTemplateLoader] Attempting to load template: ' . $template);

            $template_path = self::locate_template($template);

            if (! $template_path) {
                error_log('[StarmusTemplateLoader] Template not found: ' . $template);
                error_log('[StarmusTemplateLoader] STARMUS_PATH: ' . (defined('STARMUS_PATH') ? STARMUS_PATH : 'NOT DEFINED'));
                return '<div class="notice notice-error"><p>Template not found: ' . esc_html($template) . '</p></div>';
            }

            error_log('[StarmusTemplateLoader] Template found at: ' . $template_path);
            error_log('[StarmusTemplateLoader] Template args: ' . print_r(array_keys($args), true));

            do_action('starmus_before_template_render');

            // Allow filters to modify the args if needed.
            $args = apply_filters(basename($template_path), $args, $template_path);

            $output = self::post_template_render(self::render($template_path, $args));

            error_log('[StarmusTemplateLoader] Template rendered successfully, output length: ' . strlen($output));

            return $output;
        } catch (\Throwable $throwable) {
            error_log('[StarmusTemplateLoader] ERROR: ' . $throwable->getMessage());
            error_log('[StarmusTemplateLoader] Stack trace: ' . $throwable->getTraceAsString());
            StarmusLogger::log('UI:render_template', 'Starmus Template Loader Error: ' . $throwable->getMessage());
            return '<div class="notice notice-error"><p>' . esc_html__('Template loading failed: ', 'starmus-audio-recorder') . esc_html($throwable->getMessage()) . '</p></div>';
        }
    }

    /**
     * Locates a template file: checks theme first, then plugin.
     *
     * @param string $template Template file name.
     *
     * @return string|null Full path or null if not found.
     */
    public static function locate_template(string $template): ?string
    {
        $template_name = basename($template);
        $locations     = [
            trailingslashit(get_stylesheet_directory()) . 'starmus/' . $template_name,
            trailingslashit(get_template_directory()) . 'starmus/' . $template_name,
            trailingslashit(STARMUS_PATH) . 'src/templates/' . $template_name,
        ];

        foreach ($locations as $location) {
            if (file_exists($location)) {
                return $location;
            }
        }

        // Log for debugging if template not found.
        StarmusLogger::log('UI:locate_template', 'Template not found: ' . $template_name);
        return null;
    }

    /**
     * Admin edit screen URL.
     */
    public static function get_edit_page_url_admin(?string $cpt_slug = 'audio-recording'): string
    {
        return admin_url('edit.php?post_type=' . $cpt_slug);
    }
}
