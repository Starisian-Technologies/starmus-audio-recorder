<?php

/**
 * Unit Test Bootstrap (No WordPress)
 *
 * @package Starmus\Tests
 */

// Load Composer autoloader
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoload)) {
	require $autoload;
}

// Load WordPress mocks
require_once __DIR__ . '/wordpress-mocks.php';

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

if (!function_exists('get_option')) {
	function get_option($option, $default = false)
	{
		return $default;
	}
}

if (!function_exists('update_option')) {
	function update_option($option, $value, $autoload = null)
	{
		return true;
	}
}

if (!function_exists('add_option')) {
	function add_option($option, $value = '', $deprecated = '', $autoload = 'yes')
	{
		return true;
	}
}

if (!function_exists('delete_option')) {
	function delete_option($option)
	{
		return true;
	}
}

if (!function_exists('wp_parse_args')) {
	function wp_parse_args($args, $defaults = '')
	{
		if (is_object($args)) {
			$r = get_object_vars($args);
		} elseif (is_array($args)) {
			$r = &$args;
		} else {
			wp_parse_str($args, $r);
		}

		if (is_array($defaults)) {
			return array_merge($defaults, $r);
		}
		return $r;
	}
}

if (!function_exists('wp_parse_str')) {
	function wp_parse_str($string, &$array)
	{
		parse_str($string, $array);
	}
}

if (!function_exists('sanitize_key')) {
	function sanitize_key($key)
	{
		return strtolower($key);
	}
}

if (!function_exists('absint')) {
	function absint($maybeint)
	{
		return abs((int) $maybeint);
	}
}

if (!function_exists('esc_url_raw')) {
	function esc_url_raw($url, $protocols = null)
	{
		return $url;
	}
}

if (!function_exists('trailingslashit')) {
	function trailingslashit($string)
	{
		return rtrim($string, '/\\') . '/';
	}
}

if (!function_exists('wp_kses_post')) {
	function wp_kses_post($data)
	{
		return $data;
	}
}

if (!function_exists('is_admin')) {
	function is_admin()
	{
		return false;
	}
}

if (!function_exists('plugin_dir_path')) {
	function plugin_dir_path($file)
	{
		return dirname($file) . '/';
	}
}

if (!function_exists('apply_filters')) {
	function apply_filters($tag, $value, ...$args)
	{
		return $value;
	}
}

if (!function_exists('wp_json_encode')) {
	function wp_json_encode($data, $options = 0, $depth = 512)
	{
		return json_encode($data, $options, $depth);
	}
}

if (!defined('STARMUS_REST_NAMESPACE')) {
	define('STARMUS_REST_NAMESPACE', 'starmus/v1');
}
