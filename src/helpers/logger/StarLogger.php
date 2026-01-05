<?php

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\helpers\logger;

/**
 * @file StarLogger.php
 *
 * @package Starisian\Sparxstar\Starmus\helpers\logger
 *
 * @author Starisian Technologies <support@starisian.com>
 * @license Starisian Technolgoies Proprietary License
 *
 * @version 1.0.0
 *
 * @since
 */

use function basename;
use function debug_backtrace;
use function error_log;

use Exception;

use function implode;
use function is_admin;

use Psr\Log\AbstractLogger;
use RuntimeException;

use function str_contains;
use function str_replace;
use function strtolower;
use function strtoupper;

use Throwable;
use WP_Error;

use function wp_json_encode;

/**
 * Internal handler for Starmus Logging.
 * Extends PSR-3 AbstractLogger to provide standard logging capabilities.
 *
 * @package Starisian\Sparxstar\Starmus\helpers\logger
 *
 * @author Starisian Technologies <support@starisian.com>
 * @license Starisian Technolgoies Proprietary License
 *
 * @version 1.0.0
 *
 * @since 1.0.0
 */
class StarLogger extends AbstractLogger
{
    /**
     * Summary of min_level
     */
    private int $min_level;

    /**
     * Map PSR-3 string levels to integer priorities for filtering.
     * Lower number = higher priority
     *
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
     * @param mixed $level Log level (string or int)
     * @param mixed $message Message or Object (like an Exception)
     * @param array $context Additional data
     */
    public function log($level, $message, array $context = []): void
    {
        try {
            // 1. Determine priority and skip if below min_level
            $level_str = (string) $level;
            $priority  = self::LEVEL_PRIORITY[strtolower($level_str)] ?? 6;

            if ($priority > $this->min_level) {
                return;
            }

            // 2. Parse the message (Handle strings, Throwables, and Objects)
            $processed_message = $this->process_message($message);

            // 3. Detect the caller
            $caller      = $this->get_caller();
            $context_str = $context === [] ? '' : ' ' . wp_json_encode($context);

            // 4. Format and send to error_log
            $formatted = \sprintf(
            'Starmus-%s [%s]: %s%s',
            strtoupper($level_str),
            $caller,
            $processed_message,
            $context_str
            );
        } catch (\Exception $exception) {
            // In case of logging failure, fallback to error_log
            error_log('StarLogger log() failed: ' . $exception->getMessage());

            return;
        }

        error_log($formatted);
    }

    /**
     * Logic for parsing different message types.
     *
     * @return string Processed message string
     */
    private function process_message(mixed $message): string
    {
        try {
            if (\is_string($message)) {
                return $message;
            }

            if ($message instanceof Throwable) {
                return \sprintf(
                'EXCEPTION [%s]: %s in %s:%d',
                $message::class,
                $message->getMessage(),
                $message->getFile(),
                $message->getLine()
                );
            }

            if ($message instanceof Exception) {
                return \sprintf(
                'EXCEPTION [%s]: %s in %s:%d',
                $message::class,
                $message->getMessage(),
                $message->getFile(),
                $message->getLine()
                );
            }

            if ($message instanceof WP_Error) {
                $errors = [];
                foreach ($message->get_error_codes() as $code) {
                    $errors[] = \sprintf(
                    'WP_Error [%s]: %s',
                    $code,
                    implode('; ', $message->get_error_messages($code))
                    );
                }

                return implode(' | ', $errors);
            }

            if ($message instanceof RuntimeException) {
                return \sprintf(
                'RUNTIME EXCEPTION [%s]: %s in %s:%d',
                $message::class,
                $message->getMessage(),
                $message->getFile(),
                $message->getLine()
                );
            }

            if (\is_array($message) || \is_object($message)) {
                return (string) wp_json_encode($message);
            }
        } catch (\Exception $exception) {
            // In case of message processing failure, fallback to error_log
            error_log('StarLogger process_message() failed: ' . $exception->getMessage());

            return 'Logging Error: ' . $exception->getMessage();
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
        try {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
            foreach ($trace as $frame) {
                if (isset($frame['class']) && ! str_contains($frame['class'], 'Logger')) {
                    $class  = basename(str_replace('\\', '/', $frame['class']));
                    $method = $frame['function'] ?? 'unknown';
                    return \sprintf('%s::%s', $class, $method);
                }
            }
        } catch (\Exception $exception) {
            // In case of caller detection failure, fallback to error_log
            error_log('StarLogger get_caller() failed: ' . $exception->getMessage());
        }

        return 'unknown';
    }
}
