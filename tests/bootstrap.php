<?php

/**
 * WordPress Test Framework Bootstrap
 *
 * @package Starisian\Sparxstar\Starmus\tests
 */

// Load Composer autoloader first
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoload)) {
	require $autoload;
}

// Load WordPress test environment
$_tests_dir = getenv('WP_TESTS_DIR');
if (!$_tests_dir) {
	$_tests_dir = '/wordpress-phpunit';
}

$file = $_tests_dir . '/includes/functions.php';
if (!file_exists($file)) {
	echo "Could not find " . $file;
	exit(1);
}

// Give access to tests_add_filter() function
require_once $file;

/**
 * Manually load the plugin being tested
 */
function _manually_load_plugin(): void
{
	require dirname(dirname(__FILE__)) . '/starmus-audio-recorder.php';
}

tests_add_filter('muplugins_loaded', '_manually_load_plugin');

// Start up the WP testing environment
require $_tests_dir . '/includes/bootstrap.php';
