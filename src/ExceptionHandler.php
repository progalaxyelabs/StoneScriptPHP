<?php

namespace StoneScriptPHP;

use StoneScriptPHP\Exceptions\FrameworkException;
use StoneScriptPHP\Exceptions\ValidationException;
use Throwable;
use Error;

/**
 * Global Exception Handler for StoneScriptPHP
 * Handles all uncaught exceptions and errors
 */
class ExceptionHandler
{
    private static ?ExceptionHandler $instance = null;

    private function __construct() {}

    public static function getInstance(): ExceptionHandler
    {
        if (self::$instance === null) {
            self::$instance = new ExceptionHandler();
        }
        return self::$instance;
    }

    /**
     * Register global exception and error handlers
     */
    public function register(): void
    {
        set_exception_handler([$this, 'handleException']);
        set_error_handler([$this, 'handleError']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * Handle uncaught exceptions
     */
    public function handleException(Throwable $exception): void
    {
        $this->logException($exception);
        $this->renderException($exception);
    }

    /**
     * Handle PHP errors
     */
    public function handleError(int $level, string $message, string $file = '', int $line = 0): bool
    {
        // Don't handle errors suppressed with @
        if (!(error_reporting() & $level)) {
            return false;
        }

        Logger::get_instance()->log_php_error($level, $message, $file, $line);

        // Let PHP handle fatal errors
        if (in_array($level, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            return false;
        }

        return true;
    }

    /**
     * Handle fatal errors during shutdown
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error === null) {
            return;
        }

        // Check if it's a fatal error
        if (in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->logFatalError($error);
            $this->renderFatalError($error);
        }
    }

    /**
     * Log exception to logger
     */
    private function logException(Throwable $exception): void
    {
        Logger::get_instance()->log_php_exception($exception);
    }

    /**
     * Log fatal error
     */
    private function logFatalError(array $error): void
    {
        log_critical('Fatal error: ' . $error['message'], [
            'file' => $error['file'],
            'line' => $error['line'],
            'type' => $error['type']
        ]);
    }

    /**
     * Render exception as API response
     */
    private function renderException(Throwable $exception): void
    {
        // Clear any existing output
        if (ob_get_level() > 0) {
            ob_clean();
        }

        // Determine HTTP status code
        $status_code = 500;
        if ($exception instanceof FrameworkException) {
            $status_code = $exception->getHttpStatusCode();
        } elseif (method_exists($exception, 'getStatusCode')) {
            $status_code = $exception->getStatusCode();
        }

        http_response_code($status_code);
        header('Content-Type: application/json');

        // Build error response
        $response = $this->buildErrorResponse($exception, $status_code);

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit(1);
    }

    /**
     * Render fatal error as API response
     */
    private function renderFatalError(array $error): void
    {
        // Clear any existing output
        if (ob_get_level() > 0) {
            ob_clean();
        }

        http_response_code(500);
        header('Content-Type: application/json');

        $response = [
            'status' => 'error',
            'message' => 'A fatal error occurred',
            'data' => null
        ];

        if (DEBUG_MODE) {
            $response['debug'] = [
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'type' => $error['type']
            ];
        }

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit(1);
    }

    /**
     * Build structured error response
     */
    private function buildErrorResponse(Throwable $exception, int $status_code): array
    {
        $response = [
            'status' => 'error',
            'message' => $this->getPublicMessage($exception, $status_code),
            'data' => null
        ];

        // Add validation errors if ValidationException
        if ($exception instanceof ValidationException) {
            $response['errors'] = $exception->getValidationErrors();
        }

        // Add debug information in debug mode
        if (DEBUG_MODE) {
            $response['debug'] = [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $this->formatTrace($exception->getTrace())
            ];

            // Add context if FrameworkException
            if ($exception instanceof FrameworkException) {
                $context = $exception->getContext();
                if (!empty($context)) {
                    $response['debug']['context'] = $context;
                }
            }

            // Add previous exception if exists
            if ($exception->getPrevious()) {
                $response['debug']['previous'] = [
                    'exception' => get_class($exception->getPrevious()),
                    'message' => $exception->getPrevious()->getMessage(),
                    'file' => $exception->getPrevious()->getFile(),
                    'line' => $exception->getPrevious()->getLine()
                ];
            }
        }

        return $response;
    }

    /**
     * Get public-facing error message
     */
    private function getPublicMessage(Throwable $exception, int $status_code): string
    {
        // In debug mode, show actual message
        if (DEBUG_MODE) {
            return $exception->getMessage();
        }

        // In production, show generic messages
        if ($exception instanceof FrameworkException) {
            return $exception->getMessage();
        }

        // Generic messages for different status codes
        return match ($status_code) {
            400 => 'Bad request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not found',
            422 => 'Validation failed',
            429 => 'Too many requests',
            503 => 'Service unavailable',
            default => 'An error occurred'
        };
    }

    /**
     * Format exception trace for output
     */
    private function formatTrace(array $trace): array
    {
        return array_map(function ($item) {
            return [
                'file' => $item['file'] ?? 'unknown',
                'line' => $item['line'] ?? 0,
                'function' => ($item['class'] ?? '') . ($item['type'] ?? '') . ($item['function'] ?? '')
            ];
        }, array_slice($trace, 0, 10)); // Limit to 10 frames
    }

    /**
     * Report exception to external service (for future integration)
     */
    private function reportException(Throwable $exception): void
    {
        // TODO: Integrate with error reporting services like:
        // - Sentry
        // - Rollbar
        // - Bugsnag
        // - New Relic
        // - Custom logging service
    }
}
