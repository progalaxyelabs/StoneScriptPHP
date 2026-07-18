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
 *
 *   // Optional: pin a specific platform token instead of auto-provisioning one
 *   // on every provision() call (see getPlatformToken() below):
 *   'provisioner' => new AcmeProvisioner('acme', $env->SCHEMA_NAME, $env->DB_GATEWAY_URL, $env->DB_GATEWAY_ADMIN_TOKEN, platformToken: $env->DB_GATEWAY_PLATFORM_TOKEN),
 */
abstract class TenantProvisioner
{
    /**
     * Cached per-platform scoped bearer token (ssdb_pt_...), lazily resolved by
     * getPlatformToken(). Either the explicit $platformToken constructor value,
     * or the result of auto-provisioning via POST /admin/platform-token.
     */
    private ?string $resolvedPlatformToken = null;

    public function __construct(
        protected string $platformCode,
        protected string $schemaName,
        protected string $gatewayUrl,
        protected string $adminToken,
        protected string $authServiceUrl = '',      // NEW: auth service HTTP URL
        protected string $platformSecret = '',       // NEW: X-Platform-Secret value
        protected ?string $platformToken = null,     // NEW: explicit DB_GATEWAY_PLATFORM_TOKEN override
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
     * Split into buildCreateDatabasePayload()/postToGateway() (mirrors the
     * platform-side fix that first uncovered this bug) so the payload shape and the
     * 409/success/failure handling are independently unit testable without a live gateway.
     *
     * IMPORTANT: gateway v4.1.0+ protects POST /admin/database/create with
     * `platform_token_middleware` — it requires a per-platform scoped bearer token
     * (ssdb_pt_...), NOT the shared admin token. Sending the admin token here gets a
     * `403 unknown token` from the gateway (fleet-wide new-tenant-signup blocker fixed
     * in 7.1.3 — see getPlatformToken()). This mirrors the CLI's
     * resolveGatewayPlatformToken()/stepCreateDatabase() in cli/helpers/gateway-common.php,
     * which deploy-manager's register-tenant/migrate-all-tenants steps already use
     * successfully.
     *
     * @throws \RuntimeException If the gateway returns a non-success, non-409 response,
     *                           or if a platform token cannot be resolved/provisioned.
     */
    protected function createDatabase(array $data): void
    {
        $payload = json_encode($this->buildCreateDatabasePayload($data));
        $platformToken = $this->getPlatformToken();
        [$httpCode, $response, $curlErr] = $this->postToGateway('/admin/database/create', $payload, $platformToken);

        if ($response === false) {
            log_error("TenantProvisioner::createDatabase: gateway unreachable for tenant {$data['tenant_id']}: {$curlErr}");
            throw new \RuntimeException("Failed to reach gateway to provision tenant database: {$curlErr}");
        }

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
     * Resolve the per-platform scoped bearer token (ssdb_pt_...) required by
     * gateway v4.1.0+ for platform-scoped admin operations (database create,
     * migrate, migrate-all).
     *
     * Mirrors cli/helpers/gateway-common.php's resolveGatewayPlatformToken() /
     * stepProvisionPlatformToken(), which is the exact flow deploy-manager's
     * gateway:register-tenant / gateway:migrate-all-tenants CLI steps already use
     * successfully in production.
     *
     * Resolution order:
     *   1. Explicit constructor override ($platformToken, e.g. from
     *      Env::DB_GATEWAY_PLATFORM_TOKEN) — preferred, avoids a round trip.
     *   2. Cached value from a prior call within this instance's lifetime
     *      (resolvedPlatformToken) — provision() only calls createDatabase() once,
     *      but subclasses/tests may call getPlatformToken() more than once.
     *   3. Auto-provision via POST /admin/platform-token using the admin token.
     *      Idempotent on the gateway side (upsert — see
     *      stonescriptdb-gateway/src/api/admin_platform_token.rs): a routine
     *      redeploy or repeated provision() calls will NOT rotate an
     *      already-issued token, so this is safe to call on every provision().
     *
     * @throws \RuntimeException If the gateway is unreachable, returns a
     *                           non-2xx response, or omits the token field.
     */
    protected function getPlatformToken(): string
    {
        if ($this->platformToken !== null && $this->platformToken !== '') {
            return $this->platformToken;
        }

        if ($this->resolvedPlatformToken !== null) {
            return $this->resolvedPlatformToken;
        }

        $payload = json_encode(['platform' => $this->platformCode]);
        [$httpCode, $response, $curlErr] = $this->postToGateway('/admin/platform-token', $payload, $this->adminToken);

        if ($response === false) {
            log_error("TenantProvisioner::getPlatformToken: gateway unreachable provisioning platform token for {$this->platformCode}: {$curlErr}");
            throw new \RuntimeException("Failed to reach gateway to provision platform token: {$curlErr}");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            log_error("TenantProvisioner: platform token provisioning failed (HTTP $httpCode): $response");
            throw new \RuntimeException("Failed to provision platform token (HTTP $httpCode)");
        }

        $decoded = json_decode($response, true);
        $token = $decoded['token'] ?? null;
        if (!$token) {
            log_error("TenantProvisioner: gateway returned success but no 'token' field provisioning platform token for {$this->platformCode}");
            throw new \RuntimeException('Gateway returned success but no token field provisioning platform token');
        }

        $this->resolvedPlatformToken = $token;
        return $token;
    }

    /**
     * Build the /admin/database/create payload.
     *
     * IMPORTANT: `uuid` (NOT `database_id`) is the field the gateway's
     * CreateDatabaseRequest struct (stonescriptdb-gateway src/api/database.rs)
     * actually deserializes. It has no `database_id` field, and — until the
     * gateway ships deny_unknown_fields — serde silently drops any unrecognized
     * key instead of erroring. Sending `database_id` here previously left
     * `uuid` at None on every call, so DatabaseRouter::database_name() collapsed
     * every tenant onto the shared `{platform}_{schema_name}` base database
     * instead of provisioning `{platform}_{schema_name}_{uuid}`. Every
     * subsequent provisioning attempt for a different tenant then hit
     * HTTP 409 "already exists" on that same shared DB and was treated as
     * idempotent success — so tenants were marked db_status='active' with a
     * real db_created_at despite having no physical per-tenant database
     * ("ghost tenants"). See the platform-side provisioner override (the
     * platform-side workaround that first diagnosed this) for the full trace.
     */
    protected function buildCreateDatabasePayload(array $data): array
    {
        return [
            'platform'    => $this->platformCode,
            'schema_name' => $this->schemaName,
            'uuid'        => $data['tenant_id'],
        ];
    }

    /**
     * Perform the gateway HTTP POST. Isolated (protected, overridable) so tests
     * can stub the transport and assert on the outgoing payload + exercise the
     * 409/success/failure branches in createDatabase() without a live gateway.
     *
     * @param string      $path        Gateway path, e.g. '/admin/database/create'.
     * @param string      $payload     JSON request body.
     * @param string|null $bearerToken Bearer token for the Authorization header.
     *   Callers MUST pass the correct token for the target endpoint — gateway-wide
     *   admin operations (POST /admin/platform-token) take the shared admin token;
     *   platform-scoped operations (POST /admin/database/create) take the
     *   per-platform token from getPlatformToken(). Defaults to $this->adminToken
     *   for backward compatibility with any direct callers, but createDatabase()
     *   always passes the resolved platform token explicitly.
     * @return array{0:int,1:string|false,2:string} [httpCode, response, curlError]
     */
    protected function postToGateway(string $path, string $payload, ?string $bearerToken = null): array
    {
        $gatewayUrl = rtrim($this->gatewayUrl, '/');
        $token = $bearerToken ?? $this->adminToken;

        $ch = curl_init("$gatewayUrl$path");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array_values(array_filter([
                'Content-Type: application/json',
                $token ? "Authorization: Bearer {$token}" : null,
            ])),
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        return [$httpCode, $response, $curlErr];
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
