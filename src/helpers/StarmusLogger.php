<?php
namespace Starisian\Sparxstar\Starmus\helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralized, extensible error and debug logger for Starmus.
 * Retains full backward compatibility while adding:
 *  - JSON mode for structured logs
 *  - Correlation ID support
 *  - Execution timers
 *  - PII masking for safe logs
 *  - Alert hooks for external integrations
 *  - Log rotation & maintenance helpers
 *
 * @version 0.8.4
 */
class StarmusLogger
{
    // --- Existing log level constants ---
    public const DEBUG = 100;
    public const INFO = 200;
    public const NOTICE = 250;
    public const WARNING = 300;
    public const ERROR = 400;
    public const CRITICAL = 500;
    public const ALERT = 550;
    public const EMERGENCY = 600;

    protected static ?string $log_file_path = null;
    protected static int $min_log_level = self::INFO;

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

    // --- New features ---
    protected static bool $json_mode = false;
    protected static ?string $correlation_id = null;
    protected static array $timers = [];

    /*==============================================================
     * CONFIGURATION
     *=============================================================*/

    public static function setMinLogLevel(string $level_name): void {
        $level_name = strtolower($level_name);
        if (isset(self::$levels[$level_name])) {
            self::$min_log_level = self::$levels[$level_name];
        }
    }

    public static function getMinLogLevel(): int {
        return self::$min_log_level;
    }

    public static function setLogFilePath(string $path): void {
        self::$log_file_path = $path;
    }

    public static function enableJsonMode(bool $enabled = true): void {
        self::$json_mode = $enabled;
    }

    public static function setCorrelationId(?string $id = null): void {
        self::$correlation_id = $id ?? wp_generate_uuid4();
    }

    public static function getCorrelationId(): ?string {
        return self::$correlation_id;
    }

    /*==============================================================
     * FILE HANDLING
     *=============================================================*/

    protected static function getLogFilePath(): ?string {
        if (self::$log_file_path === null) {
            if (!function_exists('wp_upload_dir')) {
                return null;
            }

            $upload_dir_info = wp_upload_dir();
            if (false === $upload_dir_info['basedir']) {
                return null;
            }

            $log_dir = $upload_dir_info['basedir'] . '/starmus-logs';
            if (!is_dir($log_dir)) {
                if (!wp_mkdir_p($log_dir)) {
                    error_log('StarmusLogger: Failed to create log directory: ' . $log_dir);
                    return null;
                }
                file_put_contents($log_dir . '/.htaccess', 'Deny from all');
                file_put_contents($log_dir . '/index.html', '');
            }

            self::$log_file_path = $log_dir . '/starmus-' . date('Y-m-d') . '.log';
        }
        return self::$log_file_path;
    }

    public static function getCurrentLogFile(): ?string {
        return self::getLogFilePath();
    }

    public static function clearOldLogs(int $days = 30): int {
        $upload_dir_info = wp_upload_dir();
        $log_dir = $upload_dir_info['basedir'] . '/starmus-logs';
        if (!is_dir($log_dir)) return 0;

        $deleted = 0;
        foreach (glob($log_dir . '/starmus-*.log') as $file) {
            if (filemtime($file) < strtotime("-{$days} days")) {
                @unlink($file);
                $deleted++;
            }
        }
        return $deleted;
    }

    /*==============================================================
     * CORE LOGGING
     *=============================================================*/

    protected static function getLevelInt(string $level_name): int {
        return self::$levels[strtolower($level_name)] ?? self::ERROR;
    }

    protected static function getLevelName(int $level_int): string {
        foreach (self::$levels as $name => $value) {
            if ($value === $level_int) {
                return strtoupper($name);
            }
        }
        return 'UNKNOWN';
    }

    protected static function sanitizeData(array $data): array {
        foreach ($data as $k => &$v) {
            if (is_string($v) && preg_match('/(ip|email|user|token|auth|fingerprint)/i', $k)) {
                $v = '[REDACTED]';
            } elseif (is_array($v)) {
                $v = self::sanitizeData($v);
            }
        }
        return $data;
    }

    public static function log(string $context, $msg, string $level = 'error', array $extra = []): void {
        $current_level_int = self::getLevelInt($level);
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
            if ($current_level_int < self::ERROR) {
                return;
            }
        }
        if ($current_level_int < self::$min_log_level) {
            return;
        }

        $log_file = self::getLogFilePath();
        $timestamp = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
        $level_name = self::getLevelName($current_level_int);
        $message_content = self::formatMessageContent($msg);
        $trace_content = $msg instanceof \Throwable ? $msg->getTraceAsString() : '';

        $entry_data = [
            'timestamp' => $timestamp,
            'level' => $level_name,
            'context' => $context,
            'message' => $message_content,
            'trace' => $trace_content,
            'correlation_id' => self::$correlation_id,
            'extra' => self::sanitizeData($extra),
        ];

        // --- JSON mode ---
        if (self::$json_mode) {
            $log_entry = json_encode($entry_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            $prefix = self::$correlation_id ? '[' . self::$correlation_id . '] ' : '';
            $log_entry = sprintf(
                "%s[%s] %s: [%s] %s%s\n",
                $prefix,
                $timestamp,
                $level_name,
                $context,
                $message_content,
                $trace_content ? "\nStack trace:\n" . $trace_content : ''
            );
        }

        if ($log_file) {
            $file_perms = defined('WP_FS_CHMOD_FILE') ? WP_FS_CHMOD_FILE : 0644;
            @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        } else {
            error_log('StarmusLogger (Fallback): ' . $log_entry);
        }

        // --- Hook for external observers or alerts ---
        do_action('starmus_log_event', $level_name, $context, $msg, $extra);
        if (in_array($level_name, ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)) {
            do_action('starmus_logger_alert', $level_name, $context, $msg, $extra);
        }
    }

    protected static function formatMessageContent($msg): string {
        if ($msg instanceof \Throwable) {
            return sprintf(
                '%s: %s in %s:%d',
                get_class($msg),
                $msg->getMessage(),
                $msg->getFile(),
                $msg->getLine()
            );
        }
        return (string) $msg;
    }

    /*==============================================================
     * TIMER UTILITIES
     *=============================================================*/

    public static function timeStart(string $label): void {
        self::$timers[$label] = microtime(true);
    }

    public static function timeEnd(string $label, string $context = 'Timer'): void {
        if (!isset(self::$timers[$label])) return;
        $duration = round((microtime(true) - self::$timers[$label]) * 1000, 2);
        unset(self::$timers[$label]);
        self::debug($context, "$label completed in {$duration}ms");
    }

    /*==============================================================
     * CONVENIENCE WRAPPERS (unchanged signatures)
     *=============================================================*/

    public static function debug(string $context, $msg, array $extra = []): void { self::log($context, $msg, 'debug', $extra); }
    public static function info(string $context, $msg, array $extra = []): void { self::log($context, $msg, 'info', $extra); }
    public static function notice(string $context, $msg, array $extra = []): void { self::log($context, $msg, 'notice', $extra); }
    public static function warning(string $context, $msg, array $extra = []): void { self::log($context, $msg, 'warning', $extra); }
    public static function error(string $context, $msg, array $extra = []): void { self::log($context, $msg, 'error', $extra); }
    public static function critical(string $context, $msg, array $extra = []): void { self::log($context, $msg, 'critical', $extra); }
    public static function alert(string $context, $msg, array $extra = []): void { self::log($context, $msg, 'alert', $extra); }
    public static function emergency(string $context, $msg, array $extra = []): void { self::log($context, $msg, 'emergency', $extra); }


    // Add this to your wp-config.php or your theme's functions.php,
// or a custom plugin that you know is active early.
// THIS IS FOR DIAGNOSTIC PURPOSES ONLY. Remove after finding the error.

public function starmus_catch_callback_errors() {
    $error = error_get_last();
    if ( $error && $error['type'] === E_ERROR ) { // E_ERROR includes E_USER_ERROR
        // Check if the error message matches the one we're looking for
        if ( strpos( $error['message'], 'call_user_func_array(): Argument #1 ($callback) must be a valid callback' ) !== false ) {
            $log_message = sprintf(
                "Callback Error Caught: Type: %d, Message: %s in %s on line %d\n",
                $error['type'],
                $error['message'],
                $error['file'],
                $error['line']
            );
            error_log( $log_message );

            // You can also try to get more context from the hook system if possible,
            // but this is advanced and might require modifying WP core or using
            // very specific hooks that run *before* the error.
            // The call stack provided by WordPress is usually sufficient.
        }
    }
}

public function register_hooks(): void{
    register_shutdown_function( 'starmus_catch_callback_errors' );

}
}
