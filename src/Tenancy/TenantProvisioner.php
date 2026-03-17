<?php

namespace StoneScriptPHP\Tenancy;

use StoneScriptPHP\Database;

/**
 * Tenant Provisioner (abstract base class)
 *
 * Encapsulates the sequential provisioning flow for per-tenant database isolation:
 *   1. createTenantRecord() — create tenant in central DB via Database::fn('create_tenant')
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
        protected string $adminToken
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
     * Create tenant record in the main database via Database::fn('create_tenant').
     *
     * Idempotent — if the slug already exists the function returns the existing row.
     * Returns updated $data with canonical tenant_id and tenant_db_schema.
     */
    protected function createTenantRecord(array $data): array
    {
        $gw = Database::getGatewayClient();
        $prevTenant = $gw->getTenantId();
        $gw->setTenantId(null); // route to main DB

        try {
            $rows = Database::fn('create_tenant', [
                $data['tenant_id'],
                $data['tenant_name'],
                $data['tenant_slug'],
                $data['tenant_db_schema'],
                $data['identity_id'],
            ]);

            // Use the returned UUID — may differ from $data['tenant_id'] on retry
            $result = $rows[0]['create_tenant'] ?? $rows[0];
            $data['tenant_id'] = $result['uuid'];
            $data['tenant_db_schema'] = $result['biz_db_name'];

            if (!($result['created'] ?? true)) {
                log_info("TenantProvisioner: Tenant slug '{$data['tenant_slug']}' exists — resuming provisioning with UUID {$data['tenant_id']}");
            }
        } finally {
            $gw->setTenantId($prevTenant);
        }

        return $data;
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
