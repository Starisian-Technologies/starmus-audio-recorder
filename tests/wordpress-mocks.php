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
/**
 * WordPress Mock Classes for Unit Testing
 *
 * These are mock implementations of WordPress classes
 * that don't exist in the unit test environment.
 */

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

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private $errors = [];
        private $error_data = [];

        public function __construct($code = '', $message = '', $data = '')
        {
            if (!empty($code)) {
                $this->add($code, $message, $data);
            }
        }

        public function add($code, $message, $data = '')
        {
            $this->errors[$code][] = $message;
            if (!empty($data)) {
                $this->error_data[$code] = $data;
            }
        }

        public function get_error_code()
        {
            $codes = array_keys($this->errors);
            return $codes[0] ?? '';
        }

        public function get_error_message($code = '')
        {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            return $this->errors[$code][0] ?? '';
        }
    }
}

if (!class_exists('WP_UnitTestCase')) {
    class WP_UnitTestCase extends PHPUnit\Framework\TestCase
    {
        public function setUp(): void
        {
            parent::setUp();
        }

        public function tearDown(): void
        {
            parent::tearDown();
        }
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}
