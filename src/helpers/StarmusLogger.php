<?php

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\helpers;

if (! defined('ABSPATH')) {
    exit();
}

use Starisian\Sparxstar\Starmus\helpers\logger\StarLogger;

/**
 * PSR-3 compliant logging system for AiWA Orchestrator
 * Implements log levels, message formatting, and context handling
 *
 * Usage:
 * AiWASWMLogger::info('This is an info message');
 * AiWASWMLogger::error('An error occurred', ['error_code' =>
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
 * [2024-06-01 12:00:00] AiWA-ERROR
 * AiWATemplateLoader::aiwa_get_template: Template not found {"template":"queue/recording-queue-list"}
 *
 * @package AiWA\Orchestrator\helpers
 *
 * @author Ai West Africa / Starisian Technologies
 */
class StarmusLogger
{
    /** Log level constants */
    public const EMERGENCY = 0;
    public const ALERT     = 1;
    public const CRITICAL  = 2;
    public const ERROR     = 3;
    public const WARNING   = 4;
    public const NOTICE    = 5;
    public const INFO      = 6;
    public const DEBUG     = 7;

    /** @var StarLogger|null */
    private static ?StarLogger $handler = null;
    private static int $min_log_level = self::INFO;

    /**
     * Set the minimum log level
     *
     * @param int $level Log level constant
     * @return void
     */
    public static function set_min_level(int $level): void
    {
        self::$min_log_level = $level;
        self::$handler = null; // Reset to refresh instance with new level
    }

    /**
     * Get the internal handler instance
     *
     * @return StarLogger
     * @throws \Exception
     *
     */
    private static function get_handler(): StarLogger
    {
        if (self::$handler === null) {
            self::$handler = new StarLogger(self::$min_log_level);
        }
        return self::$handler;
    }

    /**
     * Log an error-level message to the centralized handler.
     *
     * @param mixed                $message Log message or throwable instance.
     * @param array<string, mixed> $context Optional structured context data.
     *
     * @return void
     */
    public static function error(mixed $message, array $context = []): void
    {
        self::get_handler()->error($message, $context);
    }

    /**
     * Log an informational message to the centralized handler.
     *
     * @param mixed                $message Log message or throwable instance.
     * @param array<string, mixed> $context Optional structured context data.
     *
     * @return void
     */
    public static function info(mixed $message, array $context = []): void
    {
        self::get_handler()->info($message, $context);
    }

    /**
     * Log a debug-level message for verbose troubleshooting.
     *
     * @param mixed                $message Log message or throwable instance.
     * @param array<string, mixed> $context Optional structured context data.
     *
     * @return void
     */
    public static function debug(mixed $message, array $context = []): void
    {
        self::get_handler()->debug($message, $context);
    }

    /**
     * Log a warning-level message indicating recoverable issues.
     *
     * @param mixed                $message Log message or throwable instance.
     * @param array<string, mixed> $context Optional structured context data.
     *
     * @return void
     */
    public static function warning(mixed $message, array $context = []): void
    {
        self::get_handler()->warning($message, $context);
    }

    /**
     * Log a critical-level message for severe conditions.
     *
     * @param mixed                $message Log message or throwable instance.
     * @param array<string, mixed> $context Optional structured context data.
     *
     * @return void
     */
    public static function critical(mixed $message, array $context = []): void
    {
        self::get_handler()->critical($message, $context);
    }

    /**
     * Log an alert-level message requiring immediate action.
     *
     * @param mixed                $message Log message or throwable instance.
     * @param array<string, mixed> $context Optional structured context data.
     *
     * @return void
     */
    public static function alert(mixed $message, array $context = []): void
    {
        self::get_handler()->alert($message, $context);
    }

    /**
     * Log an emergency-level message for system unavailability.
     *
     * @param mixed                $message Log message or throwable instance.
     * @param array<string, mixed> $context Optional structured context data.
     *
     * @return void
     */
    public static function emergency(mixed $message, array $context = []): void
    {
        self::get_handler()->emergency($message, $context);
    }

    /**
     * Compatibility alias for log_error
     *
     * @deprecated Use AiWASWMLogger::error() instead
     * @param mixed $message
     * @param array $context
     *
     * @return void
     */
    public static function log_error(mixed $message, array $context = []): void
    {
        self::error($message, $context);
    }

    /**
     * Backwards-compatible logging shim for legacy callers.
     *
     * @param mixed                $message Log message or throwable instance.
     * @param array<string, mixed> $context Optional structured context data.
     *
     * @return void
     */
    public static function log(mixed $message, array $context = []): void
    {
        self::error($message, $context);
    }

    /**
     * Render an error message for frontend display and log it.
     *
     * @deprecated Use AiWAUIHelper::renderError() instead.
     *
     * @param string $message Message to render and log.
     *
     * @return string Safe HTML notice markup.
     */
    public static function renderError(string $message): string
    {
        self::error($message);
        return '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    }
}
