<?php

/**
 * Plugin Name: Starmus Audio Recorder (DIAGNOSTIC MODE)
 * Description: Debug mode active to find WSOD source.
 * Version: 0.9.3-DEBUG
 */

if (! defined('ABSPATH')) exit;

// 1. Immediate Life Sign
error_log('STARMUS BOOT: Loading main file...');

// Define Constants
define('STARMUS_MAIN_FILE', __FILE__);
define('STARMUS_PATH', plugin_dir_path(__FILE__));
define('STARMUS_URL', plugin_dir_url(__FILE__));

// 2. Load Autoloader with Trap
$autoloader = STARMUS_PATH . 'vendor/autoload.php';
if (file_exists($autoloader)) {
	try {
		require_once $autoloader;
		error_log('STARMUS BOOT: Autoloader loaded.');
	} catch (Throwable $e) {
		error_log('STARMUS CRITICAL: Autoloader crash - ' . $e->getMessage());
		return;
	}
} else {
	error_log('STARMUS CRITICAL: vendor/autoload.php missing.');
	return;
}

// 3. Load Main Runner
function starmus_debug_run()
{
	error_log('STARMUS BOOT: Hook fired. Checking classes...');

	// Check Orchestrator Class Existence
	if (! class_exists('\Starisian\Sparxstar\Starmus\StarmusAudioRecorder')) {
		error_log('STARMUS CRITICAL: Orchestrator Class NOT FOUND. Check namespace/autoload mapping.');
		return;
	}

	try {
		error_log('STARMUS BOOT: Running Orchestrator...');
		\Starisian\Sparxstar\Starmus\StarmusAudioRecorder::starmus_run();
		error_log('STARMUS BOOT: Success.');
	} catch (Throwable $e) {
		error_log('STARMUS CRITICAL: Orchestrator Crash - ' . $e->getMessage());
		error_log($e->getTraceAsString());
	}
}

add_action('plugins_loaded', 'starmus_debug_run', 20);
