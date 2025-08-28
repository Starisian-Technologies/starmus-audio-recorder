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
    private array $components = [];

    /**
     * Private constructor. Hooks into WordPress.
     */
    private function __construct()
    {
        // Use 'plugins_loaded' to run the initial setup.
        // This is safer than running logic directly in the constructor.
        add_action('plugins_loaded', [$this, 'bootstrap']);
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
     * Bootstraps the plugin: checks compatibility and hooks the main init function.
     * This is an INSTANCE method, so it can safely use $this.
     */
    public function bootstrap(): void
    {
        $this->load_textdomain();

        if ($this->check_compatibility()) {
            // If compatible, hook the main initialization.
            add_action('init', [$this, 'init']);
        } else {
            // If not compatible, show an admin notice.
            add_action('admin_notices', [$this, 'display_compatibility_notice']);
        }
    }

    /**
     * Wires up all plugin components. Runs on 'init'.
     */
    public function init(): void
    {
        // Load the procedural CPT file.
        require_once STARMUS_PATH . 'src/includes/StarmusCustomPostType.php';
        
        $this->instantiate_components();
        add_action('admin_notices', [$this, 'display_runtime_error_notice']);
    }

    private function instantiate_components(): void
    {
        if (is_admin()) {
            $this->instantiate_component(StarmusAdmin::class);
        }

        $this->instantiate_component(StarmusAudioEditorUI::class);
        $this->instantiate_component(StarmusAudioRecorderUI::class);
    }

    // --- Plugin Activation Hook (STATIC METHOD) ---
    // This is called directly by WordPress and CANNOT use $this.
    public static function activate(): void
    {
        // Load the CPT file to ensure the post type is available for flushing.
        require_once STARMUS_PATH . 'src/includes/StarmusCustomPostType.php';
        
        self::add_custom_capabilities();
        flush_rewrite_rules();
    }

    // --- (The rest of the file is correct and can remain as it was) ---
    
    public static function deactivate(): void { flush_rewrite_rules(); }
    public static function starmus_run(): void { self::get_instance(); }

    private function instantiate_component(string $class_name): void {
        if (isset($this->components[$class_name])) { return; }
        try {
            $instance = new $class_name();
            $this->components[$class_name] = $instance;
        } catch (Throwable $e) {
            $error_message = sprintf('Starmus Plugin: Runtime error while instantiating %s. Message: "%s"', $class_name, $e->getMessage());
            error_log($error_message);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                trigger_error($error_message, E_USER_WARNING);
            }
            $this->runtime_errors[] = $error_message;
        }
    }
    public function display_runtime_error_notice(): void {
        if (empty($this->runtime_errors) || !current_user_can('manage_options')) { return; }
        $unique_errors = array_unique($this->runtime_errors);
        foreach ($unique_errors as $message) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Starmus Plugin Error:</strong><br>' . esc_html($message) . '</p></div>';
        }
    }
    public function load_textdomain(): void {
        load_plugin_textdomain('starmus_audio_recorder', false, dirname(plugin_basename(STARMUS_MAIN_FILE)) . '/languages/');
    }
    private static function add_custom_capabilities(): void {
        $roles_to_modify = [
            'editor' => [self::CAP_EDIT_AUDIO, self::CAP_RECORD_AUDIO],
            'administrator' => [self::CAP_EDIT_AUDIO, self::CAP_RECORD_AUDIO],
            'contributor' => [self::CAP_RECORD_AUDIO],
            'community_contributor' => [self::CAP_RECORD_AUDIO],
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
    private function check_compatibility(): bool {
        if (version_compare(PHP_VERSION, self::MINIMUM_PHP_VERSION, '<')) {
            $this->compatibility_messages[] = sprintf(__('Starmus Audio Recorder requires PHP %1$s or higher. You are running %2$s.', 'starmus_audio_recorder'), self::MINIMUM_PHP_VERSION, PHP_VERSION);
        }
        if (version_compare(get_bloginfo('version'), self::MINIMUM_WP_VERSION, '<')) {
            $this->compatibility_messages[] = sprintf(__('Starmus Audio Recorder requires WordPress %1$s or higher. You are running %2$s.', 'starmus_audio_recorder'), self::MINIMUM_WP_VERSION, get_bloginfo('version'));
        }
        return empty($this->compatibility_messages);
    }
    public function display_compatibility_notice(): void {
        if (!current_user_can('activate_plugins')) { return; }
        foreach ($this->compatibility_messages as $message) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }
    public function __clone() { throw new \Exception('Cloning of ' . __CLASS__ . ' is not allowed.'); }
    public function __wakeup() { throw new \Exception('Unserializing of ' . __CLASS__ . ' is not allowed.'); }
}
