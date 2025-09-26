<?php
/**
 * Bootstrapper for the Starmus Audio Recorder plugin lifecycle.
 *
 * @package Starmus\includes
 */

namespace Starmus;

/**
 * Main plugin class. Initializes hooks and manages plugin star_components.
 * This version uses a clean, linear loading sequence to avoid race conditions.
 *
 * @package Starmus\includes
 * @version 0.7.4
 * @since 0.1.0
 */


use Starmus\admin\StarmusAdmin;
use Starmus\frontend\StarmusAudioEditorUI;
use Starmus\frontend\StarmusAudioRecorderUI;
use Starmus\includes\StarmusSettings;
use Starmus\cli\StarmusCLI;
use Starmus\core\StarmusPluginUpdater;
use WP_CLI;
use LogicException;
use Throwable;
// Import WordPress core functions for static analysis and clarity
use function is_admin;
use function wp_next_scheduled;
use function wp_schedule_event;
use function flush_rewrite_rules;
use function wp_clear_scheduled_hook;
use function get_role;
use Starmus\includes\StarmusCustomPostType;
use Starmus\services\AudioProcessingService;
use Starmus\services\PostProcessingService;
use Starmus\services\WaveformService;

use function current_user_can;
use function load_plugin_textdomain;
use function plugin_basename;
use function plugin_dir_path; // Added for static analysis
use function plugin_dir_url;  // Added for static analysis
use function register_activation_hook; // Added for static analysis
use function register_deactivation_hook; // Added for static analysis
use function register_uninstall_hook; // Added for static analysis
use function sanitize_text_field;

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
        /** Admin controller dependency. */
        private ?StarmusAdmin $admin = null;
        /** Front-end editor controller dependency. */
        private ?StarmusAudioEditorUI $editor = null;
        /** Front-end recorder controller dependency. */
        private ?StarmusAudioRecorderUI $recorder = null;
        /** Updater service dependency. */
        private ?StarmusPluginUpdater $updater = null;
        /** WP-CLI command handler dependency. */
        private ?StarmusCLI $cli = null;
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
		load_plugin_textdomain( 'starmus-audio-recorder', false, dirname( plugin_basename( STARMUS_MAIN_FILE ) ) . '/languages/' );
		error_log( 'Starmus Plugin: Text domain loaded' );

		// Load Custom Post Type definitions.
		$this->loadCPT();
		error_log( 'Starmus Plugin: CPT loaded' );

		// Ensure settings are loaded before other components that may depend on them.
		$this->set_starmus_settings();
		error_log( 'Starmus Plugin: Settings component set' );

		// Instantiate components
		$this->instantiateComponents();
		error_log( 'Starmus Plugin: Components instantiated' );

		// Register hooks
		$this->register_hooks();
		error_log( 'Starmus Plugin: Hooks registered' );
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

                if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
                        $cli_path = STARMUS_PATH . 'src/cli/';
                        if ( file_exists( $cli_path . 'StarmusCLI.php' ) && file_exists( $cli_path . 'StarmusCacheCommand.php' ) ) {
                                require_once $cli_path . 'StarmusCLI.php';
                                WP_CLI::add_command( 'starmus', 'Starmus\\cli\\StarmusCLI' );
                                WP_CLI::add_command( 'starmus cache', 'Starmus\\cli\\StarmusCacheCommand' );
                        }
                }

                if ( \is_admin() ) {
                        add_action( 'admin_notices', array( $this, 'displayRuntimeErrorNotice' ) );
                }

                if ( is_object( $this->updater ) ) {
                        add_filter( 'pre_set_site_transient_update_plugins', array( $this->updater, 'check_for_updates' ) );
                }

                $this->hooksRegistered = true;
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
                $whitelist = array(
                        'mp3'  => 'audio/mpeg',
                        'wav'  => 'audio/wav',
                        'ogg'  => 'audio/ogg',
                        'oga'  => 'audio/ogg',
                        'opus' => 'audio/ogg; codecs=opus',
                        'weba' => 'audio/webm',
                        'aac'  => 'audio/aac',
                        'm4a'  => 'audio/mp4',
                        'flac' => 'audio/flac',
                        'mp4'  => 'video/mp4',
                        'm4v'  => 'video/x-m4v',
                        'mov'  => 'video/quicktime',
                        'webm' => 'video/webm',
                        'ogv'  => 'video/ogg',
                        'avi'  => 'video/x-msvideo',
                        'wmv'  => 'video/x-ms-wmv',
						'3gp'  => 'video/3gpp',
						'3g2'  => 'video/3gpp2',

                );

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
                $mimes['weba'] = 'audio/webm';
                $mimes['webm'] = 'video/webm';
                $mimes['opus'] = 'audio/ogg; codecs=opus';
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
		} else {
			error_log( 'Starmus Plugin: CPT file not found or invalid path' );
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

				error_log( 'Starmus Plugin: Settings component instantiated.' );
			}
		} catch ( Throwable $e ) {
			error_log( 'Starmus Plugin: Error instantiating settings component - ' . $e->getMessage() );
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

		if ( is_object( $this->get_starmus_settings() ) ) {
			try {
				error_log( 'Starmus Plugin: Attempting to instantiate StarmusAdmin' );
				$this->admin = new StarmusAdmin( $this->get_starmus_settings() );
				error_log( 'Starmus Plugin: StarmusAdmin instantiated successfully' );
                        } catch ( Throwable $e ) {
                                error_log( 'Starmus Plugin: Failed to load admin component: ' . sanitize_text_field( $e->getMessage() ) . ' in ' . sanitize_text_field( $e->getFile() ) . ':' . sanitize_text_field( (string) $e->getLine() ) );
                                $this->runtimeErrors[] = 'Failed to load admin component: ' . sanitize_text_field( $e->getMessage() );
			}

			try {
				error_log( 'Starmus Plugin: Attempting to instantiate StarmusAudioRecorderUI' );
				$this->recorder = new StarmusAudioRecorderUI( $this->get_starmus_settings() );
				error_log( 'Starmus Plugin: StarmusAudioRecorderUI instantiated successfully' );
                        } catch ( Throwable $e ) {
                                error_log( 'Starmus Plugin: Failed to load recorder component: ' . sanitize_text_field( $e->getMessage() ) . ' in ' . sanitize_text_field( $e->getFile() ) . ':' . sanitize_text_field( (string) $e->getLine() ) );
                                $this->runtimeErrors[] = 'Failed to load recorder component: ' . sanitize_text_field( $e->getMessage() );
			}
		}

		try {
			error_log( 'Starmus Plugin: Attempting to instantiate StarmusAudioEditorUI' );
			$this->editor = new StarmusAudioEditorUI();
			error_log( 'Starmus Plugin: StarmusAudioEditorUI instantiated successfully' );
                } catch ( Throwable $e ) {
                        error_log( 'Starmus Plugin: Failed to load editor component: ' . sanitize_text_field( $e->getMessage() ) . ' in ' . sanitize_text_field( $e->getFile() ) . ':' . sanitize_text_field( (string) $e->getLine() ) );
                        $this->runtimeErrors[] = 'Failed to load editor component: ' . sanitize_text_field( $e->getMessage() );
		}

		try {
			if ( class_exists( 'Starmus\\core\\StarmusPluginUpdater' ) ) {
				// phpcs:ignore Squiz.NamingConventions.ValidVariableName.NotCamelCaps
				// @phpstan-ignore-next-line
				$this->updater = new StarmusPluginUpdater( STARMUS_MAIN_FILE, STARMUS_VERSION );
				error_log( 'Starmus Plugin: StarmusPluginUpdater instantiated successfully' );

			}
		} catch ( Throwable $e ) {
                        error_log( 'Failed to load updater component: ' . sanitize_text_field( $e->getMessage() ) );
                        $this->runtimeErrors[] = 'Failed to load updater component: ' . sanitize_text_field( $e->getMessage() );
		}

		error_log( 'Starmus Plugin: Component instantiation complete' );
	}
	/**
	 *
	 * Ensures the settings component is instantiated before returning it.
	 *
	 * @since 0.1.0
	 * @return StarmusSettings The settings instance.
	 */
	public function get_starmus_settings(): StarmusSettings{
		if(! is_object($this->settings)) {
			$this->set_starmus_settings();
		}
		return $this->settings;
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
			if ( ! \wp_next_scheduled( 'starmus_cleanup_temp_files' ) ) {
				\wp_schedule_event( time(), 'hourly', 'starmus_cleanup_temp_files' );
			}
			\flush_rewrite_rules();
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
		\flush_rewrite_rules();
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
		\wp_clear_scheduled_hook( 'starmus_cleanup_temp_files' );
		\flush_rewrite_rules();
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
