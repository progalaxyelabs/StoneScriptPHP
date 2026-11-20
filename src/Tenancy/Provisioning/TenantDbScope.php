<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

use StoneScriptPHP\Database;

/**
 * Runs a callable with Database::fn() routed to one tenant database (the gateway client's tenant id) and
 * always restores the previous routing. Under Database::fake() there is no gateway client and nothing to
 * route, so the callable simply runs.
 */
final class TenantDbScope
{
    private function __construct()
    {
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public static function run(string $tenantId, callable $work): mixed
    {
        $outer = self::$current;
        self::$current = $tenantId;
        try {
            if (Database::isFaked()) {
                return $work();
            }
            $gateway = Database::getGatewayClient();
            $previous = $gateway->getTenantId();
            $gateway->setTenantId($tenantId);
            try {
                return $work();
            } finally {
                $gateway->setTenantId(is_string($previous) ? $previous : null);
            }
        } finally {
            self::$current = $outer;
        }
    }

    /** The tenant id the innermost running scope routes to, or null outside any scope (useful for logs and test doubles). */
    public static function current(): ?string
    {
        return self::$current;
    }

    /**
     * The tenant the current request is routed to: the innermost running scope, else the gateway client's tenant id
     * (set by the tenant-routing middleware). Null when neither exists (or the gateway client is not available).
     */
    public static function routedTenantId(): ?string
    {
        if (self::$current !== null) {
            return self::$current;
        }
        if (Database::isFaked()) {
            return null;
        }
        try {
            $id = Database::getGatewayClient()->getTenantId();
        } catch (\Throwable) {
            return null;
        }
        return is_string($id) && $id !== '' ? $id : null;
    }

    private static ?string $current = null;
}
