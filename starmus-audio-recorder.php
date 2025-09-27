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
 * @version 0.7.4
 * @package Starmus
 */

/**
 * Plugin Name:       Starmus Audio Recorder
 * Plugin URI:        https://github.com/Starisian-Technologies/starmus-audio-recorder
 * Description:       Adds a mobile-friendly MP3 audio recorder for oral history submission in low-bandwidth environments.
 * Version:           0.7.4
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Starisian Technologies (Max Barrett)
 * Author URI:        https://starisian.com
 * Text Domain:       starmus-audio-recorder
 * Domain Path:       /languages
 * License:           LicenseRef-Starisian-Technologies-Proprietary
 * License URI:       https://github.com/Starisian-Technologies/starmus-audio-recorder/LICENSE.md
 * Update URI:        https://github.com/Starisian-Technologies/starmus-audio-recorder.git
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Absolute filesystem path to the plugin directory. */
define( 'STARMUS_PATH', plugin_dir_path( __FILE__ ) );
/** Public URL to the plugin directory. */
define( 'STARMUS_URL', plugin_dir_url( __FILE__ ) );
/** Main plugin file reference used for WordPress hooks. */
define( 'STARMUS_MAIN_FILE', __FILE__ );
/** Directory path alias kept for backward compatibility. */
define( 'STARMUS_MAIN_DIR', plugin_dir_path( __FILE__ ) );

/** Human readable plugin name displayed in WordPress admin. */
define( 'STARMUS_PLUGIN_NAME', 'Starmus Audio Recorder' );
/** Shared prefix applied to option keys, actions, and filters. */
define( 'STARMUS_PLUGIN_PREFIX', 'starmus' );
/** Current plugin semantic version string. */
define( 'STARMUS_VERSION', '0.7.4' );

// Load Composer autoloader if present.
if ( file_exists( STARMUS_MAIN_DIR . '/vendor/autoload.php' ) ) {
	require STARMUS_MAIN_DIR . '/vendor/autoload.php';
}

// Ensure all required classes are loaded
require_once STARMUS_PATH . 'src/includes/StarmusSettings.php';
require_once STARMUS_PATH . 'src/StarmusPlugin.php';
require_once STARMUS_PATH . 'src/admin/StarmusAdmin.php';
require_once STARMUS_PATH . 'src/frontend/StarmusAudioRecorderUI.php';
require_once STARMUS_PATH . 'src/frontend/StarmusAudioEditorUI.php';
require_once STARMUS_PATH . 'src/core/StarmusPluginUpdater.php';
require_once STARMUS_PATH . 'src/cli/StarmusCLI.php';
require_once STARMUS_PATH . 'src/services/PostProcessingService.php';
require_once STARMUS_PATH . 'src/services/WaveformService.php';
require_once STARMUS_PATH . 'src/services/AudioProcessingService.php';

use Starmus\StarmusPlugin;

// Register Plugin Lifecycle Hooks.
register_activation_hook( STARMUS_MAIN_FILE, array( 'Starmus\StarmusPlugin', 'activate' ) );
register_deactivation_hook( STARMUS_MAIN_FILE, array( 'Starmus\StarmusPlugin', 'deactivate' ) );
register_uninstall_hook( STARMUS_MAIN_FILE, array( 'Starmus\StarmusPlugin', 'uninstall' ) );
// Initialize the plugin once all other plugins are loaded.
add_action( 'plugins_loaded', array( StarmusPlugin::class, 'run' ) );

// Bootstrap plugin services during WordPress init lifecycle.
add_action( 'init', array( StarmusPlugin::class, 'init_plugin' ) );
