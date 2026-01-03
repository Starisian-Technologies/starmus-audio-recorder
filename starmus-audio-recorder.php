<?php
/**
 * SPARXSTAR Starmus Audio
 *
 * Production bootloader for the Starmus Audio Recorder plugin.
 *
 * Responsibilities:
 * - Load Composer autoloader
 * - Initialize logging
 * - Gate plugin execution on SparxStar SCF Runtime state
 * - Boot core Starmus services
 *
 * Non-Responsibilities:
 * - SCF lifecycle or version management
 * - Schema installation or registration (JSON is installer)
 * - ACF / SCF internals probing
 *
 * @package   Starisian\Sparxstar\Starmus
 * @author    Starisian Technologies
 * @license   Starisian Technologies Proprietary
 * @version   0.9.3
 *
 * Plugin Name:       Starmus Audio Recorder
 * Description:       Mobile-friendly audio recorder for optimized for emerging markets.
 * Version:           0.9.3
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Text Domain:       starmus-audio-recorder
 */

declare(strict_types=1);

use Starisian\Sparxstar\SCF\Sparxstar_SCF_Runtime;

if (!defined('ABSPATH')) {
	exit;
}

/* -------------------------------------------------------------------------
 * 0. SHUTDOWN SAFETY NET
 * ------------------------------------------------------------------------- */
register_shutdown_function(static function (): void {
	$error = error_get_last();
	if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
		error_log('STARMUS FATAL CRASH: ' . print_r($error, true));
	}
});

/* -------------------------------------------------------------------------
 * 1. CONSTANTS
 * ------------------------------------------------------------------------- */
define('STARMUS_VERSION', '0.9.3');
define('STARMUS_PATH', plugin_dir_path(__FILE__));
define('STARMUS_URL', plugin_dir_url(__FILE__));

// Configuration Defaults
if (!defined('STARMUS_LOG_LEVEL')) define('STARMUS_LOG_LEVEL', 8);
if (!defined('STARMUS_TUS_ENDPOINT')) define('STARMUS_TUS_ENDPOINT', 'https://upload.sparxstar.com/files/');
if (!defined('STARMUS_R2_ENDPOINT')) define('STARMUS_R2_ENDPOINT', 'https://cdn.sparxstar.com/');

/* -------------------------------------------------------------------------
 * 2. ENVIRONMENT & AUTOLOAD (Priority 0)
 * ------------------------------------------------------------------------- */
add_action('plugins_loaded', static function (): void {

	$autoloader = STARMUS_PATH . 'vendor/autoload.php';
	
	if (!file_exists($autoloader)) {
		if (is_admin()) {
			add_action('admin_notices', static function () {
				echo '<div class="notice notice-error"><p><strong>Starmus Error:</strong> vendor/autoload.php missing. Run composer install.</p></div>';
			});
		}
		return;
	}
	
	require_once $autoloader;

	// Initialize Logger Alias
	if (class_exists(\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::class) && !class_exists('StarmusLogger')) {
		class_alias(\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::class, 'StarmusLogger');
		\StarmusLogger::set_min_level(STARMUS_LOG_LEVEL);
	}

}, 0);

/* -------------------------------------------------------------------------
 * 3. INFRASTRUCTURE & DEPENDENCIES (Priority 5)
 * ------------------------------------------------------------------------- */
add_action('plugins_loaded', static function (): void {

	// 1. Check if the SCF Runtime (MU-Plugin) is present
	if (!class_exists(Sparxstar_SCF_Runtime::class)) {
		// If runtime is missing, we can't register sources.
		// The app boot (Priority 10) will handle the graceful failure.
		return;
	}

	// 2. Register THIS plugin's vendor copy of SCF
	Sparxstar_SCF_Runtime::register_source(
		'starmus-audio-recorder',
		STARMUS_PATH . 'vendor/advanced-custom-fields/secure-custom-fields/',
		STARMUS_URL . 'vendor/advanced-custom-fields/secure-custom-fields/'
	);

	// 3. Ensure SCF is active for this site (Self-healing state)
	if (!Sparxstar_SCF_Runtime::is_active_on_current_site()) {
		Sparxstar_SCF_Runtime::activate_site();
	}

	// 4. Register JSON Load Point
	// We hook this NOW so it is ready when the Runtime boots at Priority 20.
	add_action('sparxstar_scf/loaded', static function (): void {
		add_filter('acf/settings/load_json', static function (array $paths): array {
			$paths[] = STARMUS_PATH . 'acf-json';
			return $paths;
		});
	});

}, 5);

/* -------------------------------------------------------------------------
 * 4. APPLICATION BOOT (Priority 10)
 * ------------------------------------------------------------------------- */
add_action('plugins_loaded', static function (): void {

	error_log('Starmus boot starting.');
	
	try {
		// i18n
		if (class_exists(\Starisian\Sparxstar\Starmus\i18n\Starmusi18NLanguage::class)) {
			new \Starisian\Sparxstar\Starmus\i18n\Starmusi18NLanguage();
		}

		// Core Class Check
		if (!class_exists(\Starisian\Sparxstar\Starmus\StarmusAudioRecorder::class)) {
			throw new RuntimeException('StarmusAudioRecorder class missing.');
		}

		// Run App
		$instance = \Starisian\Sparxstar\Starmus\StarmusAudioRecorder::starmus_get_instance();
		$instance::starmus_run();

		error_log('Starmus boot completed.');

		// Deferred rewrite flush (runs once after activation)
		if (get_transient('starmus_flush_rewrite_rules')) {
			flush_rewrite_rules();
			delete_transient('starmus_flush_rewrite_rules');
		}

	} catch (Throwable $e) {
		if (class_exists('StarmusLogger')) {
			\StarmusLogger::log($e);
		}
		error_log('Starmus boot error: ' . $e->getMessage());
	}

}, 10);

/* -------------------------------------------------------------------------
 * 5. LIFECYCLE HOOKS
 * ------------------------------------------------------------------------- */
function starmus_on_activate(): void
{
	error_log('Starmus activation started.');

	try {
		// Ensure Runtime is active immediately upon activation
		if (class_exists(Sparxstar_SCF_Runtime::class)) {
			Sparxstar_SCF_Runtime::activate_site();
		}

		if (class_exists(\Starisian\Sparxstar\Starmus\cron\StarmusCron::class)) {
			\Starisian\Sparxstar\Starmus\cron\StarmusCron::activate();
		}
		set_transient('starmus_flush_rewrite_rules', true, 60);

	} catch (Throwable $e) {
		error_log('Starmus activation error: ' . $e->getMessage());
	}
}

function starmus_on_deactivate(): void
{
	try {
		if (class_exists(\Starisian\Sparxstar\Starmus\cron\StarmusCron::class)) {
			\Starisian\Sparxstar\Starmus\cron\StarmusCron::deactivate();
		}
		flush_rewrite_rules();
		
		/**
		 * NOTE: We do NOT deactivate the SCF Runtime here.
		 * Other plugins might still need it. We only remove our Cron/Rules.
		 */

	} catch (Throwable $e) {
		error_log('Starmus deactivation error: ' . $e->getMessage());
	}
}

function starmus_on_uninstall(): void
{
	// Optional: Remove SCF runtime state if you want to clean up completely
	if (class_exists(Sparxstar_SCF_Runtime::class)) {
		Sparxstar_SCF_Runtime::uninstall_site();
	}

	if (defined('STARMUS_DELETE_ON_UNINSTALL') && STARMUS_DELETE_ON_UNINSTALL) {
		$file = STARMUS_PATH . 'uninstall.php';
		if (file_exists($file)) {
			require_once $file;
		}
	}
}

register_activation_hook(__FILE__, 'starmus_on_activate');
register_deactivation_hook(__FILE__, 'starmus_on_deactivate');
register_uninstall_hook(__FILE__, 'starmus_on_uninstall');
