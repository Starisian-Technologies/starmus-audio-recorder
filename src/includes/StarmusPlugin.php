<?php

/**
 * Main plugin class. Handles initialization, hooks, and compatibility checks.
 *
 * @package Starmus\includes
 */

namespace Starmus\includes;

// These are the 'use' statements that match YOUR specific file structure.
// They will work with a custom autoloader that understands this structure.
use Starmus\admin\StarmusAdmin;
use Starmus\frontend\StarmusAudioEditorUI;
use Starmus\frontend\StarmusAudioRecorderUI;
use Starmus\includes\StarmusSettings;

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
        add_action('init', [$this, 'starmus_init']);
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
            'starmus_audio_recorder', // Your text domain
            false,
            dirname(plugin_basename(STARMUS_MAIN_FILE)) . '/languages/'
        );
    }

    /**
     * Initializes all plugin features.
     */
    public function starmus_init(): void
    {
         // We include this file to execute its 'add_action' calls to build cpt.
        $file = STARMUS_PATH . 'src/includes/StarmusCustomPostType.php';
        if (!file_exists($file)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('StarmusCustomPostType.php file is missing.');
            }
        }
        require_once $file;

        if (is_admin()) {
            new StarmusAdmin();
        }

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if (array_intersect(['contributor','author', 'editor', 'administrator'], (array) $user->roles) || is_super_admin($user->ID)) {
                new StarmusAudioEditorUI();
            }
            if (array_intersect(['contributor', 'community_contributor', 'editor', 'author', 'administrator'], (array) $user->roles) || is_super_admin($user->ID)) {
                new StarmusAudioRecorderUI();
            }
        }
    }

    public static function starmus_run(): void
    {
        self::get_instance();
        return;
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
        // This requires the StarmusSettings class to be loaded.
        if (!class_exists(StarmusSettings::class)) {
            $file = STARMUS_PATH . 'src/includes/StarmusSettings.php';
            if(file_exists($file)){
                require_once $file;
            }
        }
        $cpt_slug = StarmusSettings::starmus_get_option('cpt_slug', $cpt_slug);


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
                __('Starmus Audio Recorder requires PHP %1$s or higher. You are running %2$s.', 'starmus_audio_recorder'),
                self::MINIMUM_PHP_VERSION,
                PHP_VERSION
            );
        }

        if (version_compare(get_bloginfo('version'), self::MINIMUM_WP_VERSION, '<')) {
            $this->compatibility_messages[] = sprintf(
                __('Starmus Audio Recorder requires WordPress %1$s or higher. You are running %2$s.', 'starmus_audio_recorder'),
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
