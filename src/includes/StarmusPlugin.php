<?php

/**
 * Main plugin class. Handles initialization, hooks, and compatibility checks.
 *
 * @package Starmus\includes
 */

namespace Starmus\includes;

use Starmus\admin\StarmusAdmin;
use Starmus\frontend\StarmusAudioEditorUI;
use Starmus\frontend\StarmusAudioRecorderUI;
use Exception; // Use the base Exception class for catching.

/**
 * Summary of StarmusPlugin
 * This class is responsible for initializing the Starmus Audio Recorder plugin,
 * including setting up custom post types, admin settings, and frontend UI components.
 */
final class StarmusPlugin
{
    public const MINIMUM_PHP_VERSION = '8.2';
    public const MINIMUM_WP_VERSION = '6.4';
    public const CAP_EDIT_AUDIO = 'starmus_edit_audio';
    public const CAP_RECORD_AUDIO = 'starmus_record_audio';

    private static ?StarmusPlugin $instance = null;
    private array $compatibility_messages = [];

    /**
     * @var array Stores runtime error messages to be displayed in an admin notice.
     */
    private array $runtime_errors = [];

    /**
     * Private constructor to enforce the singleton pattern.
     */
    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'load_textdomain']);

        if (!$this->check_compatibility()) {
            add_action('admin_notices', [$this, 'display_compatibility_notice']);
            return;
        }

        // Hook the main initialization and the error notice display.
        add_action('plugins_loaded', [$this, 'init']);
        add_action('admin_notices', [$this, 'display_runtime_error_notice']);
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
     * Loads the plugin text domain for internationalization.
     */
    public function load_textdomain(): void
    {
        load_plugin_textdomain(
            'starmus_audio_recorder',
            false,
            dirname(plugin_basename(STARMUS_MAIN_FILE)) . '/languages/'
        );
    }

    /**
     * Initializes all plugin features.
     */
    public function init(): void
    {
        $this->load_and_instantiate_components();
    }

    /**
     * Loads component files and safely instantiates their classes.
     */
    private function load_and_instantiate_components(): void
    {
        // --- Critical Components ---
        // The Custom Post Type is essential and should always be loaded.
        $cpt_file = STARMUS_PATH . 'src/includes/StarmusCustomPostType.php';
        if (file_exists($cpt_file)) {
            require_once $cpt_file;
            $this->instantiate_component(\Starmus\includes\StarmusCustomPostType::class);
        } elseif (defined('WP_DEBUG') && WP_DEBUG) {
            trigger_error('Starmus Plugin Critical Error: StarmusCustomPostType.php file is missing.', E_USER_WARNING);
        }

        // --- Conditional Components ---
        if (is_admin()) {
            $this->instantiate_component(StarmusAdmin::class);
        }

        if (is_user_logged_in()) {
            if (current_user_can(self::CAP_EDIT_AUDIO) || current_user_can('edit_posts')) {
                $this->instantiate_component(StarmusAudioEditorUI::class);
            }

            if (current_user_can(self::CAP_RECORD_AUDIO) || current_user_can('edit_posts') || current_user_can('contributor')) {
                $this->instantiate_component(StarmusAudioRecorderUI::class);
            }
        }
    }

    /**
     * Helper method to safely instantiate a class and report runtime exceptions.
     *
     * @param string $class_name The fully qualified name of the class to instantiate.
     * @return bool True on success, false on failure.
     */
    private function instantiate_component(string $class_name): bool
    {
        if (!class_exists($class_name)) {
            $error_message = "Starmus Plugin: Cannot instantiate component because class does not exist: $class_name";
            if (defined('WP_DEBUG') && WP_DEBUG) {
                trigger_error(esc_html($error_message), E_USER_WARNING);
            }
            return false;
        }

        try {
            // Attempt to create the component. A runtime error in the
            // constructor will be caught below.
            new $class_name();
            return true;
        } catch (Exception $e) {
            // A runtime exception was caught. Report it.
            $error_message = sprintf(
                'Starmus Plugin: A runtime error occurred in the constructor of %s. Error: "%s"',
                $class_name,
                $e->getMessage()
            );

            // Always log the specific error.
            error_log($error_message);

            // If debugging, trigger a visible PHP warning for immediate feedback.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                trigger_error(esc_html($error_message), E_USER_WARNING);
            }

            // Store the error to display in a persistent admin notice.
            $this->runtime_errors[] = $error_message;

            return false;
        }
    }

    /**
     * Displays any captured runtime errors as dismissible admin notices.
     */
    public function display_runtime_error_notice(): void
    {
        if (empty($this->runtime_errors) || !current_user_can('manage_options')) {
            return;
        }

        $unique_errors = array_unique($this->runtime_errors);
        foreach ($unique_errors as $message) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Starmus Audio Recorder Plugin Error:</strong><br>' . esc_html($message) . '</p></div>';
        }
    }

    /**
     * Public static method to run the plugin. Main entry point.
     */
    public static function starmus_run(): void
    {
        self::get_instance();
    }

    // --- Plugin Lifecycle Methods ---

    public static function activate(): void
    {
        // Ensure CPT is available for rewrite rule flushing.
        $cpt_file = STARMUS_PATH . 'src/includes/StarmusCustomPostType.php';
        if (file_exists($cpt_file)) {
            require_once $cpt_file;
            if (class_exists('\Starmus\includes\StarmusCustomPostType')) {
                new \Starmus\includes\StarmusCustomPostType();
            }
        }
        self::add_custom_capabilities();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public static function uninstall(): void
    {
        if (!current_user_can('activate_plugins')) { return; }

        if (!class_exists(StarmusSettings::class)) {
            $file = STARMUS_PATH . 'src/includes/StarmusSettings.php';
            if (file_exists($file)) { require_once $file; }
        }

        $cpt_slug = class_exists(StarmusSettings::class) ? StarmusSettings::get('cpt_slug', 'audio-recording') : 'audio-recording';

        $posts = get_posts(['post_type' => $cpt_slug, 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids']);
        foreach ($posts as $post_id) {
            wp_delete_post($post_id, true);
        }

        delete_option('starmus_settings');
        self::remove_custom_capabilities();
        flush_rewrite_rules();
    }

    // --- Capability Management ---

    private static function add_custom_capabilities(): void
    {
        $roles_to_modify = [
            'editor' => [self::CAP_EDIT_AUDIO, self::CAP_RECORD_AUDIO],
            'administrator' => [self::CAP_EDIT_AUDIO, self::CAP_RECORD_AUDIO],
            'contributor' => [self::CAP_RECORD_AUDIO],
            'community_contributor' => [self::CAP_RECORD_AUDIO]
        ];

        foreach ($roles_to_modify as $role_name => $caps) {
            $role = get_role($role_name);
            if ($role) {
                foreach ($caps as $cap) {
                    $role->add_cap($cap);
                }
            }
        }
    }

    private static function remove_custom_capabilities(): void
    {
        $roles_to_modify = ['editor', 'administrator', 'contributor', 'community_contributor'];
        foreach ($roles_to_modify as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                $role->remove_cap(self::CAP_EDIT_AUDIO);
                $role->remove_cap(self::CAP_RECORD_AUDIO);
            }
        }
    }

    // --- Compatibility Checks ---

    private function check_compatibility(): bool
    {
        if (version_compare(PHP_VERSION, self::MINIMUM_PHP_VERSION, '<')) {
            $this->compatibility_messages[] = sprintf(__('Starmus Audio Recorder requires PHP %1$s or higher. You are running %2$s.', 'starmus_audio_recorder'), self::MINIMUM_PHP_VERSION, PHP_VERSION);
        }
        if (version_compare(get_bloginfo('version'), self::MINIMUM_WP_VERSION, '<')) {
            $this->compatibility_messages[] = sprintf(__('Starmus Audio Recorder requires WordPress %1$s or higher. You are running %2$s.', 'starmus_audio_recorder'), self::MINIMUM_WP_VERSION, get_bloginfo('version'));
        }
        return empty($this->compatibility_messages);
    }

    public function display_compatibility_notice(): void
    {
        if (!is_admin()) { return; }
        foreach ($this->compatibility_messages as $message) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }

    // --- Singleton Prevention ---

    public function __clone() { throw new Exception('Cloning of ' . __CLASS__ . ' is not allowed.'); }
    public function __wakeup() { throw new Exception('Unserializing of ' . __CLASS__ . ' is not allowed.'); }
}
