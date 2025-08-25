<?php

/**
 * Main plugin class. Handles initialization, hooks, and compatibility checks.
 *
 * @package Starmus\includes
 */

namespace Starmus\includes;

// These are the 'use' statements that match YOUR specific file structure.
// They will work with a custom autoloader that understands this structure.
use Starisian\admin\StarmusAdmin;
use Starisian\frontend\StarmusAudioEditorUI;
use Starisian\frontend\StarmusAudioRecorderUI;
use Starisian\includes\StarmusCustomPostType;

/**
 * Summary of StarmusPlugin
 * This class is responsible for initializing the Starmus Audio Recorder plugin,
 * including setting up custom post types, admin settings, and frontend UI components.
 * 
 */
final class StarmusPlugin
{
    public const MINIMUM_PHP_VERSION = '8.2';
    public const MINIMUM_WP_VERSION = '6.4';

    private static ?StarmusPlugin $instance = null;
    private array $compatibility_messages = [];

    /**
     * Private constructor to enforce the singleton pattern.
     */
    private function __construct()
    {
        // --- ADD THIS ---
        // Load the plugin text domain for translation.
        add_action('init', [$this, 'load_textdomain']);

        if (! $this->check_compatibility()) {
            add_action('admin_notices', [$this, 'display_compatibility_notice']);
            return;
        }
        add_action('init', [$this, 'init_plugin_features']);
    }
    /**
     * Main singleton instance method.
     */
    public static function get_instance(): StarmusPlugin
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Loads the plugin text domain.
     */
    public function load_textdomain(): void
    {
        load_plugin_textdomain(
            STARMUS_TEXT_DOMAIN, // Your text domain
            false,
            dirname(plugin_basename(STARMUS_MAIN_FILE)) . '/languages/'
        );
    }

    /**
     * Initializes all plugin features.
     */
    public function init_plugin_features(): void
    {
         // We include this file to execute its 'add_action' calls to build cpt.
        require_once STARMUS_PATH . 'src/includes/StarmusCustomPostType.php';

        if (is_admin()) {
            new StarmusAdmin();
        }

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if (array_intersect(['author', 'editor', 'administrator'], (array) $user->roles) || is_super_admin($user->ID)) {
                new StarmusAudioEditorUI();
            }
            if (array_intersect(['contributor', 'community_contributor', 'editor', 'author', 'administrator'], (array) $user->roles) || is_super_admin($user->ID)) {
                new StarmusAudioRecorderUI();
            }
        }
    }

    // --- Plugin Lifecycle Methods ---

    public static function activate(): void
    {
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public static function uninstall(): void
    {
        $cpt_slug = 'starmus-admin';
        // This requires the StarmusAdminSettings class to be loaded.
        if (class_exists(StarmusAdmin::class)) {
            $cpt_slug = StarmusAdmin::get_option('cpt_slug', $cpt_slug);
        }

        delete_option('starmus_settings');

        $posts = get_posts([
            'post_type'   => $cpt_slug,
            'numberposts' => -1,
            'post_status' => 'any',
        ]);

        foreach ($posts as $post) {
            wp_delete_post($post->ID, true);
        }
    }

    // --- Compatibility Checks ---

    private function check_compatibility(): bool
    {
        if (version_compare(PHP_VERSION, self::MINIMUM_PHP_VERSION, '<')) {
            $this->compatibility_messages[] = sprintf(
                __('Starmus Audio Recorder requires PHP %1$s or higher. You are running %2$s.', 'starmus-audio-recorder'),
                self::MINIMUM_PHP_VERSION,
                PHP_VERSION
            );
        }

        if (version_compare(get_bloginfo('version'), self::MINIMUM_WP_VERSION, '<')) {
            $this->compatibility_messages[] = sprintf(
                __('Starmus Audio Recorder requires WordPress %1$s or higher. You are running %2$s.', 'starmus-audio-recorder'),
                self::MINIMUM_WP_VERSION,
                get_bloginfo('version')
            );
        }

        return empty($this->compatibility_messages);
    }

    public function display_compatibility_notice(): void
    {
        foreach ($this->compatibility_messages as $message) {
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        }
    }

    public function __clone()
    {
        // Prevent cloning
        throw new \Exception('Cloning of ' . __CLASS__ . ' is not allowed.');
    }
}
