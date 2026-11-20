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
    private bool $gatewayVersionResolved = false;
    private ?string $gatewayVersionCache = null;

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
     * (the auth contract leaves the mechanism unspecified; the auth server exposes
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

    // syncBusinessDetails() REMOVED in 15.0.0: it called /api/internal/sync-tenant, which the auth service
    // does not have. Business details live in the platform's own tenants table.

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
     * `403 unknown token` from the gateway (platform-wide new-tenant-signup blocker fixed
     * in 7.1.3 — see getPlatformToken()). This mirrors the CLI's
     * resolveGatewayDeployToken()/stepCreateDatabase() in cli/helpers/gateway-common.php,
     * which deploy-manager's register-tenant/migrate-all-tenants steps already use
     * successfully.
     *
     * @throws \RuntimeException If the gateway returns a non-success, non-409 response,
     *                           or if a platform token cannot be resolved/provisioned.
     */
    protected function createDatabase(array $data): void
    {
        $this->assertTenantIdSafeForGateway((string) ($data['tenant_id'] ?? ''));

        $payload = json_encode($this->buildCreateDatabasePayload($data));
        $platformToken = $this->getPlatformToken();
        [$httpCode, $response, $curlErr] = $this->postToGateway('/admin/database/create', $payload, $platformToken);

        if ($response === false) {
            log_error("TenantProvisioner::createDatabase: gateway unreachable for tenant {$data['tenant_id']}: {$curlErr}");
            throw new \RuntimeException("Failed to reach gateway to provision tenant database: {$curlErr}");
        }

        // Act on the gateway's error CODE, not just the status (gateway 4.7+ contract):
        //   409 database_already_exists   complete database (identity verified by gateway >= 4.7.0) -> idempotent success
        //   409 database_exists_legacy    pre-4.7 database, identity unverifiable -> success + warning
        //   409 (no/older body)           older gateway plain 409 -> treated as legacy
        //   409|422 database_name_collision  belongs to a DIFFERENT tenant -> hard failure
        //   any other 409 code            unknown -> fail closed
        //   non-2xx otherwise             failure (incl. 500 database_incomplete_* / registry errors)
        $code = self::gatewayErrorCode((string) $response);

        if ($code === 'database_name_collision') {
            log_error("TenantProvisioner: gateway reports database NAME COLLISION for tenant {$data['tenant_id']} (HTTP $httpCode): the database belongs to a different tenant. Not provisioning.");
            throw new \RuntimeException('Tenant database name collides with a different tenant; refusing to provision (HTTP ' . $httpCode . ')');
        }

        if ($httpCode === 409) {
            if ($code === 'database_already_exists') {
                log_info("TenantProvisioner: Gateway DB already exists for {$data['tenant_id']} - treating as success"
                    . ($this->gatewayVerifiesIdentity() ? ' (gateway verified the database identity)' : ' (gateway version unknown or < 4.7.0: identity not verified)'));
                return;
            }
            if ($code === null || $code === 'database_exists_legacy') {
                log_warning("TenantProvisioner: Gateway DB for {$data['tenant_id']} pre-dates identity verification (HTTP 409" . ($code !== null ? " $code" : ', no error code - older gateway') . ') - treating as success, identity NOT verified');
                return;
            }
            log_error("TenantProvisioner: unknown gateway 409 code '$code' for tenant {$data['tenant_id']} - failing closed");
            throw new \RuntimeException("Failed to provision tenant database (HTTP 409 $code)");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            // Code + status only: the response body can echo identifiers or database text and is never logged.
            log_error("TenantProvisioner: Gateway DB provision failed (HTTP $httpCode" . ($code !== null ? " $code" : '') . ')');
            throw new \RuntimeException("Failed to provision tenant database (HTTP $httpCode)");
        }

        log_info("TenantProvisioner: Provisioned database for tenant {$data['tenant_id']}");
    }

    /** The gateway's `error` code from a JSON error body, or null when absent/unparseable. */
    protected static function gatewayErrorCode(string $body): ?string
    {
        $decoded = json_decode($body, true);
        $code = is_array($decoded) ? ($decoded['error'] ?? null) : null;
        // Only a machine code counts; prose from an older gateway ("database already exists") is "no code".
        return is_string($code) && preg_match('/^[a-z][a-z0-9_]*$/', $code) === 1 ? $code : null;
    }

    /**
     * Reject, before calling the gateway, every tenant id that does not map one-to-one onto a database
     * name (see {@see TenantIdRule}): the gateway sanitises ids, so different ids can land on one database.
     *
     * @throws \RuntimeException
     */
    protected function assertTenantIdSafeForGateway(string $tenantId): void
    {
        $why = TenantIdRule::violation($tenantId, $this->platformCode, $this->schemaName);
        if ($why !== null) {
            log_error('TenantProvisioner: tenant id rejected before calling the gateway - ' . $why);
            throw new \RuntimeException('Tenant id is not safe to map to a database name: ' . $why);
        }
    }

    /**
     * Gateway version from GET /health (cached per instance), or null when it cannot be learned.
     * Identity verification of existing databases exists from gateway 4.7.0.
     */
    protected function gatewayVersion(): ?string
    {
        if ($this->gatewayVersionResolved) {
            return $this->gatewayVersionCache;
        }
        $this->gatewayVersionResolved = true;
        $ch = curl_init(rtrim($this->gatewayUrl, '/') . '/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $body = curl_exec($ch);
        $decoded = is_string($body) ? json_decode($body, true) : null;
        $v = is_array($decoded) ? ($decoded['version'] ?? null) : null;
        return $this->gatewayVersionCache = (is_string($v) && $v !== '' ? $v : null);
    }

    private function gatewayVerifiesIdentity(): bool
    {
        $v = $this->gatewayVersion();
        return $v !== null && version_compare(ltrim($v, 'v'), '4.7.0', '>=');
    }

    /**
     * The platform's runtime token (ssdb_pt_...), needed by gateway v4.1.0+ to create a tenant
     * database. It comes from the constructor ($platformToken, e.g. Env::DB_GATEWAY_PLATFORM_TOKEN).
     *
     * There is no fallback that mints one with the admin token: an application server must not
     * hold or use the admin token, and doing so would undo the token scopes. A missing runtime
     * token is a configuration error; issue it from the workstation with
     * `php stone gateway:provision-token` and set DB_GATEWAY_PLATFORM_TOKEN for the application.
     *
     * @throws \RuntimeException If no runtime token is configured.
     */
    protected function getPlatformToken(): string
    {
        if ($this->platformToken !== null && $this->platformToken !== '') {
            return $this->platformToken;
        }

        log_error("TenantProvisioner: DB_GATEWAY_PLATFORM_TOKEN is not set for platform {$this->platformCode}");
        throw new \RuntimeException(
            'DB_GATEWAY_PLATFORM_TOKEN is not set. Issue the runtime token from the workstation '
            . '(php stone gateway:provision-token) and configure it for the application; '
            . 'the application does not mint tokens with the admin token.'
        );
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
