<?php
// phpstan-bootstrap.php

// Define a minimal set of WordPress constants to avoid errors
define( 'ABSPATH', __DIR__ . '/fake-wordpress-dir/' );
define( 'WPINC', 'wp-includes' );
// 1. Define Plugin Constants.
define( 'STARMUS_PATH', plugin_dir_path( __FILE__ ) );
define( 'STARMUS_URL', plugin_dir_url( __FILE__ ) );
define( 'STARMUS_MAIN_FILE', __FILE__ );
define( 'STARMUS_MAIN_DIR', plugin_dir_path( __FILE__ ) );

// ... other constants ...
define( 'STARMUS_VERSION', '0.3.1' );

// Create dummy WordPress core files that are being required
// This prevents "file not found" errors for `require_once`.
$dummy_wp_admin_dir = __DIR__ . '/wp-admin/includes';
if ( ! is_dir( $dummy_wp_admin_dir ) ) {
	mkdir( $dummy_wp_admin_dir, 0755, true );
}
touch( $dummy_wp_admin_dir . '/file.php' );
touch( $dummy_wp_admin_dir . '/media.php' );
touch( $dummy_wp_admin_dir . '/image.php' );

// Now, load your plugin's main file to define its constants
require_once __DIR__ . '/starmus-audio-recorder.php';
