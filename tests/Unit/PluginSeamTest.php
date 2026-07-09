<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Plugin\AbstractPlugin;
use StoneScriptPHP\Plugin\PluginInterface;
use StoneScriptPHP\Routing\Middleware\GatewayTenantMiddleware;
use StoneScriptPHP\Tenancy\NoTenantStrategy;
use StoneScriptPHP\Tenancy\TenancyStrategyInterface;
use StoneScriptPHP\Auth\AuthenticatedUser;

/**
 * Phase 1 extensibility seam tests (PluginInterface, TenancyStrategyInterface).
 *
 * These exercise the additive hook points in isolation — Application::run()
 * itself can't be unit tested (it dispatches HTTP), but its plugin-normalization
 * and route/middleware-merge helpers are pure and reflectable, and the
 * lower-level pieces (GatewayTenantMiddleware, NoTenantStrategy) are directly
 * testable without any framework bootstrap.
 *
 * @covers \StoneScriptPHP\Plugin\AbstractPlugin
 * @covers \StoneScriptPHP\Tenancy\NoTenantStrategy
 * @covers \StoneScriptPHP\Routing\Middleware\GatewayTenantMiddleware
 * @covers \StoneScriptPHP\Application
 */
class PluginSeamTest extends TestCase
{
    /**
     * AbstractPlugin's no-op defaults must produce genuinely empty contributions —
     * this is what guarantees "extending it and overriding nothing" changes nothing.
     */
    public function test_abstract_plugin_defaults_are_all_empty(): void
    {
        $plugin = new class extends AbstractPlugin {
            public function name(): string
            {
                return 'noop-plugin';
            }
        };

        $this->assertSame([], $plugin->middleware());
        $this->assertSame([], $plugin->routes());
        $this->assertSame([], $plugin->migrationPaths());
        $this->assertSame([], $plugin->schemaPaths());
        $this->assertNull($plugin->tenancyStrategy());
        $this->assertInstanceOf(PluginInterface::class, $plugin);
    }

    /**
     * NoTenantStrategy must reproduce the exact pre-refactor GatewayTenantMiddleware
     * behavior: forward $user->tenant_id, nothing more, nothing derived elsewhere.
     */
    public function test_no_tenant_strategy_forwards_user_tenant_id(): void
    {
        $strategy = new NoTenantStrategy();

        $user = new AuthenticatedUser(user_id: 'u-1', tenant_id: 't-1');
        $this->assertSame('t-1', $strategy->resolveTenantId(['input' => []], $user));

        $passportUser = new AuthenticatedUser(user_id: 'u-1', tenant_id: null);
        $this->assertNull($strategy->resolveTenantId(['input' => []], $passportUser));

        $this->assertNull($strategy->resolveTenantId(['input' => []], null));
    }

    /**
     * GatewayTenantMiddleware without a constructor argument (every pre-Phase-1
     * call site, including Application::run()'s `new GatewayTenantMiddleware()`)
     * must default to NoTenantStrategy — confirmed via reflection since the
     * strategy property is private.
     */
    public function test_gateway_tenant_middleware_defaults_to_no_tenant_strategy(): void
    {
        $middleware = new GatewayTenantMiddleware();

        $ref = new \ReflectionClass($middleware);
        $prop = $ref->getProperty('strategy');
        $prop->setAccessible(true);

        $this->assertInstanceOf(NoTenantStrategy::class, $prop->getValue($middleware));
    }

    /**
     * A custom TenancyStrategyInterface passed to GatewayTenantMiddleware is used
     * as-is — the Phase 1 injection seam a future multi-tenancy plugin relies on.
     */
    public function test_gateway_tenant_middleware_accepts_custom_strategy(): void
    {
        $customStrategy = new class implements TenancyStrategyInterface {
            public function resolveTenantId(array $request, ?AuthenticatedUser $user): ?string
            {
                return 'custom-tenant-from-url';
            }
        };

        $middleware = new GatewayTenantMiddleware($customStrategy);

        $ref = new \ReflectionClass($middleware);
        $prop = $ref->getProperty('strategy');
        $prop->setAccessible(true);

        $this->assertSame($customStrategy, $prop->getValue($middleware));
    }

    /**
     * Application::normalizePlugins() (reflected — private) must silently drop
     * non-PluginInterface entries rather than throwing, so a malformed
     * plugins.php can't take the whole platform down at boot.
     */
    public function test_normalize_plugins_drops_invalid_entries(): void
    {
        $validPlugin = new class extends AbstractPlugin {
            public function name(): string
            {
                return 'valid';
            }
        };

        $ref = new \ReflectionClass(\StoneScriptPHP\Application::class);
        $method = $ref->getMethod('normalizePlugins');
        $method->setAccessible(true);

        $result = $method->invoke(null, [$validPlugin, 'not-a-plugin', 42, null]);

        $this->assertCount(1, $result);
        $this->assertSame($validPlugin, $result[0]);
    }

    /**
     * Application::normalizePlugins() with non-array input (defensive — a
     * plugins.php that returns the wrong shape) returns an empty list, not a fatal.
     */
    public function test_normalize_plugins_handles_non_array_input(): void
    {
        $ref = new \ReflectionClass(\StoneScriptPHP\Application::class);
        $method = $ref->getMethod('normalizePlugins');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke(null, 'not-an-array'));
        $this->assertSame([], $method->invoke(null, null));
    }

    /**
     * mergePluginRoutes(): with no plugins, $appRoutes passes through unchanged
     * (identity check, not just value-equal) — the non-breaking-by-construction
     * guarantee for the default (empty plugins) case.
     */
    public function test_merge_plugin_routes_is_noop_with_no_plugins(): void
    {
        $appRoutes = ['GET' => ['/foo' => ['handler' => 'X']]];

        $ref = new \ReflectionClass(\StoneScriptPHP\Application::class);
        $method = $ref->getMethod('mergePluginRoutes');
        $method->setAccessible(true);

        $result = $method->invoke(null, $appRoutes, []);

        $this->assertSame($appRoutes, $result);
    }

    /**
     * mergePluginRoutes(): a platform's own route for the same METHOD+path always
     * wins over a plugin-contributed one (§ PluginInterface precedence docs).
     */
    public function test_merge_plugin_routes_platform_route_wins_on_collision(): void
    {
        $appRoutes = ['GET' => ['/shared' => ['handler' => 'PlatformHandler']]];

        $plugin = new class extends AbstractPlugin {
            public function name(): string
            {
                return 'route-plugin';
            }
            public function routes(): array
            {
                return [
                    'GET' => [
                        '/shared' => ['handler' => 'PluginHandler'],
                        '/plugin-only' => ['handler' => 'PluginOnlyHandler'],
                    ],
                ];
            }
        };

        $ref = new \ReflectionClass(\StoneScriptPHP\Application::class);
        $method = $ref->getMethod('mergePluginRoutes');
        $method->setAccessible(true);

        $result = $method->invoke(null, $appRoutes, [$plugin]);

        $this->assertSame('PlatformHandler', $result['GET']['/shared']['handler'], 'platform route must win on collision');
        $this->assertSame('PluginOnlyHandler', $result['GET']['/plugin-only']['handler'], 'plugin-only route must still be added');
    }

    /**
     * resolveTenancyStrategy(): explicit config['tenancy']['strategy'] wins over
     * any plugin-supplied strategy.
     */
    public function test_resolve_tenancy_strategy_explicit_config_wins(): void
    {
        $explicitStrategy = new NoTenantStrategy();
        $pluginStrategy = new class implements TenancyStrategyInterface {
            public function resolveTenantId(array $request, ?AuthenticatedUser $user): ?string
            {
                return 'from-plugin';
            }
        };
        $plugin = new class ($pluginStrategy) extends AbstractPlugin {
            public function __construct(private TenancyStrategyInterface $strategy)
            {
            }
            public function name(): string
            {
                return 'tenancy-plugin';
            }
            public function tenancyStrategy(): ?TenancyStrategyInterface
            {
                return $this->strategy;
            }
        };

        $ref = new \ReflectionClass(\StoneScriptPHP\Application::class);
        $method = $ref->getMethod('resolveTenancyStrategy');
        $method->setAccessible(true);

        $result = $method->invoke(null, ['strategy' => $explicitStrategy], [$plugin]);

        $this->assertSame($explicitStrategy, $result);
    }

    /**
     * resolveTenancyStrategy(): with no explicit config, falls back to the first
     * plugin that supplies a non-null strategy.
     */
    public function test_resolve_tenancy_strategy_falls_back_to_plugin(): void
    {
        $pluginStrategy = new class implements TenancyStrategyInterface {
            public function resolveTenantId(array $request, ?AuthenticatedUser $user): ?string
            {
                return 'from-plugin';
            }
        };
        $plugin = new class ($pluginStrategy) extends AbstractPlugin {
            public function __construct(private TenancyStrategyInterface $strategy)
            {
            }
            public function name(): string
            {
                return 'tenancy-plugin';
            }
            public function tenancyStrategy(): ?TenancyStrategyInterface
            {
                return $this->strategy;
            }
        };

        $ref = new \ReflectionClass(\StoneScriptPHP\Application::class);
        $method = $ref->getMethod('resolveTenancyStrategy');
        $method->setAccessible(true);

        $result = $method->invoke(null, [], [$plugin]);

        $this->assertSame($pluginStrategy, $result);
    }

    /**
     * resolveTenancyStrategy(): with no config and no plugins (the default,
     * today, for every platform) returns null — GatewayTenantMiddleware then
     * defaults to NoTenantStrategy itself.
     */
    public function test_resolve_tenancy_strategy_returns_null_by_default(): void
    {
        $ref = new \ReflectionClass(\StoneScriptPHP\Application::class);
        $method = $ref->getMethod('resolveTenancyStrategy');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(null, [], []));
    }
}
