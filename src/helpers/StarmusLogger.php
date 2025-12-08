<?php

namespace Starisian\Sparxstar\Starmus\helpers;

if (!\defined('ABSPATH')) {
    exit;
}

/**
 * Centralized logger for Starmus, configured to write strictly to wp-content/debug.log
 * using forced file writing mode (error_log mode 3). This bypasses the need for
 * WP_DEBUG_LOG being set to true, making the logger reliable.
 */
final class StarmusLogger
{
    // Log Levels - Kept the reduced set from your second example
    public const DEBUG   = 100;
    public const INFO    = 200;
    public const NOTICE  = 250;
    public const WARNING = 300;
    public const ERROR   = 400;

    /**
     * Minimum log level to record.
     */
    protected static int $min_log_level = self::DEBUG;

    /**
     * Single source of truth for the log file path.
     * Forces the path to wp-content/debug.log using standard WP constants.
     */
    protected static function get_log_path(): string
    {
        // 1. Use the standard WordPress constant for the absolute path to wp-content
        if (\defined('WP_CONTENT_DIR')) {
            return WP_CONTENT_DIR . '/debug.log';
        }

        // 2. Fallback: If WP_CONTENT_DIR isn't defined, use the standard path relative to ABSPATH
        if (\defined('ABSPATH')) {
            return ABSPATH . 'wp-content/debug.log';
        }

        // 3. Final fallback (should not be reached in a WP context)
        return \sys_get_temp_dir() . '/starmus_fallback.log';
    }

    /*==============================================================
     * CONFIGURATION
     *=============================================================*/

    public static function set_min_log_level(string $level): void
    {
        $map = [
            'debug'   => self::DEBUG,
            'info'    => self::INFO,
            'notice'  => self::NOTICE,
            'warning' => self::WARNING,
            'error'   => self::ERROR,
        ];

        $level = \strtolower($level);
        if (isset($map[$level])) {
            self::$min_log_level = $map[$level];
        }
    }

    /*==============================================================
     * CONVENIENCE WRAPPERS
     *=============================================================*/

    /**
     * @param string $context The source of the log message (e.g., 'Setup', 'AJAX', 'API').
     * @param string $message The human-readable message.
     * @param array<string|int, mixed> $data Optional associative array of extra data to log.
     */
    public static function debug(string $context, string $message, array $data = []): void
    {
        self::log($context, $message, $data, self::DEBUG);
    }

    /**
     * @param string $context The source of the log message.
     * @param string $message The human-readable message.
     * @param array<string|int, mixed> $data Optional associative array of extra data to log.
     */
    public static function info(string $context, string $message, array $data = []): void
    {
        self::log($context, $message, $data, self::INFO);
    }

    /**
     * @param string $context The source of the log message.
     * @param string $message The human-readable message.
     * @param array<string|int, mixed> $data Optional associative array of extra data to log.
     */
    public static function notice(string $context, string $message, array $data = []): void
    {
        self::log($context, $message, $data, self::NOTICE);
    }

    /**
     * @param string $context The source of the log message.
     * @param string $message The human-readable message.
     * @param array<string|int, mixed> $data Optional associative array of extra data to log.
     */
    public static function warning(string $context, string $message, array $data = []): void
    {
        self::log($context, $message, $data, self::WARNING);
    }

    /**
     * @param string $context The source of the log message.
     * @param string $message The human-readable message.
     * @param array<string|int, mixed> $data Optional associative array of extra data to log.
     */
    public static function error(string $context, string $message, array $data = []): void
    {
        self::log($context, $message, $data, self::ERROR);
    }

    /*==============================================================
     * CORE LOGGING
     *=============================================================*/

    /**
     * Main logging method.
     *
     * @param int $level The log level constant.
     * @param string $context The source of the log message.
     * @param string $message The human-readable message.
     * @param array<string|int, mixed> $data Optional associative array of extra data.
     */
    public static function log(string $context, string $message, array $data = [], string $level = 'DEBUG'): void
    {
        // Check internal minimum level setting
        if ($level < self::$min_log_level) {
            return;
        }

        $timestamp = \gmdate('Y-m-d H:i:s');
        $level_str = match ($level) {
            self::DEBUG   => 'DEBUG',
            self::INFO    => 'INFO',
            self::NOTICE  => 'NOTICE',
            self::WARNING => 'WARNING',
            self::ERROR   => 'ERROR',
            default       => 'UNKNOWN',
        };

        // Encode the optional data for appending to the line.
        // Using wp_json_encode ensures a safe JSON output for logs.
        $data_str = $data ? ' ' . \wp_json_encode($data) : '';

        // Format: [YYYY-MM-DD HH:MM:SS] [LEVEL] [Context] Message {data}
        $line = \sprintf(
            "[%s] [%s] [%s] %s%s%s",
            $timestamp,
            $level_str,
            $context,
            $message,
            $data_str,
            PHP_EOL // Ensure a newline for file appending
        );

        $log_file = self::get_log_path();

        // The core fix: error_log mode 3 forces writing/appending to the specified file path.
        // @ suppresses any warnings if the file path is unwritable (due to permissions).
        @\error_log($line, 3, $log_file);
    }
}
