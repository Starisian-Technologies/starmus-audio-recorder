<?php
/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * Main bootstrapper for the Starmus Audio Recorder plugin lifecycle.
 *
 * @package   Starisian\Sparxstar\Starmus
 * @since     0.1.0
 * @version   0.7.6
 */

namespace Starisian\Sparxstar\Starmus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LogicException;
use Throwable;

// Core + services you actually use here
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;
use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderUpdater;

// Admin/UI/Assets
use Starisian\Sparxstar\Starmus\admin\StarmusAdmin;
use Starisian\Sparxstar\Starmus\core\StarmusAssetLoader;
use Starisian\Sparxstar\Starmus\frontend\StarmusShortcodeLoader;
use Starisian\Sparxstar\Starmus\frontend\StarmusAudioEditorUI; // if directly referenced (not required here)
use Starisian\Sparxstar\Starmus\frontend\StarmusAudioRecorderUI; // if directly referenced (not required here)

// REST layer
use Starisian\Sparxstar\Starmus\api\StarmusRESTHandler;

// Cron
use Starisian\Sparxstar\Starmus\cron\StarmusCron;

// WP functions used (for clarity in static analysis)
use function is_admin;
use function load_plugin_textdomain;
use function plugin_basename;
use function current_user_can;

/**
 * Main plugin bootstrapper.
 *
 * Responsibilities:
 * - Initialize settings early.
 * - Instantiate/wire dependent components (Admin, Assets, UI, REST).
 * - Register global hooks once.
 * - Defer heavy logic to dedicated classes.
 */
final class StarmusAudioRecorder {

	/** Capability allowing users to edit uploaded audio. */
	public const STARMUS_CAP_EDIT_AUDIO = 'starmus_edit_audio';
	/** Capability allowing users to create new recordings. */
	public const STARMUS_CAP_RECORD_AUDIO = 'starmus_record_audio';

	/** Singleton instance. */
	private static ?StarmusAudioRecorder $instance = null;

	/** Collected runtime errors for admin notice. */
	private array $runtimeErrors = array();

	/** Whether we've registered WordPress hooks (guard). */
	private bool $hooksRegistered = false;

	private ?StarmusAudioRecorderDAL $DAL = null;

	/** Settings service (must be ready before other deps). */
	private ?StarmusSettings $settings = null;

	/** Admin controller. */
	private ?StarmusAdmin $admin = null;

	/** Frontend shortcode/UX loader (manages Recorder + Editor UI). */
	private ?StarmusShortcodeLoader $ui_loader = null;

	/** Asset loader (enqueue styles/scripts). */
	private ?StarmusAssetLoader $asset_loader = null;

	/** REST handler (bridges endpoints to SubmissionHandler). */
	private ?StarmusRESTHandler $rest_handler = null;

	/** Updater (optional; registers update filters). */
	private ?StarmusAudioRecorderUpdater $updater = null;

	/** Cron service (only in non-CLI contexts). */
	private ?StarmusCron $cron = null;

	/**
	 * Private constructor for singleton pattern.
	 * - Initializes settings first.
	 * - Instantiates components.
	 * - Registers hooks.
	 */
	private function __construct() {
		// Example: Only log messages of WARNING level or higher
		StarmusLogger::setMinLogLevel( STARMUS_LOG_LEVEL );
		if ( ! empty( STARMUS_LOG_FILE ) ) {
			// Example: Log to a specific file (overrides the default daily file in uploads)
			StarmusLogger::setLogFilePath( ABSPATH . STARMUS_LOG_FILE );
		}
		$this->set_DAL();
		$this->init_settings_or_throw();
		$this->instantiate_components();
		$this->register_hooks();
	}

	/**
	 * Get singleton instance.
	 */
	public static function starmus_get_instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Entry point called from main plugin file.
	 */
	public static function starmus_run(): void {
		self::starmus_get_instance();
	}

	/**
	 * Hook target for `init` to perform late initialization pieces.
	 */
	public static function starmus_init_plugin(): void {
		self::starmus_get_instance()->on_wp_init();
	}

	/**
	 * Late init: translations, (optionally CPTs), anything that must run on `init`.
	 */
	private function on_wp_init(): void {
		// Translations
		load_plugin_textdomain(
			'starmus-audio-recorder',
			false,
			dirname( plugin_basename( STARMUS_MAIN_FILE ) ) . '/languages/'
		);

		// If/when you restore CPT registration, do it here and wrap in try/catch.
		// try { $this->load_cpt(); } catch (Throwable $e) { $this->log_runtime_error('CPT load', $e); }
	}

	/**
	 * Ensure settings are instantiated before anything else.
	 * Throws if settings class cannot be created (prevents silent null returns).
	 */
	private function init_settings_or_throw(): void {
		try {
			// Autoloader should resolve this class. Namespaced class_exists is safest.
			if ( class_exists( \Starisian\Sparxstar\Starmus\core\StarmusSettings::class ) ) {
				$this->settings = new StarmusSettings();
				return;
			}
		} catch ( Throwable $e ) {
			error_log( 'Starmus Plugin (Settings Init): ' . $e->getMessage() );
		}

		// If we reach here, settings is not available — fail loudly to avoid null downstream.
		throw new \RuntimeException( 'StarmusSettings failed to initialize.' );
	}

	private function set_DAL(): void {
		if ( $this->DAL === null ) {
			$this->DAL = new StarmusAudioRecorderDAL();
		}
	}

	public function get_DAL(): StarmusAudioRecorderDAL {
		return $this->DAL;
	}

	/**
	 * Instantiate components that depend on settings and environment.
	 * Each component is wrapped in a try/catch so one failure doesn’t kill the plugin.
	 */
	private function instantiate_components(): void {
		error_log( 'Init Starmus Components' );
		// Admin
		try {
			$this->admin = new StarmusAdmin( $this->DAL, $this->settings );
		} catch ( Throwable $e ) {
			error_log( $e );
			$this->log_runtime_error( 'Admin component', $e );
		}

		// Frontend UI (Shortcodes) — manages Recorder UI + Editor UI
		try {
			$this->ui_loader = new StarmusShortcodeLoader( $this->DAL, $this->settings );
		} catch ( Throwable $e ) {
			error_log( $e );
			$this->log_runtime_error( 'Shortcode loader', $e );
		}

		// Assets
		try {
			$this->asset_loader = new StarmusAssetLoader();
		} catch ( Throwable $e ) {
			error_log( $e );
			$this->log_runtime_error( 'Asset loader', $e );
		}

		// REST Handler — this internally creates the SubmissionHandler
		try {
			$this->rest_handler = new StarmusRESTHandler( $this->DAL, $this->settings );
		} catch ( Throwable $e ) {
			error_log( $e );
			$this->log_runtime_error( 'REST handler', $e );
		}

		// Updater (optional)
		try {
			if ( class_exists( \Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderUpdater::class ) ) {
				$this->updater = new StarmusAudioRecorderUpdater( \STARMUS_MAIN_FILE, \STARMUS_VERSION );
			}
		} catch ( Throwable $e ) {
			error_log( $e );
			$this->log_runtime_error( 'Updater', $e );
		}

		// Cron — only in non-CLI contexts
		try {
			if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				$this->cron = new StarmusCron();
			}
		} catch ( Throwable $e ) {
			error_log( $e );
			$this->log_runtime_error( 'Cron', $e );
		}
	}

	/**
	 * Register cross-cutting hooks once.
	 * Note: most components self-register inside their constructors.
	 */
	private function register_hooks(): void {

		if ( $this->hooksRegistered ) {
			return;
		}

		try {

			// WP-CLI commands (optional). Load your CLI files and register commands here.
			if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
				$cli_path = \STARMUS_PATH . 'src/cli/';
				if ( file_exists( $cli_path . 'StarmusCLI.php' ) && file_exists( $cli_path . 'StarmusCacheCommand.php' ) ) {
					require_once $cli_path . 'StarmusCLI.php';
					\WP_CLI::add_command( 'starmus', 'Starmus\\cli\\StarmusCLI' );
					\WP_CLI::add_command( 'starmus cache', 'Starmus\\cli\\StarmusCacheCommand' );
				}
			}

			// Admin runtime error banner
			if ( is_admin() ) {
				add_action( 'admin_notices', array( $this, 'displayRuntimeErrorNotice' ) );
			}

			$this->hooksRegistered = true;
		} catch ( Throwable $e ) {
			error_log( $e );
		}
	}

	/**
	 * Admin notice for collected runtime errors.
	 */
	public function displayRuntimeErrorNotice(): void {
		try {
			if ( empty( $this->runtimeErrors ) || ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$unique = array_unique( $this->runtimeErrors );
			foreach ( $unique as $msg ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Starmus Audio Recorder:</strong><br>' .
					esc_html( $msg ) .
					'</p></div>';
			}
		} catch ( Throwable $e ) {
			error_log( 'Starmus Plugin: Error in displayRuntimeErrorNotice - ' . sanitize_text_field( $e->getMessage() ) );
		}
	}

	/**
	 * Centralized error capture + log + user-visible storage.
	 */
	private function log_runtime_error( string $what, Throwable $e ): void {
		$msg = $what . ' failed: ' . $e->getMessage();
		error_log( 'Starmus Plugin: ' . $msg );
		$this->runtimeErrors[] = $msg;
	}

	/**
	 * Strictly prevent cloning.
	 *
	 * @throws LogicException Always.
	 */
	public function __clone() {
		throw new LogicException( 'Cloning of ' . __CLASS__ . ' is not allowed.' );
	}

	/**
	 * Strictly prevent (un)serialization.
	 *
	 * @throws LogicException Always.
	 */
	public function __wakeup() {
		throw new LogicException( 'Unserializing of ' . __CLASS__ . ' is not allowed.' );
	}
	public function __sleep(): array {
		throw new LogicException( 'Serializing of ' . __CLASS__ . ' is not allowed.' );
	}
	public function __serialize(): array {
		throw new LogicException( 'Serialization of ' . __CLASS__ . ' is not allowed.' );
	}
	public function __unserialize( array $data ): void {
		throw new LogicException( 'Unserialization of ' . __CLASS__ . ' is not allowed.' );
	}
}
