<?php

declare(strict_types=1);

namespace StoneScriptPHP\Db;

use StoneScriptDB\GatewayClient;

/**
 * DB_MODE=gateway (the default) — wraps the existing GatewayClient.
 *
 * Behavior-identical to the pre-transport-refactor Database::_fn(): every
 * call is forwarded to GatewayClient::callFunction() unchanged, and
 * GatewayException propagates untouched so Database::_fn()'s existing
 * catch(GatewayException) branch keeps handling it exactly as before. This
 * class adds NO new error translation of its own — that would risk
 * double-wrapping and breaking the byte-identical parity guardrail.
 */
final class GatewayTransport implements DbTransport
{
    public function __construct(private readonly GatewayClient $client)
    {
    }

    public function callFunction(string $function_name, array $params): array
    {
        // Intentionally NOT caught/wrapped here — GatewayException must
        // propagate as-is so Database::_fn()'s existing catch(GatewayException)
        // block (untouched) keeps translating connection_failed -> 503 exactly
        // as it did before this class existed.
        return $this->client->callFunction($function_name, $params);
    }

    public function isConnected(): bool
    {
        return $this->client->isConnected();
    }

    /**
     * Escape hatch for Database::getGatewayClient() — tenant-schema routing
     * (setTenantId) and provisioning (createDatabase, migrateV2, ...) stay
     * on the real GatewayClient, gateway-mode only. See DbTransport's
     * docblock for why those aren't on the shared interface.
     */
    public function getClient(): GatewayClient
    {
        return $this->client;
    }
}
