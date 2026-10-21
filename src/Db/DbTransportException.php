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
    /**
     * @param string $message
     * @param bool $isConnectionFailure True when the underlying database is
     *   unreachable (connection refused/lost/timed out) — the direct/
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

    /** Raw database/engine error text. Debug and in-process classification ONLY; never output or log it. */
    private ?string $rawCause = null;

    /** @var array<string,string> allow-listed identifier-only diagnostics */
    private array $safe = [];

    public function isConnectionFailure(): bool
    {
        return $this->isConnectionFailure;
    }

    /**
     * Wrap a raw driver failure WITHOUT letting its text escape: the message is rebuilt from allow-listed
     * identifiers (SQLSTATE, constraint, column, table, schema), the driver exception is NOT chained as
     * `previous` (its message and trace would print the raw text), and the raw text is kept privately.
     */
    public static function fromDriverFailure(string $prefix, Throwable $driver, bool $isConnectionFailure): self
    {
        $raw = $driver->getMessage();
        $state = $driver instanceof \PDOException && is_array($driver->errorInfo) && isset($driver->errorInfo[0])
            ? (string) $driver->errorInfo[0] : null;
        $safe = \StoneScriptPHP\Persistence\SafeDbContext::fromText($raw, $state);
        if ($isConnectionFailure) {
            // Connection-level failures (refused, lost, engine not initialised) carry no row values, and their text
            // is what an operator needs. It still goes through the best-effort scrubber.
            $text = \StoneScriptPHP\Persistence\LogSanitizer::forLog($raw);
            $e = new self($prefix . ': ' . $text, true);
        } else {
            // Query-level failures: rebuilt from allow-listed identifiers only. `SQLSTATE[xxxxx]` keeps its
            // historical position so message-based SQLSTATE detection keeps working.
            $rest = $safe;
            $head = $prefix;
            if (isset($safe['sqlstate'])) {
                $head .= ': SQLSTATE[' . $safe['sqlstate'] . ']';
                unset($rest['sqlstate']);
            }
            $suffix = \StoneScriptPHP\Persistence\SafeDbContext::suffix($rest);
            $e = new self($head . ($suffix !== '' ? $suffix : (isset($safe['sqlstate']) ? '' : ' (details withheld)')), false);
        }
        $e->rawCause = $raw;
        $e->safe = $safe;
        return $e;
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

    /** DEBUG-ONLY raw driver text (may contain customer data). Never log or print. */
    public function getRawCauseForDebug(): ?string
    {
        return $this->rawCause;
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
        $this->safe = is_array($data['safe'] ?? null) ? $data['safe'] : [];
    }
}
