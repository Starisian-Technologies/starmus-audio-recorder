<?php
/**
 * Plugin Name:       Starmus Audio Recorder
 * ... (all your header comments) ...
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. DEFINE CONSTANTS
define( 'STARMUS_PATH', plugin_dir_path( __FILE__ ) );
define( 'STARMUS_URL', plugin_dir_url( __FILE__ ) );
define( 'STARMUS_VERSION', '0.3.1' ); // Or your get_file_data logic

// 2. INCLUDE ALL NECESSARY FILES
// This is the crucial step you were missing. This file contains all your
// add_action('init', ...) calls for CPTs and Taxonomies.
require_once STARMUS_PATH . 'includes/post-types.php';

// Include class files
require_once STARMUS_PATH . 'src/includes/StarmusPlugin.php';
require_once STARMUS_PATH . 'src/admin/StarmusAdminSettings.php';
// ... include other classes like StarmusAudioEditorUI, StarmusAudioRecorderUI etc.

use Starisian\src\includes\StarmusPlugin;

final class StarmusAudioRecorder {
    
    // --- FIX: DEFINE MISSING CONSTANTS AND PROPERTIES ---
    const MINIMUM_PHP_VERSION = '8.2';
    const MINIMUM_WP_VERSION = '6.4';
    private static $instance = null;
    private $compatibility_messages = [];

	private function __construct() {
		if ( ! $this->check_compatibility() ) {
			add_action( 'admin_notices', [ $this, 'display_compatibility_notice' ] );
			return;
		}
        
        // Initialize the loader
        StarmusPlugin::init();
    }

	public static function get_instance(): StarmusAudioRecorder {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
    
    // --- All your other methods like check_compatibility(), display_compatibility_notice(), __clone(), __wakeup() go here ---
    // ... (They were well-written, just needed the properties defined)

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
    
    // Paste your other methods (check_compatibility, display_compatibility_notice, clone, wakeup) here.
}

// Register plugin lifecycle hooks.
register_activation_hook( __FILE__, [ 'StarmusAudioRecorder', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'StarmusAudioRecorder', 'deactivate' ] );
register_uninstall_hook( __FILE__, [ 'StarmusAudioRecorder', 'uninstall' ] );

// Initialize the plugin.
add_action( 'plugins_loaded', [ 'StarmusAudioRecorder', 'get_instance' ] );