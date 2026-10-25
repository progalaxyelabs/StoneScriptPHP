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
 * NEVER writes to the DB from here - it only records the cause.
 * Writing is done exclusively by RequestLogger::persistRequestLog().
 *
 * What is recorded is ALWAYS "class + safe message" ({@see FailureText}): no request values, no personal data.
 *
 * Three kinds of record, in priority order:
 *  1. a FAILURE (exception / fatal) - {@see captureException()}, {@see captureFatalError()};
 *  2. an ERROR RESPONSE with no exception behind it (a handler returned 4xx/5xx) - {@see captureResponse()};
 *  3. a HANDLED failure (something failed, the code recovered and the request still succeeded or degraded) -
 *     {@see noteHandled()}. It is stored so the request is never recorded as a clean success.
 *
 * @package StoneScriptPHP\RequestLogging
 */
class RequestContext
{
    /** Class recorded for an error response that has no exception behind it. */
    public const CLASS_ERROR_RESPONSE = 'ErrorResponse';

    /** Class recorded for a 4xx/5xx for which NOTHING recorded a cause (a gap to be closed, never silence). */
    public const CLASS_UNRECORDED = 'UnrecordedError';

    private static ?string $errorClass   = null;
    private static ?string $errorMessage = null;
    /** true once a real failure (exception/fatal) was captured; a later response record must not replace it */
    private static bool $failureCaptured = false;
    /** @var list<array{source:string,class:string,message:string}> */
    private static array $handled = [];

    /**
     * Stamp an exception as the error for this request. Called by the Router for every exception it turns into an
     * error response, and by ExceptionHandler::handleException() for uncaught ones.
     */
    public static function captureException(Throwable $e): void
    {
        // The first real failure is the cause; a follow-on failure while rendering must not mask it.
        if (self::$failureCaptured) {
            return;
        }
        self::$failureCaptured = true;
        self::$errorClass   = FailureText::safeClass(get_class($e));
        self::$errorMessage = FailureText::safe($e->getMessage());
    }

    /**
     * Stamp a fatal error (from error_get_last()) as the error for this request.
     *
     * @param array{type: int, message: string, file: string, line: int} $error
     */
    public static function captureFatalError(array $error): void
    {
        if (self::$failureCaptured) {
            return;
        }
        self::$failureCaptured = true;
        $message = (string) $error['message'];
        // "Uncaught X: msg in file:line Stack trace: ..." embeds the exception chain; keep only the head.
        if (str_starts_with($message, 'Uncaught ')) {
            $message = (string) strstr($message, "\n", true) ?: $message;
            $message = (string) preg_replace('/ in \/.*$/s', '', $message);
        }
        self::$errorClass   = 'FatalError';
        self::$errorMessage = FailureText::safe($message);
    }

    /**
     * Record an error RESPONSE that no exception explains (status >= 400). Does nothing when a failure was already
     * captured, or for success statuses.
     */
    public static function captureResponse(int $status, string $message, ?string $class = null): void
    {
        if ($status < 400 || self::$failureCaptured || self::$errorClass !== null) {
            return;
        }
        self::$errorClass   = $class !== null ? FailureText::safeClass($class) : self::CLASS_ERROR_RESPONSE;
        self::$errorMessage = FailureText::safe($message);
    }

    /**
     * Record that something FAILED but the code recovered from it (fail-open lookups, optional steps). The request
     * log then carries the cause even though the status is not an error, so a failure is never reported as a clean
     * success. Call this wherever code deliberately swallows a Throwable.
     *
     * @param string $source short, fixed, value-free label of the swallowing site (e.g. 'subscription-lookup')
     */
    public static function noteHandled(string $source, Throwable|string $failure): void
    {
        if (count(self::$handled) >= 5) {
            return;
        }
        $class = $failure instanceof Throwable ? get_class($failure) : 'HandledFailure';
        $text  = $failure instanceof Throwable ? $failure->getMessage() : $failure;
        self::$handled[] = [
            'source'  => FailureText::safeClass($source),
            'class'   => FailureText::safeClass($class),
            'message' => FailureText::safe($text),
        ];
    }

    /** @return list<array{source:string,class:string,message:string}> */
    public static function getHandled(): array
    {
        return self::$handled;
    }

    /** Return the captured error class name, or null on success. */
    public static function getErrorClass(): ?string
    {
        if (self::$errorClass !== null) {
            return self::$errorClass;
        }
        return self::$handled !== [] ? self::$handled[0]['class'] : null;
    }

    /** Return the captured error message (safe, <= 300 chars), or null on success. */
    public static function getErrorMessage(): ?string
    {
        if (self::$errorMessage !== null) {
            return self::$errorMessage;
        }
        if (self::$handled === []) {
            return null;
        }
        $h = self::$handled[0];
        return FailureText::cut('[handled:' . $h['source'] . '] ' . $h['message'], FailureText::MAX_LENGTH);
    }

    /** True when a failure/error response (not merely a handled one) was recorded. */
    public static function hasError(): bool
    {
        return self::$errorClass !== null;
    }

    /** Reset captured error state. Used by the test suite between runs. */
    public static function reset(): void
    {
        self::$errorClass      = null;
        self::$errorMessage    = null;
        self::$failureCaptured = false;
        self::$handled         = [];
    }
}
