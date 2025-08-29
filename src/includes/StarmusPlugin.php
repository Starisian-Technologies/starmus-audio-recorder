<?php
/**
 * Main plugin class. Initializes hooks and manages plugin components.
 * This version uses a clean, linear loading sequence to avoid race conditions.
 *
 * @since 0.1.0
 * @version 0.3.0
 * @package Starmus\includes
 */

namespace Starmus\includes;

use Starmus\admin\StarmusAdmin;
use Starmus\frontend\StarmusAudioEditorUI;
use Starmus\frontend\StarmusAudioRecorderUI;
use Throwable;

/**
 * Main plugin class (Singleton).
 *
 * This class is the central controller for the Starmus Audio Recorder plugin.
 * It ensures that all components are loaded in the correct order, handles plugin
 * lifecycle hooks (activation, deactivation), and manages custom capabilities.
 *
 * @package Starmus\includes
 * @since 0.1.0
 */
final class StarmusPlugin {

	public const CAP_EDIT_AUDIO   = 'starmus_edit_audio';
	public const CAP_RECORD_AUDIO = 'starmus_record_audio';

	private static ?StarmusPlugin $instance = null;
	private array $runtime_errors           = array();
	private array $components               = array();

	/**
	 * Private constructor. Its only job is to register the main init hook.
	 */
	private function __construct() {
		// do nothing
	}

	/**
	 * Main singleton instance method.
	 *
	 * Ensures that only one instance of the plugin's main class exists.
	 *
	 * @since 0.1.0
	 * @return StarmusPlugin The single instance of the class.
	 */
	public static function get_instance(): StarmusPlugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * The main "engine room" of the plugin, hooked to `init`.
	 *
	 * This method is responsible for loading essential components like text domain,
	 * custom post types, and instantiating all necessary classes.
	 *
	 * @since 0.1.0
	 */
	public function starmus_init(): void {
		// Load translations first.
		load_plugin_textdomain( 'starmus_audio_recorder', false, dirname( plugin_basename( STARMUS_MAIN_FILE ) ) . '/languages/' );

		// Load the procedural CPT file to register post types and taxonomies.
		require_once STARMUS_PATH . 'src/includes/StarmusCustomPostType.php';

		// Instantiate all class-based components.
		$this->instantiate_components();

		// Hook the admin notice for any runtime errors that occurred.
		add_action( 'admin_notices', array( $this, 'display_runtime_error_notice' ) );
	}

	/**
	 * Instantiates all the main component classes of the plugin.
	 *
	 * Conditionally loads admin-only classes and frontend classes.
	 *
	 * @since 0.1.0
	 */
	private function instantiate_components(): void {
		if ( is_admin() ) {
			$this->instantiate_component( StarmusAdmin::class );
		}
		$this->instantiate_component( StarmusAudioEditorUI::class );
		$this->instantiate_component( StarmusAudioRecorderUI::class );
	}

	/**
	 * Plugin Activation Hook.
	 *
	 * Sets up the plugin on activation by adding custom capabilities and flushing rewrite rules.
	 *
	 * @since 0.1.0
	 */
	public static function activate(): void {
		require_once STARMUS_PATH . 'src/includes/StarmusCustomPostType.php';
		// If your CPT file contains a function for registration, you might need to call it here.
		// e.g., if (function_exists('your_cpt_register_function')) { your_cpt_register_function(); }

		self::add_custom_capabilities();
		flush_rewrite_rules();
	}

	/**
	 * Plugin Deactivation Hook.
	 *
	 * Flushes rewrite rules to ensure CPTs are no longer publicly accessible.
	 *
	 * @since 0.1.0
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Plugin Uninstall Hook.
	 *
	 * Placeholder for future cleanup logic.
	 *
	 * @since 0.1.0
	 */
	public static function uninstall(): void {
		// Clean up plugin data on uninstall
		flush_rewrite_rules();
	}

	/**
	 * Adds custom capabilities to user roles.
	 */
	private static function add_custom_capabilities(): void {
		$roles_to_modify = array(
			'editor'                => array( self::CAP_EDIT_AUDIO, self::CAP_RECORD_AUDIO ),
			'administrator'         => array( self::CAP_EDIT_AUDIO, self::CAP_RECORD_AUDIO ),
			'contributor'           => array( self::CAP_RECORD_AUDIO ),
			'community_contributor' => array( self::CAP_RECORD_AUDIO ),
		);

		foreach ( $roles_to_modify as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( $role ) {
				foreach ( $caps as $cap ) {
					$role->add_cap( $cap );
				}
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
	public static function starmus_run(): void {
		self::get_instance();
	}

	// --- (The rest of the class is correct and remains) ---
	/**
	 * Safely instantiates a component class and stores it.
	 *
	 * Catches any `Throwable` errors during instantiation, logs them,
	 * and adds them to an array for display as an admin notice.
	 *
	 * @param string $class_name The fully qualified name of the class to instantiate.
	 */
	private function instantiate_component( string $class_name ): void {
		if ( isset( $this->components[ $class_name ] ) ) {
			return; }
		try {
			$instance                        = new $class_name();
			$this->components[ $class_name ] = $instance;
		} catch ( Throwable $e ) {
			$error_message = sprintf( 'Starmus Plugin: Runtime error while instantiating %s. Message: "%s"', $class_name, $e->getMessage() );
			error_log( $error_message );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				trigger_error( $error_message, E_USER_WARNING );
			}
			$this->runtime_errors[] = $error_message;
		}
	}
	/**
	 * Displays a dismissible admin notice for any runtime errors.
	 *
	 * This notice is only shown to users who can 'manage_options'.
	 *
	 * @since 0.1.0
	 */
	public function display_runtime_error_notice(): void {
		if ( empty( $this->runtime_errors ) || ! current_user_can( 'manage_options' ) ) {
			return; }
		$unique_errors = array_unique( $this->runtime_errors );
		foreach ( $unique_errors as $message ) {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Starmus Audio Recorder Plugin Error:</strong><br>' . esc_html( $message ) . '</p></div>';
		}
	}
	/**
	 * Prevents cloning of the singleton instance.
	 *
	 * @since 0.1.0
	 * @throws \Exception If someone tries to clone the object.
	 */
	public function __clone() {
		throw new \Exception( 'Cloning of ' . __CLASS__ . ' is not allowed.' ); }

	/**
	 * Prevents unserializing of the singleton instance.
	 *
	 * @since 0.1.0
	 * @throws \Exception If someone tries to unserialize the object.
	 */
	public function __wakeup() {
		throw new \Exception( 'Unserializing of ' . __CLASS__ . ' is not allowed.' ); }
}
