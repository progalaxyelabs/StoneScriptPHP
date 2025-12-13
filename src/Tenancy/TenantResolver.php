<?php

namespace Framework\Tenancy;

use Framework\Auth\AuthContext;

/**
 * Tenant Resolver
 *
 * Resolves the current tenant from various sources:
 * - JWT token (tenant_id, tenant_uuid, tenant_slug claims)
 * - HTTP Header (X-Tenant-ID, X-Tenant-UUID, X-Tenant-Slug)
 * - Subdomain ({tenant}.example.com)
 * - Domain mapping (tenant.com -> tenant record)
 * - Route parameter (/tenant/{slug}/...)
 *
 * Strategies are tried in order until one succeeds.
 */
class TenantResolver
{
    /**
     * Create a new TenantResolver
     *
     * @param \PDO|null $authDb Optional database connection to central auth DB for lookups
     * @param array $strategies Resolution strategies in priority order
     * @param string|null $tenantTable Table name in auth DB containing tenant records
     */
    public function __construct(
        private ?\PDO $authDb = null,
        private array $strategies = ['jwt', 'header', 'subdomain'],
        private ?string $tenantTable = 'tenants'
    ) {}

    /**
     * Resolve tenant from request
     *
     * Tries each strategy in order until one succeeds
     *
     * @param array $request Request array containing headers, params, etc.
     * @return Tenant|null Resolved tenant or null if not found
     */
    public function resolve(array $request): ?Tenant
    {
        foreach ($this->strategies as $strategy) {
            $tenant = match ($strategy) {
                'jwt' => $this->resolveFromJWT($request),
                'header' => $this->resolveFromHeader($request),
                'subdomain' => $this->resolveFromSubdomain($request),
                'domain' => $this->resolveFromDomain($request),
                'route' => $this->resolveFromRoute($request),
                default => null
            };

            if ($tenant !== null) {
                return $tenant;
            }
        }

        return null;
    }

    /**
     * Resolve tenant from JWT token claims
     *
     * Checks if user is authenticated and JWT contains tenant information
     *
     * @param array $request
     * @return Tenant|null
     */
    private function resolveFromJWT(array $request): ?Tenant
    {
        // Check if user is authenticated with JWT
        $user = AuthContext::getUser();
        if (!$user) {
            return null;
        }

        // Check if JWT has tenant claims
        $customClaims = $user->customClaims ?? [];

        // Check for tenant_id or tid
        $tenantId = $customClaims['tenant_id'] ?? $customClaims['tid'] ?? $user->tenant_id ?? null;

        if ($tenantId === null) {
            return null;
        }

        try {
            // Build payload from JWT claims
            $payload = [
                'tenant_id' => $tenantId,
                'tenant_uuid' => $customClaims['tenant_uuid'] ?? $customClaims['tuid'] ?? null,
                'tenant_slug' => $customClaims['tenant_slug'] ?? $customClaims['tslug'] ?? null,
                'tenant_db' => $customClaims['tenant_db'] ?? $customClaims['tdb'] ?? null,
            ];

            // Add any other tenant_* claims to payload
            foreach ($customClaims as $key => $value) {
                if (str_starts_with($key, 'tenant_')) {
                    $payload[$key] = $value;
                }
            }

            return Tenant::fromJWT($payload);
        } catch (\Exception $e) {
            log_error("TenantResolver: Failed to resolve from JWT - {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Resolve tenant from HTTP headers
     *
     * Checks headers:
     * - X-Tenant-ID
     * - X-Tenant-UUID
     * - X-Tenant-Slug
     *
     * @param array $request
     * @return Tenant|null
     */
    private function resolveFromHeader(array $request): ?Tenant
    {
        $headers = $request['headers'] ?? [];

        // Try X-Tenant-ID
        $tenantId = $headers['X-Tenant-ID'] ?? $headers['x-tenant-id'] ?? null;
        if ($tenantId) {
            return $this->lookupTenantById($tenantId);
        }

        // Try X-Tenant-UUID
        $tenantUuid = $headers['X-Tenant-UUID'] ?? $headers['x-tenant-uuid'] ?? null;
        if ($tenantUuid) {
            return $this->lookupTenantByUuid($tenantUuid);
        }

        // Try X-Tenant-Slug
        $tenantSlug = $headers['X-Tenant-Slug'] ?? $headers['x-tenant-slug'] ?? null;
        if ($tenantSlug) {
            return $this->lookupTenantBySlug($tenantSlug);
        }

        return null;
    }

    /**
     * Resolve tenant from subdomain
     *
     * Extracts tenant slug from subdomain: {tenant}.example.com
     *
     * @param array $request
     * @return Tenant|null
     */
    private function resolveFromSubdomain(array $request): ?Tenant
    {
        $host = $request['headers']['Host'] ?? $request['headers']['host'] ?? $_SERVER['HTTP_HOST'] ?? null;

        if (!$host) {
            return null;
        }

        // Extract subdomain
        $parts = explode('.', $host);

        // Need at least 3 parts for subdomain (e.g., tenant.example.com)
        if (count($parts) < 3) {
            return null;
        }

        $subdomain = $parts[0];

        // Skip common subdomains
        if (in_array($subdomain, ['www', 'api', 'admin', 'app'])) {
            return null;
        }

        return $this->lookupTenantBySlug($subdomain);
    }

    /**
     * Resolve tenant from domain mapping
     *
     * Looks up tenant by full domain (e.g., tenant.com)
     *
     * @param array $request
     * @return Tenant|null
     */
    private function resolveFromDomain(array $request): ?Tenant
    {
        $host = $request['headers']['Host'] ?? $request['headers']['host'] ?? $_SERVER['HTTP_HOST'] ?? null;

        if (!$host) {
            return null;
        }

        // Remove port if present
        $domain = explode(':', $host)[0];

        return $this->lookupTenantByDomain($domain);
    }

    /**
     * Resolve tenant from route parameter
     *
     * Extracts tenant from route params: /tenant/{slug}/...
     *
     * @param array $request
     * @return Tenant|null
     */
    private function resolveFromRoute(array $request): ?Tenant
    {
        $params = $request['params'] ?? [];

        // Try common parameter names
        $tenantSlug = $params['tenant'] ?? $params['tenant_slug'] ?? $params['slug'] ?? null;

        if ($tenantSlug) {
            return $this->lookupTenantBySlug($tenantSlug);
        }

        return null;
    }

    /**
     * Lookup tenant by ID from database
     *
     * @param int|string $id
     * @return Tenant|null
     */
    private function lookupTenantById(int|string $id): ?Tenant
    {
        if (!$this->authDb || !$this->tenantTable) {
            return null;
        }

        try {
            $stmt = $this->authDb->prepare("SELECT * FROM {$this->tenantTable} WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $row ? Tenant::fromDatabase($row) : null;
        } catch (\Exception $e) {
            log_error("TenantResolver: Failed to lookup tenant by ID - {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Lookup tenant by UUID from database
     *
     * @param string $uuid
     * @return Tenant|null
     */
    private function lookupTenantByUuid(string $uuid): ?Tenant
    {
        if (!$this->authDb || !$this->tenantTable) {
            return null;
        }

        try {
            $stmt = $this->authDb->prepare("SELECT * FROM {$this->tenantTable} WHERE uuid = ? LIMIT 1");
            $stmt->execute([$uuid]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $row ? Tenant::fromDatabase($row) : null;
        } catch (\Exception $e) {
            log_error("TenantResolver: Failed to lookup tenant by UUID - {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Lookup tenant by slug from database
     *
     * @param string $slug
     * @return Tenant|null
     */
    private function lookupTenantBySlug(string $slug): ?Tenant
    {
        if (!$this->authDb || !$this->tenantTable) {
            return null;
        }

        try {
            $stmt = $this->authDb->prepare("SELECT * FROM {$this->tenantTable} WHERE slug = ? OR biz_slug = ? LIMIT 1");
            $stmt->execute([$slug, $slug]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $row ? Tenant::fromDatabase($row) : null;
        } catch (\Exception $e) {
            log_error("TenantResolver: Failed to lookup tenant by slug - {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Lookup tenant by domain from database
     *
     * @param string $domain
     * @return Tenant|null
     */
    private function lookupTenantByDomain(string $domain): ?Tenant
    {
        if (!$this->authDb || !$this->tenantTable) {
            return null;
        }

        try {
            $stmt = $this->authDb->prepare("SELECT * FROM {$this->tenantTable} WHERE domain = ? LIMIT 1");
            $stmt->execute([$domain]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $row ? Tenant::fromDatabase($row) : null;
        } catch (\Exception $e) {
            log_error("TenantResolver: Failed to lookup tenant by domain - {$e->getMessage()}");
            return null;
        }
    }
}
