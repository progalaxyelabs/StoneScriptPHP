<?php

namespace StoneScriptPHP;

use DateTime;
use Throwable;

/**
 * Production-ready Logger with multiple outputs and log levels
 * Supports: Console output, File output, Structured JSON logging
 */
class Logger
{
    private static ?Logger $_instance = null;
    private function __construct() {}

    // Log Levels (PSR-3 compatible)
    const EMERGENCY = 'EMERGENCY'; // System is unusable
    const ALERT = 'ALERT';         // Action must be taken immediately
    const CRITICAL = 'CRITICAL';   // Critical conditions
    const ERROR = 'ERROR';         // Error conditions
    const WARNING = 'WARNING';     // Warning conditions
    const NOTICE = 'NOTICE';       // Normal but significant condition
    const INFO = 'INFO';           // Informational messages
    const DEBUG = 'DEBUG';         // Debug-level messages

    // PHP Error mappings
    const ERROR_STRINGS = [
        E_ERROR => "E_ERROR",
        E_WARNING => "E_WARNING",
        E_PARSE => "E_PARSE",
        E_NOTICE => "E_NOTICE",
        E_CORE_ERROR => "E_CORE_ERROR",
        E_CORE_WARNING => "E_CORE_WARNING",
        E_COMPILE_ERROR => "E_COMPILE_ERROR",
        E_COMPILE_WARNING => "E_COMPILE_WARNING",
        E_USER_ERROR => "E_USER_ERROR",
        E_USER_WARNING => "E_USER_WARNING",
        E_USER_NOTICE => "E_USER_NOTICE",
        E_RECOVERABLE_ERROR => "E_RECOVERABLE_ERROR",
        E_DEPRECATED => "E_DEPRECATED",
        E_USER_DEPRECATED => "E_USER_DEPRECATED",
        E_ALL => "E_ALL"
    ];

    // ANSI Color codes for console
    const COLORS = [
        'EMERGENCY' => "\033[41;37m",  // Red background
        'ALERT' => "\033[1;35m",       // Bright magenta
        'CRITICAL' => "\033[1;31m",    // Bright red
        'ERROR' => "\033[0;31m",       // Red
        'WARNING' => "\033[0;33m",     // Yellow
        'NOTICE' => "\033[0;36m",      // Cyan
        'INFO' => "\033[0;32m",        // Green
        'DEBUG' => "\033[0;37m",       // White
        'RESET' => "\033[0m"           // Reset
    ];

    public static function get_instance(): Logger
    {
        if (Logger::$_instance === null) {
            Logger::$_instance = new Logger();
        }
        return Logger::$_instance;
    }

    private array $lines = [];
    private bool $enable_console = true;
    private bool $enable_file = true;
    private bool $enable_json = false;

    /**
     * Configure logger outputs
     */
    public function configure(bool $console = true, bool $file = true, bool $json = false): void
    {
        $this->enable_console = $console;
        $this->enable_file = $file;
        $this->enable_json = $json;
    }

    /**
     * Log at DEBUG level
     */
    public function log_debug($message, array $context = []): void
    {
        if (DEBUG_MODE) {
            $this->log(self::DEBUG, $message, $context);
        }
    }

    /**
     * Log at INFO level
     */
    public function log_info($message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    /**
     * Log at NOTICE level
     */
    public function log_notice($message, array $context = []): void
    {
        $this->log(self::NOTICE, $message, $context);
    }

    /**
     * Log at WARNING level
     */
    public function log_warning($message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    /**
     * Log at ERROR level
     */
    public function log_error($message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * Log at CRITICAL level
     */
    public function log_critical($message, array $context = []): void
    {
        $this->log(self::CRITICAL, $message, $context);
    }

    /**
     * Log at ALERT level
     */
    public function log_alert($message, array $context = []): void
    {
        $this->log(self::ALERT, $message, $context);
    }

    /**
     * Log at EMERGENCY level
     */
    public function log_emergency($message, array $context = []): void
    {
        $this->log(self::EMERGENCY, $message, $context);
    }

    // Sensitive field patterns to redact
    const SENSITIVE_KEYS = [
        'password', 'password_hash', 'token', 'secret', 'api_key', 'access_token',
        'refresh_token', 'private_key', 'authorization', 'cookie', 'session',
        'csrf_token', 'otp_code', 'verification_token', 'reset_token', 'jwt'
    ];

    /**
     * Sanitize context to remove sensitive data
     */
    private function sanitize_context(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            $lower_key = strtolower($key);

            // Check if key contains sensitive patterns
            $is_sensitive = false;
            foreach (self::SENSITIVE_KEYS as $pattern) {
                if (str_contains($lower_key, $pattern)) {
                    $is_sensitive = true;
                    break;
                }
            }

            if ($is_sensitive) {
                // Redact sensitive value
                if (is_string($value) && strlen($value) > 0) {
                    $sanitized[$key] = '***REDACTED***';
                } else {
                    $sanitized[$key] = '***';
                }
            } elseif (is_array($value)) {
                // Recursively sanitize nested arrays
                $sanitized[$key] = $this->sanitize_context($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Core logging method
     */
    private function log(string $level, $message, array $context = []): void
    {
        $timestamp = new DateTime();
        $formatted_time = $timestamp->format('Y-m-d H:i:s.u');

        // Sanitize context to remove sensitive data
        $sanitized_context = $this->sanitize_context($context);

        // Build log entry
        $log_entry = [
            'timestamp' => $formatted_time,
            'level' => $level,
            'message' => $message,
            'context' => $sanitized_context,
            'memory' => memory_get_usage(true),
            'pid' => getmypid()
        ];

        // Store in memory for debug mode
        if (DEBUG_MODE) {
            $this->lines[] = $log_entry;
        }

        // Output to console
        if ($this->enable_console && $this->should_output_to_console($level)) {
            $this->write_to_console($level, $message, $formatted_time, $sanitized_context);
        }

        // Output to file
        if ($this->enable_file) {
            if ($this->enable_json) {
                $this->write_json_to_file($log_entry);
            } else {
                $this->write_to_file($level, $message, $formatted_time, $sanitized_context);
            }
        }
    }

    /**
     * Determine if log level should output to console
     */
    private function should_output_to_console(string $level): bool
    {
        // In production, only show WARNING and above in console
        if (!DEBUG_MODE) {
            return in_array($level, [
                self::WARNING,
                self::ERROR,
                self::CRITICAL,
                self::ALERT,
                self::EMERGENCY
            ]);
        }
        return true;
    }

    /**
     * Write colorized output to console
     */
    private function write_to_console(string $level, $message, string $timestamp, array $context): void
    {
        $color = self::COLORS[$level] ?? '';
        $reset = self::COLORS['RESET'];

        $output = sprintf(
            "%s[%s] %s%-9s%s %s",
            $reset,
            $timestamp,
            $color,
            $level,
            $reset,
            $message
        );

        if (!empty($context)) {
            $output .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }

        // Write to STDERR for errors, STDOUT for others
        // Use php:// streams instead of constants for compatibility with php -S
        $streamUrl = in_array($level, [self::ERROR, self::CRITICAL, self::ALERT, self::EMERGENCY])
            ? 'php://stderr'
            : 'php://stdout';

        $stream = fopen($streamUrl, 'w');
        if ($stream) {
            fwrite($stream, $output . PHP_EOL);
            fclose($stream);
        }
    }

    /**
     * Write plain text to log file
     */
    private function write_to_file(string $level, $message, string $timestamp, array $context): void
    {
        $log_dir = ROOT_PATH . 'logs';
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        $file_path = $log_dir . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';

        $line = sprintf('[%s] %-9s %s', $timestamp, $level, $message);

        if (!empty($context)) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }

        file_put_contents($file_path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Write structured JSON to log file
     */
    private function write_json_to_file(array $log_entry): void
    {
        $log_dir = ROOT_PATH . 'logs';
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        $file_path = $log_dir . DIRECTORY_SEPARATOR . date('Y-m-d') . '.json.log';

        $json_line = json_encode($log_entry, JSON_UNESCAPED_SLASHES) . PHP_EOL;
        file_put_contents($file_path, $json_line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Log PHP errors
     */
    public function log_php_error(int $error_number, string $message, string $file, int $line_number): void
    {
        $level_string = self::ERROR_STRINGS[$error_number] ?? 'UNKNOWN';

        // Map PHP error to log level
        $log_level = match ($error_number) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => self::ERROR,
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => self::WARNING,
            E_NOTICE, E_USER_NOTICE => self::NOTICE,
            E_DEPRECATED, E_USER_DEPRECATED => self::WARNING,
            default => self::ERROR
        };

        $this->log($log_level, "PHP $level_string: $message", [
            'file' => $file,
            'line' => $line_number,
            'error_type' => $level_string
        ]);
    }

    /**
     * Log PHP exceptions
     */
    public function log_php_exception(Throwable $exception): void
    {
        $this->log(self::CRITICAL, $exception->getMessage(), [
            'exception_class' => get_class($exception),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => DEBUG_MODE ? $exception->getTraceAsString() : 'hidden'
        ]);
    }

    /**
     * Log HTTP request
     */
    public function log_request(string $method, string $uri, int $status_code, float $duration_ms): void
    {
        $level = $status_code >= 500 ? self::ERROR
            : ($status_code >= 400 ? self::WARNING : self::INFO);

        $this->log($level, sprintf('%s %s', $method, $uri), [
            'status_code' => $status_code,
            'duration_ms' => round($duration_ms, 2),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    }

    /**
     * Get all logged messages (for debug mode)
     */
    public function get_all(): array
    {
        if (!DEBUG_MODE) {
            return [];
        }

        return $this->lines;
    }

    /**
     * Flush logs to disk immediately
     */
    public function flush(): void
    {
        // Files are written immediately with LOCK_EX, so nothing to do
        // This method exists for API compatibility
    }
}
