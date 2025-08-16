<?php
/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * NOTICE: All information contained herein is, and remains, the property of Starisian Technologies and its suppliers, if any.
 * The intellectual and technical concepts contained herein are proprietary to Starisian Technologies and its suppliers and may be covered by U.S.
 * and foreign patents, patents in process, and are protected by trade secret or copyright law.
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
 * Version:           0.5.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Starisian Technologies (Max Barrett)
 * Author URI:        https://starisian.com
 * Text Domain:       starmus-audio-recorder
 * License:           LicenseRef-Starisian-Technologies-Proprietary
 * License URI:       https://github.com/Starisian-Technologies/starmus-audio-recorder/LICENSE.md
 * Update URI:        https://github.com/Starisian-Technologies/starmus-audio-recorder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'STARMUS_PATH' ) ) {
	define( 'STARMUS_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'STARMUS_URL' ) ) {
	define( 'STARMUS_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'STARMUS_VERSION' ) ) {
	define( 'STARMUS_VERSION', '0.5.0' );
}

require_once __DIR__ . '/src/includes/Autoloader.php';
Starisian\src\Includes\Autoloader::register();

register_activation_hook( __FILE__, [ 'Starisian\\src\\Core\\AudioRecorder', 'starmus_activate' ] );
register_deactivation_hook( __FILE__, [ 'Starisian\\src\\Core\\AudioRecorder', 'starmus_deactivate' ] );
register_uninstall_hook( __FILE__, [ 'Starisian\\src\\Core\\AudioRecorder', 'starmus_uninstall' ] );

add_action( 'plugins_loaded', [ 'Starisian\\src\\Core\\AudioRecorder', 'starmus_run' ] );
