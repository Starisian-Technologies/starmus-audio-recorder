<?php
/**
 * Unit Test Bootstrap (No WordPress)
 *
 * @package Starmus\tests
 */

// Load Composer autoloader
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoload)) {
	require $autoload;
}

// Define WordPress constants for testing without WordPress
if (!defined('ABSPATH')) {
	define('ABSPATH', '/tmp/');
}

if (!defined('WP_DEBUG')) {
	define('WP_DEBUG', true);
}

if (!defined('WP_DEBUG_LOG')) {
	define('WP_DEBUG_LOG', true);
}

if (!defined('STARMUS_AUDIO_RECORDER')) {
	define('STARMUS_AUDIO_RECORDER', 'starmus-audio-recorder');
}

if (!defined('STARMUS_PATH')) {
	define('STARMUS_PATH', dirname(__DIR__) . '/');
}

if (!defined('STARMUS_MAIN_FILE')) {
	define('STARMUS_MAIN_FILE', STARMUS_PATH . 'starmus-audio-recorder.php');
}

// Mock WordPress functions for unit testing
if (!function_exists('add_action')) {
	function add_action($hook, $callback, $priority = 10, $args = 1)
	{
		// Mock implementation
		return true;
	}
}

if (!function_exists('add_filter')) {
	function add_filter($hook, $callback, $priority = 10, $args = 1)
	{
		// Mock implementation
		return true;
	}
}

if (!function_exists('esc_html')) {
	function esc_html($text)
	{
		return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('sanitize_text_field')) {
	function sanitize_text_field($str)
	{
		return trim(strip_tags($str));
	}
}

if (!function_exists('error_log')) {
	function error_log($message)
	{
		// Mock for testing
		return true;
	}
}

if (!function_exists('current_user_can')) {
	function current_user_can($capability)
	{
		return true; // Mock - always allow for unit tests
	}
}

if (!function_exists('wp_verify_nonce')) {
	function wp_verify_nonce($nonce, $action)
	{
		return true; // Mock - always valid for unit tests
	}
}

if (!function_exists('get_current_user_id')) {
	function get_current_user_id()
	{
		return 1; // Mock user ID
	}
}

if (!function_exists('wp_insert_post')) {
	function wp_insert_post($postarr)
	{
		return 123; // Mock post ID
	}
}

if (!function_exists('add_shortcode')) {
	function add_shortcode($tag, $callback)
	{
		return true;
	}
}

if (!function_exists('register_post_type')) {
	function register_post_type($post_type, $args = array())
	{
		return (object) array('name' => $post_type);
	}
}

if (!function_exists('register_taxonomy')) {
	function register_taxonomy($taxonomy, $object_type, $args = array())
	{
		return (object) array('name' => $taxonomy);
	}
}

if (!function_exists('load_plugin_textdomain')) {
	function load_plugin_textdomain($domain, $deprecated = false, $plugin_rel_path = false)
	{
		return true;
	}
}

if (!function_exists('plugin_basename')) {
	function plugin_basename($file)
	{
		return basename($file);
	}
}

if (!function_exists('apply_filters_ref_array')) {
	function apply_filters_ref_array($tag, $args)
	{
		return $args[0] ?? null;
	}
}

// Mock WordPress classes for unit testing
if (!class_exists('WP_REST_Request')) {
	class WP_REST_Request
	{
		public function get_header($key)
		{
			return 'mock_header_value';
		}
	}
}
