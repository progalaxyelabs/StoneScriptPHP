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
 * Unit tests for ExchangeRoute — passport/card tenancy model (framework-spec.md §6).
 *
 * ## What the card model exchange does
 *
 *   1. Validate the PASSPORT (identity JWT, tenant-less) from the Authorization header.
 *   2. Read tenant_id + optional role_id from the REQUEST BODY.
 *   3. Resolve available_tenants for the identity (via tenants_resolver).
 *   4. Verify the requested tenant_id is in the available set.
 *   5. Resolve available_roles in that tenant (via roles_resolver).
 *   6. Pick active_role_id (body hint if valid, else first role).
 *   7. Mint a CARD carrying: identity_id + tenant_id + single role_id.
 *   8. Return §6 session contract: access_token + active_tenant + available_tenants
 *      + active_role + available_roles.
 *
 * Tests use a TestableExchangeRoute subclass that overrides the three external
 * seams (header extraction, JWKS validation, card signing) so no network call,
 * private key, or real token is needed.
 */
class ExchangeRouteTest extends TestCase
{
    protected function setUp(): void
    {
        // ExternalAuthConfig -> Env::get_instance() needs gateway placeholders.
        if (empty(getenv('DB_GATEWAY_URL'))) {
            putenv('DB_GATEWAY_URL=http://localhost:9000');
        }
        if (empty(getenv('DB_GATEWAY_PLATFORM'))) {
            putenv('DB_GATEWAY_PLATFORM=test-platform');
        }
        if (empty(getenv('AUTH_SERVICE_URL'))) {
            putenv('AUTH_SERVICE_URL=http://localhost:3139');
        }
        $ref = new \ReflectionClass(\StoneScriptPHP\Env::class);
        $prop = $ref->getProperty('_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        // res_error() (helpers.php) reads these from $_SERVER for its log line;
        // they are absent in the CLI test context.
        $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'POST';
        $_SERVER['REQUEST_URI']    = $_SERVER['REQUEST_URI'] ?? '/api/auth/exchange';
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

    // ── tenant_id from body, not from passport claims ─────────────────────────

    public function test_passport_is_tenant_less_tenant_comes_from_body(): void
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
        $route->stubClaims = ['sub' => 'id-42'];  // no tenant_id in passport!
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

    public function test_missing_passport_returns_401(): void
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

    public function test_invalid_passport_returns_401(): void
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

    // ── passport without identity_id → 401 ───────────────────────────────────

    public function test_passport_without_identity_id_returns_401(): void
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

    // ── roles_resolver receives passport claims + merged tenant_id ────────────

    public function test_resolver_receives_passport_claims_with_tenant_merged(): void
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
        $route->stubClaims = ['sub' => 'id-xyz', 'email' => 'u@test.com'];  // no tenant_id in passport
        $route->stubCard   = 'card.signed';
        $route->tenant_id  = 't-9';  // body

        $route->process();

        $this->assertNotNull($received);
        $this->assertSame('id-xyz', $received['sub']);
        $this->assertSame('u@test.com', $received['email']);
        $this->assertSame('t-9', $received['tenant_id'], 'tenant_id merged from body into resolver claims');
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

    protected function validateIdentity(string $passportToken): array
    {
        if ($this->validateThrows !== null) {
            throw $this->validateThrows;
        }
        return $this->stubClaims;
    }

    protected function signCard(array $claimsWithTenant, string $activeRoleId): string
    {
        return $this->stubCard;
    }
}
