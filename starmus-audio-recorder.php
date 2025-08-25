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

use Starisian\src\Autoloader;

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
 * License:           LicenseRef-Starisian-Technologies-Proprietary
 * License URI:       https://github.com/Starisian-Technologies/starmus-audio-recorder/LICENSE.md
 * Update URI:        https://github.com/Starisian-Technologies/starmus-audio-recorder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants.
define( 'STARMUS_PATH', plugin_dir_path( __FILE__ ) );
define( 'STARMUS_URL', plugin_dir_url( __FILE__ ) );
define( 'STARMUS_VERSION', '0.3.1' );
define( 'STARMUS_MAIN_FILE', __FILE__ );

// Register custom autoloader.
require_once STARMUS_PATH . 'src/Autoloader.php';
Autoloader::register();

// Load Composer autoloader if present.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require __DIR__ . '/vendor/autoload.php';
}

// Bootstrap the plugin.
new \Starisian\Starmus\Plugin();
