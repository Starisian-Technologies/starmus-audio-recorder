<?php
namespace Starmus\frontend;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

use Starmus\includes\StarmusSettings;

/**
 * Manages the loading of custom templates for Starmus CPTs and pages,
 * with logic for different user roles and views.
 */
class StarmusTemplateLoader
{
    private ?StarmusSettings $settings = null;

    public function __construct(?StarmusSettings $settings)
    {
        $this->settings = $settings;
        add_filter('template_include', array($this, 'load_custom_template'), 99);
    }

    /**
     * Intercepts the WordPress template hierarchy to load the correct Starmus template.
     *
     * @param string $original_template The template file WordPress originally intended to load.
     * @return string The path to the new template file, or the original if no match is found.
     */
    public function load_custom_template(string $original_template): string
    {
        try {
            // --- 1. Handle the Single Audio Recording Detail Page ---
            if (\is_singular('audio-recording')) {
                global $post;
                if (!is_object($post) || !isset($post->ID)) {
                    throw new \Exception('Post object missing or invalid in template loader.');
                }

                if (current_user_can('edit_post', $post->ID)) {
                    $template_name = 'starmus-recording-detail-admin.php';
                } else {
                    $template_name = 'starmus-recording-detail-user.php';
                }

                $new_template = $this->locate_template($template_name);
                if ($new_template) {
                    /**
                     * Fires right before a Starmus template is loaded.
                     *
                     * @param string $new_template The resolved template path.
                     * @param \WP_Post|null $post The current post object, if available.
                     */
                    do_action('starmus_before_template_load', $new_template, $post ?? null);

                    return $new_template;
                }
            }

            // --- 2. Handle the "My Submissions" Archive Page ---
            if (\is_post_type_archive('audio-recording')) {
                $new_template = $this->locate_template('starmus-my-recordings-list.php');
                if ($new_template) {
                    do_action('starmus_before_template_load', $new_template, null);
                    return $new_template;
                }
            }
        } catch (\Throwable $e) {
            error_log('StarmusTemplateLoader error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            if (is_admin() && function_exists('add_action')) {
                add_action('admin_notices', function () use ($e) {
                    echo '<div class="notice notice-error"><p><strong>Starmus Template Loader Error:</strong> ' . esc_html($e->getMessage()) . '</p></div>';
                });
            }
        }

        // If no specific Starmus template is found, return the theme's default.
        return $original_template;
    }

    /**
     * Locates a template file, checking the theme/child theme first, then the plugin.
     *
     * @param string $template_name The name of the template file to find.
     * @return string|null The full path to the template file, or null if not found.
     */
    private function locate_template(string $template_name): ?string
    {
        // Check the active theme's directory for a 'starmus' subfolder first.
        $theme_template = \locate_template('starmus/' . $template_name);
        if ($theme_template) {
            return $theme_template;
        }

        // If no override is found in the theme, use the template from our plugin's directory.
        $plugin_template_path = STARMUS_PATH . 'src/templates/' . $template_name;
        if (file_exists($plugin_template_path)) {
            return $plugin_template_path;
        }

        return null;
    }
}
