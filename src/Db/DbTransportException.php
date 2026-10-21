<?php

declare(strict_types=1);

namespace StoneScriptPHP\Db;

use Throwable;

/**
 * Thrown by non-gateway DbTransport implementations (DirectTransport,
 * PgandroidTransport) when a Database::fn() call fails.
 *
 * This is the transport-agnostic equivalent of GatewayException: it lets
 * Database::_fn() apply the exact same "connection failure -> 503" policy
 * to every transport without a parallel error-handling path per mode.
 * GatewayException itself is untouched — gateway mode keeps using it
 * directly, unchanged, so its behavior stays byte-identical to before this
 * class existed.
 */
class DbTransportException extends \Exception
{
    /** @var \WeakMap<DbTransportException,string>|null raw driver text, kept OFF the exception object */
    private static ?\WeakMap $rawCauses = null;

    /** @var array<string,string> allow-listed identifier-only diagnostics */
    private array $safe = [];

    /**
     * @param string $message
     * @param bool $isConnectionFailure True when the underlying database is
     *   unreachable (connection refused/lost/timed out) - the direct/
     *   pgandroid analogue of the gateway's `connection_failed` error code.
     *   False for a query-level failure (bad SQL, business-rule exception,
     *   constraint violation) that reached a live connection.
     * @param int $code
     * @param Throwable|null $previous
     */
    public function __construct(
        string $message,
        private readonly bool $isConnectionFailure = false,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function isConnectionFailure(): bool
    {
        return $this->isConnectionFailure;
    }

    /**
     * Wrap a raw driver failure WITHOUT letting its text escape. The driver message never enters this exception's
     * message: a connection failure gets a fixed sentence (plus SQLSTATE / reason), a query failure gets
     * allow-listed identifiers (SQLSTATE, constraint, column, table, application error code) parsed from the
     * PostgreSQL primary line only. The driver exception is NOT chained as `previous`, and the raw text is kept in
     * a weak registry (not a property) reachable only via {@see getRawCauseForDebug()}.
     */
    public static function fromDriverFailure(string $prefix, Throwable $driver, bool $isConnectionFailure): self
    {
        $raw = $driver->getMessage();
        $state = $driver instanceof \PDOException && is_array($driver->errorInfo) && isset($driver->errorInfo[0])
            ? (string) $driver->errorInfo[0] : null;
        $safe = \StoneScriptPHP\Persistence\SafeDbContext::fromText($raw, $state);
        if ($driver instanceof EngineUnavailableException) {
            $safe['reason'] = $driver->reason();
        }

        if ($isConnectionFailure) {
            $head = $prefix . ': database unavailable';
            $rest = array_intersect_key($safe, ['sqlstate' => 1, 'reason' => 1]);
        } else {
            // `SQLSTATE[xxxxx]` keeps its historical position so message-based detection keeps working.
            $head = $prefix;
            $rest = $safe;
            if (isset($safe['sqlstate'])) {
                $head .= ': SQLSTATE[' . $safe['sqlstate'] . ']';
                unset($rest['sqlstate']);
            }
        }
        $suffix = self::suffixOf($rest);
        $e = new self($head . ($suffix !== '' ? $suffix : ($isConnectionFailure || isset($safe['sqlstate']) ? '' : ' (details withheld)')), $isConnectionFailure);
        self::$rawCauses ??= new \WeakMap();
        self::$rawCauses[$e] = $raw;
        $e->safe = $isConnectionFailure ? array_intersect_key($safe, ['sqlstate' => 1, 'reason' => 1]) : $safe;
        return $e;
    }

    /** @param array<string,string> $ctx */
    private static function suffixOf(array $ctx): string
    {
        $parts = [];
        if (isset($ctx['reason'])) {
            $parts[] = 'reason=' . $ctx['reason'];
            unset($ctx['reason']);
        }
        $std = \StoneScriptDB\GatewayException::formatSafeSuffix($ctx);
        if ($std !== '') {
            $parts[] = trim($std, ' []');
        }
        return $parts === [] ? '' : ' [' . implode(' ', $parts) . ']';
    }

    /** @return array<string,string> */
    public function getSafeContext(): array
    {
        return $this->safe;
    }

    public function getSqlState(): ?string
    {
        return $this->safe['sqlstate'] ?? null;
    }

    /** Application error code a function raised (see GatewayException), else null. Safe to log. */
    public function getAppErrorCode(): ?string
    {
        return $this->safe['app_code'] ?? null;
    }

    /** DEBUG-ONLY raw driver text (may contain customer data). Never log or print. */
    public function getRawCauseForDebug(): ?string
    {
        return self::$rawCauses[$this] ?? null;
    }

    public function __toString(): string
    {
        $out = static::class . ': ' . $this->getMessage() . ' in ' . $this->getFile() . ':' . $this->getLine();
        foreach ($this->getTrace() as $i => $f) {
            $out .= "\n#" . $i . ' ' . ($f['file'] ?? '[internal]') . '(' . ($f['line'] ?? 0) . '): '
                . ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '') . '()';
        }
        return $out;
    }

    public function __debugInfo(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'isConnectionFailure' => $this->isConnectionFailure,
            'safe' => $this->safe,
        ];
    }

    public function __serialize(): array
    {
        return ['message' => $this->getMessage(), 'code' => $this->getCode(), 'conn' => $this->isConnectionFailure, 'safe' => $this->safe];
    }

    public function __unserialize(array $data): void
    {
        $this->message = (string) ($data['message'] ?? '');
        $this->code = (int) ($data['code'] ?? 0);
        // Promoted readonly property: uninitialised on an unserialised object, so it can be set exactly once here.
        $this->isConnectionFailure = (bool) ($data['conn'] ?? false);
        $this->safe = is_array($data['safe'] ?? null) ? $data['safe'] : [];
    }
}
