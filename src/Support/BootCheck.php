<?php

declare(strict_types=1);

namespace StoneScriptPHP\Support;

use StoneScriptPHP\Env;
use StoneScriptPHP\Tenancy\DeclaresTenantRouting;
use StoneScriptPHP\Tenancy\TenancyStrategyInterface;

/**
 * The boot-time configuration checks that decide whether `Application::run()` serves, as plain functions so
 * `php stone env:check` runs EXACTLY the same code and the two cannot drift. Each returns a failure message
 * (variable names only, never values) or null.
 */
final class BootCheck
{
    /** Env-level failures that need no application config. */
    public static function environment(Env $env): ?string
    {
        $failure = Env::bootFailure();
        if ($failure !== null) {
            return $failure instanceof \Exception ? $failure->getMessage() : get_class($failure);
        }
        // Same property the database layer reads (Database::buildGatewayTransport); required by every gateway
        // call but not part of Env's construction-time list, so CLI tools that never touch the database still work.
        if ($env->DB_MODE === 'gateway' && (!isset($env->DB_GATEWAY_SCHEMA_NAME) || trim($env->DB_GATEWAY_SCHEMA_NAME) === '')) {
            return "Required configuration 'DB_GATEWAY_SCHEMA_NAME' is missing or empty (e.g. DB_GATEWAY_SCHEMA_NAME=main).";
        }
        return null;
    }

    /**
     * Does the configuration route requests to per-tenant databases? Based on the options that actually do
     * (`require_api_token`, `store_access`, `tenant_url_match`) and on a strategy that declares it does.
     * A custom strategy that does not implement {@see DeclaresTenantRouting} does not count.
     *
     * @param array<string, mixed> $config Application::run() config
     */
    public static function tenancyDeclared(array $config, ?TenancyStrategyInterface $strategy): bool
    {
        return !empty(($config['require_api_token'] ?? [])['enabled'])
            || !empty(($config['store_access'] ?? [])['enabled'])
            || !empty(($config['tenant_url_match'] ?? [])['enabled'])
            || ($strategy instanceof DeclaresTenantRouting && $strategy->routesTenants());
    }

    public static function tenantSchema(Env $env): ?string
    {
        if ($env->DB_MODE === 'gateway' && trim((string) $env->DB_GATEWAY_TENANT_SCHEMA_NAME) === '') {
            return "Tenancy is in use but 'DB_GATEWAY_TENANT_SCHEMA_NAME' is missing or empty (e.g. DB_GATEWAY_TENANT_SCHEMA_NAME=tenant).";
        }
        return null;
    }
}
