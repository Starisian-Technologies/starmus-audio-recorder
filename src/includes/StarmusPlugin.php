<?php

/**
 * Main plugin class. Initializes hooks and manages plugin star_components.
 * This version uses a clean, linear loading sequence to avoid race conditions.
 *
 * @package Starmus\includes
 * @version 0.4.0
 * @since 0.1.0
 */

namespace Starmus\includes;

use Starmus\admin\StarmusAdmin;
use Starmus\frontend\StarmusAudioEditorUI;
use Starmus\frontend\StarmusAudioRecorderUI;
use LogicException;
use Throwable;

/**
 * Main plugin class (Singleton).
 *
 * This class is the central controller for the Starmus Audio Recorder plugin.
 * It ensures that all star_components are loaded in the correct order, handles plugin
 * lifecycle hooks (activation, deactivation), and manages custom capabilities.
 *
 * @package Starmus\includes
 * @since 0.1.0
 */
final class StarmusPlugin
{

	public const STAR_CAP_EDIT_AUDIO = 'starmus_edit_audio';
	public const STAR_CAP_RECORD_AUDIO = 'starmus_record_audio';

	private static ?StarmusPlugin $instance = null;
	private array $runtimeErrors = [];
	private array $componentClasses = [];
	private array $components = [];

	/**
	 * Private constructor for singleton pattern.
	 *
	 * Prevents direct instantiation of the class.
	 */
	private function __construct()
	{
		$this->componentClasses = [
			StarmusAdmin::class,
			StarmusAudioEditorUI::class,
			StarmusAudioRecorderUI::class,
		];
	}

	/**
	 * Main singleton instance method.
	 *
	 * Intentionally empty - initialization happens in init()
	 * Ensures that only one instance of the plugin's main class exists.
	 *
	 * @since 0.1.0
	 * @return StarmusPlugin The single instance of the class.
	 */
	public static function get_instance(): StarmusPlugin
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * The main "engine room" of the plugin, hooked to `init`.
	 *
	 * This method is responsible for loading essential star_components like text domain,
	 * custom post types, and instantiating all necessary classes.
	 *
	 * @since 0.1.0
	 */
	public function init(): void
	{
		// Load translations first.
		load_plugin_textdomain(STARMUS_TEXT_DOMAIN, false, dirname(plugin_basename(STARMUS_MAIN_FILE)) . '/languages/');

		// Load the procedural CPT file to register post types and taxonomies.
		$cpt_file = STARMUS_PATH . 'src/includes/StarmusCustomPostType.php';
		if (file_exists($cpt_file)) {
			require_once $cpt_file;
		} else {
			error_log('Starmus Plugin: CPT file not found: ' . $cpt_file);
			$this->runtimeErrors[] = 'Custom Post Type file is missing.';
		}

		// Instantiate all class-based star_components.
		$this->instantiateComponents();
		// Register all necessary hooks.
		$this->register_hooks();

		// Hook the admin notice for any runtime errors that occurred.
		add_action('admin_notices', array($this, 'displayRuntimeErrorNotice'));
	}

	public function getComponent(string $class_name): ?object
	{
		return $this->components[$class_name] ?? null;
	}

	public function register_hooks(): void
	{
		error_log('Starmus Plugin: Registering hooks.');
		// Register admin menu and settings ($this->componentClasses[StarmusAdmin::class])
		add_action( 'admin_menu', array( $this->componentClasses[StarmusAdmin::class], 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this->componentClasses[StarmusAdmin::class], 'register_settings' ) );

		// Register shortcodes and hooks for the frontend audio editor UI ($this->componentClasses[StarmusAudioEditorUI::class])
		add_shortcode( 'starmus_audio_editor', array( $this->componentClasses[StarmusAudioEditorUI::class], 'render_audio_editor_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this->componentClasses[StarmusAudioEditorUI::class], 'enqueue_scripts' ) );
		add_action( 'rest_api_init', array( $this->componentClasses[StarmusAudioEditorUI::class], 'register_rest_endpoint' ) );

		// Register shortcodes and hooks for the frontend audio recorder UI ($this->componentClasses[StarmusAudioRecorderUI::class])
		add_shortcode( 'starmus_my_recordings', array( $this->componentClasses[StarmusAudioRecorderUI::class], 'render_my_recordings_shortcode' ) );
		add_shortcode( 'starmus_audio_recorder', array( $this->componentClasses[StarmusAudioRecorderUI::class], 'render_recorder_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this->componentClasses[StarmusAudioRecorderUI::class], 'enqueue_scripts' ) );
		add_action( 'rest_api_init', array( $this->componentClasses[StarmusAudioRecorderUI::class], 'register_rest_routes' ) );
		add_action( 'starmus_after_audio_upload', array( $this->componentClasses[StarmusAudioRecorderUI::class], 'save_all_metadata' ), 10, 3 );
		add_filter( 'starmus_audio_upload_success_response', array( $this->componentClasses[StarmusAudioRecorderUI::class], 'add_conditional_redirect' ), 10, 3 );
		add_action( 'init', array( $this->componentClasses[StarmusAudioRecorderUI::class], 'maybe_schedule_cron' ) );
		add_action( 'starmus_cleanup_temp_files', array( $this->componentClasses[StarmusAudioRecorderUI::class], 'cleanup_stale_temp_files' ) );
		add_action( 'saved_term', array( $this->componentClasses[StarmusAudioRecorderUI::class], 'clear_taxonomy_transients' ) );
		add_action( 'delete_term', array( $this->componentClasses[StarmusAudioRecorderUI::class], 'clear_taxonomy_transients' ) );
	}

	/**
	 * Instantiates all the main component classes of the plugin.
	 *
	 * Conditionally loads admin-only classes and frontend classes.
	 *
	 * @since 0.1.0
	 */
	private function instantiateComponents(): void
	{
		try {
			foreach ($this->componentClasses as $class_name) {
				$this->instantiateComponent($class_name);
			}
		} catch (Throwable $e) {
			if ( (WP_DEBUG === true) && (WP_DEBUG_LOG === true) ) {
				trigger_error('Starmus Plugin: Runtime error during component instantiation - ' . sanitize_text_field($e->getMessage()), E_USER_WARNING);
			}
			$error_message = 'Starmus Plugin: Runtime error during component instantiation - ' . sanitize_text_field($e->getMessage());
			error_log($error_message);
			$this->runtimeErrors[] = $error_message;
		}
	}

	/**
	 * Plugin Activation Hook.
	 *
	 * Sets up the plugin on activation by adding custom capabilities and flushing rewrite rules.
	 *
	 * @since 0.1.0
	 */
	public static function activate(): void
	{
		try {
			$cpt_file = STARMUS_PATH . 'src/includes/StarmusCustomPostType.php';
			if (file_exists($cpt_file)) {
				require_once $cpt_file;
			} else {
				error_log('Starmus Plugin: CPT file not found during activation: ' . $cpt_file);
			}

			self::add_custom_capabilities();
			flush_rewrite_rules();
		} catch (Throwable $e) {
			error_log('Starmus Plugin: Activation error - ' . sanitize_text_field($e->getMessage()));
		}
	}

	/**
	 * Plugin Deactivation Hook.
	 *
	 * Flushes rewrite rules to ensure CPTs are no longer publicly accessible.
	 *
	 * @since 0.1.0
	 */
	public static function deactivate(): void
	{
		flush_rewrite_rules();
	}

	/**
	 * Plugin Uninstall Hook.
	 *
	 * Placeholder for future cleanup logic.
	 *
	 * @since 0.1.0
	 */
	public static function uninstall(): void
	{
		$file = STARMUS_PATH . 'uninstall.php';
		if (file_exists($file)) {
			require_once $file;
		} else {
			if ( (WP_DEBUG === true) && (WP_DEBUG_LOG === true) ) {
				trigger_error('Starmus Plugin: Uninstall file not found: ' . $file, E_USER_WARNING);
			}
		}
		// Clean up plugin data on uninstall
		flush_rewrite_rules();
	}

	/**
	 * Adds custom capabilities to user roles.
	 */
	private static function add_custom_capabilities(): void
	{
		$roles_to_modify = array(
			'editor' => array(self::STAR_CAP_EDIT_AUDIO, self::STAR_CAP_RECORD_AUDIO),
			'administrator' => array(self::STAR_CAP_EDIT_AUDIO, self::STAR_CAP_RECORD_AUDIO),
			'contributor' => array(self::STAR_CAP_RECORD_AUDIO),
			'community_contributor' => array(self::STAR_CAP_RECORD_AUDIO),
		);
		try {
			foreach ($roles_to_modify as $role_name => $caps) {
				$role = get_role($role_name);
				if ($role) {
					foreach ($caps as $cap) {
						$role->add_cap($cap);
					}
				}
			}
		} catch (Throwable $e) {
			if ( (WP_DEBUG === true) && (WP_DEBUG_LOG === true) ) {
				trigger_error('Starmus Plugin: Error adding capabilities - ' . sanitize_text_field($e->getMessage()), E_USER_WARNING);
			}
		}
	}

	/**
	 * Public static method to run the plugin.
	 *
	 * This is called from the main plugin file to get the singleton instance
	 * and start the plugin.
	 *
	 * @since 0.1.0
	 */
	public static function run(): void
	{
		self::get_instance();
	}

	/**
	 * Safely instantiates a component class and stores it.
	 *
	 * Catches any `Throwable` errors during instantiation, logs them,
	 * and adds them to an array for display as an admin notice.
	 *
	 * @param string $class_name The fully qualified name of the class to instantiate.
	 */
	private function instantiateComponent(string $class_name): void
	{
		if ( (isset($this->components[$class_name])) && is_object($this->components[$class_name]) ) {
			// Already instantiated
			error_log('Starmus Plugin: Component already instantiated: ' . sanitize_text_field($class_name));
			return;
		}
		try {
			$this->components[$class_name] = new $class_name();
		} catch (Throwable $e) {
			$error_message = sprintf('Starmus Plugin: Runtime error while instantiating %s. Message: "%s"', sanitize_text_field($class_name), sanitize_text_field($e->getMessage()));
			error_log($error_message);
			if ( (WP_DEBUG === true) && (WP_DEBUG_LOG === true) ) {
				trigger_error($error_message, E_USER_WARNING);
			}
			$this->runtimeErrors[] = $error_message;
		}
	}
	/**
	 * Displays a dismissible admin notice for any runtime errors.
	 *
	 * This notice is only shown to users who can 'manage_options'.
	 *
	 * @since 0.1.0
	 */
	public function displayRuntimeErrorNotice(): void
	{
		try {
			if (empty($this->runtimeErrors) || !current_user_can('manage_options')) {
				return;
			}
			$unique_errors = array_unique($this->runtimeErrors);
			foreach ($unique_errors as $message) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Starmus Audio Recorder Plugin Error:</strong><br>' . esc_html($message) . '</p></div>';
			}
		} catch (Throwable $e) {
			error_log($e->getMessage());
		}
	}
	/**
	 * Prevents cloning of the singleton instance.
	 *
	 * @since 0.1.0
	 * @throws LogicException If someone tries to clone the object.
	 */
	public function __clone()
	{
		throw new LogicException('Cloning of ' . __CLASS__ . ' is not allowed.');
	}

	/**
	 * Prevents unserializing of the singleton instance.
	 *
	 * @since 0.1.0
	 * @throws LogicException If someone tries to unserialize the object.
	 */
	public function __wakeup()
	{
		throw new LogicException('Unserializing of ' . __CLASS__ . ' is not allowed.');
	}
}
