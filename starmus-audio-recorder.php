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

use Starisian\src\Autoloader;

/**
 * Plugin Name:       Starmus Audio Recorder
 * Plugin URI:        https://github.com/Starisian-Technologies/starmus-audio-recorder
 * Description:       Adds a mobile-friendly MP3 audio recorder for oral history submission in low-bandwidth environments.
 * Version:           0.2.0
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


// 1. DEFINE CONSTANTS
define( 'STARMUS_PATH', plugin_dir_path( __FILE__ ) );
define( 'STARMUS_URL', plugin_dir_url( __FILE__ ) );
define( 'STARMUS_VERSION', '0.3.1' ); // Or your get_file_data logic


// 2. LOAD AUTOLOADER AND INCLUDE NECESSARY FILES
require_once STARMUS_PATH . 'src/Autoloader.php';
Starisian\src\Autoloader::register();

// This file contains all add_action('init', ...) calls for CPTs and Taxonomies.
require_once STARMUS_PATH . 'includes/StarmusCustomPostType.php';


use Starisian\src\includes\StarmusPlugin;

final class StarmusAudioRecorder {
    
    // --- FIX: DEFINE MISSING CONSTANTS AND PROPERTIES ---
    const MINIMUM_PHP_VERSION = '8.2';
    const MINIMUM_WP_VERSION = '6.4';
    private static $instance = null;
	private StarmusPlugin $starmus_plugin;
    private $compatibility_messages = [];

	private function __construct() {
		if ( ! $this->check_compatibility() ) {
			add_action( 'admin_notices', [ $this, 'display_compatibility_notice' ] );
			return;
		}
        
        // Initialize the loader
        $this->load_starmus_plugin();
    }

	public static function get_instance(): StarmusAudioRecorder {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function load_starmus_plugin(): void {
		if(!class_exists('StarmusPlugin')){
			require_once STARMUS_PATH . 'src/includes/StarmusPlugin.php';
		}
		try{
			$this->starmus_plugin = StarmusPlugin::get_instance();
		}catch(Exception $e){
			if(defined('WP_DEBUG') && WP_DEBUG){
				error_log('Failed to load StarmusPlugin: ' . $e->getMessage());
			}
		}
		return;
	}

	public function init(): void {
		$this->get_starmus_plugin()->init();
	}

	public function get_starmus_plugin(): StarmusPlugin {
		return $this->starmus_plugin;
	}

	/**
	 * FIX: Activation callback. ONLY flush rewrite rules.
	 */
	public static function activate(): void {
		// The CPTs will be registered via the 'init' hook in post-types.php.
        // We just need to flush the rules so WordPress recognizes their URLs.
		flush_rewrite_rules();
	}

	/**
	 * FIX: Deactivation callback. Should generally be empty unless you need to reverse a specific activation step.
	 */
	public static function deactivate(): void {
		// No action needed. CPTs will disappear because the plugin's code won't run.
		flush_rewrite_rules();
	}

	/**
	 * WARNING: This is a destructive operation.
	 * It deletes all data associated with this plugin.
	 */
	public static function uninstall(): void {
        // You might want to keep this, but be aware of the consequences.
		delete_option( 'starmus_settings' ); // Ensure this option name is correct

        // This is still not robust because the slug can be changed in settings.
		$posts = get_posts( [
			'post_type'   => StarmusAdminSettings::get_option( 'cpt_slug', 'starmus_submission' ), // Safer way
			'numberposts' => -1,
			'post_status' => 'any'
		] );

		foreach ( $posts as $post ) {
			wp_delete_post( $post->ID, true );
		}
	}
    
    private function check_compatibility(): bool {
        if ( version_compare( PHP_VERSION, self::MINIMUM_PHP_VERSION, '<' ) ) {
            $this->compatibility_messages[] = sprintf(
                __( 'Starmus Audio Recorder requires PHP %1$s or higher. You are running %2$s.', 'starmus-audio-recorder' ),
                self::MINIMUM_PHP_VERSION,
                PHP_VERSION
            );
        }

        if ( version_compare( get_bloginfo( 'version' ), self::MINIMUM_WP_VERSION, '<' ) ) {
            $this->compatibility_messages[] = sprintf(
                __( 'Starmus Audio Recorder requires WordPress %1$s or higher. You are running %2$s.', 'starmus-audio-recorder' ),
                self::MINIMUM_WP_VERSION,
                get_bloginfo( 'version' )
            );
        }

        return empty( $this->compatibility_messages );
    }

    public function display_compatibility_notice(): void {
        foreach ( $this->compatibility_messages as $message ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
        }
    }

    private function __clone() {}

    public function __wakeup() {
        $this->check_compatibility();
    }
}

// Register plugin lifecycle hooks.
register_activation_hook( __FILE__, [ 'StarmusAudioRecorder', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'StarmusAudioRecorder', 'deactivate' ] );
register_uninstall_hook( __FILE__, [ 'StarmusAudioRecorder', 'uninstall' ] );

// Initialize the plugin.
add_action( 'plugins_loaded', [ 'StarmusAudioRecorder', 'get_instance' ] );
add_action( 'init', [ StarmusAudioRecorder::get_instance(), 'init' ] );