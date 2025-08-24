<?php
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
    require $autoload;
}

$_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = dirname(__DIR__) . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! file_exists( $_tests_dir . '/includes/bootstrap.php' ) ) {
    fwrite( STDERR, "Could not find WordPress tests in {$_tests_dir}.\n" );
    exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
    'muplugins_loaded',
    function () {
        require dirname(__DIR__) . '/starmus-audio-recorder.php';
    }
);

require $_tests_dir . '/includes/bootstrap.php';
