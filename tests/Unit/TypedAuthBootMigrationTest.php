<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Application;
use StoneScriptPHP\Auth\AuthRoutes;
use StoneScriptPHP\Auth\InMemoryRefreshTokenStore;
use StoneScriptPHP\Auth\Middleware\AuthMiddlewareRegistrar;
use StoneScriptPHP\Auth\Middleware\AuthWiringException;
use StoneScriptPHP\Auth\RsaJwtHandler;
use StoneScriptPHP\Auth\TrustedIssuerVerifier;
use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\Routing\RouteAccess;
use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\ApiResponse;

/**
 * Consumer-shaped boot verification for the 7.0.0 strict typed-auth model.
 *
 * These build a Router the way Application::run() does for a real builtin platform —
 * standard (non-typed) middleware + the framework's OWN auto-registered auth routes
 * (/auth/refresh, /auth/logout) + a protected business route — and exercise the SAME
 * boot guard run() invokes. This reproduces the real-consumer regression (an
 * un-migrated builtin platform 500ing on boot) and its fix, which the framework's own
 * unit suite never caught because it never boots a consumer-shaped router.
 *
 * @covers \StoneScriptPHP\Auth\Middleware\AuthMiddlewareRegistrar
 * @covers \StoneScriptPHP\Auth\Middleware\AuthWiringException
 * @covers \StoneScriptPHP\Application
 */
class TypedAuthBootMigrationTest extends TestCase
{
    /** A stand-in for the standard non-typed middleware run() wires (Logging/Cors/etc). */
    private function nonTypedMiddleware(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            public function handle(array $request, callable $next): ?ApiResponse
            {
                return $next($request);
            }
        };
    }

    /** Build a builtin-consumer-shaped router (framework auth routes + a business route). */
    private function consumerRouter(): Router
    {
        $router = new Router();
        $router->use($this->nonTypedMiddleware());  // realistic: non-typed mw present
        // Framework's own auto-registered auth routes — protected by default.
        AuthRoutes::register($router, [
            'jwt_handler' => new RsaJwtHandler(),
            'prefix'      => '/api/auth',
        ]);
        // A protected business route (old-schema: no access/token_type declared).
        $router->loadRoutes(['GET' => ['/api/warehouses' => 'App\\Routes\\ListWarehouses']]);
        return $router;
    }

    private function typedAuthPair(): array
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $verifier = new TrustedIssuerVerifier([
            'https://api.testapp.in' => ['kind' => 'local', 'public_key' => openssl_pkey_get_details($res)['key']],
        ]);
        return AuthMiddlewareRegistrar::create($verifier, new InMemoryRefreshTokenStore());
    }

    // ── (a) un-migrated builtin platform → actionable migration error ────────────

    public function test_unmigrated_builtin_boot_throws_actionable_migration_error(): void
    {
        $router = $this->consumerRouter();

        try {
            AuthMiddlewareRegistrar::assertFullyWired(
                $router->getGlobalMiddleware(),
                $router->getRouteMeta()
            );
            $this->fail('Un-migrated builtin platform must NOT boot cleanly — expected AuthWiringException');
        } catch (AuthWiringException $e) {
            $msg = $e->getMessage();
            // The message IS the migration aid — assert it is clear and actionable.
            $this->assertStringContainsString('old routing schema detected', $msg);
            $this->assertStringContainsString('AuthMiddlewareRegistrar::create', $msg);
            $this->assertStringContainsString('token_type', $msg);
            $this->assertStringContainsString('UPGRADING-7.0.md', $msg);
        }
    }

    public function test_migration_message_helper_matches_thrown_message(): void
    {
        $this->assertSame(
            AuthMiddlewareRegistrar::migrationMessage(),
            (function () {
                try {
                    AuthMiddlewareRegistrar::assertFullyWired(
                        [$this->nonTypedMiddleware()],
                        [['method' => 'GET', 'path' => '/x', 'access' => RouteAccess::AUTHORIZATION, 'token_type' => 'access']]
                    );
                    return '';
                } catch (AuthWiringException $e) {
                    return $e->getMessage();
                }
            })()
        );
    }

    // ── (b) migrated platform → boots; still refuses to boot on a half-wire ──────

    public function test_migrated_platform_boots_when_both_middleware_wired(): void
    {
        $router = $this->consumerRouter();
        foreach ($this->typedAuthPair() as $mw) {
            $router->use($mw);
        }

        // Should NOT throw — both credential classes enforced.
        AuthMiddlewareRegistrar::assertFullyWired(
            $router->getGlobalMiddleware(),
            $router->getRouteMeta()
        );
        $this->addToAssertionCount(1);
    }

    public function test_migrated_but_half_wired_still_refuses_to_boot(): void
    {
        $router = $this->consumerRouter();
        // A migrated platform that explicitly declares a refresh route...
        $router->loadRoutes(['POST' => ['/api/auth/token-refresh' => [
            'handler'    => 'App\\Routes\\TokenRefresh',
            'access'     => RouteAccess::AUTHORIZATION,
            'token_type' => RouteAccess::TOKEN_REFRESH,
        ]]]);
        [$accessMw] = $this->typedAuthPair();  // ...but wires ONLY the access middleware
        $router->use($accessMw);

        try {
            AuthMiddlewareRegistrar::assertFullyWired(
                $router->getGlobalMiddleware(),
                $router->getRouteMeta()
            );
            $this->fail('A half-wired platform must refuse to boot');
        } catch (AuthWiringException $e) {
            // Half-wire message names the missing middleware, not the full migration guide.
            $this->assertStringContainsString('RefreshTokenMiddleware', $e->getMessage());
            $this->assertStringContainsString('wiring incomplete', $e->getMessage());
        }
    }

    // ── run()'s failure experience: a controlled 500, NOT a bare stack trace ─────

    public function test_boot_config_error_renders_clean_json_not_a_stack_trace(): void
    {
        // Application::run() catches AuthWiringException and delegates to
        // renderBootConfigError(); verify that renders clean, parseable JSON carrying
        // the actionable message — never an uncaught exception / stack trace.
        $startTime = new \ReflectionProperty(Application::class, 'startTime');
        $startTime->setAccessible(true);
        $startTime->setValue(null, microtime(true));

        $render = new \ReflectionMethod(Application::class, 'renderBootConfigError');
        $render->setAccessible(true);

        $msg = AuthMiddlewareRegistrar::migrationMessage();

        ob_start();
        $render->invoke(null, $msg);
        $out = ob_get_clean();

        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded, 'Boot error output must be clean JSON, not a stack trace');
        $this->assertSame('error', $decoded['status']);
        $this->assertSame('auth_wiring_required', $decoded['data']['error']);
        $this->assertStringContainsString('old routing schema detected', $decoded['message']);
    }
}
