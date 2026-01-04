<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\helpers;

/**
 * @file src/helpers/StarmusLogger.php
 *
 * @package Starisian\Sparxstar\Starmus\helpers
 *
 * @author Starisian Technologies
 * @license Starisian Technolgoies Proprietary License
 */

if ( ! \defined('ABSPATH')) {
    exit();
}

use function error_log;
use function is_admin;

use Starisian\Sparxstar\Starmus\helpers\logger\StarLogger;

/**
 * PSR-3 compliant logging system for Starmus Audio Recorder
 * Implements log levels, message formatting, and context handling
 *
 * Usage:
 * StarmusLogger::info('This is an info message');
 * StarmusLogger::error('An error occurred', ['error_code' =>
 * 123]);
 *
 * Log Levels:
 *      - EMERGENCY
 *     - ALERT
 *    - CRITICAL
 *   - ERROR
 *  - WARNING
 * - NOTICE
 * - INFO
 * - DEBUG
 *
 * Each log entry includes a timestamp, log level, calling method, message, and optional context.
 *
 * Example log entry:
 * [2024-06-01 12:00:00] Starmus-ERROR
 * StarmusTemplateLoader::Starmus_get_template: Template not found {"template":"queue/recording-queue-list"}
 *
 * @package Starisian\Sparxstar\Starmus\helpers
 *
 * @author Starisian Technologies
 *
 * @version 1.0.0
 *
 * @since 1.0.0
 */
class StarmusLogger
{
    /**
     * Emergency log level indicating system is unusable.
     *
     * @var int
     */
    public const EMERGENCY = 0;

    /**
     * Alert log level for immediate action required.
     *
     * @var int
     */
    public const ALERT = 1;

    /**
     * Critical log level for critical conditions.
     *
     * @var int
     */
    public const CRITICAL = 2;

    /**
     * Error log level for runtime errors that do not require immediate intervention.
     *
     * @var int
     */
    public const ERROR = 3;

    /**
     * Warning log level for exceptional occurrences that are not errors.
     *
     * @var int
     */
    public const WARNING = 4;

    /**
     * Notice log level for normal but significant events.
     *
     * @var int
     */
    public const NOTICE = 5;

    /**
     * Info log level for informational messages.
     *
     * @var int
     */
    public const INFO = 6;

    /**
     * Debug log level for detailed diagnostic information.
     *
     * @var int
     */
    public const DEBUG = 7;

    /**
     * Shared logger handler instance used to dispatch PSR-3 calls.
     */
    private static ?StarLogger $handler = null;

    /**
     * Minimum severity level that will be recorded.
     */
    private static int $min_log_level = self::INFO;

    /**
     * Set the minimum log level
     *
     * @param int $level Log level constant
     */
    public static function set_min_level(int $level): void
    {
        self::$min_log_level = $level;
        self::$handler       = null; // Reset to refresh instance with new level
    }

    /**
     * Get the internal handler instance
     *
     * @throws \Exception
     */
    private static function get_handler(): StarLogger
    {
        try {
            if ( ! self::$handler instanceof \Starisian\Sparxstar\Starmus\helpers\logger\StarLogger) {
                self::$handler = new StarLogger(self::$min_log_level);
            }
        } catch (\Exception $exception) {
            // In case of logger initialization failure, fallback to error_log
            error_log('StarmusLogger initialization failed: ' . $exception->getMessage());
            throw $exception;
        }

        return self::$handler;
    }

    /**
     * Log an error-level message.
     *
     * @param mixed $message Error message or throwable to log.
     * @param array $context Context array for interpolation and metadata.
     */
    public static function error(mixed $message, array $context = []): void
    {
        self::get_handler()->error($message, $context);
    }

    /**
     * Log an informational message.
     *
     * @param mixed $message Information to capture.
     * @param array $context Additional context data.
     */
    public static function info(mixed $message, array $context = []): void
    {
        self::get_handler()->info($message, $context);
    }

    /**
     * Log a debug-level message for detailed troubleshooting.
     *
     * @param mixed $message Diagnostic information.
     * @param array $context Structured context for the log entry.
     */
    public static function debug(mixed $message, array $context = []): void
    {
        self::get_handler()->debug($message, $context);
    }

    /**
     * Log a warning-level message for recoverable issues.
     *
     * @param mixed $message Warning description.
     * @param array $context Context array supplying metadata.
     */
    public static function warning(mixed $message, array $context = []): void
    {
        self::get_handler()->warning($message, $context);
    }

    /**
     * Log a critical-level message for serious failures.
     *
     * @param mixed $message Critical error details.
     * @param array $context Context array for the log entry.
     */
    public static function critical(mixed $message, array $context = []): void
    {
        self::get_handler()->critical($message, $context);
    }

    /**
     * Log an alert-level message requiring immediate attention.
     *
     * @param mixed $message Alert description.
     * @param array $context Additional log context.
     */
    public static function alert(mixed $message, array $context = []): void
    {
        if (is_admin()) {
            // For admin users, also output to error_log for immediate visibility
            StarmusUIHelper::renderError('Starmus ALERT: ' . (\is_string($message) ? $message : print_r($message, true)));
        }

        self::get_handler()->alert($message, $context);
    }

    /**
     * Log an emergency-level message when system is unusable.
     *
     * @param mixed $message Emergency message.
     * @param array $context Contextual metadata for the event.
     */
    public static function emergency(mixed $message, array $context = []): void
    {
        self::get_handler()->emergency($message, $context);
    }

    /**
     * Compatibility alias for log_error
     *
     * @deprecated Use StarmusLogger::error() instead
     */
    public static function log_error(mixed $message, array $context = []): void
    {
        self::error($message, $context);
    }

    /**
     * Backward-compatible log shim mapping to error level.
     *
     * @param mixed $message Log message or throwable instance.
     * @param array $context Context array for additional fields.
     */
    public static function log(mixed $message, array $context = []): void
    {
        self::error($message, $context);
    }

    /**
     * Render an error message for frontend display
     *
     * @deprecated Use StarmusUIHelper::renderError() instead
     *
     * @param string $message User-facing error text.
     *
     * @return string HTML markup for a WordPress error notice.
     */
    public static function renderError(string $message): string
    {
        self::error($message);
        return '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    }
}
