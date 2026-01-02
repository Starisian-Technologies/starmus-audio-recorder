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
define('STARMUS_MAIN_FILE', __FILE__);
define('STARMUS_PATH', plugin_dir_path(__FILE__));
define('STARMUS_URL', plugin_dir_url(__FILE__));

if (!defined('STARMUS_LOG_LEVEL')) {
	define('STARMUS_LOG_LEVEL', 8);
}
if (!defined('STARMUS_TUS_ENDPOINT')) {
	define('STARMUS_TUS_ENDPOINT', 'https://upload.sparxstar.com/files/');
}
if (!defined('STARMUS_R2_ENDPOINT')) {
	define('STARMUS_R2_ENDPOINT', 'https://cdn.sparxstar.com/');
}
if (!defined('TUS_WEBHOOK_SECRET')) {
	define('TUS_WEBHOOK_SECRET', 'CHANGE_ME');
}

/* -------------------------------------------------------------------------
 * 2. ENVIRONMENT INITIALIZATION
 * ------------------------------------------------------------------------- */
add_action('plugins_loaded', static function (): void {

	/* Composer autoloader */
	$autoloader = STARMUS_PATH . 'vendor/autoload.php';
	if (!file_exists($autoloader)) {
		add_action('admin_notices', static function (): void {
			echo '<div class="notice notice-error"><p><strong>Starmus error:</strong> vendor/autoload.php missing.</p></div>';
		});
		return;
	}
	require_once $autoloader;

	/* Logger bootstrap (failsafe) */
	if (
		class_exists(\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::class) &&
		!class_exists('StarmusLogger')
	) {
		class_alias(
			\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::class,
			'StarmusLogger'
		);
		\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::set_min_level(STARMUS_LOG_LEVEL);
	}

}, 0);

/* -------------------------------------------------------------------------
 * 3. MAIN BOOTSTRAP (GATED BY SCF RUNTIME)
 * ------------------------------------------------------------------------- */
add_action('plugins_loaded', static function (): void {

	error_log('Starmus boot starting.');

	/* Hard gate: SCF must be installed via SparxStar runtime */
	if (
		!class_exists(Sparxstar_SCF_Runtime::class) ||
		!Sparxstar_SCF_Runtime::sparx_scf_is_installed()
	) {
		error_log('Starmus halted: SCF runtime not installed or inactive.');
		return;
	}

	try {

		/* i18n */
		if (class_exists(\Starisian\Sparxstar\Starmus\i18n\Starmusi18NLanguage::class)) {
			new \Starisian\Sparxstar\Starmus\i18n\Starmusi18NLanguage();
		}

		/* Core orchestrator */
		if (!class_exists(\Starisian\Sparxstar\Starmus\StarmusAudioRecorder::class)) {
			throw new RuntimeException('StarmusAudioRecorder class missing.');
		}

		$instance = \Starisian\Sparxstar\Starmus\StarmusAudioRecorder::starmus_get_instance();
		$instance::starmus_run();

		error_log('Starmus boot completed.');

		/* Deferred rewrite flush */
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
 * 4. LIFECYCLE HOOKS
 * ------------------------------------------------------------------------- */
function starmus_on_activate(): void
{
	error_log('Starmus activation started.');

	try {
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
	} catch (Throwable $e) {
	}
}

function starmus_on_uninstall(): void
{
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
