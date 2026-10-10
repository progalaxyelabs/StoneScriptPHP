<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy;

/**
 * The rule a tenant id must satisfy to map one-to-one onto a gateway database name.
 *
 * The gateway derives the database name `{platform}_{schema}_{tenant id}` by sanitising each part
 * (lower-case; every character except [a-z0-9_] becomes `_`; edge underscores trimmed), and
 * PostgreSQL silently truncates identifiers to 63 bytes. Two different ids can therefore land on ONE
 * database. An id is accepted only when the mapping is provably injective:
 *
 *  - lower-case letters and digits in groups separated by SINGLE hyphens (`\A[a-z0-9]+(-[a-z0-9]+)*\z`;
 *    canonical UUIDs match). `_` is rejected outright: ids never contain it, and allowing it would let
 *    `a-b` and `a_b` collide. A trailing newline is not tolerated (`\z`, not `$`).
 *  - the resulting database name fits in 63 bytes (no truncation collision).
 */
final class TenantIdRule
{
    public const MAX_DB_NAME_BYTES = 63;

    /** @return string|null null when acceptable, otherwise a human reason */
    public static function violation(string $tenantId, string $platformCode, string $schemaName): ?string
    {
        if ($tenantId === '') {
            return 'empty id';
        }
        if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $tenantId) !== 1) {
            return 'must be lower-case letters/digits in groups separated by single hyphens (no "_", upper case, spaces, edge or double hyphens, trailing newline)';
        }
        $dbName = self::databaseName($platformCode, $schemaName, $tenantId);
        if (strlen($dbName) > self::MAX_DB_NAME_BYTES) {
            return sprintf('database name "%s" is %d bytes; PostgreSQL truncates identifiers at %d, which can merge different tenants',
                $dbName, strlen($dbName), self::MAX_DB_NAME_BYTES);
        }
        return null;
    }

    /** Mirror of the gateway's database_name() for a tenant database. */
    public static function databaseName(string $platformCode, string $schemaName, string $tenantId): string
    {
        return self::sanitize($platformCode) . '_' . self::sanitize($schemaName) . '_' . self::sanitize($tenantId);
    }

    /** Mirror of the gateway's sanitize_identifier(). */
    public static function sanitize(string $s): string
    {
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            $out .= (ctype_alnum($c) && ord($c) < 128) ? strtolower($c) : ($c === '_' ? '_' : '_');
        }
        return trim($out, '_');
    }
}
