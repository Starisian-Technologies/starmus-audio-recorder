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
	 * Cached base directory path for performance optimization.
	 *
	 * @var string|null
	 */
	private static $realBaseDir = null;

	/**
	 * Registers the autoload method with PHP's SPL autoloader stack.
	 *
	 * This is the entry point that tells PHP to use our `loadClass` method
	 * whenever it encounters a class that hasn't been defined yet.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Initialize cached base directory
		if ( null === self::$realBaseDir ) {
			$baseDir = dirname( __DIR__ ) . '/';
			self::$realBaseDir = realpath( $baseDir );
		}
		spl_autoload_register( [ self::class, 'loadClass' ] );
	}

	/**
	 * Loads a class file after performing security and path validation.
	 *
	 * @param string $class The fully-qualified class name (e.g., "Starisian\src\Includes\StarmusAudioSubmissionHandler").
	 * @return void
	 */
	private static function loadClass( string $class ): void {
		// 1. Define the namespace prefix this autoloader is responsible for.
		$prefix = 'Starisian\\src\\';

		// 2. Interoperability Check:
		// If the class does not start with our prefix, do nothing. This allows
		// other autoloaders (from WordPress core, themes, or other plugins) to handle it.
		// Uses str_starts_with() for improved readability (PHP 8.0+).
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		// 3. Additional input validation for class name
		if ( ! preg_match( '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff\\]*$/', $class ) ) {
			return;
		}

		// 4. Convert the class name to a file path according to PSR-4 standard.
		// - Remove the namespace prefix from the class name.
		// - Replace namespace separators (\) with directory separators (/).
		// - Append the .php extension.
		$relativeClass = substr( $class, strlen( $prefix ) );
		
		// Additional validation on relative class path
		if ( strpos( $relativeClass, '..' ) !== false || strpos( $relativeClass, '/' ) === 0 ) {
			return;
		}
		
		$baseDir = dirname( __DIR__ ) . '/';
		$filePath = $baseDir . str_replace( '\\', '/', $relativeClass ) . '.php';

		// 5. Security - Path Canonicalization and Existence Check:
		// `realpath()` resolves all symbolic links, '.', and '..' path segments.
		// It returns the absolute, canonicalized path, or `false` if the file does not exist.
		// This is a crucial first step in preventing path manipulation.
		$realPath = realpath( $filePath );
		if ( false === $realPath ) {
			return;
		}

		// 6. Security - Directory Jailing:
		// This is the most critical security check. We ensure that the resolved,
		// absolute path of the file to be included is *within* our allowed base directory.
		// This makes it impossible for the autoloader to be tricked into including a sensitive
		// file from elsewhere on the server (e.g., ../../../wp-config.php).
		if ( false === self::$realBaseDir || ! str_starts_with( $realPath, self::$realBaseDir . DIRECTORY_SEPARATOR ) ) {
			return;
		}
		
		// Additional security: ensure file has .php extension
		if ( ! str_ends_with( $realPath, '.php' ) ) {
			return;
		}

		// 7. Include the File:
		// All checks have passed; the file is confirmed to be a valid, existing file
		// located safely within our plugin's directory.
		require $realPath;
	}
}