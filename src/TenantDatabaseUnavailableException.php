<?php

namespace StoneScriptPHP;

use Exception;

/**
 * Thrown when the gateway cannot connect to a tenant's database.
 *
 * Gateway error code: connection_failed (HTTP 503)
 *
 * Causes:
 *  - Tenant database was dropped (deleted account, deprovisioned tenant)
 *  - Tenant database is temporarily unreachable (migration in progress, restart)
 *
 * The Router maps this to HTTP 503 with a clean "service unavailable" message
 * instead of leaking an internal 500 to the client.
 */
class TenantDatabaseUnavailableException extends Exception
{
    public function __construct(string $message = 'Tenant database unavailable', int $code = 503, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
