<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ExchangeRoute;
use StoneScriptPHP\Auth\TokenExchangeException;

/**
 * Unit tests for ExchangeRoute — auth-token/API-token tenancy model (framework-spec.md §6).
 *
 * ## What the API-token model exchange does
 *
 *   1. Validate the AUTH TOKEN (identity JWT, tenant-less) from the Authorization header.
 *   2. Read tenant_id + optional role_id from the REQUEST BODY.
 *   3. Resolve available_tenants for the identity (via tenants_resolver).
 *   4. Verify the requested tenant_id is in the available set.
 *   5. Resolve available_roles in that tenant (via roles_resolver).
 *   6. Pick active_role_id (body hint if valid, else first role).
 *   7. Mint an API TOKEN carrying: identity_id + tenant_id + single role_id.
 *   8. Return §6 session contract: access_token + active_tenant + available_tenants
 *      + active_role + available_roles.
 *
 * Tests use a TestableExchangeRoute subclass that overrides the three external
 * seams (header extraction, JWKS validation, API-token signing) so no network call,
 * private key, or real token is needed.
 */
class ExchangeRouteTest extends TestCase
{
    /**
     * Env vars this test process-wide putenv()'d because they were empty on
     * entry. Tracked so tearDown() can putenv()-unset exactly what setUp()
     * set. Without this, putenv() leaks for the rest of the single-process
     * PHPUnit run (no process isolation) and silently flips env-gated skip
     * guards in unrelated tests (e.g. DatabaseTest, MigrationsTest) that run
     * later alphabetically.
     */
    private array $envVarsSetByThisTest = [];

    protected function setUp(): void
    {
        // ExternalAuthConfig -> Env::get_instance() needs gateway placeholders.
        $this->setEnvIfEmpty('DB_GATEWAY_URL', 'http://localhost:9000');
        $this->setEnvIfEmpty('DB_GATEWAY_PLATFORM', 'test-platform');
        $this->setEnvIfEmpty('AUTH_SERVICE_URL', 'http://localhost:3139');
        $ref = new \ReflectionClass(\StoneScriptPHP\Env::class);
        $prop = $ref->getProperty('_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        // res_error() (helpers.php) reads these from $_SERVER for its log line;
        // they are absent in the CLI test context.
        $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'POST';
        $_SERVER['REQUEST_URI']    = $_SERVER['REQUEST_URI'] ?? '/api/auth/exchange';
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(\StoneScriptPHP\Env::class);
        $prop = $ref->getProperty('_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        // Undo only the putenv() calls this test made — restores the
        // process env to how it was before setUp() ran.
        foreach ($this->envVarsSetByThisTest as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
        $this->envVarsSetByThisTest = [];
    }

    /**
     * putenv() a placeholder only if $name is currently empty/unset, and
     * remember it so tearDown() can undo exactly this test's leakage.
     */
    private function setEnvIfEmpty(string $name, string $value): void
    {
        if (empty(getenv($name))) {
            putenv("{$name}={$value}");
            $this->envVarsSetByThisTest[] = $name;
        }
    }

    private function makeConfig(array $options = []): ExternalAuthConfig
    {
        return new ExternalAuthConfig(array_merge([
            'platform_code' => 'testapp',
            'auth_issuer'   => 'http://localhost:3139',
        ], $options));
    }

    private function makeClient(): ExternalAuthServiceClient
    {
        return new ExternalAuthServiceClient('http://localhost:3139', 'testapp');
    }

    // ── §6 session contract response ─────────────────────────────────────────

    public function test_valid_exchange_returns_card_session_contract(): void
    {
        $tenants = [
            ['id' => 't-1', 'name' => 'Acme Store'],
        ];
        $route = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(['exchange_ttl' => 1800]),
            rolesResolver:   fn(array $claims) => ['owner', 'manager'],
            tenantsResolver: fn(array $claims) => $tenants
        );
        $route->stubToken  = 'passport.jwt.token';
        $route->stubClaims = ['sub' => 'id-123', 'platform_code' => 'testapp'];
        $route->stubCard   = 'card.jwt.signed';
        $route->tenant_id  = 't-1';  // body field

        $response = $route->process();

        $this->assertSame('ok', $response->status);
        $this->assertSame('card.jwt.signed', $response->data['access_token']);
        $this->assertSame('Bearer', $response->data['token_type']);
        $this->assertSame(1800, $response->data['expires_in']);

        // §6 session contract
        $this->assertSame(['id' => 't-1', 'name' => 'Acme Store'], $response->data['active_tenant']);
        $this->assertSame($tenants, $response->data['available_tenants']);
        $this->assertSame('owner', $response->data['active_role']);       // first role = default
        $this->assertSame(['owner', 'manager'], $response->data['available_roles']);
    }

    // ── tenant_id from body, not from auth-token claims ─────────────────────────

    public function test_auth_token_is_tenant_less_tenant_comes_from_body(): void
    {
        $receivedTenantId = null;
        $route = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(),
            rolesResolver:   function (array $claims) use (&$receivedTenantId) {
                $receivedTenantId = $claims['tenant_id'];  // the merged-in tenant_id
                return ['owner'];
            },
            tenantsResolver: fn(array $claims) => [['id' => 'body-tenant']]
        );
        $route->stubToken  = 'passport.jwt';
        $route->stubClaims = ['sub' => 'id-42'];  // no tenant_id in auth token!
        $route->stubCard   = 'card.signed';
        $route->tenant_id  = 'body-tenant';  // comes from request body

        $response = $route->process();

        $this->assertSame('ok', $response->status);
        $this->assertSame('body-tenant', $receivedTenantId, 'roles_resolver receives tenant_id from body');
    }

    // ── role_id hint from body ────────────────────────────────────────────────

    public function test_role_id_hint_selects_non_default_role(): void
    {
        $route = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(),
            rolesResolver:   fn(array $claims) => ['owner', 'cashier'],
            tenantsResolver: fn(array $claims) => [['id' => 't-1']]
        );
        $route->stubToken  = 'passport.jwt';
        $route->stubClaims = ['sub' => 'id-10'];
        $route->stubCard   = 'card.signed';
        $route->tenant_id  = 't-1';
        $route->role_id    = 'cashier';  // body hint

        $response = $route->process();

        $this->assertSame('ok', $response->status);
        $this->assertSame('cashier', $response->data['active_role'], 'Role hint from body is honoured');
    }

    public function test_invalid_role_hint_falls_back_to_first_role(): void
    {
        $route = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(),
            rolesResolver:   fn(array $claims) => ['owner', 'cashier'],
            tenantsResolver: fn(array $claims) => [['id' => 't-1']]
        );
        $route->stubToken  = 'passport.jwt';
        $route->stubClaims = ['sub' => 'id-10'];
        $route->stubCard   = 'card.signed';
        $route->tenant_id  = 't-1';
        $route->role_id    = 'admin';  // not in available_roles

        $response = $route->process();

        $this->assertSame('ok', $response->status);
        $this->assertSame('owner', $response->data['active_role'], 'Falls back to first role');
    }

    // ── tenants_resolver verification ────────────────────────────────────────

    public function test_tenant_not_in_available_set_is_rejected_403(): void
    {
        $route = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(),
            rolesResolver:   fn(array $claims) => ['owner'],
            tenantsResolver: fn(array $claims) => [['id' => 'allowed-tenant']]
        );
        $route->stubToken  = 'passport.jwt';
        $route->stubClaims = ['sub' => 'id-99'];
        $route->tenant_id  = 'not-my-tenant';  // not in available set

        $response = $route->process();

        $this->assertSame('error', $response->status);
        $this->assertSame(403, $response->httpStatusCode);
        $this->assertSame('tenant_access_denied', $response->data['error']);
    }

    public function test_without_tenants_resolver_trusted_tenant_id_passes(): void
    {
        $route = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(),
            rolesResolver:   fn(array $claims) => ['owner'],
            tenantsResolver: null  // no tenants_resolver → trust body tenant_id
        );
        $route->stubToken  = 'passport.jwt';
        $route->stubClaims = ['sub' => 'id-5'];
        $route->stubCard   = 'card.signed';
        $route->tenant_id  = 'any-tenant';

        $response = $route->process();

        $this->assertSame('ok', $response->status);
        $this->assertSame(['id' => 'any-tenant'], $response->data['active_tenant']);
    }

    // ── no roles in tenant → 403 ─────────────────────────────────────────────

    public function test_no_roles_in_tenant_returns_403(): void
    {
        $route = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(),
            rolesResolver:   fn(array $claims) => [],  // empty — no membership
            tenantsResolver: fn(array $claims) => [['id' => 't-1']]
        );
        $route->stubToken  = 'passport.jwt';
        $route->stubClaims = ['sub' => 'id-8'];
        $route->tenant_id  = 't-1';

        $response = $route->process();

        $this->assertSame('error', $response->status);
        $this->assertSame(403, $response->httpStatusCode);
        $this->assertSame('no_roles_in_tenant', $response->data['error']);
    }

    // ── missing token → 401 ──────────────────────────────────────────────────

    public function test_missing_auth_token_returns_401(): void
    {
        $route = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(),
            fn(array $claims) => ['owner']
        );
        $route->stubToken = null;  // no Authorization header

        $response = $route->process();

        $this->assertSame('error', $response->status);
        $this->assertSame(401, $response->httpStatusCode);
        $this->assertSame('invalid_identity_token', $response->data['error']);
    }

    // ── invalid / expired token → 401 ────────────────────────────────────────

    public function test_invalid_auth_token_returns_401(): void
    {
        $route = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(),
            fn(array $claims) => ['owner']
        );
        $route->stubToken     = 'bad.token';
        $route->validateThrows = new TokenExchangeException('bad sig', 'INVALID_SIGNATURE');

        $response = $route->process();

        $this->assertSame('error', $response->status);
        $this->assertSame(401, $response->httpStatusCode);
        $this->assertSame('invalid_identity_token', $response->data['error']);
    }

    // ── no resolver → 501 ────────────────────────────────────────────────────

    public function test_no_roles_resolver_returns_501(): void
    {
        $route = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(),
            null  // no roles_resolver
        );
        $route->stubToken  = 'passport.jwt';
        $route->stubClaims = ['sub' => 'id-1'];
        $route->tenant_id  = 't-1';

        $response = $route->process();

        $this->assertSame('error', $response->status);
        $this->assertSame(501, $response->httpStatusCode);
    }

    // ── auth token without identity_id → 401 ───────────────────────────────────

    public function test_auth_token_without_identity_id_returns_401(): void
    {
        $route = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(),
            fn(array $claims) => ['owner']
        );
        $route->stubToken  = 'passport.jwt';
        $route->stubClaims = ['tenant_id' => 't-1'];  // no sub / identity_id

        $response = $route->process();

        $this->assertSame('error', $response->status);
        $this->assertSame(401, $response->httpStatusCode);
        $this->assertSame('invalid_identity_token', $response->data['error']);
    }

    // ── roles_resolver receives auth-token claims + merged tenant_id ────────────

    public function test_resolver_receives_auth_claims_with_tenant_merged(): void
    {
        $received = null;
        $route = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(),
            rolesResolver: function (array $claims) use (&$received) {
                $received = $claims;
                return ['owner'];
            },
            tenantsResolver: fn(array $claims) => [['id' => 't-9']]
        );
        $route->stubToken  = 'passport.jwt';
        $route->stubClaims = ['sub' => 'id-xyz', 'email' => 'u@test.com'];  // no tenant_id in auth token
        $route->stubCard   = 'card.signed';
        $route->tenant_id  = 't-9';  // body

        $route->process();

        $this->assertNotNull($received);
        $this->assertSame('id-xyz', $received['sub']);
        $this->assertSame('u@test.com', $received['email']);
        $this->assertSame('t-9', $received['tenant_id'], 'tenant_id merged from body into resolver claims');
    }

    // ── §5.5 (framework-spec.md): re-exchange for a different tenant does not ──
    // ── revoke or affect any previously-issued API token — multi-tab support ──

    /**
     * Two sequential exchanges for two DIFFERENT tenants, same identity, must each
     * independently succeed with their own correct API token — simulating what happens
     * when a user has two browser tabs open on two different tenants (framework-
     * spec.md §5.5). Nothing about minting the second API token may reference,
     * invalidate, or depend on the first call having happened.
     *
     * This locks in framework-spec.md §5.5: "exchange never revokes or invalidates
     * a previously-issued API token for a different tenant_id when minting a new one."
     * process() has no shared mutable state and no revocation call at all — this
     * test would fail if either were ever introduced.
     */
    public function test_exchanging_a_second_tenant_does_not_affect_the_first_cards_result(): void
    {
        $allTenants = [
            ['id' => 't-software-dev', 'name' => 'Acme Dev Shop'],
            ['id' => 't-marketing',    'name' => 'Bright Marketing'],
        ];

        // Tab 1 — exchange into the software-dev tenant.
        $tab1 = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(),
            rolesResolver:   fn(array $claims) => ['owner'],
            tenantsResolver: fn(array $claims) => $allTenants
        );
        $tab1->stubToken  = 'passport.jwt';        // same identity's auth token in both tabs
        $tab1->stubClaims = ['sub' => 'ceo-identity'];
        $tab1->stubCard   = 'card.software-dev.signed';
        $tab1->tenant_id  = 't-software-dev';

        $tab1Response = $tab1->process();

        // Tab 2 — separate ApiClient/session, exchange into the marketing tenant,
        // using a completely independent route instance (mirrors two real tabs
        // never sharing any in-process state).
        $tab2 = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(),
            rolesResolver:   fn(array $claims) => ['owner'],
            tenantsResolver: fn(array $claims) => $allTenants
        );
        $tab2->stubToken  = 'passport.jwt';         // same identity's auth token
        $tab2->stubClaims = ['sub' => 'ceo-identity'];
        $tab2->stubCard   = 'card.marketing.signed';
        $tab2->tenant_id  = 't-marketing';

        $tab2Response = $tab2->process();

        // Both exchanges succeeded independently.
        $this->assertSame('ok', $tab1Response->status);
        $this->assertSame('ok', $tab2Response->status);

        // Each got its OWN distinct API token, for its OWN tenant.
        $this->assertSame('card.software-dev.signed', $tab1Response->data['access_token']);
        $this->assertSame('t-software-dev', $tab1Response->data['active_tenant']['id']);

        $this->assertSame('card.marketing.signed', $tab2Response->data['access_token']);
        $this->assertSame('t-marketing', $tab2Response->data['active_tenant']['id']);

        // Re-reading tab 1's response object after tab 2's exchange ran proves tab 1's
        // result was never mutated by the second call — the strongest assertion
        // available at this seam (there is no server-side revocation list to query
        // directly from a unit test; ExchangeRoute simply never touches one).
        $this->assertSame(
            'card.software-dev.signed',
            $tab1Response->data['access_token'],
            "Tab 1's API token must be unchanged after tab 2 exchanged a different tenant"
        );
    }

    // ── available_tenants: multiple tenants list ──────────────────────────────

    public function test_available_tenants_list_returned_for_multi_tenant_identity(): void
    {
        $allTenants = [
            ['id' => 't-1', 'name' => 'Store A'],
            ['id' => 't-2', 'name' => 'Store B'],
        ];
        $route = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(),
            rolesResolver:   fn(array $claims) => ['owner'],
            tenantsResolver: fn(array $claims) => $allTenants
        );
        $route->stubToken  = 'passport.jwt';
        $route->stubClaims = ['sub' => 'multi-owner'];
        $route->stubCard   = 'card.signed';
        $route->tenant_id  = 't-2';  // entering second tenant

        $response = $route->process();

        $this->assertSame('ok', $response->status);
        $this->assertSame(['id' => 't-2', 'name' => 'Store B'], $response->data['active_tenant']);
        $this->assertSame($allTenants, $response->data['available_tenants']);
    }
}

/**
 * Testable subclass — overrides the three external seams so process() can be
 * unit-tested without network/JWKS/private keys.
 *
 * Uses named constructor parameters so tests can choose which seams to override.
 */
class TestableExchangeRoute extends ExchangeRoute
{
    public ?string $stubToken = null;
    public array $stubClaims  = [];
    public string $stubCard   = 'card.jwt.signed';
    public ?TokenExchangeException $validateThrows = null;

    public function __construct(
        ExternalAuthServiceClient $client,
        array $hooks,
        ExternalAuthConfig $config,
        ?callable $rolesResolver = null,
        ?callable $tenantsResolver = null
    ) {
        parent::__construct($client, $hooks, $config, $rolesResolver, $tenantsResolver);
    }

    protected function extractIdentityToken(): ?string
    {
        return $this->stubToken;
    }

    protected function validateIdentity(string $authToken): array
    {
        if ($this->validateThrows !== null) {
            throw $this->validateThrows;
        }
        return $this->stubClaims;
    }

    protected function signApiToken(array $claimsWithTenant, string $activeRoleId): string
    {
        return $this->stubCard;
    }
}
