<?php
namespace Starmus\helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralized error and debug logger for Starmus.
 */
class StarmusLogger
{

    /**
     * Log a message with context.
     *
     * @param string          $context Short context label (e.g. "SubmissionHandler").
     * @param string|\Throwable $msg   Message or exception.
     * @param string          $level   Log level: error|warning|info|debug.
     * @return void
     */
    public static function log(string $context, $msg, string $level = 'error'): void
    {
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
            return; // Respect WP settings
        }

        $message = '';

        if ($msg instanceof \Throwable) {
            $message = sprintf(
                '%s: [%s] %s in %s:%d',
                strtoupper($level),
                $context,
                $msg->getMessage(),
                $msg->getFile(),
                $msg->getLine()
            );
        } else {
            $message = sprintf(
                '%s: [%s] %s',
                strtoupper($level),
                $context,
                sanitize_text_field((string) $msg)
            );
        }

        error_log('Starmus ' . $message);
    }
}
