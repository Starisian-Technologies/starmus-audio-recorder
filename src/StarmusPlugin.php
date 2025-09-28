<?php
/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * Bootstrapper for the Starmus Audio Recorder plugin lifecycle.
 *
 * @package Starmus;
 * @since 0.1.0
 * @version 0.7.5
 * @author Starisian Technologies (Max Barrett)
 * @license SEE LICENSE.md
 * @link   https://starisian.com
 */

namespace Starmus;

/**
 * Main plugin class. Initializes hooks and manages plugin star_components.
 * This version uses a clean, linear loading sequence to avoid race conditions.
 *
 * @package Starmus\includes
 * @version 0.7.5
 * @since 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starmus\admin\StarmusAdmin;
use Starmus\helpers\StarmusMimeHelper;
use Starmus\frontend\StarmusAudioEditorUI;
use Starmus\frontend\StarmusAudioRecorderUI;
use Starmus\includes\StarmusSettings;
use Starmus\includes\StarmusAssetLoader;
use Starmus\frontend\StarmusShortcodeLoader;
use Starmus\cli\StarmusCLI;
use Starmus\cron\StarmusCron;
use Starmus\core\StarmusPluginUpdater;
use Starmus\services\AudioProcessingService;
use Starmus\services\PostProcessingService;
use Starmus\services\WaveformService;
// Only import WP_CLI if available (for static analysis, not runtime).
use WP_CLI;
use LogicException;
use Throwable;
// Import WordPress core functions for static analysis and clarity.
use function is_admin;
use function wp_next_scheduled;
use function wp_schedule_event;
use function flush_rewrite_rules;
use function wp_clear_scheduled_hook;
use function get_role;
use function current_user_can;
use function load_plugin_textdomain;
use function plugin_basename;
use function plugin_dir_path; // Added for static analysis
use function plugin_dir_url;  // Added for static analysis
use function register_activation_hook; // Added for static analysis
use function register_deactivation_hook; // Added for static analysis
use function register_uninstall_hook; // Added for static analysis
use function sanitize_text_field;
use function deactivate_plugins;
use function wp_die;
use function __;

final class StarmusPlugin {





	/** Capability allowing users to edit uploaded audio. */
	public const STAR_CAP_EDIT_AUDIO = 'starmus_edit_audio';
	/** Capability allowing users to create new recordings. */
	public const STAR_CAP_RECORD_AUDIO = 'starmus_record_audio';

	/** Singleton instance reference. */
	private static ?StarmusPlugin $instance = null;
	/**
	 * Collected runtime error messages for admin display.
	 *
	 * @var string[]
	 */
	private array $runtimeErrors = array();

	/** Indicates whether WordPress hooks are already registered. */
	private bool $hooksRegistered = false;

	/** Settings manager dependency. */
	private ?StarmusSettings $settings = null;
	/** Asset Loader dependency. */
	private ?StarmusAssetLoader $asset_loader = null;
	/** Admin controller dependency. */
	private ?StarmusAdmin $admin = null;
	/** Front-end editor controller dependency. */
	private ?StarmusAudioEditorUI $editor = null;
	/** Front-end recorder controller dependency. */
	private ?StarmusAudioRecorderUI $recorder = null;
	/** Updater service dependency. */
	private ?StarmusPluginUpdater $updater = null;
	/** Template loader dependency. */
	private ?StarmusShortcodeLoader $shortcode_loader = null;
	/** WP-CLI command handler dependency. */
	private ?StarmusCLI $cli = null;
	/** Cron service dependency. */
	private ?StarmusCron $cron = null;
	/** Waveform processing service dependency. */
	private ?WaveformService $waveform = null;
	/** Audio processing service dependency. */
	private ?AudioProcessingService $audioService = null;
	/** Post processing service dependency. */
	private ?PostProcessingService $postService = null;


	/**
	 * Private constructor for singleton pattern.
	 */
	private function __construct() {
		// Components will be instantiated in init().
	}

	/**
	 * Main singleton instance method.
	 *
	 * Intentionally empty - initialization happens in init().
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
		// Load translations first.
		load_plugin_textdomain( 'starmus-audio-recorder', false, dirname( plugin_basename( STARMUS_MAIN_FILE ) ) . '/languages/' );

		// Load Custom Post Type definitions.
		try {
			// $this->loadCPT();
		} catch ( Throwable $e ) {
			error_log( 'Starmus Plugin: Error loading CPT - ' . esc_html( $e->getMessage() ) . ' in ' . esc_html( $e->getFile() ) . ':' . esc_html( (string) $e->getLine() ) );
			$this->runtimeErrors[] = 'Error loading CPT: ' . esc_html( $e->getMessage() ) . ' in ' . esc_html( $e->getFile() ) . ':' . esc_html( (string) $e->getLine() );
		}
		// error_log('Starmus Plugin: CPT load called');

		// Ensure settings are loaded before other components that may depend on them.
		$this->set_starmus_settings();

		// Instantiate components
		$this->instantiateComponents();

		// Register hooks
		$this->register_hooks();
	}

	/**
	 * Registers global WordPress hooks owned by the plugin bootstrapper.
	 *
	 * Component classes self-register their own actions during instantiation; this
	 * method focuses on cross-cutting filters, CLI commands, and admin diagnostics.
	 *
	 * @since 0.4.0
	 */
	public function register_hooks(): void {
		if ( $this->hooksRegistered ) {
			return;
		}

		add_filter( 'wp_check_filetype_and_ext', array( $this, 'filter_filetype_and_ext' ), 10, 5 );
		add_filter( 'upload_mimes', array( $this, 'filter_upload_mimes' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			$cli_path = STARMUS_PATH . 'src/cli/';
			if ( file_exists( $cli_path . 'StarmusCLI.php' ) && file_exists( $cli_path . 'StarmusCacheCommand.php' ) ) {
				require_once $cli_path . 'StarmusCLI.php';
				\WP_CLI::add_command( 'starmus', 'Starmus\\cli\\StarmusCLI' );
				\WP_CLI::add_command( 'starmus cache', 'Starmus\\cli\\StarmusCacheCommand' );
			}
		}

		if ( is_admin() ) {
			add_action( 'admin_notices', array( $this, 'displayRuntimeErrorNotice' ) );
		}

		if ( is_object( $this->updater ) ) {
			add_filter( 'pre_set_site_transient_update_plugins', array( $this->updater, 'check_for_updates' ) );
		}

		$this->hooksRegistered = true;
	}

	/**
	 * Checks for required field plugins (ACF or SCF) and shows admin notice if missing.
	 *
	 * @return bool True if dependency present, false if missing.
	 */
	public static function check_field_plugin_dependency(): bool {
		if ( class_exists( 'ACF' ) || class_exists( 'SCF' ) ) {
			return true;
		}
		// Show admin notice if in admin area
		if ( is_admin() ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p><strong>Starmus Audio Recorder:</strong> This plugin requires <a href="https://www.advancedcustomfields.com/" target="_blank">Advanced Custom Fields</a> or <a href="https://ja.wordpress.org/plugins/scf/" target="_blank">Smart Custom Fields</a> to be installed and activated.</p></div>';
				}
			);
		}
		return false;
	}

	/**
	 * Expand allowable MIME types during uploads.
	 *
	 * @param array|false $types         Existing MIME check result from WordPress.
	 * @param string      $file          Current file path (unused).
	 * @param string      $filename      Original filename provided by the user.
	 * @param array       $mimes_allowed Allowed MIME types passed into the filter.
	 * @param string      $real_mime     MIME type detected by PHP.
	 *
	 * @return array Filtered MIME type data.
	 */
	public function filter_filetype_and_ext( $types, $file, $filename, $mimes_allowed, $real_mime ): array {
		unset( $file, $mimes_allowed, $real_mime );
		$ext       = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		$whitelist = StarmusMimeHelper::get_allowed_mimes();
		if ( isset( $whitelist[ $ext ] ) ) {
			return array(
				'ext'             => $ext,
				'type'            => $whitelist[ $ext ],
				'proper_filename' => $filename,
			);
		}
		return is_array( $types ) ? $types : array();
	}

	/**
	 * Allow audio/video MIME uploads that WordPress blocks by default.
	 *
	 * @param array $mimes Existing MIME mapping keyed by extension.
	 *
	 * @return array Filtered MIME mapping.
	 */
	public function filter_upload_mimes( array $mimes ): array {
		$whitelist = StarmusMimeHelper::get_allowed_mimes();
		foreach ( $whitelist as $ext => $mime ) {
			$mimes[ $ext ] = $mime;
		}
		return $mimes;
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
			// Instantiate the CPT class to register
		} else {

			$this->runtimeErrors[] = 'Custom Post Type file is missing.';
		}
	}
	/**
	 *
	 * Instantiates the StarmusSettings component of the plugin.
	 *
	 * @return void
	 */
	private function set_starmus_settings(): void {
		try {
			if ( ! is_object( $this->settings ) && class_exists( 'Starmus\\includes\\StarmusSettings' ) ) {
				$this->settings = new \Starmus\includes\StarmusSettings();

			}
		} catch ( Throwable $e ) {
			// Settings instantiation failed - component will be null.
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

		if ( is_object( $this->get_starmus_settings() ) ) {
			try {

				$this->admin = new StarmusAdmin( $this->get_starmus_settings() );

				error_log( 'Starmus Plugin: StarmusAdmin instantiated successfully' );
			} catch ( Throwable $e ) {
				error_log( 'Starmus Plugin: Failed to load admin component: ' . sanitize_text_field( $e->getMessage() ) . ' in ' . sanitize_text_field( $e->getFile() ) . ':' . sanitize_text_field( (string) $e->getLine() ) );
				$this->runtimeErrors[] = 'Failed to load admin component: ' . sanitize_text_field( $e->getMessage() );
			}

			try {
				$this->asset_loader = new StarmusAssetLoader();
				error_log( 'Starmus Plugin:  StarmusAssetLoader initialized' );
			} catch ( Throwable $e ) {
				error_log( 'Starmus Plugin: Failed to load asset loader component: ' . sanitize_text_field( $e->getMessage() ) . ' in ' . sanitize_text_field( $e->getFile() ) . ':' . sanitize_text_field( (string) $e->getLine() ) );
				$this->runtimeErrors[] = 'Failed to load asset loader component: ' . sanitize_text_field( $e->getMessage() );
			}

			try {

				$this->recorder = new StarmusAudioRecorderUI( $this->get_starmus_settings() );

				error_log( 'Starmus Plugin: StarmusAudioRecorderUI instantiated successfully' );
			} catch ( Throwable $e ) {
				error_log( 'Starmus Plugin: Failed to load recorder component: ' . sanitize_text_field( $e->getMessage() ) . ' in ' . sanitize_text_field( $e->getFile() ) . ':' . sanitize_text_field( (string) $e->getLine() ) );
				$this->runtimeErrors[] = 'Failed to load recorder component: ' . sanitize_text_field( $e->getMessage() );
			}
		}

		try {

			$this->editor = new StarmusAudioEditorUI();

			error_log( 'Starmus Plugin: StarmusAudioEditorUI instantiated successfully' );
		} catch ( Throwable $e ) {
			error_log( 'Starmus Plugin: Failed to load editor component: ' . sanitize_text_field( $e->getMessage() ) . ' in ' . sanitize_text_field( $e->getFile() ) . ':' . sanitize_text_field( (string) $e->getLine() ) );
			$this->runtimeErrors[] = 'Failed to load editor component: ' . sanitize_text_field( $e->getMessage() );
		}

		try {
			if ( class_exists( 'StarmusPluginUpdater' ) ) {
				// phpcs:ignore Squiz.NamingConventions.ValidVariableName.NotCamelCaps.
				// @phpstan-ignore-next-line.
				$this->updater = new StarmusPluginUpdater( STARMUS_MAIN_FILE, STARMUS_VERSION );
				error_log( 'Starmus Plugin: StarmisPluginUpdater instantiated successfully' );

			}
		} catch ( Throwable $e ) {
			error_log( 'Failed to load updater component: ' . esc_html( $e->getMessage() ) );
			$this->runtimeErrors[] = 'Failed to load updater component: ' . esc_html( $e->getMessage() );
		}

		// Initialize StarmusTemplateLoader
		try {
			$this->shortcode_loader = new StarmusShortcodeLoader( $this->get_starmus_settings() );
			error_log( 'Starmus Plugin: StarmusTemplateLoader instantiated successfully' );

		} catch ( Throwable $e ) {
			error_log( 'Starmus Plugin: Error loading StarmusTemplateLoader - ' . esc_html( $e->getMessage() ) );
			$this->runtimeErrors[] = 'Starmus Plugin: Error loading StarmusTemplateLoader - ' . esc_html( $e->getMessage() );
		}

		try {
			$this->cli = new StarmusCLI();
			error_log( 'Starmus Plugin: StarmusCLI instantiated successfully' );

		} catch ( Throwable $e ) {
			error_log( 'Starmus Plugin: Error loading Starmus CLI - ' . esc_html( $e->getMessage() ) );
			$this->runtimeErrors[] = 'Starmus Plugin: Error loading Starmus CLI - ' . esc_html( $e->getMessage() );
		}

		try {
			$this->cron = new StarmusCron();
			error_log( 'Starmus Plugin: StarmusCron instantiated successfully' );

		} catch ( Throwable $e ) {
			error_log( 'Starmus Plugin: Error loading Starmus Cron - ' . esc_html( $e->getMessage() ) );
			$this->runtimeErrors[] = 'Starmus Plugin: Error loading Starmus Cron - ' . esc_html( $e->getMessage() );
		}
	}
	/**
	 *
	 * Ensures the settings component is instantiated before returning it.
	 *
	 * @since 0.1.0
	 * @return StarmusSettings The settings instance.
	 */
	public function get_starmus_settings(): StarmusSettings {
		if ( ! is_object( $this->settings ) ) {
			$this->set_starmus_settings();
		}
		return $this->settings;
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
				$role = \get_role( $role_name );
				if ( $role ) {
					foreach ( $caps as $cap ) {
						$role->add_cap( $cap );
					}
				} else {
					error_log( "Starmus Plugin: Role '" . sanitize_text_field( $role_name ) . "' not found" );
				}
			}
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				trigger_error( 'Starmus Plugin: Error adding capabilities - ' . sanitize_text_field( $e->getMessage() ), E_USER_WARNING );
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
	 * Hooked into WordPress init to bootstrap services and hooks.
	 */
	public static function init_plugin(): void {
		self::get_instance()->init();
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
			error_log( 'Starmus Plugin: Error in displayRuntimeErrorNotice - ' . sanitize_text_field( $e->getMessage() ) );
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
