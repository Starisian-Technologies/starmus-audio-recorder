<?php

// Define WordPress constants for PHPStan analysis
if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}

if (!defined('WPINC')) {
	define('WPINC', 'wp-includes');
}

if (!defined('WP_CONTENT_DIR')) {
	define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
}

if (!defined('WP_PLUGIN_DIR')) {
	define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
}

if (!defined('WP_DEBUG')) {
	define('WP_DEBUG', true);
}
