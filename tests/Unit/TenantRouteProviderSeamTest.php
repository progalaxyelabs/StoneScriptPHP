<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\ExternalAuth\DefaultTenantRouteProvider;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthRoutes;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\ExternalAuth\TenantRouteProviderInterface;
use StoneScriptPHP\Env;
use StoneScriptPHP\Routing\Router;

/**
 * Phase 1 seam: ExternalAuthRoutes no longer hard-`use`-imports tenant route
 * classes (SelectTenantRoute, ProvisionTenantRoute, InviteMemberRoute,
 * MembershipsRoute, UpdateMembershipRoute, CheckTenantSlugRoute,
 * AcceptInviteRoute) — it delegates to a TenantRouteProviderInterface, which
 * defaults to DefaultTenantRouteProvider.
 *
 * These tests confirm:
 *   1. The default provider reproduces the EXACT same protectedPaths() output
 *      as before this refactor (the ExternalAuthRoutesProtectedPathsTest suite
 *      already covers this at the ExternalAuthRoutes API level — this file
 *      covers the provider seam itself).
 *   2. A platform CAN swap in a custom provider via `tenant_route_provider`,
 *      and ExternalAuthRoutes::protectedPaths()/publicPaths() reflect it —
 *      proving the Bug-1 exemption-derivation stays coherent with whichever
 *      provider is actually registering routes.
 *
 * @covers \StoneScriptPHP\Auth\ExternalAuth\DefaultTenantRouteProvider
 * @covers \StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig
 * @covers \StoneScriptPHP\Auth\ExternalAuth\ExternalAuthRoutes
 */
class TenantRouteProviderSeamTest extends TestCase
{
    private array $envVarsSetByThisTest = [];

    protected function setUp(): void
    {
        $this->setEnvIfEmpty('DB_GATEWAY_URL', 'http://localhost:9000');
        $this->setEnvIfEmpty('DB_GATEWAY_PLATFORM', 'test-platform');
        $this->setEnvIfEmpty('AUTH_SERVICE_URL', 'http://localhost:3139');
        $this->setEnvIfEmpty('AUTH_ISSUER', 'http://localhost:3139');
        $ref = new \ReflectionClass(Env::class);
        $prop = $ref->getProperty('_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(Env::class);
        $prop = $ref->getProperty('_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        foreach ($this->envVarsSetByThisTest as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
        $this->envVarsSetByThisTest = [];
    }

    private function setEnvIfEmpty(string $name, string $value): void
    {
        if (empty(getenv($name))) {
            putenv("{$name}={$value}");
            $this->envVarsSetByThisTest[] = $name;
        }
    }

    /**
     * ExternalAuthConfig defaults tenantRouteProvider to DefaultTenantRouteProvider
     * when no `tenant_route_provider` option is given — every platform today.
     */
    public function test_config_defaults_to_default_tenant_route_provider(): void
    {
        $config = new ExternalAuthConfig(['provision_tenant' => true]);

        $this->assertInstanceOf(DefaultTenantRouteProvider::class, $config->tenantRouteProvider);
    }

    /**
     * A custom TenantRouteProviderInterface passed via `tenant_route_provider`
     * is used as-is — the seam a Phase 2 multi-tenancy plugin will use.
     */
    public function test_config_accepts_custom_tenant_route_provider(): void
    {
        $customProvider = new class implements TenantRouteProviderInterface {
            public function register(Router $router, string $prefix, ExternalAuthServiceClient $client, ExternalAuthConfig $config, mixed $provisioner, mixed $rolesResolver, mixed $tenantsResolver): void
            {
            }
            public function publicPaths(string $prefix, ExternalAuthConfig $config): array
            {
                return ["$prefix/custom-public"];
            }
            public function protectedPaths(string $prefix, ExternalAuthConfig $config): array
            {
                return ["$prefix/custom-protected"];
            }
            public function getRouteDefinitions(string $prefix, array $features): array
            {
                return [];
            }
        };

        $config = new ExternalAuthConfig(['tenant_route_provider' => $customProvider]);

        $this->assertSame($customProvider, $config->tenantRouteProvider);
    }

    /**
     * An invalid (non-TenantRouteProviderInterface) `tenant_route_provider` value
     * is ignored, falling back to DefaultTenantRouteProvider — defensive, not fatal.
     */
    public function test_config_ignores_invalid_tenant_route_provider(): void
    {
        $config = new ExternalAuthConfig(['tenant_route_provider' => 'not-a-provider']);

        $this->assertInstanceOf(DefaultTenantRouteProvider::class, $config->tenantRouteProvider);
    }

    /**
     * ExternalAuthRoutes::protectedPaths() reflects a CUSTOM provider's protectedPaths()
     * output — proving the exemption-list derivation (RequireCardMiddleware's Bug-1
     * guard) stays coherent with whichever provider actually registers the routes,
     * not hardcoded to the framework's built-in tenant route set.
     */
    public function test_protected_paths_reflects_custom_provider(): void
    {
        $customProvider = new class implements TenantRouteProviderInterface {
            public function register(Router $router, string $prefix, ExternalAuthServiceClient $client, ExternalAuthConfig $config, mixed $provisioner, mixed $rolesResolver, mixed $tenantsResolver): void
            {
            }
            public function publicPaths(string $prefix, ExternalAuthConfig $config): array
            {
                return [];
            }
            public function protectedPaths(string $prefix, ExternalAuthConfig $config): array
            {
                return ["$prefix/join-workspace"];
            }
            public function getRouteDefinitions(string $prefix, array $features): array
            {
                return [];
            }
        };

        $paths = ExternalAuthRoutes::protectedPaths([
            'tenant_route_provider' => $customProvider,
            'legacy_compat' => false,
        ]);

        $this->assertContains('/api/auth/join-workspace', $paths);
        // Framework's own built-in tenant routes must NOT appear — the custom
        // provider fully replaced DefaultTenantRouteProvider, it wasn't merged with it.
        $this->assertNotContains('/api/auth/select-tenant', $paths);
    }

    /**
     * DefaultTenantRouteProvider's protectedPaths() output — used as the default —
     * matches the exact tier-2 tenant route set from before this refactor.
     */
    public function test_default_provider_protected_paths_matches_pre_refactor_set(): void
    {
        $config = new ExternalAuthConfig(['provision_tenant' => true]);
        $provider = new DefaultTenantRouteProvider();

        $paths = $provider->protectedPaths('/api/auth', $config);

        $this->assertContains('/api/auth/select-tenant', $paths);
        $this->assertContains('/api/auth/provision-tenant', $paths);
        $this->assertContains('/api/auth/invite-member', $paths);
        $this->assertContains('/api/auth/memberships', $paths);
        // change-password and me stay in ExternalAuthRoutes itself (not tenant routes).
        $this->assertNotContains('/api/auth/change-password', $paths);
        $this->assertNotContains('/api/auth/me', $paths);
    }

    /**
     * DefaultTenantRouteProvider's getRouteDefinitions() — used by the client
     * generator — produces the same class map as before this refactor.
     */
    public function test_default_provider_route_definitions_include_tenant_routes(): void
    {
        $provider = new DefaultTenantRouteProvider();
        $features = [
            'select_tenant' => true,
            'provision_tenant' => true,
            'invite' => true,
            'memberships' => true,
            'check_slug' => true,
            'accept_invite' => true,
        ];

        $routes = $provider->getRouteDefinitions('/api/auth', $features);

        $this->assertSame(
            \StoneScriptPHP\Auth\ExternalAuth\Routes\SelectTenantRoute::class,
            $routes['POST']['/api/auth/select-tenant']
        );
        $this->assertSame(
            \StoneScriptPHP\Auth\ExternalAuth\Routes\MembershipsRoute::class,
            $routes['GET']['/api/auth/memberships']
        );
        $this->assertSame(
            \StoneScriptPHP\Auth\ExternalAuth\Routes\UpdateMembershipRoute::class,
            $routes['PUT']['/api/auth/memberships/{id}']
        );
    }

    /**
     * ExternalAuthRoutes::getRouteDefinitions() (the full static entry point used
     * by the client generator) still includes tenant routes by default — proving
     * the merge with DefaultTenantRouteProvider's contribution actually happens.
     */
    public function test_external_auth_routes_get_route_definitions_includes_tenant_routes(): void
    {
        $routes = ExternalAuthRoutes::getRouteDefinitions(['provision_tenant' => true]);

        $this->assertArrayHasKey('/api/auth/select-tenant', $routes['POST']);
        $this->assertArrayHasKey('/api/auth/provision-tenant', $routes['POST']);
        $this->assertArrayHasKey('/api/auth/memberships', $routes['GET']);
        // Identity routes (never moved) must still be present too.
        $this->assertArrayHasKey('/api/auth/login', $routes['POST']);
        $this->assertArrayHasKey('/api/auth/me', $routes['GET']);
    }
}
