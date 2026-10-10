<?php

declare(strict_types=1);

namespace StoneScriptPHP\Persistence;

use StoneScriptDB\GatewayException;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Db\DbTransportException;
use StoneScriptPHP\TenantDatabaseUnavailableException;

/**
 * The ONE place a caught database/persistence exception becomes an HTTP status and a message that is
 * safe to show an end user.
 *
 * PRIVACY: raw database text is NEVER returned to a client and never logged unsanitised. A response carries
 * either (a) a framework-classified generic sentence, or (b) an explicitly PUBLIC business message:
 *   - a function result envelope with `public_message` (+ optional `error_code`, `status` 4xx, `fields`, `errors`), or
 *   - a `RAISE EXCEPTION '[public:error_code:status] The message'` marker (error_code and status optional), or
 *   - a platform classifier registered with {@see extend()}.
 * Anything else that reaches here is a 500 with the generic sentence.
 *
 * Also guaranteed: a failure is never a 2xx; the full chain is logged (sanitised, see {@see LogSanitizer}).
 */
final class DbErrorMapper
{
    /**
     * @var list<callable(string, ?string, string, \Throwable): (array{0: int, 1: string}|PublicError|null)>
     */
    private static array $extensions = [];

    /**
     * Register a platform classifier, tried BEFORE the built-in table. It receives the lower-cased cause text,
     * the noun, the RAW cause text (for extracting constraint names / dynamic values; never echo it) and the
     * throwable. It returns `[status, safeMessage]`, a full {@see PublicError} (status, message, dynamic data,
     * structured errors), or null to pass.
     *
     * @param callable(string, ?string, string, \Throwable): (array{0: int, 1: string}|PublicError|null) $classifier
     */
    public static function extend(callable $classifier): void
    {
        self::$extensions[] = $classifier;
    }

    public static function clearExtensions(): void
    {
        self::$extensions = [];
    }

    /**
     * True when $e came from the database layer (so mapping it is appropriate), as opposed to a
     * programming error elsewhere in a handler.
     */
    public static function isDatabaseOrigin(\Throwable $e): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ($cur instanceof GatewayException || $cur instanceof DbTransportException
                || $cur instanceof \PDOException || $cur instanceof PersistenceException) {
                return true;
            }
        }
        return false;
    }

    /** Case-insensitive search of the RAW cause chain, for platform code that branches on a constraint name. Never echo the text. */
    public static function causeContains(\Throwable $e, string $needle): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if (stripos($cur->getMessage(), $needle) !== false) {
                return true;
            }
            if ($cur instanceof GatewayException && is_string($cur->getCause()) && stripos($cur->getCause(), $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /** The generic, always-safe sentence for an HTTP status. */
    public static function genericMessage(int $status): string
    {
        return match ($status) {
            400 => 'Bad request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not found',
            409 => 'Conflict',
            422 => 'Validation failed',
            429 => 'Too many requests',
            503 => 'Service unavailable',
            default => $status >= 500 ? 'Internal server error' : 'The request could not be completed',
        };
    }

    /** Build a response for a business error decided by platform code. All arguments are shown to the client. */
    public static function publicResponse(int $status, string $message, ?array $data = null, ?array $errors = null, ?string $errorCode = null): ApiResponse
    {
        return (new PublicError($status, $message, $data, $errors, $errorCode))->toResponse();
    }

    /**
     * @return array{0: int, 1: string} [httpStatusCode, safe message]
     */
    public static function classify(\Throwable $e, ?string $noun = null): array
    {
        $r = self::resolve($e, $noun);
        return [$r->status, $r->message];
    }

    public static function resolve(\Throwable $e, ?string $noun = null): PublicError
    {
        if ($e instanceof TenantDatabaseUnavailableException) {
            return new PublicError(503, 'The service is temporarily unavailable. Please try again in a moment.', public: false);
        }

        if ($e instanceof PersistenceException) {
            $status = $e->httpStatusCode();
            // A 400 whose public wording says "not found" is a 404.
            if ($status === 400 && !$e->isStatusExplicit() && self::looksLikeNotFound($e->getMessage())) {
                $status = 404;
            }
            return new PublicError($status, $e->getMessage(), $e->publicData(), $e->publicErrors(), $e->errorCode(), $e->isPublic(), true);
        }

        // A coded \RuntimeException keeps its status. Its message is shown ONLY when the exception is deliberately
        // marked public ({@see PublicMessage}); otherwise it may hold internal text and the client gets the generic
        // sentence for that status.
        if ($e instanceof \RuntimeException && $e->getCode() >= 400 && $e->getCode() < 600) {
            $status = (int) $e->getCode();
            return new PublicError($status, $e instanceof \StoneScriptPHP\Exceptions\PublicMessage ? $e->getMessage() : self::genericMessage($status), public: $e instanceof \StoneScriptPHP\Exceptions\PublicMessage);
        }

        $raw = self::rawCause($e);
        $lower = strtolower($raw);

        // A database that is unreachable (direct / pgandroid transports) is retryable.
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ($cur instanceof DbTransportException && $cur->isConnectionFailure()) {
                return new PublicError(503, 'The service is temporarily unavailable. Please try again in a moment.', public: false);
            }
        }

        foreach (self::$extensions as $classifier) {
            $hit = $classifier($lower, $noun, $raw, $e);
            if ($hit instanceof PublicError) {
                return new PublicError(self::clamp($hit->status), $hit->message, $hit->data, $hit->errors, $hit->errorCode, true, $hit->explicitStatus);
            }
            if (is_array($hit)) {
                return new PublicError(self::clamp((int) $hit[0]), (string) $hit[1]);
            }
        }

        // Explicit public RAISE marker: `[public:code:status] message`
        if (preg_match('/\[public(?::([a-z][a-z0-9_]*))?(?::([1-4]\d\d))?\]\s*(.+?)(?=\s\|\s|\s\(SQLSTATE|$)/s', $raw, $m) === 1 && trim($m[3]) !== '') {
            return new PublicError($m[2] !== '' ? (int) $m[2] : 400, trim($m[3]), null, null, $m[1] !== '' ? $m[1] : null, true, $m[2] !== '');
        }

        $thing = $noun !== null && $noun !== '' ? $noun : 'record';
        $state = self::sqlState($e);

        // 23505 unique_violation
        if ($state === '23505' || self::contains($lower, ['duplicate key', 'unique constraint', 'already exists'])) {
            return new PublicError(409, "A {$thing} with these details already exists.", public: false);
        }
        // 23503 foreign_key_violation: deleting something still in use is a conflict; pointing at
        // something that does not exist is a bad request.
        if ($state === '23503' || self::contains($lower, ['foreign key'])) {
            if (self::contains($lower, ['still referenced'])) {
                return new PublicError(409, "This {$thing} is still in use and cannot be removed.", public: false);
            }
            return new PublicError(400, "This {$thing} refers to something that doesn't exist or was removed. Please check and try again.", public: false);
        }
        // 23514 check_violation
        if ($state === '23514' || self::contains($lower, ['violates check constraint'])) {
            return new PublicError(422, "Some of the details for this {$thing} are not allowed. Please check and try again.", public: false);
        }
        // 22xxx: the CLIENT sent a malformed value (bad uuid/int/date, too long, out of range).
        if (in_array($state, ['22P02', '22003', '22001', '22007', '22008', '22P03'], true)
            || self::contains($lower, ['invalid input syntax', 'out of range for type', 'value too long for type'])) {
            return new PublicError(400, 'One of the values you sent is not valid. Please check and try again.', public: false);
        }
        // 40001, 40P01, 55P03, 57014: retryable, the server is busy.
        if (in_array($state, ['40001', '40P01', '55P03', '57014'], true)
            || self::contains($lower, ['could not serialize access', 'deadlock detected', 'could not obtain lock', 'canceling statement due to'])) {
            return new PublicError(503, 'The service is busy right now. Please try again in a moment.', public: false);
        }

        // Everything else (undefined column/function, syntax error, not-null, unrecognised RAISE ...): generic 500.
        return new PublicError(500, PersistenceException::DEFAULT_MESSAGE, public: false);
    }

    /** Map to an ApiResponse that always carries a real 4xx/5xx status. Logs the sanitised chain. */
    public static function toResponse(\Throwable $e, ?string $noun = null): ApiResponse
    {
        $r = self::resolve($e, $noun);
        self::logFailure($e, $r);
        return $r->toResponse();
    }

    /** One sanitised log line; raw database text never reaches the log. */
    public static function logFailure(\Throwable $e, ?PublicError $mapped = null): void
    {
        try {
            log_error(self::failureLine($e, $mapped));
        } catch (\Throwable) {
        }
    }

    /** The (sanitised) line {@see logFailure()} writes. */
    public static function failureLine(\Throwable $e, ?PublicError $mapped = null): string
    {
        return sprintf('DbErrorMapper: HTTP %s: %s', $mapped !== null ? (string) $mapped->status : '?', LogSanitizer::describe($e));
    }

    /** The PostgreSQL message when the gateway forwarded one, else the exception chain's text. */
    public static function rawCause(\Throwable $e): string
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ($cur instanceof GatewayException) {
                $cause = $cur->getCause();
                if (is_string($cause) && $cause !== '') {
                    return $cause;
                }
            }
        }
        return $e->getMessage();
    }

    /** SQLSTATE from a PDOException in the chain, or from an embedded `SQLSTATE[xxxxx]`. */
    private static function sqlState(\Throwable $e): ?string
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ($cur instanceof \PDOException) {
                $info = $cur->errorInfo;
                if (is_array($info) && isset($info[0]) && preg_match('/^[0-9A-Z]{5}$/', (string) $info[0]) === 1) {
                    return (string) $info[0];
                }
            }
            if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $cur->getMessage(), $m) === 1) {
                return $m[1];
            }
        }
        return null;
    }

    private static function looksLikeNotFound(string $msg): bool
    {
        return self::contains(strtolower($msg), ['not found', 'could not be found', "couldn't find", 'no such', 'does not exist']);
    }

    private static function clamp(int $status): int
    {
        return ($status >= 400 && $status < 600) ? $status : 500;
    }

    /** @param list<string> $needles */
    private static function contains(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }
}
