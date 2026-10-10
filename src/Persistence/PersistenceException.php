<?php

declare(strict_types=1);

namespace StoneScriptPHP\Persistence;

/**
 * A write did NOT persist: the function returned no row where one was expected, a business-rule or
 * failure envelope instead of the saved record, or the database refused the statement.
 *
 * The HTTP status travels in the standard exception `code` slot so the router's typed handler path
 * (which maps a thrown RuntimeException by `getCode()`) honours it too.
 *
 * PRIVACY CONTRACT. `getMessage()` is ALWAYS safe to show a user: either a framework-classified generic
 * sentence or an explicitly PUBLIC business message the SQL function marked as such (see
 * {@see isPublic()} and docs/PERSISTENCE-CONTRACT.md). The raw database text is NEVER the message; it lives
 * only in `getPrevious()` (never public; platform code may inspect it, loggers must go through
 * {@see LogSanitizer}).
 */
final class PersistenceException extends \RuntimeException
{
    public const EMPTY_RESULT = 'empty_result';
    public const NOT_FOUND = 'not_found';
    public const ROW_COUNT = 'row_count';
    public const ENVELOPE_FAILURE = 'envelope_failure';
    public const ENVELOPE_ERROR = 'envelope_error';
    public const BUSINESS_RULE = 'business_rule';
    public const DATABASE_ERROR = 'database_error';
    /** The write COMMITTED but the returned row could not be mapped onto the model (strict hydration). */
    public const PERSISTED_UNREADABLE = 'persisted_unreadable';

    /** Generic, safe-to-show fallback when there is no better human message. */
    public const DEFAULT_MESSAGE = "We couldn't save your changes - nothing was changed. Please try again.";

    /**
     * @param array<string, mixed>|null $data   extra public response data (e.g. ['error_code' => ..., 'fields' => [...]])
     * @param array<int, mixed>|null    $errors public structured error list (field-level errors)
     */
    public function __construct(
        string $message,
        int $httpStatusCode = 500,
        ?\Throwable $previous = null,
        private readonly string $reason = self::DATABASE_ERROR,
        private readonly ?string $function = null,
        private readonly ?string $errorCode = null,
        private readonly bool $public = false,
        private readonly ?array $data = null,
        private readonly ?array $errors = null,
        private readonly bool $statusExplicit = false,
    ) {
        parent::__construct($message, $httpStatusCode, $previous);
    }

    /** The HTTP status this failure surfaces as, clamped to a real 4xx/5xx. */
    public function httpStatusCode(): int
    {
        $code = $this->getCode();
        return ($code >= 400 && $code < 600) ? $code : 500;
    }

    /** One of the class constants; stable, for logs and tests. */
    public function reason(): string
    {
        return $this->reason;
    }

    /** The SQL function that failed, when known. */
    public function function(): ?string
    {
        return $this->function;
    }

    /** Stable machine code for a business error (`insufficient_stock`), when the function supplied one. */
    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    /** True when the status was declared by the function/classifier (never auto-adjusted); false when it is the default 400. */
    public function isStatusExplicit(): bool
    {
        return $this->statusExplicit;
    }

    /** True when the message was explicitly marked public by the SQL function (a business error). */
    public function isPublic(): bool
    {
        return $this->public;
    }

    /** @return array<string, mixed>|null */
    public function publicData(): ?array
    {
        return $this->data;
    }

    /** @return array<int, mixed>|null */
    public function publicErrors(): ?array
    {
        return $this->errors;
    }
}
