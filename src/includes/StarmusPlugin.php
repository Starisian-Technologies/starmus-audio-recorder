<?php
/**
 * Main plugin class. Initializes hooks and manages plugin components.
 *
 * @package Starmus\includes
 */

namespace Starmus\includes;

use Starmus\admin\StarmusAdmin;
use Starmus\frontend\StarmusAudioEditorUI;
use Starmus\frontend\StarmusAudioRecorderUI;
use Throwable; // Catch both Exceptions and Errors (PHP 7+) for maximum robustness.

/**
 * Main plugin class. Initializes hooks and manages plugin components.
 * Adheres to a singleton pattern to ensure it is only loaded once.
 */
final class StarmusPlugin
{
    public const MINIMUM_PHP_VERSION = '8.2';
    public const MINIMUM_WP_VERSION = '6.4';
    public const CAP_EDIT_AUDIO = 'starmus_edit_audio';
    public const CAP_RECORD_AUDIO = 'starmus_record_audio';

    private static ?StarmusPlugin $instance = null;
    private array $compatibility_messages = [];
    private array $runtime_errors = [];

    /**
     * @var array<string, object> Holds instantiated component objects for potential later use or unhooking.
     */
    private array $components = [];

    /**
     * Private constructor to enforce the singleton pattern.
     * It should only set up the most essential hooks.
     */
    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'load_textdomain']);

        if ($this->check_compatibility()) {
            // Defer all major logic to the 'init' hook to ensure the WordPress environment is ready.
            add_action('init', [$this, 'init']);
        } else {
            // If incompatible, only register the notice.
            add_action('admin_notices', [$this, 'display_compatibility_notice']);
        }
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
     * Wires up all plugin components.
     * This runs on the 'init' hook, ensuring user capabilities and roles are available.
     */
    public function init(): void
    {
        $this->instantiate_components();
        add_action('admin_notices', [$this, 'display_runtime_error_notice']);
    }

    /**
     * Instantiates all necessary plugin components in the correct order and at the right time.
     */
    private function instantiate_components(): void
    {
        // The Custom Post Type is fundamental and should be initialized on 'init'.
        $this->instantiate_component(StarmusCustomPostType::class);

        if (is_admin()) {
            $this->instantiate_component(StarmusAdmin::class);
        }

        // Defer frontend checks until the 'wp' action hook, when the main query is set up.
        // This is a form of "lazy-loading" that prevents unnecessary object creation on every page load.
        add_action('wp', function() {
            if (!is_user_logged_in()) {
                return;
            }

            if (current_user_can(self::CAP_EDIT_AUDIO) || current_user_can('edit_posts')) {
                $this->instantiate_component(StarmusAudioEditorUI::class);
            }

            if (current_user_can(self::CAP_RECORD_AUDIO) || current_user_can('edit_posts') || current_user_can('contributor')) {
                $this->instantiate_component(StarmusAudioRecorderUI::class);
            }
        });
    }

    /**
     * Safely instantiates a component class, handles errors, and stores the instance.
     *
     * @param string $class_name The fully qualified name of the class to instantiate.
     */
    private function instantiate_component(string $class_name): void
    {
        // Don't re-instantiate if it already exists.
        if (isset($this->components[$class_name])) {
            return;
        }

        try {
            // The PSR-4 autoloader should handle class availability.
            $instance = new $class_name();
            $this->components[$class_name] = $instance; // Store the instance.

        } catch (Throwable $e) {
            $error_message = sprintf(
                'Starmus Plugin: Runtime error while instantiating %s. Message: "%s"',
                $class_name,
                $e->getMessage()
            );

            // Log the error. error_log() respects WP_DEBUG_LOG.
            error_log($error_message);

            // Trigger a warning for developers if WP_DEBUG is on.
            // Do not escape HTML here; error logs should contain raw data.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                trigger_error($error_message, E_USER_WARNING);
            }

            $this->runtime_errors[] = $error_message;
        }
    }

    /**
     * Displays captured runtime errors as persistent admin notices.
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
     * Loads the plugin text domain for internationalization.
     */
    public function load_textdomain(): void
    {
        load_plugin_textdomain('starmus_audio_recorder', false, dirname(plugin_basename(STARMUS_MAIN_FILE)) . '/languages/');
    }

    // --- Plugin Lifecycle Methods (Called statically from the bootstrap file) ---

    public static function activate(): void
    {
        // On activation, directly load the CPT class to ensure the post type is registered.
        require_once STARMUS_PATH . 'src/includes/StarmusCustomPostType.php';
        new StarmusCustomPostType();

        self::add_custom_capabilities();
        flush_rewrite_rules(); // Flush rules after CPT and capabilities are correctly defined.
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    private static function add_custom_capabilities(): void
    {
        $roles_to_modify = [
            'editor'                => [self::CAP_EDIT_AUDIO, self::CAP_RECORD_AUDIO],
            'administrator'         => [self::CAP_EDIT_AUDIO, self::CAP_RECORD_AUDIO],
            'contributor'           => [self::CAP_RECORD_AUDIO],
            'community_contributor' => [self::CAP_RECORD_AUDIO], // Custom role example
        ];

        foreach ($roles_to_modify as $role_name => $caps) {
            $role = get_role($role_name);
            // Harden by verifying the role exists before adding capabilities.
            if ($role) {
                foreach ($caps as $cap) {
                    $role->add_cap($cap);
                }
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
        if (!current_user_can('activate_plugins')) { return; }
        foreach ($this->compatibility_messages as $message) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }

    /**
     * Public static method to run the plugin. This is the main entry point.
     */
    public static function starmus_run(): void
    {
        self::get_instance();
    }

    // --- Singleton Prevention ---

    public function __clone() { throw new \Exception('Cloning of ' . __CLASS__ . ' is not allowed.'); }
    public function __wakeup() { throw new \Exception('Unserializing of ' . __CLASS__ . ' is not allowed.'); }
}
