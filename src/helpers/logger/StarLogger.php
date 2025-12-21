<?php

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\helpers\logger;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Log\AbstractLogger;
use Throwable;
use Exception;
use WP_Error;
use RuntimeException;

/**
 * Internal handler for AiWA Logging.
 * Extends PSR-3 AbstractLogger to provide standard logging capabilities.
 */
class StarLogger extends AbstractLogger
{
    /**
     * Summary of min_level
     * @var int
     */
    private int $min_level;

    /**
     * Map PSR-3 string levels to integer priorities for filtering.
     * Lower number = higher priority
     * @var array<string, int>
     */
    private const LEVEL_PRIORITY = [
        'emergency' => 0,
        'alert'     => 1,
        'critical'  => 2,
        'error'     => 3,
        'warning'   => 4,
        'notice'    => 5,
        'info'      => 6,
        'debug'     => 7,
    ];

    /**
     * @param int $min_level The minimum priority level to log.
     */
    public function __construct(int $min_level = 6)
    {
        $this->min_level = $min_level;
    }

    /**
     * The core PSR-3 log method. 
     * Handles the conversion of exceptions, objects, and strings.
     * 
     * @param mixed  $level   Log level (string or int)
     * @param mixed  $message Message or Object (like an Exception)
     * @param array  $context Additional data
     */
    public function log($level, $message, array $context = []): void
    {
        // 1. Determine priority and skip if below min_level
        $level_str = (string) $level;
        $priority  = self::LEVEL_PRIORITY[strtolower($level_str)] ?? 6;

        if ($priority > $this->min_level) {
            return;
        }

        // 2. Parse the message (Handle strings, Throwables, and Objects)
        $processed_message = $this->process_message($message);

        // 3. Detect the caller
        $caller = $this->get_caller();
        $context_str = empty($context) ? '' : ' ' . wp_json_encode($context);

        // 4. Format and send to error_log
        $formatted = sprintf(
            'AiWA-%s [%s]: %s%s',
            strtoupper($level_str),
            $caller,
            $processed_message,
            $context_str
        );

        error_log($formatted);
    }

    /**
     * Logic for parsing different message types.
     * 
     * @param mixed $message
     * @return string Processed message string
     */
    private function process_message(mixed $message): string
    {
        if (is_string($message)) {
            return $message;
        }

        if ($message instanceof Throwable) {
            return sprintf(
                'EXCEPTION [%s]: %s in %s:%d',
                get_class($message),
                $message->getMessage(),
                $message->getFile(),
                $message->getLine()
            );
        }

        if ($message instanceof Exception) {
            return sprintf(
                'EXCEPTION [%s]: %s in %s:%d',
                get_class($message),
                $message->getMessage(),
                $message->getFile(),
                $message->getLine()
            );
        }

        if ($message instanceof WP_Error) {
            $errors = [];
            foreach ($message->get_error_codes() as $code) {
                $errors[] = sprintf(
                    'WP_Error [%s]: %s',
                    $code,
                    implode('; ', $message->get_error_messages($code))
                );
            }
            return implode(' | ', $errors);
        }

        if ($message instanceof RuntimeException) {
            return sprintf(
                'RUNTIME EXCEPTION [%s]: %s in %s:%d',
                get_class($message),
                $message->getMessage(),
                $message->getFile(),
                $message->getLine()
            );
        }

        if (is_array($message) || is_object($message)) {
            return (string) wp_json_encode($message);
        }

        return (string) $message;
    }

    /**
     * Skip frames to find the class/method that triggered the log.
     * 
     * @return string Caller in "Class::method" format
     */
    private function get_caller(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
        foreach ($trace as $frame) {
            if (isset($frame['class']) && !str_contains($frame['class'], 'Logger')) {
                $class  = basename(str_replace('\\', '/', $frame['class']));
                $method = $frame['function'] ?? 'unknown';
                return "{$class}::{$method}";
            }
        }
        return 'unknown';
    }
}