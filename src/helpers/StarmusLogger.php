<?php

namespace Starisian\Sparxstar\Starmus\helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralized logger for Starmus.
 * Version 1.0.0: Standardized. Writes strictly to wp-content/debug.log via error_log().
 */
class StarmusLogger
{
    public const DEBUG = 100;

    public const INFO = 200;

    public const NOTICE = 250;

    public const WARNING = 300;

    public const ERROR = 400;

    public const CRITICAL = 500;

    public const ALERT = 550;

    public const EMERGENCY = 600;

    /**
     * Minimum log level to record.
     */
    protected static int $min_log_level = self::INFO;

    /**
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

    protected static bool $json_mode = false;

    protected static ?string $correlation_id = null;

    /**
     * @var array<string, float>
     */
    protected static array $timers = [];

    /*==============================================================
     * CONFIGURATION
     *=============================================================*/

    public static function setMinLogLevel(string $level_name): void
    {
        $level_name = strtolower($level_name);
        if (isset(self::$levels[$level_name])) {
            self::$min_log_level = self::$levels[$level_name];
        }
    }

    /**
     * Legacy method kept for backward compatibility.
     * Does nothing as we now rely on standard WP debug.log.
     */
    public static function setLogFilePath(string $path): void
    {
        // No-op
    }

    public static function enableJsonMode(bool $enabled = true): void
    {
        self::$json_mode = $enabled;
    }

    public static function setCorrelationId(?string $id = null): void
    {
        self::$correlation_id = $id ?? wp_generate_uuid4();
    }

    /*==============================================================
     * CORE LOGGING
     *=============================================================*/

    protected static function getLevelInt(string $level_name): int
    {
        return self::$levels[strtolower($level_name)] ?? self::ERROR;
    }

    /**
     * @param array<string|int, mixed> $data
     * @return array<string|int, mixed>
     */
    protected static function sanitizeData(array $data): array
    {
        foreach ($data as $k => &$v) {
            if (is_string($v) && preg_match('/(ip|email|user|token|auth|fingerprint)/i', (string) $k)) {
                $v = '[REDACTED]';
            } elseif (is_array($v)) {
                $v = self::sanitizeData($v);
            }
        }

        return $data;
    }

    /**
     * Main logging method.
     * Writes directly to PHP error_log (standard WP debug.log).
     * 
     * @param array<string|int, mixed> $extra
     */
    public static function log(string $context, $msg, string $level = 'error', array $extra = []): void
    {
        $current_level_int = self::getLevelInt($level);

        // Check internal minimum level setting
        if ($current_level_int < self::$min_log_level) {
            return;
        }

        $level_name = strtoupper($level);
        $message_content = self::formatMessageContent($msg);

        // Prepare context data
        $extra_clean = self::sanitizeData($extra);
        $extra_str = $extra_clean === [] ? '' : ' | Data: ' . json_encode($extra_clean, JSON_UNESCAPED_SLASHES);

        $prefix = self::$correlation_id ? '[' . self::$correlation_id . '] ' : '';

        // Construct the log line
        if (self::$json_mode) {
            $log_entry = json_encode([
                'level' => $level_name,
                'context' => $context,
                'message' => $message_content,
                'extra' => $extra_clean,
                'cid' => self::$correlation_id
            ], JSON_UNESCAPED_UNICODE);
        } else {
            // Format: [STARMUS] [LEVEL] [Context] Message | Data: {...}
            // Note: error_log automatically adds the Timestamp.
            $log_entry = sprintf(
                "%s[STARMUS] [%s] [%s] %s%s",
                $prefix,
                $level_name,
                $context,
                $message_content,
                $extra_str
            );
        }

        // Send to standard WordPress debug.log
        error_log($log_entry);

        // Fire hooks for external integrations
        do_action('starmus_log_event', $level_name, $context, $msg, $extra);
    }

    protected static function formatMessageContent($msg): string
    {
        if ($msg instanceof \Throwable) {
            return sprintf(
                '%s: %s in %s:%d',
                $msg::class,
                $msg->getMessage(),
                $msg->getFile(),
                $msg->getLine()
            );
        }

        return is_array($msg) || is_object($msg) ? print_r($msg, true) : (string) $msg;
    }

    /*==============================================================
     * TIMER UTILITIES
     *=============================================================*/

    public static function timeStart(string $label): void
    {
        self::$timers[$label] = microtime(true);
    }

    public static function timeEnd(string $label, string $context = 'Timer'): void
    {
        if (!isset(self::$timers[$label])) {
            return;
        }

        $duration = round((microtime(true) - self::$timers[$label]) * 1000, 2);
        unset(self::$timers[$label]);
        // Log timer results as debug
        self::debug($context, sprintf('%s completed in %sms', $label, $duration));
    }

    /*==============================================================
     * CONVENIENCE WRAPPERS
     *=============================================================*/
    /**
     * @param array<string|int, mixed> $extra
     */
    public static function debug(string $context, $msg, array $extra = []): void
    {
        self::log($context, $msg, 'debug', $extra);
    }

    public static function info(string $context, $msg, array $extra = []): void
    {
        self::log($context, $msg, 'info', $extra);
    }

    public static function notice(string $context, $msg, array $extra = []): void
    {
        self::log($context, $msg, 'notice', $extra);
    }

    public static function warning(string $context, $msg, array $extra = []): void
    {
        self::log($context, $msg, 'warning', $extra);
    }

    public static function warn(string $context, $msg, array $extra = []): void
    {
        self::log($context, $msg, 'warning', $extra);
    }

    public static function error(string $context, $msg, array $extra = []): void
    {
        self::log($context, $msg, 'error', $extra);
    }

    public static function critical(string $context, $msg, array $extra = []): void
    {
        self::log($context, $msg, 'critical', $extra);
    }

    public static function alert(string $context, $msg, array $extra = []): void
    {
        self::log($context, $msg, 'alert', $extra);
    }

    public static function emergency(string $context, $msg, array $extra = []): void
    {
        self::log($context, $msg, 'emergency', $extra);
    }

    /*==============================================================
     * BOOTSTRAP
     *=============================================================*/
    public static function boot(): void
    {
        // No special boot logic needed for standard error_log
    }
}
