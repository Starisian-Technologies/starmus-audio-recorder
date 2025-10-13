<?php
namespace Starisian\Starmus\helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralized error and debug logger for Starmus.
 * This improved version writes to a dedicated log file, supports multiple log levels,
 * and provides better formatting for messages and exceptions.
 */
class StarmusLogger
{
    // Define log levels with integer values for easy comparison
    public const DEBUG = 100;
    public const INFO = 200;
    public const NOTICE = 250;
    public const WARNING = 300;
    public const ERROR = 400;
    public const CRITICAL = 500;
    public const ALERT = 550;
    public const EMERGENCY = 600;

    /**
     * @var string|null The absolute path to the log file.
     */
    protected static ?string $log_file_path = null;

    /**
     * @var int The minimum log level to record messages. Messages with a lower severity will be ignored.
     */
    protected static int $min_log_level = self::INFO; // Default minimum level

    /**
     * Maps string level names to integer values for internal use.
     * @var array<string, int>
     */
    protected static array $levels = [
        'debug'     => self::DEBUG,
        'info'      => self::INFO,
        'notice'    => self::NOTICE,
        'warning'   => self::WARNING,
        'error'     => self::ERROR,
        'critical'  => self::CRITICAL,
        'alert'     => self::ALERT,
        'emergency' => self::EMERGENCY,
    ];

    /**
     * Sets the minimum log level for messages to be written.
     * Messages below this level will be ignored.
     *
     * @param string $level_name The string name of the minimum log level (e.g., 'info', 'debug', 'error').
     * @return void
     */
    public static function setMinLogLevel(string $level_name): void
    {
        $level_name = strtolower($level_name);
        if (isset(self::$levels[$level_name])) {
            self::$min_log_level = self::$levels[$level_name];
        } else {
            // Optionally log an error if an invalid level is provided, but avoid recursion.
            // For now, silently ignore invalid levels.
        }
    }

    /**
     * Gets the current minimum log level.
     *
     * @return int
     */
    public static function getMinLogLevel(): int
    {
        return self::$min_log_level;
    }

    /**
     * Sets a custom log file path. If not set, a default path in wp-content/uploads/starmus-logs/ will be used.
     *
     * @param string $path Absolute path to the log file.
     * @return void
     */
    public static function setLogFilePath(string $path): void
    {
        self::$log_file_path = $path;
    }

    /**
     * Determines and returns the absolute path to the log file.
     * Creates the directory if it doesn't exist and attempts to secure it.
     *
     * @return string|null The absolute path to the log file, or null if it cannot be determined/created.
     */
    protected static function getLogFilePath(): ?string
    {
        if (self::$log_file_path === null) {
            if (!function_exists('wp_upload_dir')) {
                // If WordPress functions are not available, we cannot determine the default path.
                return null;
            }

            $upload_dir_info = wp_upload_dir();
            if (false === $upload_dir_info['basedir']) {
                // If uploads directory cannot be determined.
                return null;
            }

            $log_dir = $upload_dir_info['basedir'] . '/starmus-logs';
            if (!is_dir($log_dir)) {
                // Attempt to create the directory using WordPress's function.
                if (!wp_mkdir_p($log_dir)) {
                    // If directory creation fails, fall back to native error_log.
                    error_log('StarmusLogger: Failed to create log directory: ' . $log_dir);
                    return null;
                }
                // Secure the directory from direct web access.
                file_put_contents($log_dir . '/.htaccess', 'Deny from all');
                file_put_contents($log_dir . '/index.html', '');
            }

            // Use a daily log file for easier management and rotation.
            self::$log_file_path = $log_dir . '/starmus-' . date('Y-m-d') . '.log';
        }
        return self::$log_file_path;
    }

    /**
     * Converts a string log level name to its integer value.
     *
     * @param string $level_name
     * @return int The integer representation of the level, or ERROR if unknown.
     */
    protected static function getLevelInt(string $level_name): int
    {
        return self::$levels[strtolower($level_name)] ?? self::ERROR;
    }

    /**
     * Converts an integer log level to its string name.
     *
     * @param int $level_int
     * @return string The string name of the level, or 'UNKNOWN' if unknown.
     */
    protected static function getLevelName(int $level_int): string
    {
        foreach (self::$levels as $name => $value) {
            if ($value === $level_int) {
                return strtoupper($name);
            }
        }
        return 'UNKNOWN';
    }

    /**
     * Main log method.
     *
     * @param string          $context Short context label (e.g. "SubmissionHandler").
     * @param string|\Throwable $msg   Message or exception.
     * @param string          $level   Log level: debug|info|notice|warning|error|critical|alert|emergency.
     * @return void
     */
    public static function log(string $context, $msg, string $level = 'error'): void
    {
        // First, check the WordPress debug log setting.
        // If WP_DEBUG_LOG is false, we generally don't log,
        // but we'll allow critical errors through regardless for safety.
        $current_level_int = self::getLevelInt($level);

        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
            // Only bypass WP_DEBUG_LOG if the current level is ERROR or higher.
            if ($current_level_int < self::ERROR) {
                return;
            }
        }
        
        // Then, check against our internal minimum log level.
        if ($current_level_int < self::$min_log_level) {
            return;
        }

        $log_file = self::getLogFilePath();
        if ($log_file === null) {
            // Fallback to PHP's native error_log if our file logging setup fails.
            error_log(sprintf(
                'StarmusLogger (Fallback): [%s] %s: %s',
                strtoupper($level),
                $context,
                self::formatMessageContent($msg) // Use content formatter
            ));
            return;
        }

        $timestamp = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
        $level_name = self::getLevelName($current_level_int);
        $message_content = self::formatMessageContent($msg);
        $trace_content = '';

        if ($msg instanceof \Throwable) {
            $trace_content = "\nStack trace:\n" . $msg->getTraceAsString();
        }

        $log_entry = sprintf(
            "[%s] %s: [%s] %s%s\n",
            $timestamp,
            $level_name,
            $context,
            $message_content,
            $trace_content
        );

        // Get correct file permissions from WP_FS_CHMOD_FILE constant if defined, otherwise default.
        $file_perms = defined('WP_FS_CHMOD_FILE') ? WP_FS_CHMOD_FILE : 0644;

        // Write to log file. Use FILE_APPEND and LOCK_EX for atomic writes.
        // We suppress errors from file_put_contents because if it fails, we've already tried to log.
        @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Helper method to format the message content, especially for Throwables.
     *
     * @param string|\Throwable $msg
     * @return string
     */
    protected static function formatMessageContent($msg): string
    {
        if ($msg instanceof \Throwable) {
            return sprintf(
                '%s: %s in %s:%d',
                get_class($msg), // Include exception class name
                $msg->getMessage(),
                $msg->getFile(),
                $msg->getLine()
            );
        }
        // Do not use sanitize_text_field here; it's for HTML output, not raw log data.
        return (string) $msg;
    }

    /**
     * Convenience method for DEBUG level logging.
     *
     * @param string          $context Short context label.
     * @param string|\Throwable $msg   Message or exception.
     * @return void
     */
    public static function debug(string $context, $msg): void
    {
        self::log($context, $msg, 'debug');
    }

    /**
     * Convenience method for INFO level logging.
     *
     * @param string          $context Short context label.
     * @param string|\Throwable $msg   Message or exception.
     * @return void
     */
    public static function info(string $context, $msg): void
    {
        self::log($context, $msg, 'info');
    }

    /**
     * Convenience method for NOTICE level logging.
     *
     * @param string          $context Short context label.
     * @param string|\Throwable $msg   Message or exception.
     * @return void
     */
    public static function notice(string $context, $msg): void
    {
        self::log($context, $msg, 'notice');
    }

    /**
     * Convenience method for WARNING level logging.
     *
     * @param string          $context Short context label.
     * @param string|\Throwable $msg   Message or exception.
     * @return void
     */
    public static function warning(string $context, $msg): void
    {
        self::log($context, $msg, 'warning');
    }

    /**
     * Convenience method for ERROR level logging.
     *
     * @param string          $context Short context label.
     * @param string|\Throwable $msg   Message or exception.
     * @return void
     */
    public static function error(string $context, $msg): void
    {
        self::log($context, $msg, 'error');
    }

    /**
     * Convenience method for CRITICAL level logging.
     *
     * @param string          $context Short context label.
     * @param string|\Throwable $msg   Message or exception.
     * @return void
     */
    public static function critical(string $context, $msg): void
    {
        self::log($context, $msg, 'critical');
    }

    /**
     * Convenience method for ALERT level logging.
     *
     * @param string          $context Short context label.
     * @param string|\Throwable $msg   Message or exception.
     * @return void
     */
    public static function alert(string $context, $msg): void
    {
        self::log($context, $msg, 'alert');
    }

    /**
     * Convenience method for EMERGENCY level logging.
     *
     * @param string          $context Short context label.
     * @param string|\Throwable $msg   Message or exception.
     * @return void
     */
    public static function emergency(string $context, $msg): void
    {
        self::log($context, $msg, 'emergency');
    }
}