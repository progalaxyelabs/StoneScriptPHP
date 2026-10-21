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
            $raw = SafeDbContext::rawCauseOf($cur);
            if ($raw !== null && stripos($raw, $needle) !== false) {
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

        // Explicit public RAISE marker: `[public:code:status] message` (see publicMarker() for the security rules).
        $marker = self::publicMarker($raw, self::sqlState($e));
        if ($marker !== null) {
            return new PublicError($marker['status'] ?? 400, $marker['message'], null, null, $marker['code'], true, $marker['status'] !== null);
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

    /** @var list<string> exact literal prefixes platforms registered for the wrapped developer form */
    private static array $publicPrefixes = [];

    /**
     * Register an exact literal prefix that a platform's own wrapper puts BEFORE the `[public:...]` marker, e.g.
     * `DbErrorMapper::allowPublicPrefix('Failed to create order: ')`. Only registered literals are honoured; no other
     * text may precede the marker.
     *
     * A registered prefix must be used ONLY by wrappers that ALWAYS wrap a `[public...]` message, never by a RAISE
     * that interpolates after it: anything after the prefix is read as the marker and published verbatim.
     *
     * @throws \InvalidArgumentException for a prefix shorter than 8 characters, containing `%` (a format placeholder
     *         means a value follows), whitespace-only, or longer than 120 characters
     */
    public static function allowPublicPrefix(string $prefix): void
    {
        if (trim($prefix) === '') {
            throw new \InvalidArgumentException('A public prefix must not be empty or whitespace-only.');
        }
        if (strlen($prefix) < 8) {
            throw new \InvalidArgumentException('A public prefix must be at least 8 characters: a short prefix is too easy to imitate.');
        }
        if (strlen($prefix) > 120) {
            throw new \InvalidArgumentException('A public prefix must be at most 120 characters.');
        }
        if (str_contains($prefix, '%')) {
            throw new \InvalidArgumentException('A public prefix must not contain %: it must be an exact literal, never a format with a value.');
        }
        if (!in_array($prefix, self::$publicPrefixes, true)) {
            self::$publicPrefixes[] = $prefix;
        }
    }

    public static function clearPublicPrefixes(): void
    {
        self::$publicPrefixes = [];
    }

    /**
     * The `[public:code:status] message` RAISE marker, or null. SELF-CONTAINED on purpose (it reads only
     * $publicPrefixes) so the same rule can be ported to older release lines unchanged.
     *
     * SECURITY. The text after the marker is published to the API client VERBATIM, and a message is spoofable: any
     * interpolated value can carry marker-shaped text. So the marker is accepted only when nothing user-influenced can
     * sit in front of it:
     *  1. a known SQLSTATE must be P0001 (RAISE EXCEPTION); a system error (22P02, 23xxx ...) never carries one;
     *  2. driver prefixes (`SQLSTATE[xxxxx]: `, `<Class text>: <n> ERROR:  `, `ERROR:  `, `pg_query(): Query failed: `)
     *     are stripped ONLY at the very start, in that fixed order. Never search for `ERROR:` anywhere: a value that
     *     contains it would move the extraction point;
     *  3. the primary line ends at the first newline, ` | `, or inline DETAIL:/HINT:/CONTEXT: (never DETAIL / HINT /
     *     CONTEXT themselves, which echo user input and other columns of the row);
     *  4. the line must START with the marker, or with one of the exact literal prefixes registered through
     *     {@see allowPublicPrefix()} followed immediately by the marker. No arbitrary prefix text;
     *  5. the captured text ends at the end of that line (or at ` (SQLSTATE`).
     * AUTHORING RULES: a RAISE must never start with an interpolated value, and a `[public...]` RAISE must never
     * interpolate (see PublicRaiseLint, run by `php stone validate sqlintegrity`). The long-term fix is a structured
     * "public" signal from the gateway (task #10086); a message-text marker is inherently spoofable.
     *
     * @return array{status: ?int, message: string, code: ?string}|null status null = the marker gave none (400)
     */
    private static function publicMarker(string $raw, ?string $sqlstate): ?array
    {
        if ($sqlstate !== null && $sqlstate !== 'P0001') {
            return null;
        }
        // 2. driver prefixes, only at the very start
        $line = $raw;
        $line = preg_replace('/^pg_[a-z_]+\(\): Query failed: /', '', $line) ?? $line;
        $line = preg_replace('/^SQLSTATE\[[0-9A-Za-z]{5}\]:\s*/', '', $line) ?? $line;
        $line = preg_replace('/^[A-Z][A-Za-z ]{2,40}: \d+ (?=ERROR:)/', '', $line) ?? $line;
        $line = preg_replace('/^ERROR:\s+/', '', $line) ?? $line;
        // 3. primary line only
        $cut = strlen($line);
        foreach (["\n", ' | '] as $sep) { // not " - ": that joins migration/seeder causes only, and is legal inside a message
            $i = strpos($line, $sep);
            if ($i !== false && $i < $cut) {
                $cut = $i;
            }
        }
        $line = substr($line, 0, $cut);
        if (preg_match('/\s(?:DETAIL|HINT|CONTEXT|STATEMENT|QUERY):\s/', $line, $d, PREG_OFFSET_CAPTURE) === 1) {
            $line = substr($line, 0, $d[0][1]);
        }
        // 4. marker at the start, or after an exactly registered literal prefix
        foreach (array_merge([''], self::$publicPrefixes) as $prefix) {
            if ($prefix !== '' && !str_starts_with($line, $prefix)) {
                continue;
            }
            $rest = substr($line, strlen($prefix));
            // 5. text bounded by the end of the line / ` (SQLSTATE`
            if (preg_match('/^\[public(?::([a-z][a-z0-9_]*))?(?::([1-4]\d\d))?\]\s*(.+?)(?=\s\(SQLSTATE|$)/s', $rest, $m) === 1 && trim($m[3]) !== '') {
                return ['status' => $m[2] !== '' ? (int) $m[2] : null, 'message' => trim($m[3]), 'code' => $m[1] !== '' ? $m[1] : null];
            }
        }
        return null;
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

    /**
     * The PostgreSQL message when the gateway forwarded one, else the exception chain's text.
     * IN-PROCESS classification only: the result may contain customer data and must never be logged,
     * printed or returned to a client verbatim.
     */
    public static function rawCause(\Throwable $e): string
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            $cause = SafeDbContext::rawCauseOf($cur);
            if ($cause !== null && $cause !== '') {
                return $cause;
            }
        }
        return $e->getMessage();
    }

    /** SQLSTATE of a database failure in the chain (null when unknown). Safe to log. */
    public static function sqlStateOf(\Throwable $e): ?string
    {
        return self::sqlState($e);
    }

    /** SQLSTATE from a PDOException in the chain, or from an embedded `SQLSTATE[xxxxx]`. */
    private static function sqlState(\Throwable $e): ?string
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ($cur instanceof DbTransportException && $cur->getSqlState() !== null) {
                return $cur->getSqlState();
            }
            if ($cur instanceof \PDOException) {
                $info = $cur->errorInfo;
                if (is_array($info) && isset($info[0]) && preg_match('/^[0-9A-Z]{5}$/', (string) $info[0]) === 1) {
                    return (string) $info[0];
                }
            }
            if ($cur instanceof GatewayException && $cur->getSqlState() !== null) {
                return $cur->getSqlState();
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
