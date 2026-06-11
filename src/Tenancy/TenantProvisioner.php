<?php

namespace StoneScriptPHP\Tenancy;

use StoneScriptPHP\Database;

/**
 * Tenant Provisioner (abstract base class)
 *
 * Encapsulates the sequential provisioning flow for per-tenant database isolation:
 *   1. createTenantRecord() — register tenant with auth service via HTTP (POST /api/internal/create-tenant)
 *   2. createDatabase()     — provision tenant DB via gateway /admin/database/create
 *   3. runMigrations()      — run migrations (no-op by default; override if needed)
 *   4. seedData()           — seed initial data (no-op by default; override per platform)
 *
 * Auth membership creation (step 5) is handled by ProvisionTenantRoute after provision()
 * returns, since the auth client lives at the route layer.
 *
 * Usage — extend per platform, override seedData():
 *
 *   class AcmeProvisioner extends TenantProvisioner
 *   {
 *       protected function seedData(array $data): void
 *       {
 *           Database::fn('seed_acme_defaults', [$data['tenant_id']]);
 *       }
 *   }
 *
 *   // In index.php:
 *   'provisioner' => new AcmeProvisioner('acme', $env->SCHEMA_NAME, $env->DB_GATEWAY_URL, $env->DB_GATEWAY_ADMIN_TOKEN),
 */
abstract class TenantProvisioner
{
    public function __construct(
        protected string $platformCode,
        protected string $schemaName,
        protected string $gatewayUrl,
        protected string $adminToken,
        protected string $authServiceUrl = '',      // NEW: auth service HTTP URL
        protected string $platformSecret = '',       // NEW: X-Platform-Secret value
    ) {}

    /**
     * Run the full provisioning sequence.
     *
     * Final — platforms must not override this. Override seedData() or runMigrations() instead.
     *
     * @param array $data Tenant data built by ProvisionTenantRoute (tenant_id, tenant_name, tenant_slug, tenant_db_schema, identity_id, ...)
     * @return array Updated $data (tenant_id and tenant_db_schema may be rewritten by createTenantRecord on retry)
     * @throws \Throwable If any step fails
     */
    final public function provision(array $data): array
    {
        $data = $this->createTenantRecord($data);
        $this->createDatabase($data);
        $this->runMigrations($data);
        $this->seedData($data);
        return $data;
    }

    /**
     * Tenant-record registration step (no-op).
     *
     * The auth server has NO `/api/internal/create-tenant` endpoint — it never did
     * (AUTH-SPEC §5a leaves the mechanism unspecified; the auth server exposes
     * `POST /api/internal/create-membership`, which calls `auth_register_account` and
     * creates BOTH the tenant record AND the owner membership in one call). That
     * create-membership call is already made by ProvisionTenantRoute (step 4, via
     * `$this->client->createMembership($data, $secret)`), so it owns tenant+membership
     * registration end-to-end.
     *
     * The previous implementation POSTed to a non-existent `/api/internal/create-tenant`
     * and 500'd every provision-tenant. This step is therefore a no-op: the platform's
     * generated `tenant_id` / `tenant_db_schema` flow unchanged to createDatabase() and
     * then to the route's create-membership. Override per-platform only if a platform
     * genuinely needs a separate pre-registration step.
     *
     * @return array Unchanged $data.
     */
    protected function createTenantRecord(array $data): array
    {
        return $data;
    }

    /**
     * Notify auth service of updated business profile details.
     * Call from platform API whenever user updates Settings > Business.
     */
    public function syncBusinessDetails(string $tenantId, array $details): void
    {
        $authUrl = rtrim($this->authServiceUrl ?: ($_SERVER['AUTH_SERVICE_URL'] ?? ''), '/');
        $secret  = $this->platformSecret ?: ($_SERVER['EXTERNAL_AUTH_CLIENT_SECRET'] ?? '');

        if (!$authUrl || !$secret) {
            log_error('TenantProvisioner::syncBusinessDetails: AUTH_SERVICE_URL or secret missing — skipping sync');
            return;
        }

        $payload = json_encode(array_merge(['tenant_id' => $tenantId], $details));

        $ch = curl_init("$authUrl/api/internal/sync-tenant");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST   => 'PUT',
            CURLOPT_POSTFIELDS      => $payload,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HTTPHEADER      => [
                'Content-Type: application/json',
                'X-Platform-Secret: ' . $secret,
            ],
            CURLOPT_TIMEOUT         => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            log_error("TenantProvisioner::syncBusinessDetails: HTTP $httpCode: $response");
        }
    }

    /**
     * Provision the tenant database via the gateway admin endpoint.
     *
     * Idempotent — gateway returns 409 if the database already exists; treated as success.
     *
     * @throws \RuntimeException If the gateway returns a non-success, non-409 response
     */
    protected function createDatabase(array $data): void
    {
        $gatewayUrl = rtrim($this->gatewayUrl, '/');
        $payload = json_encode([
            'platform'    => $this->platformCode,
            'schema_name' => $this->schemaName,
            'database_id' => $data['tenant_id'],
        ]);

        $ch = curl_init("$gatewayUrl/admin/database/create");
        curl_setopt_array($ch, [
            CURLOPT_POST          => true,
            CURLOPT_POSTFIELDS    => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER    => array_values(array_filter([
                'Content-Type: application/json',
                $this->adminToken ? "Authorization: Bearer {$this->adminToken}" : null,
            ])),
            CURLOPT_TIMEOUT       => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 409 = database already exists — idempotent, continue to next step
        if ($httpCode === 409) {
            log_info("TenantProvisioner: Gateway DB already exists (HTTP 409) for {$data['tenant_id']} — treating as success");
            return;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            log_error("TenantProvisioner: Gateway DB provision failed (HTTP $httpCode): $response");
            throw new \RuntimeException("Failed to provision tenant database (HTTP $httpCode)");
        }

        log_info("TenantProvisioner: Provisioned database for tenant {$data['tenant_id']} slug={$data['tenant_slug']}");
    }

    /**
     * Run migrations on the tenant database.
     *
     * No-op by default — override if your platform needs explicit migration triggering
     * beyond what the gateway /admin/database/create endpoint already handles.
     */
    protected function runMigrations(array $data): void
    {
        // No-op — gateway create endpoint handles schema setup by default.
    }

    /**
     * Seed initial data for the new tenant.
     *
     * No-op by default — override in platform-specific provisioners.
     * Non-fatal failures should be caught and logged inside the override so that
     * a seed error does not roll back the entire provisioning flow.
     */
    protected function seedData(array $data): void
    {
        // No-op by default.
    }
}
