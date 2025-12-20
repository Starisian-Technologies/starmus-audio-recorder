<?php
namespace Starisian\Sparxstar\Starmus\helpers;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\AbstractLogger;
use Throwable;

/**
 * PSR-3 compliant logger for Starmus with WordPress integration and error object processing.
 */
final class StarmusLogger extends AbstractLogger implements LoggerInterface {

	private const LEVELS = [
		LogLevel::DEBUG     => 100,
		LogLevel::INFO      => 200,
		LogLevel::NOTICE    => 250,
		LogLevel::WARNING   => 300,
		LogLevel::ERROR     => 400,
		LogLevel::CRITICAL  => 500,
		LogLevel::ALERT     => 550,
		LogLevel::EMERGENCY => 600,
	];

	private static string $min_log_level = LogLevel::DEBUG;
	private static ?string $correlation_id = null;
	private static array $timers = [];

	private static function get_log_path(): string {
		if ( \defined( 'WP_CONTENT_DIR' ) ) {
			return WP_CONTENT_DIR . '/debug.log';
		}
		if ( \defined( 'ABSPATH' ) ) {
			return ABSPATH . 'wp-content/debug.log';
		}
		return sys_get_temp_dir() . '/starmus_fallback.log';
	}

	/**
	 * PSR-3 compliant log method with error object processing.
	 */
	public function log( $level, $message, array $context = [] ): void {
		if ( ! isset( self::LEVELS[$level] ) || self::LEVELS[$level] < self::LEVELS[self::$min_log_level] ) {
			return;
		}

		// Process error objects in context
		$context = $this->processErrorObjects( $context );

		// PSR-3 message interpolation
		$message = $this->interpolate( $message, $context );

		$timestamp = gmdate( 'Y-m-d H:i:s' );
		$correlation = self::$correlation_id ? '[' . self::$correlation_id . '] ' : '';
		$context_str = ! empty( $context ) ? ' ' . wp_json_encode( $context ) : '';

		$line = sprintf(
			"[%s] [STARMUS] %s[%s] %s%s%s",
			$timestamp,
			$correlation,
			strtoupper( $level ),
			$message,
			$context_str,
			PHP_EOL
		);

		@error_log( $line, 3, self::get_log_path() );
	}

	/**
	 * Process error objects and exceptions in context.
	 */
	private function processErrorObjects( array $context ): array {
		foreach ( $context as $key => $value ) {
			if ( $value instanceof Throwable ) {
				$context[$key] = [
					'class' => get_class( $value ),
					'message' => $value->getMessage(),
					'code' => $value->getCode(),
					'file' => $value->getFile(),
					'line' => $value->getLine(),
					'trace' => $value->getTraceAsString()
				];
			}
		}
		return $context;
	}

	/**
	 * PSR-3 message interpolation.
	 */
	private function interpolate( string $message, array $context ): string {
		$replace = [];
		foreach ( $context as $key => $val ) {
			if ( is_null( $val ) || is_scalar( $val ) || ( is_object( $val ) && method_exists( $val, '__toString' ) ) ) {
				$replace['{' . $key . '}'] = $val;
			}
		}
		return strtr( $message, $replace );
	}

	// Static convenience methods
	public static function setCorrelationId( ?string $id = null ): void {
		self::$correlation_id = $id ?? wp_generate_uuid4();
	}

	public static function setMinLogLevel( string $level ): void {
		if ( isset( self::LEVELS[$level] ) ) {
			self::$min_log_level = $level;
		}
	}

	// Timer utilities
	public static function timeStart( string $label ): void {
		self::$timers[$label] = microtime( true );
	}

	public static function timeEnd( string $label, string $context = 'Timer' ): void {
		if ( ! isset( self::$timers[$label] ) ) {
			return;
		}
		$duration = round( ( microtime( true ) - self::$timers[$label] ) * 1000, 2 );
		unset( self::$timers[$label] );
		( new self() )->debug( '{context}: {label} completed in {duration}ms', [
			'context' => $context,
			'label' => $label,
			'duration' => $duration
		] );
	}

	/**
	 * Get caller information from backtrace.
	 */
	private static function getCaller(): string {
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 4 );
		$caller = $trace[3] ?? $trace[2] ?? [];
		
		if ( isset( $caller['class'] ) ) {
			return basename( $caller['class'] ) . '::' . $caller['function'];
		}
		if ( isset( $caller['function'] ) ) {
			return $caller['function'];
		}
		return 'unknown';
	}

	// Backward compatibility methods with automatic caller detection
	public static function debug( string $message, array $data = [] ): void {
		( new self() )->log( LogLevel::DEBUG, '[{caller}] {message}', array_merge( ['caller' => self::getCaller(), 'message' => $message], $data ) );
	}

	public static function info( string $message, array $data = [] ): void {
		( new self() )->log( LogLevel::INFO, '[{caller}] {message}', array_merge( ['caller' => self::getCaller(), 'message' => $message], $data ) );
	}

	public static function warning( string $message, array $data = [] ): void {
		( new self() )->log( LogLevel::WARNING, '[{caller}] {message}', array_merge( ['caller' => self::getCaller(), 'message' => $message], $data ) );
	}

	public static function error( string $message, array $data = [] ): void {
		( new self() )->log( LogLevel::ERROR, '[{caller}] {message}', array_merge( ['caller' => self::getCaller(), 'message' => $message], $data ) );
	}

	/**
	 * Log error with exception object processing and automatic caller detection.
	 */
	public static function exception( Throwable $exception, array $data = [] ): void {
		( new self() )->log( LogLevel::ERROR, '[{caller}] Exception: {message}', array_merge( [
			'caller' => self::getCaller(),
			'message' => $exception->getMessage(),
			'exception' => $exception
		], $data ) );
	}
}