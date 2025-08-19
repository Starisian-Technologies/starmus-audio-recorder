<?php
namespace Starisian\src\includes;

class Autoloader {
    private string $base_dir;

    public function __construct(string $base_dir) {
        $this->base_dir = rtrim($base_dir, '/').'/';
        spl_autoload_register([$this, 'autoload']);
    }

    private function autoload(string $class): void {
        $prefix = 'Starisian\\src\\';
        if (0 !== strpos($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $relative = str_replace('\\', '/', $relative);
        $file = $this->base_dir . $relative . '.php';
        if (file_exists($file)) {


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
