# StarmusLogger

**Namespace:** `Starisian\Sparxstar\Starmus\helpers`

**File:** `/workspaces/starmus-audio-recorder/src/helpers/StarmusLogger.php`

## Description

Centralized, extensible error and debug logger for Starmus.
Retains full backward compatibility while adding:
 - JSON mode for structured logs
 - Correlation ID support
 - Execution timers
 - PII masking for safe logs
 - Alert hooks for external integrations
 - Log rotation & maintenance helpers
@version 0.8.5

## Methods

### `setMinLogLevel()`

**Visibility:** `public`

Centralized, extensible error and debug logger for Starmus.
Retains full backward compatibility while adding:
 - JSON mode for structured logs
 - Correlation ID support
 - Execution timers
 - PII masking for safe logs
 - Alert hooks for external integrations
 - Log rotation & maintenance helpers
@version 0.8.5
/
class StarmusLogger
{
    /**
Debug log level - Detailed diagnostic information.
@var int
/
    public const DEBUG = 100;

    /**
Info log level - Informational messages.
@var int
/
    public const INFO = 200;

    /**
Notice log level - Normal but significant condition.
@var int
/
    public const NOTICE = 250;

    /**
Warning log level - Warning conditions.
@var int
/
    public const WARNING = 300;

    /**
Error log level - Error conditions.
@var int
/
    public const ERROR = 400;

    /**
Critical log level - Critical conditions.
@var int
/
    public const CRITICAL = 500;

    /**
Alert log level - Action must be taken immediately.
@var int
/
    public const ALERT = 550;

    /**
Emergency log level - System is unusable.
@var int
/
    public const EMERGENCY = 600;

    /**
Path to the log file.
/
    protected static ?string $log_file_path = null;

    /**
Minimum log level to record.
/
    protected static int $min_log_level = self::INFO;

    /**
Mapping of level names to integer values.
@var array<string, int>
/
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
Whether to output logs in JSON format.
/
    protected static bool $json_mode = false;

    /**
Correlation ID for tracking related log entries.
/
    protected static ?string $correlation_id = null;

    /**
Execution timers for performance tracking.
@var array<string, float>
/
    protected static array $timers = [];

    /*==============================================================
CONFIGURATION
=============================================================*/
    /**
Set the minimum log level to record.
Only messages at or above this level will be logged.
@param string $level_name Level name (debug, info, warning, error, etc.).

### `getMinLogLevel()`

**Visibility:** `public`

Get the current minimum log level.
@return int The minimum log level integer value.

### `setLogFilePath()`

**Visibility:** `public`

Set custom log file path.
@param string $path Absolute path to log file.

### `enableJsonMode()`

**Visibility:** `public`

Enable or disable JSON output format.
@param bool $enabled True to enable JSON mode, false for plain text.

### `setCorrelationId()`

**Visibility:** `public`

Set correlation ID for tracking related log entries.
@param string|null $id Custom ID or null to auto-generate UUID.

### `getCorrelationId()`

**Visibility:** `public`

Get the current correlation ID.
@return string|null Current correlation ID or null if not set.

### `getLogFilePath()`

**Visibility:** `protected`

Get or create the log file path.
Creates log directory in WordPress uploads folder if it doesn't exist.
Generates daily log files with format: starmus-YYYY-MM-DD.log
Adds .htaccess and index.html for security.
@return string|null Log file path or null on failure.

### `getCurrentLogFile()`

**Visibility:** `public`

Get the current log file path.
@return string|null Current log file path.

### `clearOldLogs()`

**Visibility:** `public`

Delete log files older than specified days.
@param int $days Number of days to keep logs (default 30).
@return int Number of files deleted.

### `getLevelInt()`

**Visibility:** `protected`

Convert level name to integer value.
@param string $level_name Level name (case-insensitive).
@return int Level integer value, defaults to ERROR if unknown.

### `getLevelName()`

**Visibility:** `protected`

Convert level integer to name.
@param int $level_int Level integer value.
@return string Level name in uppercase, 'UNKNOWN' if not found.

### `sanitizeData()`

**Visibility:** `protected`

Sanitize log data to prevent PII exposure.
Redacts sensitive fields like IP addresses, emails, tokens, etc.
Recursively processes nested arrays.
@param array<string, mixed> $data Data array to sanitize.
@return array<string, mixed> Sanitized data array.

### `log()`

**Visibility:** `public`

Main logging method with level filtering and formatting.
Writes log entries to file with timestamp, level, context, and message.
Supports both plain text and JSON formats.
Triggers WordPress action hooks for external integrations.
Automatically redacts PII from extra data.
@param string               $context Context identifier (class/function name).
@param string|\Throwable    $msg     Message string or Throwable exception.
@param string               $level   Log level name (default 'error').
@param array<string, mixed> $extra   Additional contextual data.

### `formatMessageContent()`

**Visibility:** `protected`

Format message content for logging.
Extracts meaningful information from Throwable objects
(class, message, file, line). Casts other types to string.
@param mixed $msg Message to format.
@return string Formatted message string.

### `timeStart()`

**Visibility:** `public`

Start a named execution timer.
@param string $label Timer label/identifier.

### `boot()`

**Visibility:** `public`

Bootstrap logger and register shutdown handler.
Call this method early in plugin bootstrap to enable
callback error detection.

### `catchCallbackErrors()`

**Visibility:** `public`

Shutdown handler to catch fatal callback errors.
Detects call_user_func_array() errors and logs them to debug.log.
Registered automatically when boot() is called.

## Properties

### `$log_file_path`

**Visibility:** `protected`

Centralized, extensible error and debug logger for Starmus.
Retains full backward compatibility while adding:
 - JSON mode for structured logs
 - Correlation ID support
 - Execution timers
 - PII masking for safe logs
 - Alert hooks for external integrations
 - Log rotation & maintenance helpers
@version 0.8.5
/
class StarmusLogger
{
    /**
Debug log level - Detailed diagnostic information.
@var int
/
    public const DEBUG = 100;

    /**
Info log level - Informational messages.
@var int
/
    public const INFO = 200;

    /**
Notice log level - Normal but significant condition.
@var int
/
    public const NOTICE = 250;

    /**
Warning log level - Warning conditions.
@var int
/
    public const WARNING = 300;

    /**
Error log level - Error conditions.
@var int
/
    public const ERROR = 400;

    /**
Critical log level - Critical conditions.
@var int
/
    public const CRITICAL = 500;

    /**
Alert log level - Action must be taken immediately.
@var int
/
    public const ALERT = 550;

    /**
Emergency log level - System is unusable.
@var int
/
    public const EMERGENCY = 600;

    /**
Path to the log file.

### `$min_log_level`

**Visibility:** `protected`

Minimum log level to record.

### `$levels`

**Visibility:** `protected`

Mapping of level names to integer values.
@var array<string, int>

### `$json_mode`

**Visibility:** `protected`

Whether to output logs in JSON format.

### `$correlation_id`

**Visibility:** `protected`

Correlation ID for tracking related log entries.

### `$timers`

**Visibility:** `protected`

Execution timers for performance tracking.
@var array<string, float>

---

_Generated by Starisian Documentation Generator_
