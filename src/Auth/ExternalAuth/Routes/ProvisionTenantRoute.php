<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\ExternalAuth\Routes;

use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;
use StoneScriptPHP\Auth\TokenExchangeService;
use StoneScriptPHP\Auth\TokenExchangeException;
use StoneScriptPHP\Tenancy\TenantProvisioner;
use StoneScriptPHP\Exceptions\ValidationException;
use StoneScriptPHP\Exceptions\FrameworkException;

/**
 * POST {prefix}/provision-tenant (PROTECTED)
 *
 * Creates a tenant for a logged-in user who has an identity but no tenant.
 *
 * Flow:
 *   1. Decode JWT — extract identity_id
 *   2. tenant_name (AUTH-SPEC §5a — the only accepted field name for this;
 *      `store_name` was removed 2026-08-12) is validated `required` at the
 *      router edge before process() ever runs.
 *   3. Existing-tenant guard (AUTH-SPEC §5a/S9, 2026-08-12): unless
 *      allow_additional_tenant=true, check whether this identity already has
 *      a membership on this platform (findExistingTenantId()) — if so, REPLAY
 *      that tenant instead of generating a new uuid and provisioning a new
 *      (orphaned, on a retry) database. Fixes a real bug: this used to run
 *      unconditionally, so a double-click/network retry physically
 *      provisioned a new, orphaned tenant database every time, even though
 *      step 5's idempotency_key handling correctly deduped the MEMBERSHIP.
 *   4. Generate tenant_id (or reuse the existing one from step 3), slug, db_schema.
 *   5. AUTH-SPEC §3d: if oauth_state is present (and this is NOT a replay),
 *      call ExternalAuthServiceClient::promoteOAuthConnection() to commit the
 *      OAuth connection linkage before provisioning.
 *   6. Call provisioner->provision($data) — sequential platform steps
 *      (skipped entirely on replay — the tenant + database already exist):
 *        a. createTenantRecord()  — write tenant to main DB
 *        b. createDatabase()      — provision tenant DB via gateway
 *        c. runMigrations()       — run migrations (default no-op)
 *        d. seedData()            — platform-specific seeding (default no-op)
 *   7. Call auth's POST /api/internal/create-membership — idempotent on
 *      (identity_id, tenant_id); reports is_new_tenant=false on replay.
 *   8. Return full platform JWT envelope (AUTH-SPEC §5a, framework-spec.md §6):
 *      access_token, token_type, expires_in, active_tenant, available_tenants[],
 *      active_role, available_roles[], identity {id, email, display_name},
 *      membership {id, tenant_id, role}
 *
 * Falls back to legacy before_provision / after_provision hooks when no
 * provisioner instance is provided (backward compatibility).
 *
 * A platform needing different idempotency/field/response-shape/OAuth
 * behavior than the above can now extend this class and register the
 * subclass via `ExternalAuthConfig`'s `provision_tenant_route_class` option
 * (see that property's docblock) — the constructor stays a normal 3+1-arg
 * DI constructor (for testability, same as every other ExternalAuth route),
 * and mintProvisionApiToken()/slugify()/generateUuid()/findExistingTenantId()
 * are protected, not private, specifically so a subclass can override just
 * the piece it needs instead of reimplementing this whole class.
 *
 * @package StoneScriptPHP\Auth\ExternalAuth\Routes
 */
class ProvisionTenantRoute extends BaseExternalAuthRoute
{
    /**
     * AUTH-SPEC §5a: the ONLY accepted field name for the tenant's display
     * name. `store_name` was removed entirely (2026-08-12) — it was never
     * part of any spec, and it's what drove real-world field-name divergence
     * between this framework default and downstream platform-specific
     * overrides. This route's current callers on the fleet have zero live
     * frontend traffic exercising it today (confirmed by a repo-wide search
     * across the fleet — no caller anywhere sends store_name to this route).
     * Required — see validation_rules(), same enforcement pattern as
     * idempotency_key below.
     */
    public string $tenant_name      = '';
    /** AUTH-SPEC §5a S9 — required, client-generated UUID. Prevents double-creates on retry. */
    public string $idempotency_key  = '';
    /**
     * AUTH-SPEC §3d. Optional. Present when this call follows an OAuth
     * confirm-signup — the Bearer token at this point is the short-lived
     * oauth_pending JWT confirm-signup issued. When set, process() calls
     * ExternalAuthServiceClient::promoteOAuthConnection() to commit the
     * OAuth connection linkage (oauth_pending_connections -> oauth_connections)
     * before creating the tenant membership. Added 2026-08-12 — a downstream
     * platform needed this for its OAuth-signup-then-provision handoff and
     * the framework had no equivalent, one of the reasons it reimplemented
     * this whole route instead of extending it.
     */
    public string $oauth_state      = '';
    /**
     * When true, skip the existing-tenant guard below and always provision a
     * genuinely new tenant even if the identity already has one for this
     * platform. Mirrors a pattern a downstream platform's own override
     * already used (an `allow_additional` field) for a "create a second
     * business/organization" flow that deliberately needs to skip the guard
     * below. Default false: the SAFE default is "prevent duplicate-tenant
     * creation on a double-click or network retry" — see the guard's own
     * comment in process() for the bug this fixes. Added 2026-08-12.
     */
    public bool $allow_additional_tenant = false;
    public string $display_name     = '';
    public string $email        = '';
    public string $phone        = '';          // preferred field name
    public string $phone_number = '';          // kept for backwards compat
    public string $address      = '';
    public string $city         = '';
    public string $state        = '';
    public string $pincode      = '';
    public string $country      = '';

    private ?TenantProvisioner $provisioner;

    public function __construct(
        ExternalAuthServiceClient $client,
        array $hooks,
        ExternalAuthConfig $config,
        ?TenantProvisioner $provisioner = null
    ) {
        parent::__construct($client, $hooks, $config);
        $this->provisioner = $provisioner;
    }

    /**
     * {@inheritdoc}
     */
    public function validation_rules(): array
    {
        return array_merge([
            'tenant_name'     => 'required|string|max:255',   // AUTH-SPEC §5a — only accepted field name
            'idempotency_key' => 'required|string|max:64',   // AUTH-SPEC §5a S9
            'oauth_state'     => 'optional|string|max:512',
            'allow_additional_tenant' => 'optional|boolean',
            'display_name'    => 'optional|string|max:255',
            'email'        => 'optional|string|max:255',
            'phone'        => 'optional|string|max:50',
            'phone_number' => 'optional|string|max:50',   // backwards compat
            'address'      => 'optional|string|max:500',
            'city'         => 'optional|string|max:100',
            'state'        => 'optional|string|max:100',
            'pincode'      => 'optional|string|max:10',
            'country'      => 'optional|string|max:50',
        ], $this->config->extraValidation);
    }

    /**
     * {@inheritdoc}
     */
    public function process(): ApiResponse
    {
        // 1. Get identity from verified JWT (JwtAuthMiddleware already ran)
        $user = auth();
        if (!$user || !isset($user->user_id)) {
            return res_error('Authorization required', 401);
        }
        $identityId = (string) $user->user_id;

        // AUTH-SPEC §5a: tenant_name is `required` in validation_rules()
        // above — the Router validates $allInput against that BEFORE
        // process() ever runs (Router::executeHandler(), before property
        // injection), so it's guaranteed non-empty here on the normal HTTP
        // path. Same enforcement pattern as idempotency_key — no redundant
        // manual guard, consistent with how this route already worked.
        $resolvedName = $this->tenant_name;

        // AUTH-SPEC §5a/S9 — existing-tenant guard (2026-08-12 fix for a real,
        // confirmed bug, not a hypothetical): generateUuid() +
        // provisioner->provision() used to run UNCONDITIONALLY on every call,
        // before idempotency_key was ever checked against anything — a
        // double-click or network retry generated a BRAND NEW random uuid and
        // physically provisioned a real, orphaned tenant database for it every
        // single time, even though createMembership() (step 4 below) correctly
        // deduped the MEMBERSHIP row afterward via idempotency_key. The
        // orphaned database was never cleaned up — createDatabase() is only
        // idempotent BY uuid, and each retry used a different one.
        //
        // Two downstream platforms that hit this in production built their own
        // app-level guard around it independently (one a cache-based approach,
        // the other an existing-membership check equivalent to this one). This
        // generalizes that pattern into the framework itself. Bypassable via
        // allow_additional_tenant for platforms/flows that legitimately create
        // more than one tenant per identity (e.g. a "create a second
        // business/organization" flow).
        //
        // Fail-open on lookup error: this guard is a best-effort optimization
        // to avoid the orphaned-DB waste, not the dedup authority — auth's
        // createMembership() (idempotency_key-keyed) remains the real
        // correctness guarantee against duplicate MEMBERSHIPS either way.
        $existingTenantId = null;
        if (!$this->allow_additional_tenant) {
            $existingTenantId = $this->findExistingTenantId();
        }

        $isReplay = $existingTenantId !== null;

        // 2. Resolve tenant identifiers — a genuinely NEW uuid, or the
        // EXISTING tenant's id when the guard above found one. The db_schema
        // naming convention is deterministic ({platform}_{uuid}), so it's
        // recomputable for an existing tenant without an extra lookup.
        // tenant_slug is intentionally not generated here — it is optional and
        // not used for routing on shared-portal platforms. Platforms that need
        // subdomain routing should generate their own slug and set it in the
        // provisioner's createTenantRecord().
        $tenantId       = $isReplay ? $existingTenantId : $this->generateUuid();
        $tenantDbSchema = $this->config->platformCode . '_' . str_replace('-', '_', $tenantId);

        $phone = $this->phone ?: $this->phone_number; // accept either field name

        $data = [
            'identity_id'      => $identityId,
            'tenant_id'        => $tenantId,
            'platform_code'    => $this->config->platformCode,
            'tenant_name'      => $resolvedName,
            'tenant_slug'      => null,  // Optional — not generated by default
            'tenant_db_schema' => $tenantDbSchema,
            'display_name'     => $this->display_name ?: ($user->display_name ?? ''),
            'email'            => $this->email ?: ($user->email ?? ''),
            'phone'            => $phone,
            'phone_number'     => $phone,   // keep for seedData() compatibility
            'address'          => $this->address,
            'city'             => $this->city,
            'state'            => $this->state,
            'pincode'          => $this->pincode,
            'country'          => $this->country,
            'role'             => 'owner',
            // AUTH-SPEC §5a/S9 — threaded to create-membership so auth can dedup
            // on replay and return the existing tenant instead of double-creating.
            'idempotency_key'  => $this->idempotency_key,
        ];

        // AUTH-SPEC §3d — OAuth promote. Only on the genuinely-new-tenant path:
        // a replay means this identity already has a tenant, so any OAuth
        // connection linkage for it was already promoted on the ORIGINAL call
        // that created that tenant — repeating it here would be redundant, not
        // wrong, but there is nothing new to commit. Fatal if it fails on the
        // new-tenant path: without it, the OAuth connection is orphaned and the
        // identity cannot log in via that provider next time (see
        // ExternalAuthServiceClient::promoteOAuthConnection()'s docblock).
        if (!$isReplay && $this->oauth_state !== '') {
            $rawToken = $this->getBearerToken() ?? '';
            try {
                $this->client->promoteOAuthConnection($this->oauth_state, $rawToken);
            } catch (\Throwable $e) {
                log_error('ProvisionTenantRoute: OAuth promote failed for state=' . $this->oauth_state . ': ' . $e->getMessage());
                return res_error('Failed to complete OAuth sign-in. Please try again.', 500);
            }
        }

        // 3. Run provisioning — prefer class-based provisioner over legacy hooks.
        // Skipped entirely on replay: the tenant record + database already
        // exist (that's what makes it a replay), re-running provision() would
        // at best no-op (createDatabase() 409s) and at worst re-run seedData()
        // against live data.
        if ($isReplay) {
            // No-op — see above.
        } elseif ($this->provisioner !== null) {
            try {
                $data = $this->provisioner->provision($data);
            } catch (\Throwable $e) {
                log_error("TenantProvisioner::provision failed: " . $e->getMessage());

                // Return structured validation errors for ValidationException (422)
                if ($e instanceof ValidationException) {
                    return new ApiResponse(
                        'error',
                        $e->getMessage(),
                        null,
                        $e->getHttpStatusCode(),
                        $e->getValidationErrors()
                    );
                }

                // Return structured error with proper status code for other FrameworkExceptions
                if ($e instanceof FrameworkException) {
                    return res_error($e->getMessage(), $e->getHttpStatusCode());
                }

                // Generic 500 for unexpected errors
                return res_error('Tenant provisioning failed: ' . $e->getMessage());
            }
        } elseif (isset($this->hooks['before_provision']) && is_callable($this->hooks['before_provision'])) {
            // Legacy hook path (backward compatibility)
            try {
                $hookResult = ($this->hooks['before_provision'])($data);
                if ($hookResult === false) {
                    return res_error('Tenant provisioning failed');
                }
                if (is_array($hookResult)) {
                    $data = array_merge($data, $hookResult);
                }
            } catch (\Throwable $e) {
                log_error("before_provision hook failed: " . $e->getMessage());
                return res_error('Tenant provisioning failed: ' . $e->getMessage());
            }
        }

        // 4. Call auth to create membership (server-to-server)
        $platformSecret = $this->config->platformSecret;
        if (!$platformSecret) {
            log_error("provision-tenant: EXTERNAL_AUTH_CLIENT_SECRET not configured (required for auth service calls)");
            return res_error('Server configuration error', 500);
        }

        try {
            $result = $this->client->createMembership($data, $platformSecret);
        } catch (\Throwable $e) {
            log_error("create-membership failed: " . $e->getMessage());
            return res_error('Failed to create organization membership');
        }

        // 5. Call after_provision hook (legacy — no-op when using provisioner)
        if (isset($this->hooks['after_provision']) && is_callable($this->hooks['after_provision'])) {
            try {
                ($this->hooks['after_provision'])($result, $data);
            } catch (\Throwable $e) {
                log_error("after_provision hook failed: " . $e->getMessage());
            }
        }

        // Determine effective tenant data (idempotent replay returns existing tenant).
        // framework-spec.md §6: provision-tenant grants owner role + mints the
        // same API token that exchange would mint. The API token is minted here rather than
        // relayed from the auth service (which issues auth tokens, not API tokens).
        $tenantSlug = $data['tenant_slug'] ?? ($result['tenant_slug'] ?? null);

        // AUTH-SPEC §5a/S9 idempotent replay: when the same idempotency_key is reused,
        // auth returns is_new_tenant=false + the EXISTING tenant_id.
        $isNewTenant       = $result['is_new_tenant'] ?? true;
        $effectiveTenantId = $result['tenant_id'] ?? $data['tenant_id'];
        $effectiveDbSchema = $result['tenant_db_schema'] ?? $result['db_schema'] ?? $data['tenant_db_schema'];
        $statusCode = $isNewTenant ? 201 : 200;
        $message    = $isNewTenant ? 'Tenant provisioned' : 'Tenant already provisioned';

        // framework-spec.md §6: provision grants 'owner' and mints an API token.
        // The API token is the authoritative platform token — not the auth service's access_token.
        // The auth service token ($result['access_token']) is an auth token (identity JWT);
        // we mint a platform API token so the client is immediately authorised without a
        // separate exchange call.
        $apiToken    = $this->mintProvisionApiToken($identityId, $effectiveTenantId, 'owner', $data);
        $activeRole  = 'owner';
        $activeTenant = [
            'id'        => $effectiveTenantId,
            'name'      => $data['tenant_name'],
            'slug'      => $tenantSlug,
            'db_schema' => $effectiveDbSchema,
        ];

        return new ApiResponse('ok', $message, [
            // API token (platform JWT) — the client uses this for all subsequent requests.
            'access_token'       => $apiToken ?? $result['access_token'] ?? null,
            'token_type'         => 'Bearer',
            'expires_in'         => $this->config->exchangeTtl,

            // §6 session contract fields — client reads context from response, not token.
            'active_tenant'      => $activeTenant,
            'available_tenants'  => [$activeTenant],
            'active_role'        => $activeRole,
            'available_roles'    => [$activeRole],

            // Legacy / supplementary fields retained for backward compatibility.
            'identity' => [
                'id'           => $identityId,
                'email'        => ($data['email'] ?: null) ?: null,
                'display_name' => ($data['display_name'] ?: null) ?: null,
            ],
            'membership' => [
                'id'        => $result['membership_id'] ?? null,
                'tenant_id' => $effectiveTenantId,
                'role'      => $activeRole,
            ],
        ], $statusCode);
    }

    /**
     * Best-effort check: does this identity already have ANY membership for
     * this platform? Used by the existing-tenant guard in process() (see its
     * comment for the real, confirmed bug this fixes — orphaned tenant
     * databases from unconditional provisioning on retry). Returns the first
     * membership's tenant_id, or null if none found, no Bearer token is
     * present, or the lookup itself failed — every null case is treated as
     * fail-open by process() (proceed to provision), never fail-closed, since
     * this guard is a best-effort optimization, not the dedup authority
     * (createMembership()'s idempotency_key handling is).
     *
     * Protected — override seam for a platform wanting different
     * existing-tenant resolution (e.g. filtering by membership status, or by
     * a specific tenant_id rather than "the first one found").
     */
    protected function findExistingTenantId(): ?string
    {
        $rawToken = $this->getBearerToken();
        if ($rawToken === null) {
            return null;
        }

        try {
            $response = $this->client->getMemberships($rawToken);
        } catch (\Throwable $e) {
            log_warning('ProvisionTenantRoute: existing-tenant guard lookup failed (proceeding to provision): ' . $e->getMessage());
            return null;
        }

        $tenantId = $response['memberships'][0]['tenant_id'] ?? null;
        return ($tenantId !== null && $tenantId !== '') ? (string) $tenantId : null;
    }

    /**
     * Mint a platform API token immediately after provisioning.
     *
     * This gives the client a usable API token without requiring a separate exchange call.
     * Per framework-spec.md §6: "provision-tenant grants owner and mints the same
     * API token that exchange would mint."
     *
     * Returns null if the signing key is not configured (e.g. during unit tests),
     * in which case the caller falls back to the auth service's access_token.
     *
     * Protected (not private) — a platform substituted via
     * `provision_tenant_route_class` (see ExternalAuthConfig::$provisionTenantRouteClass)
     * can override just this customization point (e.g. to mint a different
     * token shape) without reimplementing all of process(). Was private until
     * 2026-08-12; confirmed via `git log --all -p` that no downstream platform
     * ever attempted `extends ProvisionTenantRoute` before this fix — real
     * fleet platforms instead reimplemented the entire route from scratch as
     * a standalone IRouteHandler, in part because this and the two methods
     * below were unreachable from a subclass either way.
     */
    protected function mintProvisionApiToken(
        string $identityId,
        string $tenantId,
        string $role,
        array $data
    ): ?string {
        if (empty($this->config->signingPrivateKeyPath)) {
            return null;
        }

        $claims = [
            'sub'          => $identityId,
            'identity_id'  => $identityId,
            'tenant_id'    => $tenantId,
            'email'        => ($data['email'] ?: null) ?: null,
            'display_name' => ($data['display_name'] ?: null) ?: null,
            'platform_code' => $this->config->platformCode,
        ];

        try {
            $service = new TokenExchangeService();
            return $service->exchangeApiToken($claims, $role, [
                'private_key_path'       => $this->config->signingPrivateKeyPath,
                'private_key_passphrase' => $this->config->signingPrivateKeyPassphrase,
                'issuer'                 => $this->config->signingIssuer,
                'ttl'                    => $this->config->exchangeTtl,
            ]);
        } catch (TokenExchangeException $e) {
            log_error('ProvisionTenantRoute: API token minting failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate a URL-safe slug from a store name.
     *
     * Protected (not private) — same override-seam rationale as
     * mintProvisionApiToken() above.
     */
    protected function slugify(string $name): string
    {
        $slug = mb_strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'store-' . substr($this->generateUuid(), 0, 8);
    }

    /**
     * Generate a UUID v4 string.
     *
     * Protected (not private) — same override-seam rationale as
     * mintProvisionApiToken() above (e.g. a platform needing deterministic
     * UUIDs in tests, or a different generation strategy).
     */
    protected function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
