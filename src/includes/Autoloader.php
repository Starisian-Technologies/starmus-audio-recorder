<?php
namespace Starisian\src\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Autoloader {
    public static function register(): void {
        spl_autoload_register( [ __CLASS__, 'autoload' ] );
    }

    private static function autoload( string $class ): void {
        $prefix = 'Starisian\\src\\';
        $base_dir = dirname( __DIR__ ) . '/';
        $len = strlen( $prefix );
        if ( strncmp( $prefix, $class, $len ) !== 0 ) {
            return;
        }
        $relative_class = substr( $class, $len );
        $file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';
        if ( file_exists( $file ) ) {
            require $file;
        }
    }
}
