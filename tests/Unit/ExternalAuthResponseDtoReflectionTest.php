<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthRoutes;
use StoneScriptPHP\Auth\ExternalAuth\DefaultTenantRouteProvider;
use StoneScriptPHP\Auth\ExternalAuth\Dto\ExchangeResponseDto;
use StoneScriptPHP\Auth\ExternalAuth\Dto\ProvisionTenantResponseDto;
use StoneScriptPHP\Auth\ExternalAuth\Dto\ProfileResponseDto;
use StoneScriptPHP\Auth\ExternalAuth\Dto\LoginResponseDto;
use StoneScriptPHP\Auth\ExternalAuth\Dto\RegisterResponseDto;
use StoneScriptPHP\Auth\ExternalAuth\Dto\TenantSummaryDto;
use StoneScriptPHP\Subscriptions\SubscriptionRoutes;
use StoneScriptPHP\Analytics\AnalyticsRoutes;

/**
 * v9.6.0 (Phase 1, typed framework routes) — verifies:
 *   1. Every framework response/request DTO class is loadable and reflects
 *      cleanly through the client generator's reflectDto() machinery (no
 *      "class not found" fallback to `unknown`) — this is the drift-detection
 *      contract the task asked for: a broken DTO reference fails LOUDLY at
 *      client-generation time, not silently.
 *   2. getRouteDefinitions() on each framework route registrar (auth,
 *      subscriptions, analytics) emits the full array-config format
 *      (`handler`+`group`+`response`) that satisfies the generator's
 *      assertGroupDeclared() gate — the exact reason `response:` DTOs were
 *      wired at the getRouteDefinitions() layer, not just at runtime
 *      registration.
 *   3. ExternalAuthRoutes::register()/DefaultTenantRouteProvider::register()
 *      actually attach the SAME response DTO to the live $router — runtime
 *      wiring and client-gen wiring can't drift apart.
 */
class ExternalAuthResponseDtoReflectionTest extends TestCase
{
    /**
     * Same leak-safe env pattern as ExternalAuthConfigTest — the two
     * live-router tests below instantiate a real ExternalAuthConfig, which
     * requires DB_GATEWAY_URL/DB_GATEWAY_PLATFORM/AUTH_SERVICE_URL/AUTH_ISSUER
     * via Env::get_instance(). Only set a var if it's not already present
     * (single-process PHPUnit run, no isolation) and undo exactly what this
     * test set in tearDown() — never clobber a real value another test relies on.
     */
    private array $envVarsSetByThisTest = [];

    protected function setUp(): void
    {
        if (!defined('GENERATE_CLIENT_TESTING')) {
            define('GENERATE_CLIENT_TESTING', true);
        }
        $prevArgv = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = [__FILE__];
        require_once realpath(__DIR__ . '/../../cli/generate-client.php');
        $_SERVER['argv'] = $prevArgv;

        $GLOBALS['__dtoInterfaces'] = [];
        $GLOBALS['__dtoInProgress'] = [];

        $this->setEnvIfEmpty('DB_GATEWAY_URL', 'http://localhost:9000');
        $this->setEnvIfEmpty('DB_GATEWAY_PLATFORM', 'test-platform');
        $this->setEnvIfEmpty('AUTH_SERVICE_URL', 'http://auth.test/');
        $this->setEnvIfEmpty('AUTH_ISSUER', 'http://auth.test/');
        $ref = new \ReflectionClass(\StoneScriptPHP\Env::class);
        $prop = $ref->getProperty('_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(\StoneScriptPHP\Env::class);
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

    /** @return array<string,array{class-string}> */
    public static function frameworkResponseDtoProvider(): array
    {
        return [
            'ExchangeResponseDto' => [ExchangeResponseDto::class],
            'ProvisionTenantResponseDto' => [ProvisionTenantResponseDto::class],
            'ProfileResponseDto' => [ProfileResponseDto::class],
            'LoginResponseDto' => [LoginResponseDto::class],
            'RegisterResponseDto' => [RegisterResponseDto::class],
            'TenantSummaryDto' => [TenantSummaryDto::class],
            'MembershipsResponseDto' => [\StoneScriptPHP\Auth\ExternalAuth\Dto\MembershipsResponseDto::class],
            'OnboardingStatusResponseDto' => [\StoneScriptPHP\Auth\ExternalAuth\Dto\OnboardingStatusResponseDto::class],
            'AuthHealthResponseDto' => [\StoneScriptPHP\Auth\ExternalAuth\Dto\AuthHealthResponseDto::class],
            'OAuthInitiateResponseDto' => [\StoneScriptPHP\Auth\ExternalAuth\Dto\OAuthInitiateResponseDto::class],
            'RefreshResponseDto' => [\StoneScriptPHP\Auth\ExternalAuth\Dto\RefreshResponseDto::class],
            'LogoutResponseDto' => [\StoneScriptPHP\Auth\ExternalAuth\Dto\LogoutResponseDto::class],
            'RefreshTokenResponseDto (Model A)' => [\StoneScriptPHP\Auth\Dto\RefreshTokenResponseDto::class],
            'SubscriptionPlanDto' => [\StoneScriptPHP\Subscriptions\Dto\SubscriptionPlanDto::class],
            'SubscriptionStatusDto' => [\StoneScriptPHP\Subscriptions\Dto\SubscriptionStatusDto::class],
            'SubscriptionActivateResponseDto' => [\StoneScriptPHP\Subscriptions\Dto\SubscriptionActivateResponseDto::class],
            'RazorpayWebhookAckDto' => [\StoneScriptPHP\Subscriptions\Dto\RazorpayWebhookAckDto::class],
            'TrackEventResponseDto' => [\StoneScriptPHP\Analytics\Dto\TrackEventResponseDto::class],
        ];
    }

    /**
     * @dataProvider frameworkResponseDtoProvider
     */
    public function test_dto_reflects_cleanly_no_unknown_fallback(string $dtoClass): void
    {
        $tsName = reflectDto($dtoClass);
        $this->assertNotSame('unknown', $tsName, "$dtoClass failed to reflect — client-gen would silently fall back to ApiResponse");
        $this->assertArrayHasKey($tsName, $GLOBALS['__dtoInterfaces']);
    }

    public function test_exchange_response_dto_has_typed_nested_tenant_array(): void
    {
        reflectDto(ExchangeResponseDto::class);
        $body = $GLOBALS['__dtoInterfaces']['ExchangeResponseDto'];
        $this->assertStringContainsString('available_tenants: TenantSummaryDto[];', $body);
        $this->assertArrayHasKey('TenantSummaryDto', $GLOBALS['__dtoInterfaces']);
    }

    public function test_external_auth_get_route_definitions_wires_exchange_response_dto(): void
    {
        $routes = ExternalAuthRoutes::getRouteDefinitions(['exchange' => true, 'register' => false, 'login' => false, 'logout' => false, 'refresh' => false, 'onboarding_status' => false, 'oauth' => false, 'health' => false, 'profile' => false, 'select_tenant' => false, 'memberships' => false, 'check_slug' => false]);

        $this->assertArrayHasKey('/api/auth/exchange', $routes['POST']);
        $entry = $routes['POST']['/api/auth/exchange'];
        $this->assertSame('auth', $entry['group']);
        $this->assertSame(ExchangeResponseDto::class, $entry['response']);
    }

    public function test_external_auth_get_route_definitions_leaves_dead_endpoints_untyped(): void
    {
        // password_reset/verify_email/resend_code/change_password proxy to
        // external auth-service endpoints that a live audit found do not
        // exist on the configured service — see the KNOWN BUG comments in
        // ExternalAuthRoutes. They must have `group` (so client-gen doesn't
        // hard-error) but NO `response` (a fabricated contract for a 404
        // would be worse than none).
        $routes = ExternalAuthRoutes::getRouteDefinitions(['password_reset' => true, 'verify_email' => true, 'resend_code' => true, 'change_password' => true]);

        foreach (['/api/auth/forgot-password', '/api/auth/reset-password', '/api/auth/verify-email', '/api/auth/resend-code'] as $path) {
            $entry = $routes['POST'][$path] ?? null;
            $this->assertNotNull($entry, "$path should still be registered (group present)");
            $this->assertSame('auth', $entry['group']);
            $this->assertArrayNotHasKey('response', $entry, "$path is a dead endpoint — must not declare a fabricated response DTO");
        }
    }

    public function test_default_tenant_route_provider_wires_provision_tenant_response_dto(): void
    {
        $provider = new DefaultTenantRouteProvider();
        $routes = $provider->getRouteDefinitions('/api/auth', ['provision_tenant' => true]);

        $this->assertSame(
            ProvisionTenantResponseDto::class,
            $routes['POST']['/api/auth/provision-tenant']['response']
        );
    }

    public function test_subscription_routes_get_route_definitions_wires_response_dtos(): void
    {
        $routes = SubscriptionRoutes::getRouteDefinitions(['plans' => true, 'status' => true]);

        $this->assertSame(\StoneScriptPHP\Subscriptions\Dto\SubscriptionPlanDto::class, $routes['GET']['/subscription/plans']['response']);
        $this->assertTrue($routes['GET']['/subscription/plans']['collection']);
        $this->assertSame(\StoneScriptPHP\Subscriptions\Dto\SubscriptionStatusDto::class, $routes['GET']['/subscription/status']['response']);
    }

    public function test_analytics_routes_get_route_definitions_wires_response_dto(): void
    {
        $routes = AnalyticsRoutes::getRouteDefinitions(['enabled' => true]);

        $this->assertSame(
            \StoneScriptPHP\Analytics\Dto\TrackEventResponseDto::class,
            $routes['POST']['/portal/analytics/track']['response']
        );
    }

    public function test_register_attaches_response_dto_to_live_router_for_exchange(): void
    {
        $router = new Router();
        ExternalAuthRoutes::register($router, [
            'exchange' => true, 'register' => false, 'login' => false, 'logout' => false,
            'refresh' => false, 'onboarding_status' => false, 'oauth' => false, 'health' => false,
            'profile' => false, 'select_tenant' => false, 'memberships' => false, 'check_slug' => false,
            'legacy_compat' => false, 'auth_service_url' => 'http://auth.test/', 'auth_issuer' => 'http://auth.test/',
        ]);

        $meta = $router->getRouteMeta();
        $exchange = array_values(array_filter($meta, fn($r) => $r['path'] === '/api/auth/exchange' && $r['method'] === 'POST'));
        $this->assertCount(1, $exchange);
        $this->assertSame(ExchangeResponseDto::class, $exchange[0]['response']);
        $this->assertSame('auth', $exchange[0]['group']);
    }

    public function test_profile_route_response_dto_only_wired_when_both_resolvers_present(): void
    {
        $router = new Router();
        ExternalAuthRoutes::register($router, [
            'profile' => true, 'register' => false, 'login' => false, 'logout' => false,
            'refresh' => false, 'onboarding_status' => false, 'oauth' => false, 'health' => false,
            'exchange' => false, 'select_tenant' => false, 'memberships' => false, 'check_slug' => false,
            'legacy_compat' => false, 'auth_service_url' => 'http://auth.test/', 'auth_issuer' => 'http://auth.test/',
            // no roles_resolver/tenants_resolver supplied
        ]);

        $meta = $router->getRouteMeta();
        $me = array_values(array_filter($meta, fn($r) => $r['path'] === '/api/auth/me'));
        $this->assertCount(1, $me);
        $this->assertNull($me[0]['response'], 'without resolvers, ProfileRoute falls back to the raw proxy shape — must not declare ProfileResponseDto');

        $router2 = new Router();
        ExternalAuthRoutes::register($router2, [
            'profile' => true, 'register' => false, 'login' => false, 'logout' => false,
            'refresh' => false, 'onboarding_status' => false, 'oauth' => false, 'health' => false,
            'exchange' => false, 'select_tenant' => false, 'memberships' => false, 'check_slug' => false,
            'legacy_compat' => false, 'auth_service_url' => 'http://auth.test/', 'auth_issuer' => 'http://auth.test/',
            'roles_resolver' => fn($claims) => ['owner'],
            'tenants_resolver' => fn($claims) => [['id' => 't1', 'name' => 'Test Tenant']],
        ]);

        $meta2 = $router2->getRouteMeta();
        $me2 = array_values(array_filter($meta2, fn($r) => $r['path'] === '/api/auth/me'));
        $this->assertCount(1, $me2);
        $this->assertSame(ProfileResponseDto::class, $me2[0]['response']);
    }
}
