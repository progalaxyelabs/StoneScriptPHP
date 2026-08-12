<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\ExternalAuth\DefaultTenantRouteProvider;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ProvisionTenantRoute;
use StoneScriptPHP\Env;
use StoneScriptPHP\Routing\Router;

/**
 * ExternalAuthConfig::$provisionTenantRouteClass (2026-08-12) — lets a platform
 * substitute its own ProvisionTenantRoute subclass without reimplementing the
 * whole route from scratch, fixing the real blocker found investigating why
 * real fleet platforms both gave up on `extends ProvisionTenantRoute`:
 *
 *   - ProvisionTenantRoute's constructor REQUIRES $client/$hooks/$config/
 *     $provisioner (kept exactly as-is here — constructor injection for
 *     testability, same as every other ExternalAuth route).
 *   - A subclass registered via a platform's own routes.php
 *     (`'handler' => MyRoute::class`) is instantiated by
 *     Router::executeHandler() with a bare `new $handlerClass()` — zero args —
 *     which fatals against that required constructor.
 *   - It's also chronologically impossible to work around from routes.php:
 *     that file's array is evaluated as a function ARGUMENT to
 *     Application::run(), before ExternalAuthRoutes::register() (which builds
 *     $client/$config/$provisioner) ever runs.
 *
 * Fix: DefaultTenantRouteProvider::register() — which DOES already have real
 * $client/$hooks/$config/$provisioner in scope at construction time — builds
 * whichever class $config->provisionTenantRouteClass names, defaulting to
 * ProvisionTenantRoute::class. Same precedented pattern as
 * StoneScriptPHP\Auth\AuthRoutes::register()'s `new RefreshRoute($jwtHandler)`
 * — the framework's own registration code builds the object, not routes.php.
 *
 * @covers \StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig
 * @covers \StoneScriptPHP\Auth\ExternalAuth\DefaultTenantRouteProvider
 */
class ProvisionTenantRouteClassOverrideTest extends TestCase
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

    // ── ExternalAuthConfig validation ───────────────────────────────────────

    public function test_defaults_to_framework_provision_tenant_route(): void
    {
        $config = new ExternalAuthConfig(['provision_tenant' => true]);

        $this->assertSame(ProvisionTenantRoute::class, $config->provisionTenantRouteClass);
    }

    public function test_accepts_a_real_subclass_by_class_name_string(): void
    {
        $config = new ExternalAuthConfig([
            'provision_tenant' => true,
            'provision_tenant_route_class' => FixtureProvisionTenantRoute::class,
        ]);

        $this->assertSame(FixtureProvisionTenantRoute::class, $config->provisionTenantRouteClass);
    }

    public function test_rejects_a_class_that_is_not_a_provision_tenant_route_subclass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be .*ProvisionTenantRoute.*or a subclass/');

        new ExternalAuthConfig([
            'provision_tenant_route_class' => \stdClass::class,
        ]);
    }

    /**
     * Deliberately scalar-only: passing an OBJECT INSTANCE (not a class name)
     * must be rejected, not silently accepted — this option is a class-name
     * string by design (see ExternalAuthConfig::$provisionTenantRouteClass's
     * docblock: "deliberately NOT an object instance or a closure").
     */
    public function test_rejects_an_object_instance_instead_of_a_class_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be a class name string/');

        new ExternalAuthConfig([
            'provision_tenant_route_class' => new \stdClass(),
        ]);
    }

    // ── DefaultTenantRouteProvider actually uses the override ─────────────

    public function test_provider_registers_the_overridden_class_not_the_default(): void
    {
        $config = new ExternalAuthConfig([
            'provision_tenant' => true,
            'provision_tenant_route_class' => FixtureProvisionTenantRoute::class,
        ]);
        $client = new ExternalAuthServiceClient($config->authServiceUrl, $config->platformCode);
        $router = new Router();

        (new DefaultTenantRouteProvider())->register($router, '/api/auth', $client, $config, null, null, null);

        $handler = $this->handlerClassAt($router, 'POST', '/api/auth/provision-tenant');
        $this->assertSame(FixtureProvisionTenantRoute::class, $handler);
    }

    public function test_provider_registers_the_default_class_when_not_overridden(): void
    {
        $config = new ExternalAuthConfig(['provision_tenant' => true]);
        $client = new ExternalAuthServiceClient($config->authServiceUrl, $config->platformCode);
        $router = new Router();

        (new DefaultTenantRouteProvider())->register($router, '/api/auth', $client, $config, null, null, null);

        $handler = $this->handlerClassAt($router, 'POST', '/api/auth/provision-tenant');
        $this->assertSame(ProvisionTenantRoute::class, $handler);
    }

    // ── The override-seam methods (mintProvisionApiToken/slugify/generateUuid) ──
    // are now protected and actually reachable from a subclass ─────────────

    /**
     * Constructs the subclass with the SAME real DI arguments
     * DefaultTenantRouteProvider::register() uses (proving the constructor
     * itself needed no changes — this was never about the constructor being
     * too strict, only about routes.php having no way to satisfy it), then
     * invokes the now-protected slugify() via reflection to prove the
     * subclass's override actually executes instead of the parent's.
     */
    public function test_subclass_override_of_protected_slugify_takes_effect(): void
    {
        $config = new ExternalAuthConfig(['provision_tenant' => true]);
        $client = new ExternalAuthServiceClient($config->authServiceUrl, $config->platformCode);

        $subclass = new FixtureProvisionTenantRoute($client, $config->hooks, $config, null);
        $base     = new ProvisionTenantRoute($client, $config->hooks, $config, null);

        $subclassSlug = $this->invokeProtected($subclass, 'slugify', ['Acme Store']);
        $baseSlug     = $this->invokeProtected($base, 'slugify', ['Acme Store']);

        $this->assertSame('FIXTURE-OVERRIDE-acme-store', $subclassSlug);
        $this->assertSame('acme-store', $baseSlug);
        $this->assertNotSame($subclassSlug, $baseSlug);
    }

    private function invokeProtected(object $obj, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }

    /** @return string|null Handler class name registered at METHOD path, or null if not found. */
    private function handlerClassAt(Router $router, string $method, string $path): ?string
    {
        foreach ($router->getRouteMeta() as $meta) {
            if ($meta['method'] === $method && $meta['path'] === $path) {
                return $meta['handler'];
            }
        }
        return null;
    }
}

/**
 * Minimal real subclass — proves extension works through ordinary PHP
 * inheritance once the constructor requirement is satisfiable and the
 * customization points are protected, not private. Intentionally does NOT
 * override process() — only the narrow slugify() seam, exactly the "override
 * one method instead of reimplementing the whole route" outcome the original
 * investigation found impossible.
 */
class FixtureProvisionTenantRoute extends ProvisionTenantRoute
{
    protected function slugify(string $name): string
    {
        return 'FIXTURE-OVERRIDE-' . parent::slugify($name);
    }
}
