<?php
/**
 * Main plugin class. Initializes hooks and manages plugin star_components.
 * This version uses a clean, linear loading sequence to avoid race conditions.
 *
 * @package Starmus\includes
 * @version 0.6.6
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
final class StarmusPlugin {


	public const STAR_CAP_EDIT_AUDIO   = 'starmus_edit_audio';
	public const STAR_CAP_RECORD_AUDIO = 'starmus_record_audio';

	private static ?StarmusPlugin $instance   = null;
	private array $runtimeErrors              = array();
	private ?StarmusAdmin $admin              = null;
	private ?StarmusAudioEditorUI $editor     = null;
	private ?StarmusAudioRecorderUI $recorder = null;

	/**
	 * Private constructor for singleton pattern.
	 */
	private function __construct() {
		// Components will be instantiated in init()
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
	public function init(): void {
		error_log( 'Starmus Plugin: init() method called' );

		// Load translations first.
		load_plugin_textdomain( STARMUS_TEXT_DOMAIN, false, dirname( plugin_basename( STARMUS_MAIN_FILE ) ) . '/languages/' );
		error_log( 'Starmus Plugin: Text domain loaded' );

		// Load Custom Post Type definitions.
		$this->loadCPT();
		error_log( 'Starmus Plugin: CPT loaded' );

		// Instantiate components
		$this->instantiateComponents();
		error_log( 'Starmus Plugin: Components instantiated' );

		// Register hooks
		$this->register_hooks();
		error_log( 'Starmus Plugin: Hooks registered' );
	}

	/**
	 * Registers all necessary WordPress hooks.
	 *
	 * This method sets up admin menus, shortcodes, REST API endpoints, and other hooks
	 * for the plugin's components.
	 *
	 * @since 0.4.0
	 */
	public function register_hooks(): void {
		error_log( 'Starmus Plugin: register_hooks() called' );

		// Hook the admin notice for any runtime errors that occurred.
		add_action( 'admin_notices', array( $this, 'displayRuntimeErrorNotice' ) );

		// Register admin menu and settings if admin component is available.
		if ( is_object( $this->admin ) ) {
			error_log( 'Starmus Plugin: Admin component available, registering admin hooks' );
			add_action( 'admin_menu', array( $this->admin, 'add_admin_menu' ) );
			add_action( 'admin_init', array( $this->admin, 'register_settings' ) );
		} else {
			error_log( 'Starmus Plugin: Admin component NOT available' );
		}

		// Register front-end editor shortcodes and scripts if components are available.
		if ( is_object( $this->editor ) ) {
			error_log( 'Starmus Plugin: Editor component available, registering editor hooks' );
			add_shortcode( 'starmus_audio_editor', array( $this->editor, 'render_audio_editor_shortcode' ) );
			add_action( 'wp_enqueue_scripts', array( $this->editor, 'enqueue_scripts' ) );
			add_action( 'rest_api_init', array( $this->editor, 'register_rest_endpoint' ) );
		} else {
			error_log( 'Starmus Plugin: Editor component NOT available' );
		}

		// Register front-end recorder shortcodes, scripts, and hooks if component is available.
		if ( is_object( $this->recorder ) ) {
			error_log( 'Starmus Plugin: Recorder component available, registering recorder hooks' );
			add_shortcode( 'starmus_my_recordings', array( $this->recorder, 'render_my_recordings_shortcode' ) );
			add_shortcode( 'starmus_audio_recorder_form', array( $this->recorder, 'render_recorder_shortcode' ) );
			add_action( 'wp_enqueue_scripts', array( $this->recorder, 'enqueue_scripts' ) );
			add_action( 'rest_api_init', array( $this->recorder, 'register_rest_routes' ) );
			add_action( 'starmus_after_audio_upload', array( $this->recorder, 'save_all_metadata' ), 10, 3 );
			add_filter( 'starmus_audio_upload_success_response', array( $this->recorder, 'add_conditional_redirect' ), 10, 3 );
			// Cron scheduling moved to activation to avoid performance issues
			add_action( 'starmus_cleanup_temp_files', array( $this->recorder, 'cleanup_stale_temp_files' ) );
			// Clear cache when a Language is added, edited, or deleted.
			add_action( 'create_language', array( $this->recorder, 'clear_taxonomy_transients' ) );
			add_action( 'edit_language', array( $this->recorder, 'clear_taxonomy_transients' ) );
			add_action( 'delete_language', array( $this->recorder, 'clear_taxonomy_transients' ) );
			// Clear cache when a Recording Type is added, edited, or deleted.
			add_action( 'create_recording-type', array( $this->recorder, 'clear_taxonomy_transients' ) );
			add_action( 'edit_recording-type', array( $this->recorder, 'clear_taxonomy_transients' ) );
			add_action( 'delete_recording-type', array( $this->recorder, 'clear_taxonomy_transients' ) );
			// Add filter so mime passes
			add_filter(
				'upload_mimes',
				function ( $mimes ) {
					$mimes['webm'] = 'audio/webm';
					$mimes['weba'] = 'audio/webm';
					$mimes['opus'] = 'audio/ogg; codecs=opus';
					return $mimes;
				}
			);

			error_log( 'Starmus Plugin: Shortcodes registered - starmus_my_recordings and starmus_audio_recorder' );
		} else {
			error_log( 'Starmus Plugin: Recorder component NOT available' );
		}

		error_log( 'Starmus Plugin: All hooks registered successfully' );
	}
	/**
	 * Loads the Custom Post Type definitions.
	 *
	 * This method includes the CPT file and handles any errors that may occur during loading.
	 *
	 * @since 0.4.0
	 */
	private function loadCPT(): void {
		$cpt_file = realpath( STARMUS_PATH . 'src/includes/StarmusCustomPostType.php' );
		if ( $cpt_file && str_starts_with( $cpt_file, realpath( STARMUS_PATH ) ) && file_exists( $cpt_file ) ) {
			require_once $cpt_file;
		} else {
			error_log( 'Starmus Plugin: CPT file not found or invalid path' );
			$this->runtimeErrors[] = 'Custom Post Type file is missing.';
		}
	}
	/**
	 * Instantiates the main components of the plugin.
	 *
	 * Each component is wrapped in a try-catch block to handle any instantiation errors gracefully.
	 *
	 * @since 0.1.0
	 */
	private function instantiateComponents(): void {
		error_log( 'Starmus Plugin: Starting component instantiation' );

		try {
			error_log( 'Starmus Plugin: Attempting to instantiate StarmusAdmin' );
			$this->admin = new StarmusAdmin();
			error_log( 'Starmus Plugin: StarmusAdmin instantiated successfully' );
		} catch ( Throwable $e ) {
			error_log( 'Starmus Plugin: Failed to load admin component: ' . esc_html( $e->getMessage() ) . ' in ' . esc_html( $e->getFile() ) . ':' . esc_html( $e->getLine() ) );
			$this->runtimeErrors[] = 'Failed to load admin component: ' . esc_html( $e->getMessage() );
		}

		try {
			error_log( 'Starmus Plugin: Attempting to instantiate StarmusAudioEditorUI' );
			$this->editor = new StarmusAudioEditorUI();
			error_log( 'Starmus Plugin: StarmusAudioEditorUI instantiated successfully' );
		} catch ( Throwable $e ) {
			error_log( 'Starmus Plugin: Failed to load editor component: ' . esc_html( $e->getMessage() ) . ' in ' . esc_html( $e->getFile() ) . ':' . esc_html( $e->getLine() ) );
			$this->runtimeErrors[] = 'Failed to load editor component: ' . esc_html( $e->getMessage() );
		}

		try {
			error_log( 'Starmus Plugin: Attempting to instantiate StarmusAudioRecorderUI' );
			$this->recorder = new StarmusAudioRecorderUI();
			error_log( 'Starmus Plugin: StarmusAudioRecorderUI instantiated successfully' );
		} catch ( Throwable $e ) {
			error_log( 'Starmus Plugin: Failed to load recorder component: ' . esc_html( $e->getMessage() ) . ' in ' . esc_html( $e->getFile() ) . ':' . esc_html( $e->getLine() ) );
			$this->runtimeErrors[] = 'Failed to load recorder component: ' . esc_html( $e->getMessage() );
		}

		error_log( 'Starmus Plugin: Component instantiation complete' );
	}

	/**
	 * Plugin Activation Hook.
	 *
	 * Sets up the plugin on activation by adding custom capabilities and flushing rewrite rules.
	 *
	 * @since 0.1.0
	 */
	public static function activate(): void {
		try {
			$cpt_file = realpath( STARMUS_PATH . 'src/includes/StarmusCustomPostType.php' );
			if ( $cpt_file && str_starts_with( $cpt_file, realpath( STARMUS_PATH ) ) && file_exists( $cpt_file ) ) {
				require_once $cpt_file;
			} else {
				error_log( 'Starmus Plugin: Critical - CPT file missing during activation' );
				throw new \Exception( 'Critical dependency missing: CPT file' );
			}

			self::add_custom_capabilities();
			// Schedule cron here instead of on every init.
			if ( ! wp_next_scheduled( 'starmus_cleanup_temp_files' ) ) {
				wp_schedule_event( time(), 'hourly', 'starmus_cleanup_temp_files' );
			}
			flush_rewrite_rules();
		} catch ( Throwable $e ) {
			error_log( 'Starmus Plugin: Activation error - ' . sanitize_text_field( $e->getMessage() ) );
			throw $e;
		}
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
		$file = realpath( STARMUS_PATH . 'uninstall.php' );
		if ( $file && str_starts_with( $file, realpath( STARMUS_PATH ) ) && file_exists( $file ) ) {
			require_once $file;
		} else {
			error_log( 'Starmus Plugin: Uninstall file not found' );
		}
		wp_clear_scheduled_hook( 'starmus_cleanup_temp_files' );
		flush_rewrite_rules();
	}

	/**
	 * Adds custom capabilities to user roles.
	 *
	 * This method assigns specific capabilities to predefined user roles.
	 *
	 * @since 0.2.0
	 */
	private static function add_custom_capabilities(): void {
		$roles_to_modify = array(
			'editor'                => array( self::STAR_CAP_EDIT_AUDIO, self::STAR_CAP_RECORD_AUDIO ),
			'administrator'         => array( self::STAR_CAP_EDIT_AUDIO, self::STAR_CAP_RECORD_AUDIO ),
			'contributor'           => array( self::STAR_CAP_RECORD_AUDIO ),
			'community_contributor' => array( self::STAR_CAP_RECORD_AUDIO ),
		);
		try {
			foreach ( $roles_to_modify as $role_name => $caps ) {
				$role = get_role( $role_name );
				if ( $role ) {
					foreach ( $caps as $cap ) {
						$role->add_cap( $cap );
					}
				} else {
					error_log( "Starmus Plugin: Role '" . esc_html( $role_name ) . "' not found" );
				}
			}
		} catch ( Throwable $e ) {
			if ( ( WP_DEBUG === true ) && ( WP_DEBUG_LOG === true ) ) {
				trigger_error( 'Starmus Plugin: Error adding capabilities - ' . esc_html( sanitize_text_field( $e->getMessage() ) ), E_USER_WARNING );
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
	public static function run(): void {
		self::get_instance();
	}

	/**
	 * Displays a dismissible admin notice for any runtime errors.
	 *
	 * This notice is only shown to users who can 'manage_options'.
	 *
	 * @since 0.1.0
	 */
	public function displayRuntimeErrorNotice(): void {
		try {
			if ( empty( $this->runtimeErrors ) || ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$unique_errors = array_unique( $this->runtimeErrors );
			foreach ( $unique_errors as $message ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Starmus Audio Recorder Plugin Error:</strong><br>' . esc_html( $message ) . '</p></div>';
			}
		} catch ( Throwable $e ) {
			error_log( 'Starmus Plugin: Error in displayRuntimeErrorNotice - ' . esc_html( $e->getMessage() ) );
		}
	}
	/**
	 * Prevents cloning of the singleton instance.
	 *
	 * @since 0.1.0
	 * @throws LogicException If someone tries to clone the object.
	 */
	public function __clone() {
		throw new LogicException( 'Cloning of ' . esc_html( __CLASS__ ) . ' is not allowed.' );
	}

	/**
	 * Prevents unserializing of the singleton instance.
	 *
	 * @since 0.1.0
	 * @throws LogicException If someone tries to unserialize the object.
	 */
	public function __wakeup() {
		throw new LogicException( 'Unserializing of ' . esc_html( __CLASS__ ) . ' is not allowed.' );
	}
}
