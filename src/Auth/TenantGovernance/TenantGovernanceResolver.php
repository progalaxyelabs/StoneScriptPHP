<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\TenantGovernance;

use StoneScriptPHP\Database;

/**
 * TenantGovernanceResolver — the framework-shipped default implementation of
 * the `tenants_resolver`/`roles_resolver` closures that ExchangeRoute
 * (StoneScriptPHP\Auth\ExternalAuth\Routes\ExchangeRoute) expects.
 *
 * Ships IN the framework (unlike the table + twelve SQL functions, which are
 * scaffolded per-platform by `php stone generate tenant-governance`) because
 * this logic never varies platform to platform — only the schema it queries
 * does, and that schema is fixed by the generated tenant-governance tables.
 * A platform wires it in config/auth.php with two lines:
 *
 *   $governance = new TenantGovernanceResolver();
 *   return [
 *       // ...
 *       'tenants_resolver' => $governance->tenantsResolver(),
 *       'roles_resolver'   => $governance->rolesResolver(),
 *   ];
 *
 * Both closures read from the platform's OWN main DB via
 * `get_identity_tenant_memberships()` / `resolve_role_id()` — zero dependency
 * on the external auth service's membership response beyond the identity id,
 * which is already on the verified auth-token claims. This is the whole
 * point of platform-owned governance: roles belong to the platform, not auth.
 *
 * ## Display-name enrichment (why the constructor takes an optional callable)
 *
 * `get_identity_tenant_memberships()` returns tenant_ids + governance flags,
 * but NOT a tenant's display name/slug — those live on the platform's OWN
 * `tenants` table, whose column names differ per platform (biz_name vs name
 * vs store_name, biz_slug vs slug, ...). The framework can't universally JOIN
 * to fetch them. So the default `tenantsResolver()` returns each tenant as
 * `{id, role, is_tenant_creator, is_tenant_owner, is_tenant_admin, job_role}`
 * — functionally complete for access control and API-token issuance, but with no
 * human-readable name. A platform that wants names in `available_tenants`
 * passes a `$tenantEnricher` closure to the constructor; it receives the
 * governance rows (each already carrying `id`) and returns them enriched with
 * whatever display fields that platform's frontend needs. Keeping this a
 * platform-injected closure — rather than a second hard-coded query — is what
 * keeps this class platform-agnostic.
 */
final class TenantGovernanceResolver
{
    /**
     * @param (callable(array<int,array<string,mixed>>): array<int,array<string,mixed>>)|null $tenantEnricher
     *   Optional. Receives the list of governance tenant rows (each with at
     *   least an `id` key) and returns them enriched with display fields
     *   (name, slug, ...) from the platform's own `tenants` table. If null,
     *   tenants are returned id-only (plus governance flags + derived role).
     */
    public function __construct(
        private $tenantEnricher = null
    ) {
    }

    /**
     * fn(array $authClaims): array<int, array{id: string, role: string, ...}>
     *
     * Every tenant the identity holds an ACTIVE membership in, on this
     * platform. Shape matches ExchangeRoute's contract: each entry MUST carry
     * an `id`; ExchangeRoute verifies the requested tenant_id against these
     * ids (403 `tenant_access_denied` if absent) and echoes the matching
     * entry back as `active_tenant`.
     */
    public function tenantsResolver(): callable
    {
        return function (array $authClaims): array {
            $identityId = self::identityIdFromClaims($authClaims);
            if ($identityId === null) {
                return [];
            }

            $rows = self::queryMainDb('get_identity_tenant_memberships', ['identity_id' => $identityId]);
            if (!is_array($rows)) {
                return [];
            }

            $tenants = [];
            foreach ($rows as $row) {
                $isCreator = (bool) self::col($row, 'is_tenant_creator');
                $isOwner   = (bool) self::col($row, 'is_tenant_owner');
                $isAdmin   = (bool) self::col($row, 'is_tenant_admin');

                $tenants[] = [
                    'id'                => (string) self::col($row, 'tenant_id'),
                    'role'              => self::deriveRole($isOwner, $isAdmin),
                    'is_tenant_creator' => $isCreator,
                    'is_tenant_owner'   => $isOwner,
                    'is_tenant_admin'   => $isAdmin,
                    'job_role'          => self::col($row, 'job_role'),
                ];
            }

            if ($this->tenantEnricher !== null) {
                $enriched = ($this->tenantEnricher)($tenants);
                if (is_array($enriched)) {
                    return $enriched;
                }
            }

            return $tenants;
        };
    }

    /**
     * fn(array $claimsWithTenant): array<int, string>
     *
     * The identity's single derived role in the requested tenant, as a
     * one-element list (ExchangeRoute's roles_resolver contract — an empty
     * list means "no roles in this tenant" → 403 `no_roles_in_tenant`).
     * `$claimsWithTenant` is the auth-token claims with `tenant_id` merged in
     * (ExchangeRoute does that merge before calling this).
     *
     * A suspended/removed membership resolves to NULL in SQL
     * (resolve_role_id → _tenant_membership_tier), which becomes an empty
     * list here — so a suspended member correctly cannot exchange for an API token.
     */
    public function rolesResolver(): callable
    {
        return function (array $claimsWithTenant): array {
            $identityId = self::identityIdFromClaims($claimsWithTenant);
            $tenantId   = isset($claimsWithTenant['tenant_id']) ? (string) $claimsWithTenant['tenant_id'] : '';
            if ($identityId === null || $tenantId === '') {
                return [];
            }

            $rows = self::queryMainDb('resolve_role_id', [
                'identity_id' => $identityId,
                'tenant_id'   => $tenantId,
            ]);
            if (!is_array($rows) || $rows === []) {
                return [];
            }

            $role = self::col($rows[0], 'role_id');

            return ($role === null || $role === '') ? [] : [(string) $role];
        };
    }

    /**
     * Call a governance function against the MAIN DB.
     *
     * `get_identity_tenant_memberships` / `resolve_role_id` live in the
     * platform's main DB, but these resolvers run inside the exchange flow,
     * which can carry a NON-null gateway tenant context (a prior tenant-scoped
     * call on the same PHP-FPM worker, or T3 tenant-URL middleware). Without
     * forcing the context to null, the gateway routes the call to a tenant DB
     * where the function doesn't exist — surfacing as
     * "function ...(unknown) does not exist" / a 500 in tenants_resolver. So
     * every call here pins tenant context to null first, then restores the
     * prior context, exactly as a platform's own main-DB resolvers must
     * (mirrors the same pattern platforms use for their own tenants tables).
     *
     * The context switch is itself best-effort and SEPARATE from the query: if
     * the gateway client can't be obtained (fake mode in tests, or an
     * early-boot state), the query is still issued — Database::fn handles its
     * own routing/fake-mode. This keeps the resolver unit-testable via
     * Database::fake() without a live gateway.
     *
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private static function queryMainDb(string $function, array $params): array
    {
        $gw = null;
        $prev = null;
        try {
            $gw = Database::getGatewayClient();
            $prev = $gw->getTenantId();
            $gw->setTenantId(null);
        } catch (\Throwable $e) {
            $gw = null; // no client available for context management — proceed
        }

        try {
            $rows = Database::fn($function, $params);
            return is_array($rows) ? $rows : [];
        } finally {
            if ($gw !== null) {
                try {
                    $gw->setTenantId($prev);
                } catch (\Throwable $e) {
                    // best-effort restore
                }
            }
        }
    }

    /**
     * Extract the identity id from auth-token claims. An auth token carries the
     * identity id as both `sub` and `identity_id` (the external auth service
     * mints both); prefer `sub` (the JWT-standard subject),
     * fall back to `identity_id`.
     */
    private static function identityIdFromClaims(array $claims): ?string
    {
        $id = $claims['sub'] ?? $claims['identity_id'] ?? null;
        if ($id === null) {
            return null;
        }
        $id = (string) $id;
        return $id === '' ? null : $id;
    }

    /**
     * Read a column from a Database::fn() result row, tolerating both the
     * `o_`-prefixed gateway output key (the canonical shape these functions
     * emit — SPEC.md "Output Column Naming") and a bare unprefixed key (in
     * case a platform hand-edits a generated function to drop the prefix).
     */
    private static function col(array $row, string $name): mixed
    {
        if (array_key_exists('o_' . $name, $row)) {
            return $row['o_' . $name];
        }
        return $row[$name] ?? null;
    }

    /**
     * The governance-tier → role-string mapping ExchangeRoute stamps onto the
     * API token's `role_id` claim. Mirrors resolve_role_id.pgsql /
     * _tenant_membership_tier exactly (owner > admin > member) so the two
     * derivation sites can never disagree.
     */
    private static function deriveRole(bool $isOwner, bool $isAdmin): string
    {
        if ($isOwner) {
            return 'owner';
        }
        if ($isAdmin) {
            return 'admin';
        }
        return 'member';
    }
}
