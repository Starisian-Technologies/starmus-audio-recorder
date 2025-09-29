<?php
/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * NOTICE: All information contained herein is, and remains, the property of Starisian Technologies and its suppliers, if any.
 * The intellectual and technical concepts contained herein are proprietary to Starisian Technologies and its suppliers and may
 * be covered by U.S. and foreign patents, patents in process, and are protected by trade secret or copyright law.
 *
 * Dissemination of this information or reproduction of this material is strictly forbidden unless
 * prior written permission is obtained from Starisian Technologies.
 *
 * SPDX-License-Identifier:  LicenseRef-Starisian-Technologies-Proprietary
 * License URI:              https://github.com/Starisian-Technologies/starmus-audio-recorder/LICENSE.md
 *
 * @since 0.1.0
 * @version 0.7.5
 * @package Starmus
 * @author Starisian Technologies (Max Barrett)
 * @module  StarmusAudioRecorder
 * @file    Main plugin file.
 * @license SEE LICENSE.md
 * @link   https://starisian.com
 */

/**
 * Plugin Name:       Starmus Audio Recorder
 * Plugin URI:        https://github.com/Starisian-Technologies/starmus-audio-recorder
 * Description:       Adds a mobile-friendly MP3 audio recorder for oral history submission in low-bandwidth environments.
 * Version:           0.7.5
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Tested up to:      6.5
 * Author:            Starisian Technologies (Max Barrett)
 * Author URI:        https://starisian.com
 * Text Domain:       starmus-audio-recorder
 * Domain Path:       /languages
 * License:           LicenseRef-Starisian-Technologies-Proprietary
 * License URI:       https://github.com/Starisian-Technologies/starmus-audio-recorder/LICENSE.md
 * Update URI:        https://github.com/Starisian-Technologies/starmus-audio-recorder.git
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

/** Absolute filesystem path to the plugin directory. */
define('STARMUS_PATH', plugin_dir_path(__FILE__));
/** Public URL to the plugin directory. */
define('STARMUS_URL', plugin_dir_url(__FILE__));
/** Main plugin file reference used for WordPress hooks. */
define('STARMUS_MAIN_FILE', __FILE__);
/** Directory path alias kept for backward compatibility. */
define('STARMUS_MAIN_DIR', plugin_dir_path(__FILE__));

/** Human readable plugin name displayed in WordPress admin. */
define('STARMUS_PLUGIN_NAME', 'Starmus Audio Recorder');
/** Shared prefix applied to option keys, actions, and filters. */
define('STARMUS_PLUGIN_PREFIX', 'starmus');
/** Current plugin semantic version string. */
define('STARMUS_VERSION', '0.7.4');

// 1. Include the Composer autoloader.
// This makes all the libraries you installed via Composer available.
require_once STARMUS_PATH . 'vendor/autoload.php';
// 2. Manually load the Secure Custom Fields plugin.
// This code checks if another ACF/SCF plugin is already active before loading.
if (!class_exists('ACF')) {
	// Define the path to the SCF plugin within your vendor directory.
	define('STARMUS_SCF_PATH', STARMUS_PATH . 'vendor/wpackagist-plugin/secure-custom-fields/');

	// Include the main SCF plugin file to make it active.
	require_once STARMUS_SCF_PATH . 'secure-custom-fields.php';

	// Optional: Hide the ACF admin menu if you are managing fields in code.
	add_filter('acf/settings/show_admin', '__return_false');
}


// Ensure all required classes are loaded.
require_once STARMUS_PATH . 'src/includes/StarmusSettings.php';
require_once STARMUS_PATH . 'src/frontend/StarmusAudioRecorderUI.php';
require_once STARMUS_PATH . 'src/frontend/StarmusSubmissionHandler.php';
require_once STARMUS_PATH . 'src/frontend/StarmusRestHandler.php';


require_once STARMUS_PATH . 'src/includes/StarmusSettings.php';
require_once STARMUS_PATH . 'src/StarmusPlugin.php';
require_once STARMUS_PATH . 'src/admin/StarmusAdmin.php';
require_once STARMUS_PATH . 'src/frontend/StarmusAudioRecorderUI.php';
require_once STARMUS_PATH . 'src/frontend/StarmusAudioEditorUI.php';
require_once STARMUS_PATH . 'src/core/StarmusPluginUpdater.php';
require_once STARMUS_PATH . 'src/services/PostProcessingService.php';
require_once STARMUS_PATH . 'src/services/WaveformService.php';
require_once STARMUS_PATH . 'src/services/AudioProcessingService.php';


use Starmus\StarmusPlugin;
use Starmus\cli\StarmusCLI;

// Load WP-CLI commands if in CLI context
if (defined(constant_name: 'WP_CLI') && WP_CLI) {
	require_once STARMUS_PATH . 'src/cli/StarmusCLI.php';
	// Register WP-CLI command if running in CLI context
	\WP_CLI::add_command('starmus', StarmusCLI::class);

}

/**
 * Plugin Activation Hook.
 *
 * Sets up the plugin on activation by adding custom capabilities and flushing rewrite rules.
 *
 * @since 0.1.0
 */
function starmus_activate(): void
{
	// Block activation if ACF or SCF is missing
	if (!\Starmus\StarmusPlugin::check_field_plugin_dependency()) {
		deactivate_plugins(plugin_basename(STARMUS_MAIN_FILE));
		error_log('Starmus Plugin: Activation failed due to missing ACF/SCF dependency');
		wp_die(__('Starmus Audio Recorder requires Advanced Custom Fields or Smart Custom Fields to be installed and activated.', 'starmus-audio-recorder'));
	} else {
		$cpt_file = realpath(STARMUS_PATH . 'src/includes/StarmusCustomPostType.php');
		if ($cpt_file && str_starts_with($cpt_file, realpath(STARMUS_PATH)) && file_exists($cpt_file)) {
			require_once $cpt_file;
		} else {
			error_log('Starmus Plugin: CPT file not found or invalid path during activation');
		}
	}
	// Add custom capabilities to roles
	\flush_rewrite_rules();
}
/**
 * Deactivation hook for the Starmus Audio Recorder plugin.
 *
 * @return  void
 * @since 0.1.0
 */
function starmus_deactivate(): void
{
	// Unregister the post type, so the rules are no longer in memory.
	unregister_post_type('audio-recording');
	// Clear the permalinks to remove our post type's rules from the database.
	flush_rewrite_rules();
	error_log('Starmus Plugin: Deactivation completed, CPT unregistered and rewrite rules flushed');
}
/**
 * Plugin Uninstall Hook.
 *
 * @since 0.1.0
 */
function starmus_uninstall(): void
{
	$file = realpath(STARMUS_PATH . 'uninstall.php');
	if ($file && str_starts_with($file, realpath(STARMUS_PATH)) && file_exists($file)) {
		require_once $file;
	} else {
		error_log('Starmus Plugin: Uninstall file not found');
	}
	\wp_clear_scheduled_hook('starmus_cleanup_temp_files');
	\flush_rewrite_rules();
	error_log('Starmus Plugin: Uninstall completed, scheduled hooks cleared and rewrite rules flushed');
}



// Register Plugin Lifecycle Hooks.
register_activation_hook(STARMUS_MAIN_FILE, 'starmus_activate');
register_deactivation_hook(STARMUS_MAIN_FILE, 'starmus_deactivate');
register_uninstall_hook(STARMUS_MAIN_FILE, 'starmus_uninstall');
// Initialize the plugin once all other plugins are loaded.
add_action('plugins_loaded', array(StarmusPlugin::class, 'run'));

// Bootstrap plugin services during WordPress init lifecycle.
add_action('init', array(StarmusPlugin::class, 'init_plugin'));

add_action(
	'plugins_loaded',
	function () {
		$settings = new \Starmus\includes\StarmusSettings();
		$submission_handler = new \Starmus\frontend\StarmusSubmissionHandler($settings);
		$rest_handler = new \Starmus\frontend\StarmusRestHandler($settings, $submission_handler);
		$ui_handler = new \Starmus\frontend\StarmusAudioRecorderUI($settings);
	}
);
