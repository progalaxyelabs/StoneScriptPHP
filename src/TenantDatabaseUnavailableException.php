<?php

namespace StoneScriptPHP;

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
/**
 * Extends \Error (not \Exception) deliberately.
 *
 * Route handlers use `catch (\Exception $e)` which does NOT catch \Error subclasses.
 * This ensures the exception propagates past route handler try-catch blocks and
 * reaches the Router's dedicated catch clause, which returns the correct HTTP status.
 */
class TenantDatabaseUnavailableException extends \Error
{
    public function __construct(string $message = 'Tenant database unavailable', int $code = 503, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
