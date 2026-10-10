<?php

namespace StoneScriptPHP;

use StoneScriptPHP\Exceptions\FrameworkException;
use StoneScriptPHP\Exceptions\ValidationException;
use StoneScriptPHP\RequestLogging\RequestContext;
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
        // §5 — stamp error into request-scoped context BEFORE rendering so the
        // shutdown function (RequestLogger::persistRequestLog) can read it.
        RequestContext::captureException($exception);

        $correlationId = bin2hex(random_bytes(6));
        $this->logException($exception, $correlationId);
        $this->renderException($exception, $correlationId);
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
            // §5 — stamp into request-scoped context BEFORE rendering so the
            // RequestLogger shutdown function (registered later) can read it.
            RequestContext::captureFatalError($error);

            $correlationId = bin2hex(random_bytes(6));
            $this->logFatalError($error, $correlationId);
            $this->renderFatalError($error, $correlationId);
        }
    }

    /**
     * Log exception to logger
     */
    private function logException(Throwable $exception, string $correlationId = ''): void
    {
        Logger::get_instance()->log_php_exception($exception, $correlationId);
    }

    /**
     * Log fatal error
     */
    private function logFatalError(array $error, string $correlationId = ''): void
    {
        log_critical('Fatal error: ' . $error['message'], [
            'correlation_id' => $correlationId,
            'file' => $error['file'],
            'line' => $error['line'],
            'type' => $error['type']
        ]);
    }

    /**
     * Render exception as API response
     */
    private function renderException(Throwable $exception, string $correlationId = ''): void
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
        $response = $this->buildErrorResponse($exception, $status_code, null, $correlationId);

        // HEAD responses never carry a body (RFC 9110 9.3.2).
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
            echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        exit(1);
    }

    /**
     * Render fatal error as API response
     */
    private function renderFatalError(array $error, string $correlationId = ''): void
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
        if ($correlationId !== '') {
            $response['correlation_id'] = $correlationId;
        }

        if (DEBUG_MODE) {
            // The raw fatal-error text is never put in a response (it may embed data); it is in the log.
            $response['debug'] = [
                'file' => $error['file'],
                'line' => $error['line'],
                'type' => $error['type']
            ];
        }

        // HEAD responses never carry a body (RFC 9110 9.3.2).
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
            echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        exit(1);
    }

    /**
     * Build structured error response.
     *
     * NEVER contains raw exception text, in any mode: exception messages routinely embed data values (a database
     * `Key (email)=(...)`, a quoted literal). The raw text goes to the (sanitised) log under the correlation id.
     * Debug mode adds the exception class, code, location, trace and the correlation id only.
     *
     * @param bool|null $debug null = the DEBUG_MODE constant
     */
    private function buildErrorResponse(Throwable $exception, int $status_code, ?bool $debug = null, string $correlationId = ''): array
    {
        $debug ??= DEBUG_MODE;
        $response = [
            'status' => 'error',
            'message' => $this->getPublicMessage($exception, $status_code),
            'data' => null
        ];
        if ($correlationId !== '') {
            $response['correlation_id'] = $correlationId;
        }

        // Add validation errors if ValidationException
        if ($exception instanceof ValidationException) {
            $response['errors'] = $exception->getValidationErrors();
        }

        if ($debug) {
            $response['debug'] = [
                'exception' => get_class($exception),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $this->formatTrace($exception->getTrace())
            ];

            if ($exception->getPrevious()) {
                $response['debug']['previous'] = [
                    'exception' => get_class($exception->getPrevious()),
                    'file' => $exception->getPrevious()->getFile(),
                    'line' => $exception->getPrevious()->getLine()
                ];
            }
        }

        return $response;
    }

    /**
     * Get public-facing error message. Only a FrameworkException (whose message the framework authors for
     * clients) is passed through, and only after the persistence sanitiser; everything else is generic.
     */
    private function getPublicMessage(Throwable $exception, int $status_code): string
    {
        // A message deliberately marked public (every FrameworkException) is shown as written, unmodified.
        if ($exception instanceof \StoneScriptPHP\Exceptions\PublicMessage) {
            return $exception->getMessage();
        }

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
