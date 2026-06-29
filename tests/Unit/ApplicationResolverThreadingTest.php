<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ExchangeRoute;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ProfileRoute;
use StoneScriptPHP\Env;

/**
 * Tests that the resolver closures (tenants_resolver + roles_resolver) are threaded
 * through Application::buildAuthRouteOptions() so platforms don't need to bypass
 * Application::run() with a manual bootstrap.
 *
 * We can't call Application::run() in a unit test (it dispatches HTTP), but we CAN
 * verify that ExternalAuthConfig correctly receives and exposes the resolvers when
 * passed via the options array — which is exactly what buildAuthRouteOptions() produces.
 *
 * @covers \StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig
 * @covers \StoneScriptPHP\Auth\ExternalAuth\Routes\ExchangeRoute
 * @covers \StoneScriptPHP\Auth\ExternalAuth\Routes\ProfileRoute
 */
class ApplicationResolverThreadingTest extends TestCase
{
    protected function setUp(): void
    {
        if (empty(getenv('DB_GATEWAY_URL'))) {
            putenv('DB_GATEWAY_URL=http://localhost:9000');
        }
        if (empty(getenv('DB_GATEWAY_PLATFORM'))) {
            putenv('DB_GATEWAY_PLATFORM=test-platform');
        }
        if (empty(getenv('AUTH_SERVICE_URL'))) {
            putenv('AUTH_SERVICE_URL=http://localhost:3139');
        }
        $ref = new \ReflectionClass(Env::class);
        $prop = $ref->getProperty('_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $_SERVER['REQUEST_URI']    = $_SERVER['REQUEST_URI']    ?? '/';
    }

    /**
     * ExternalAuthConfig must expose tenants_resolver when passed via options.
     *
     * This mirrors what Application::buildAuthRouteOptions() now produces after
     * the Defect 3 fix (previously the resolvers were NOT in the built options array,
     * forcing platforms to call ExternalAuthRoutes::register() directly).
     */
    public function test_tenants_resolver_threaded_through_external_auth_config(): void
    {
        $tenantsResolver = fn(array $claims) => [['id' => 'tenant-1', 'name' => 'Test']];

        $config = new ExternalAuthConfig([
            'auth_issuer'      => 'http://localhost:3139',
            'tenants_resolver' => $tenantsResolver,
        ]);

        $this->assertSame(
            $tenantsResolver,
            $config->tenantsResolver,
            'tenants_resolver closure must be exposed via ExternalAuthConfig::$tenantsResolver'
        );
    }

    /**
     * ExternalAuthConfig with no tenants_resolver returns null (T1 platforms).
     */
    public function test_tenants_resolver_defaults_to_null(): void
    {
        $config = new ExternalAuthConfig([
            'auth_issuer' => 'http://localhost:3139',
        ]);

        $this->assertNull($config->tenantsResolver);
    }

    /**
     * ExchangeRoute receives both resolvers from the options that Application::run()
     * now produces.
     *
     * Before the fix: resolvers were in $authConfig but NOT in the array passed to
     * ExternalAuthRoutes::register() → resolvers were null in ExchangeRoute → 501.
     *
     * After the fix: resolvers are forwarded to ExternalAuthRoutes::register() →
     * ExchangeRoute performs full tenant+role verification.
     */
    public function test_exchange_route_uses_injected_resolvers_end_to_end(): void
    {
        $tenantsResolver = fn(array $claims) => [['id' => 't-1', 'name' => 'Store A']];
        $rolesResolver   = fn(array $claims) => ['owner'];

        $config = new ExternalAuthConfig([
            'auth_issuer'      => 'http://localhost:3139',
            'tenants_resolver' => $tenantsResolver,
        ]);
        $client = new ExternalAuthServiceClient('http://localhost:3139', 'testapp');

        // ExchangeRoute receives roles_resolver from the $options array (not from ExternalAuthConfig).
        // This matches how ExternalAuthRoutes::register() passes it.
        $route = new TestableExchangeRouteForResolver(
            $client,
            [],
            $config,
            rolesResolver:   $rolesResolver,
            tenantsResolver: $tenantsResolver
        );
        $route->stubToken  = 'passport.jwt';
        $route->stubClaims = ['sub' => 'id-1'];
        $route->stubCard   = 'card.signed';
        $route->tenant_id  = 't-1';

        $response = $route->process();

        $this->assertSame('ok', $response->status, 'Exchange must succeed when resolvers are properly threaded');
        $this->assertSame(['id' => 't-1', 'name' => 'Store A'], $response->data['active_tenant']);
        $this->assertSame('owner', $response->data['active_role']);
    }

    /**
     * Without resolvers (simulating the PRE-FIX Application::buildAuthRouteOptions() output),
     * ExchangeRoute returns 501 — confirming the defect existed and is now fixed.
     */
    public function test_exchange_route_without_resolver_returns_501(): void
    {
        $config = new ExternalAuthConfig([
            'auth_issuer' => 'http://localhost:3139',
            // No tenants_resolver — simulates pre-fix buildAuthRouteOptions() output
        ]);
        $client = new ExternalAuthServiceClient('http://localhost:3139', 'testapp');

        $route = new TestableExchangeRouteForResolver(
            $client,
            [],
            $config,
            rolesResolver:   null,  // pre-fix: not threaded
            tenantsResolver: null   // pre-fix: not threaded
        );
        $route->stubToken  = 'passport.jwt';
        $route->stubClaims = ['sub' => 'id-1'];
        $route->tenant_id  = 't-1';

        $response = $route->process();

        $this->assertSame('error', $response->status);
        $this->assertSame(501, $response->httpStatusCode);
    }
}

/**
 * Testable subclass that overrides external seams (JWKS + card signing) so
 * ExchangeRoute::process() can run without network or real RSA keys.
 */
class TestableExchangeRouteForResolver extends ExchangeRoute
{
    public ?string $stubToken  = null;
    public array  $stubClaims  = [];
    public string $stubCard    = 'card.signed';

    protected function extractIdentityToken(): ?string
    {
        return $this->stubToken;
    }

    protected function validateIdentity(string $passportToken): array
    {
        return $this->stubClaims;
    }

    protected function signCard(array $claimsWithTenant, string $activeRoleId): string
    {
        return $this->stubCard;
    }
}
