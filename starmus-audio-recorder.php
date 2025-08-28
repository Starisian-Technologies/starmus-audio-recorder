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
 */
/**
 * Plugin Name:       Starmus Audio Recorder
 * Plugin URI:        https://github.com/Starisian-Technologies/starmus-audio-recorder
 * Description:       Adds a mobile-friendly MP3 audio recorder for oral history submission in low-bandwidth environments.
 * Version:           0.3.1
 * Requires at least: 6.4
 * Requires PHP:      8.2
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

// 1. Define Plugin Constants.
define( 'STARMUS_PATH', plugin_dir_path( __FILE__ ) );
define('STARMUS_URL', plugin_dir_url( __FILE__ ));
define( 'STARMUS_MAIN_FILE', __FILE__ );
define('STARMUS_MAIN_DIR', plugin_dir_path( __FILE__ ));

// ... other constants ...
define('STARMUS_VERSION',"0.3.1");

// Load Composer autoloader if present.
if ( file_exists( STARMUS_MAIN_DIR . '/vendor/autoload.php' ) ) {
    require STARMUS_MAIN_DIR . '/vendor/autoload.php';
}

// Now, your classes should be loadable.
use Starmus\includes\StarmusPlugin; // Good practice to include

// add initialization hook.
add_action( 'init', [ '\Starmus\includes\StarmusPlugin' , 'starmus_init' ] );

// 3. Register Plugin Lifecycle Hooks.
// These point to the static methods in your namespaced class.
register_activation_hook( STARMUS_MAIN_FILE, [ 'Starmus\includes\StarmusPlugin', 'activate' ] );
register_deactivation_hook( STARMUS_MAIN_FILE, [ 'Starmus\includes\StarmusPlugin', 'deactivate' ] );
register_uninstall_hook( STARMUS_MAIN_FILE, [ 'Starmus\includes\StarmusPlugin', 'uninstall' ] );

// 4. Instantiate the Main Plugin Class (Bootstrap).
add_action('plugins_loaded', static function () {
    \Starmus\includes\StarmusPlugin::starmus_run(); 
});

