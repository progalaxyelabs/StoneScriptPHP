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

    public function isConnectionFailure(): bool
    {
        return $this->isConnectionFailure;
    }
}
