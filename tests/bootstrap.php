<?php
/**
 * Bootstrap file for PHPUnit tests.
 *
 * @package Starmus\tests\unit
 * @version 0.4.0
 * @since 0.3.1
 */
namespace Starmus\tests\unit;
// Load Composer autoloader
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
    require $autoload;
}

// Mock WordPress constants and functions for testing
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/' );
}

if ( ! defined( 'WP_DEBUG' ) ) {
    define( 'WP_DEBUG', true );
}

// Mock WordPress functions that the plugin uses
if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) {
        return dirname( $file ) . '/';
    }
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) {
        return 'http://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
        // Mock implementation
        return true;
    }
}

if ( ! function_exists( 'load_plugin_textdomain' ) ) {
    function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) {
        return true;
    }
}

if ( ! function_exists( 'register_post_type' ) ) {
    function register_post_type( $post_type, $args = array() ) {
        return true;
    }
}

if ( ! function_exists( 'register_taxonomy' ) ) {
    function register_taxonomy( $taxonomy, $object_type, $args = array() ) {
        return true;
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
        return true;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook, $value ) {
        return $value;
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

if ( ! function_exists( 'plugin_basename' ) ) {
    function plugin_basename( $file ) {
        return basename( dirname( $file ) ) . '/' . basename( $file );
    }
}

if ( ! function_exists( 'is_admin' ) ) {
    function is_admin() {
        return false; // For testing, assume frontend
    }
}

if ( ! function_exists( 'add_shortcode' ) ) {
    function add_shortcode( $tag, $callback ) {
        return true;
    }
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
        return true;
    }
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
        return true;
    }
}

// Define plugin constants
define( 'STARMUS_PATH', dirname(__DIR__) . '/' );
define( 'STARMUS_URL', 'http://example.com/wp-content/plugins/starmus-audio-recorder/' );
define( 'STARMUS_MAIN_FILE', dirname(__DIR__) . '/starmus-audio-recorder.php' );
define( 'STARMUS_MAIN_DIR', dirname(__DIR__) . '/' );
define( 'STARMUS_VERSION', '0.3.1' );
