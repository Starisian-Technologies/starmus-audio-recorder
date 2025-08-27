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
use Starmus\includes\StarmusSettings;
use Exception; // It's good practice to explicitly use common exceptions.

/**
 * Summary of StarmusPlugin
 * This class is responsible for initializing the Starmus Audio Recorder plugin,
 * including setting up custom post types, admin settings, and frontend UI components.
 */
final class StarmusPlugin
{
    public const MINIMUM_PHP_VERSION = '8.2';
    public const MINIMUM_WP_VERSION = '6.4';

    // Define custom capabilities for better control over who can use the UI components.
    // It's good to define these as constants for consistency.
    public const CAP_EDIT_AUDIO = 'starmus_edit_audio';
    public const CAP_RECORD_AUDIO = 'starmus_record_audio';

    private static ?StarmusPlugin $instance = null;
    private array $compatibility_messages = [];

    /**
     * Private constructor to enforce the singleton pattern.
     */
    private function __construct()
    {
        // Load the plugin text domain as early as possible.
        // `plugins_loaded` is often a better hook than `init` for textdomain,
        // as it's fired earlier and ensures translations are available for all hooks.
        add_action('plugins_loaded', [$this, 'load_textdomain']);

        if (!$this->check_compatibility()) {
            add_action('admin_notices', [$this, 'display_compatibility_notice']);
            // If incompatible, prevent further plugin initialization.
            return;
        }

        // Use plugins_loaded for the main initialization to ensure all other plugins are ready.
        // This is generally safer than 'init' for plugin-level setup.
        add_action('plugins_loaded', [$this, 'starmus_init']);

        // Register activation/deactivation/uninstall hooks if not done in the main plugin file.
        // It's generally best to register these outside the class in the main plugin file.
        // If registering here, ensure it's done only once.
        // register_activation_hook(STARMUS_MAIN_FILE, [__CLASS__, 'activate']);
        // register_deactivation_hook(STARMUS_MAIN_FILE, [__CLASS__, 'deactivate']);
        // register_uninstall_hook(STARMUS_MAIN_FILE, [__CLASS__, 'uninstall']);
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
     * This method is now much cleaner and delegates to specific private methods.
     */
    public function starmus_init(): void
    {
        $this->load_custom_post_type();
        $this->initialize_admin_components();
        $this->initialize_frontend_user_interfaces();
        // You might also register settings, widgets, shortcodes here, e.g.:
        // new StarmusSettings(); // If StarmusSettings handles its own hooks.
    }

    /**
     * Loads the Custom Post Type file and instantiates its class.
     */
    private function load_custom_post_type(): void
    {
        $cpt_file = STARMUS_PATH . 'src/includes/StarmusCustomPostType.php';

        if (!file_exists($cpt_file)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Starmus: StarmusCustomPostType.php file is missing.');
            }
            // If CPT is critical, consider deactivating plugin or stopping further execution.
            return;
        }
        require_once $cpt_file;

        // Assuming StarmusCustomPostType class exists and its constructor registers hooks.
        if (class_exists('Starmus\includes\StarmusCustomPostType')) {
            new \Starmus\includes\StarmusCustomPostType();
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Starmus: StarmusCustomPostType class not found after inclusion.');
            }
        }
    }

    /**
     * Initializes admin-specific components if in the admin area.
     */
    private function initialize_admin_components(): void
    {
        if (is_admin()) {
            if (class_exists(StarmusAdmin::class)) { // Use ::class for better refactoring.
                new StarmusAdmin();
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Starmus: StarmusAdmin class not found.');
                }
            }
        }
    }

    /**
     * Initializes frontend user interfaces based on user login and capabilities.
     */
    private function initialize_frontend_user_interfaces(): void
    {
        if (!is_user_logged_in()) {
            return;
        }

        // Define which capabilities grant access to the editor/recorder.
        // It's often better to define a custom capability and then assign it to roles.
        // For example, on activation, assign 'starmus_edit_audio' to 'editor' and 'administrator'.

        // Check for Audio Editor UI access
        if (current_user_can(self::CAP_EDIT_AUDIO) || current_user_can('edit_posts')) {
            if (class_exists(StarmusAudioEditorUI::class)) {
                new StarmusAudioEditorUI();
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Starmus: StarmusAudioEditorUI class not found.');
                }
            }
        }

        // Check for Audio Recorder UI access
        // Including 'community_contributor' explicitly as it's a plugin-specific role.
        if (current_user_can(self::CAP_RECORD_AUDIO) ||
            current_user_can('edit_posts') || // Broad capability for admins/editors
            current_user_can('contributor') ||
            current_user_can('community_contributor') // Specific plugin role
        ) {
            if (class_exists(StarmusAudioRecorderUI::class)) {
                new StarmusAudioRecorderUI();
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Starmus: StarmusAudioRecorderUI class not found.');
                }
            }
        }
    }

    /**
     * Public static method to run the plugin.
     * This is the main entry point from the plugin's root file.
     */
    public static function starmus_run(): void
    {
        self::get_instance();
    }

    // --- Plugin Lifecycle Methods ---

    /**
     * Handles plugin activation.
     * Registers custom capabilities and flushes rewrite rules.
     */
    public static function activate(): void
    {
        // Ensure CPTs are registered before flushing rewrite rules.
        // Temporarily instantiate the CPT class here if its constructor registers post types.
        if (class_exists('\Starmus\includes\StarmusCustomPostType')) {
            new \Starmus\includes\StarmusCustomPostType();
        } else {
            // If the CPT class isn't loaded, require it here for activation.
            // This ensures rewrite rules for the CPT are correctly generated.
            $cpt_file = STARMUS_PATH . 'src/includes/StarmusCustomPostType.php';
            if (file_exists($cpt_file)) {
                require_once $cpt_file;
                if (class_exists('\Starmus\includes\StarmusCustomPostType')) {
                    new \Starmus\includes\StarmusCustomPostType();
                }
            }
        }

        // Add custom capabilities.
        self::add_custom_capabilities();

        flush_rewrite_rules();
    }

    /**
     * Handles plugin deactivation.
     * Flushes rewrite rules.
     */
    public static function deactivate(): void
    {
        // Flush rewrite rules to remove plugin's custom rules.
        flush_rewrite_rules();

        // Optionally, remove custom capabilities on deactivation.
        // self::remove_custom_capabilities();
    }

    /**
     * Handles plugin uninstallation.
     * Deletes plugin options, custom posts, and custom capabilities.
     */
    public static function uninstall(): void
    {
        // Check for current user capabilities to prevent unauthorized uninstallation.
        if (!current_user_can('activate_plugins')) {
            return;
        }

        // Ensure StarmusSettings is available to get the CPT slug.
        if (!class_exists(StarmusSettings::class)) {
            $file = STARMUS_PATH . 'src/includes/StarmusSettings.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }

        $cpt_slug = 'starmus-audio'; // Default slug if settings not found
        if (class_exists(StarmusSettings::class)) {
            $cpt_slug = StarmusSettings::starmus_get_option('cpt_slug', $cpt_slug);
        }

        // Delete all posts of the custom post type.
        $posts = get_posts([
            'post_type'      => $cpt_slug,
            'numberposts'    => -1,
            'post_status'    => 'any',
            'fields'         => 'ids', // Only fetch IDs for performance
            'perm'           => 'fully_public', // Ensure all posts are fetched
            'suppress_filters' => true, // Bypass filters that might hide posts
        ]);

        foreach ($posts as $post_id) {
            wp_delete_post($post_id, true); // true for permanent deletion
        }

        // Delete plugin options from the database.
        delete_option('starmus_settings');
        // If there are other options, delete them here too.
        // delete_option('other_starmus_option');

        // Remove custom capabilities and roles.
        self::remove_custom_capabilities();

        // Ensure rewrite rules are flushed one last time to remove CPT rules.
        flush_rewrite_rules();
    }

    /**
     * Adds custom capabilities to relevant user roles.
     * This should be called on plugin activation.
     */
    private static function add_custom_capabilities(): void
    {
        // Get roles that should have the capabilities.
        $editor_role = get_role('editor');
        $admin_role = get_role('administrator');
        $contributor_role = get_role('contributor'); // For CAP_RECORD_AUDIO
        $community_contributor_role = get_role('community_contributor'); // Your custom role

        if ($editor_role) {
            $editor_role->add_cap(self::CAP_EDIT_AUDIO);
            $editor_role->add_cap(self::CAP_RECORD_AUDIO);
        }
        if ($admin_role) {
            $admin_role->add_cap(self::CAP_EDIT_AUDIO);
            $admin_role->add_cap(self::CAP_RECORD_AUDIO);
        }
        if ($contributor_role) {
            $contributor_role->add_cap(self::CAP_RECORD_AUDIO);
        }
        // If 'community_contributor' role exists, add capability to it.
        if ($community_contributor_role) {
            $community_contributor_role->add_cap(self::CAP_RECORD_AUDIO);
        }
        // You might also create the 'community_contributor' role here if it's dynamic.
        // e.g., if (!get_role('community_contributor')) { add_role(...) }
    }

    /**
     * Removes custom capabilities from roles.
     * This can be called on deactivation or uninstall.
     */
    private static function remove_custom_capabilities(): void
    {
        $roles = ['editor', 'administrator', 'contributor', 'community_contributor']; // Add all relevant roles

        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                $role->remove_cap(self::CAP_EDIT_AUDIO);
                $role->remove_cap(self::CAP_RECORD_AUDIO);
            }
        }
        // If you added a custom role like 'community_contributor' dynamically,
        // you might want to remove it here as well.
        // remove_role('community_contributor');
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
        // Only display notices in the admin area.
        if (!is_admin()) {
            return;
        }

        foreach ($this->compatibility_messages as $message) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }

    /**
     * Prevent cloning of the instance.
     * @throws Exception
     */
    public function __clone()
    {
        throw new Exception('Cloning of ' . __CLASS__ . ' is not allowed.');
    }

    /**
     * Prevent unserializing of the instance.
     * @throws Exception
     */
    public function __wakeup()
    {
        throw new Exception('Unserializing of ' . __CLASS__ . ' is not allowed.');
    }
}
