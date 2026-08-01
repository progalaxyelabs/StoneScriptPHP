<?php

declare(strict_types=1);

namespace StoneScriptPHP\Db;

/**
 * The pluggable database transport contract behind Database::fn().
 *
 * Selected via Env::$DB_MODE (gateway | direct | pgandroid — default
 * gateway). Route handlers and generated model wrappers only ever call
 * Database::fn($name, $params) — they never touch a DbTransport directly.
 * Database resolves ONE transport instance per process (see
 * Database::initConnection()) and dispatches every Database::fn() call
 * through it.
 *
 * Deliberately tiny: just the one call every transport must support, plus a
 * connected? probe. Provisioning-style operations (createDatabase,
 * migrateV2, migrateAllV2, registerPlatform, uploadSchema, tenant-schema
 * routing via setTenantId) stay on GatewayClient itself, reached only via
 * Database::getGatewayClient() when DB_MODE=gateway — those are
 * gateway-specific multi-tenant-schema concepts with no direct/pgandroid
 * equivalent, so they deliberately do NOT belong on this interface.
 */
interface DbTransport
{
    /**
     * Call a database function and return its result rows.
     *
     * @param string $function_name The database function name (no SQL
     *   injection concerns here — this is always a framework-controlled
     *   name from a generated model wrapper, never raw user input).
     * @param array<int|string, mixed> $params Positional or associative
     *   parameters — implementations normalize associative arrays to
     *   positional order, matching GatewayClient::callFunction()'s existing
     *   convention.
     * @return array<int, array<string, mixed>> Result rows.
     * @throws DbTransportException On a transport-level failure (connection
     *   lost/refused, query failure, protocol error). Database::_fn()
     *   translates DbTransportException::isConnectionFailure() === true into
     *   TenantDatabaseUnavailableException (503), matching the existing
     *   GatewayException connection_failed translation — every transport
     *   gets the same 503-on-unreachable behavior.
     */
    public function callFunction(string $function_name, array $params): array;

    /**
     * Whether this transport has completed at least one successful call
     * (or, for transports without a meaningful "connection" concept, is
     * ready to serve calls). Mirrors GatewayClient::isConnected()'s
     * "at least one successful request" semantics.
     */
    public function isConnected(): bool;
}
