<?php
/**
 * A security-hardened, PSR-4 compliant class autoloader for the plugin.
 *
 * This autoloader is responsible for dynamically loading class files on demand.
 * It is designed to be interoperable with other autoloaders and includes multiple
 * security checks to prevent directory traversal and other file inclusion vulnerabilities.
 *
 * @package Starisian\src
 */

namespace Starisian\src;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

class Autoloader {
        /**
         * Namespace prefixes and their base directories.
         *
         * @var array<string,string>
         */
        private static array $prefixes = [];

        /**
         * Registers the autoload method with PHP's SPL autoloader stack.
         *
         * @return void
         */
        public static function register(): void {
                self::$prefixes = [
                        'Starisian\\src\\'     => realpath( dirname( __DIR__ ) . '/src/' ),
                        'Starisian\\Starmus\\' => realpath( dirname( __DIR__ ) . '/src/' ),
                ];
                spl_autoload_register( [ self::class, 'loadClass' ] );
        }

        /**
         * Loads a class file after performing security and path validation.
         *
         * @param string $class Fully-qualified class name.
         * @return void
         */
        private static function loadClass( string $class ): void {
                foreach ( self::$prefixes as $prefix => $baseDir ) {
                        if ( ! str_starts_with( $class, $prefix ) ) {
                                continue;
                        }

                        if ( ! preg_match( '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff\\]*$/', $class ) ) {
                                return;
                        }

                        $relativeClass = substr( $class, strlen( $prefix ) );
                        if ( strpos( $relativeClass, '..' ) !== false || strpos( $relativeClass, '/' ) === 0 ) {
                                return;
                        }

                        $filePath = $baseDir . '/' . str_replace( '\\', '/', $relativeClass ) . '.php';
                        $realPath = realpath( $filePath );
                        if ( false === $realPath ) {
                                return;
                        }

                        if ( ! str_starts_with( $realPath, $baseDir . DIRECTORY_SEPARATOR ) ) {
                                return;
                        }

                        if ( ! str_ends_with( $realPath, '.php' ) ) {
                                return;
                        }

                        require $realPath;
                        return;
                }
        }
}