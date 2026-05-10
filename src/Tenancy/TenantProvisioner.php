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
     * Register tenant with auth service via HTTP (POST /api/internal/create-tenant).
     *
     * Replaces the previous direct DB write via Database::fn('create_tenant') which
     * violated the cross-service DB boundary. Auth service now owns the tenant record.
     *
     * Idempotent — if the slug already exists for the same identity, auth returns
     * {created: false, tenant_id, tenant_db_schema}. Different identity with same slug
     * returns 422 (ValidationException).
     *
     * Returns updated $data with canonical tenant_id and tenant_db_schema from auth service.
     *
     * @throws \RuntimeException If AUTH_SERVICE_URL or EXTERNAL_AUTH_CLIENT_SECRET missing
     * @throws \StoneScriptPHP\Exceptions\ValidationException If slug/name already taken by different identity
     */
    protected function createTenantRecord(array $data): array
    {
        $authUrl = rtrim($this->authServiceUrl ?: ($_SERVER['AUTH_SERVICE_URL'] ?? ''), '/');
        $secret  = $this->platformSecret ?: ($_SERVER['EXTERNAL_AUTH_CLIENT_SECRET'] ?? '');

        if (!$authUrl || !$secret) {
            throw new \RuntimeException('TenantProvisioner: AUTH_SERVICE_URL and EXTERNAL_AUTH_CLIENT_SECRET are required');
        }

        $payload = json_encode([
            'tenant_id'        => $data['tenant_id'],
            'tenant_name'      => $data['tenant_name'],
            'tenant_slug'      => $data['tenant_slug'],
            'tenant_db_schema' => $data['tenant_db_schema'],
            'identity_id'      => $data['identity_id'],
            'platform_code'    => $data['platform_code'],
            'biz_type'         => $data['biz_type'] ?? null,
            // business profile fields (all optional)
            'address'          => $data['address'] ?? null,
            'city'             => $data['city'] ?? null,
            'state'            => $data['state'] ?? null,
            'pincode'          => $data['pincode'] ?? null,
            'country'          => $data['country'] ?? null,
            'phone'            => $data['phone'] ?? null,
            'email'            => $data['email'] ?? null,
        ]);

        $ch = curl_init("$authUrl/api/internal/create-tenant");
        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $payload,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HTTPHEADER      => [
                'Content-Type: application/json',
                'X-Platform-Secret: ' . $secret,
            ],
            CURLOPT_TIMEOUT         => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            log_error("TenantProvisioner: create-tenant HTTP $httpCode: $response");
            $decoded = json_decode($response, true);
            $msg = $decoded['message'] ?? 'Failed to register tenant with auth service';
            // Preserve ValidationException semantics for duplicate slug/name
            if ($httpCode === 422) {
                throw new \StoneScriptPHP\Exceptions\ValidationException(
                    $decoded['errors'] ?? [],
                    $msg
                );
            }
            throw new \RuntimeException($msg);
        }

        $result = json_decode($response, true);
        if (!isset($result['data']['tenant_id'])) {
            throw new \RuntimeException('TenantProvisioner: auth create-tenant returned no tenant_id');
        }

        $data['tenant_id']        = $result['data']['tenant_id'];
        $data['tenant_db_schema'] = $result['data']['tenant_db_schema'];

        log_info("TenantProvisioner: Registered tenant {$data['tenant_id']} slug={$data['tenant_slug']} via auth HTTP");
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
