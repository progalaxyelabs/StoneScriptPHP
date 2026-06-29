<?php

declare(strict_types=1);

namespace StoneScriptPHP\RequestLogging;

use Throwable;

/**
 * Request-scoped static error context.
 *
 * Holds error_class + error_message for the current request so the shutdown
 * function can read them when persisting the request_logs row.
 *
 * NEVER writes to the DB from here — it only records the cause.
 * Writing is done exclusively by RequestLogger::persistRequestLog().
 *
 * §5 of the request-logging spec.
 *
 * @package StoneScriptPHP\RequestLogging
 */
class RequestContext
{
    private static ?string $errorClass   = null;
    private static ?string $errorMessage = null;

    /**
     * Stamp an uncaught exception as the error for this request.
     * Called by ExceptionHandler::handleException() before rendering the error response.
     */
    public static function captureException(Throwable $e): void
    {
        self::$errorClass   = get_class($e);
        self::$errorMessage = mb_substr($e->getMessage(), 0, 1000);
    }

    /**
     * Stamp a fatal error (from error_get_last()) as the error for this request.
     * Called by ExceptionHandler::handleShutdown() and by RequestLogger::persistRequestLog()
     * as a safety net when no exception was captured earlier.
     *
     * @param array{type: int, message: string, file: string, line: int} $error
     */
    public static function captureFatalError(array $error): void
    {
        self::$errorClass   = 'FatalError';
        self::$errorMessage = mb_substr($error['message'], 0, 1000);
    }

    /**
     * Return the captured error class name, or null on success.
     */
    public static function getErrorClass(): ?string
    {
        return self::$errorClass;
    }

    /**
     * Return the captured error message (≤ 1000 chars), or null on success.
     */
    public static function getErrorMessage(): ?string
    {
        return self::$errorMessage;
    }

    /**
     * Reset captured error state. Used by the test suite between runs.
     */
    public static function reset(): void
    {
        self::$errorClass   = null;
        self::$errorMessage = null;
    }
}
