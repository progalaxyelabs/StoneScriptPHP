<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\InMemoryRefreshTokenStore;
use StoneScriptPHP\Auth\Middleware\AccessTokenMiddleware;
use StoneScriptPHP\Auth\Middleware\AuthMiddlewareRegistrar;
use StoneScriptPHP\Auth\Middleware\RefreshTokenMiddleware;
use StoneScriptPHP\Auth\TrustedIssuerVerifier;
use StoneScriptPHP\Routing\RouteAccess;

/**
 * AuthMiddlewareRegistrar — safe-by-construction install + fail-closed boot assertion
 * that prevents a half-wired pipeline from leaving one credential class unprotected.
 *
 * @covers \StoneScriptPHP\Auth\Middleware\AuthMiddlewareRegistrar
 */
class AuthMiddlewareRegistrarTest extends TestCase
{
    private TrustedIssuerVerifier $verifier;
    private InMemoryRefreshTokenStore $store;

    protected function setUp(): void
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->verifier = new TrustedIssuerVerifier([
            'https://api.testapp.in' => ['kind' => 'local', 'public_key' => openssl_pkey_get_details($res)['key']],
        ]);
        $this->store = new InMemoryRefreshTokenStore();
    }

    private function both(): array
    {
        return AuthMiddlewareRegistrar::create($this->verifier, $this->store);
    }

    public function test_create_returns_both_middleware(): void
    {
        [$access, $refresh] = $this->both();
        $this->assertInstanceOf(AccessTokenMiddleware::class, $access);
        $this->assertInstanceOf(RefreshTokenMiddleware::class, $refresh);
    }

    public function test_no_typed_routes_is_a_noop_even_with_empty_pipeline(): void
    {
        // Platforms that never adopt the model are unaffected.
        AuthMiddlewareRegistrar::assertFullyWired([], [
            ['access' => null, 'token_type' => 'access'],
            ['access' => RouteAccess::PUBLIC, 'token_type' => 'access'],
        ]);
        $this->addToAssertionCount(1);
    }

    public function test_both_present_passes(): void
    {
        AuthMiddlewareRegistrar::assertFullyWired($this->both(), [
            ['access' => RouteAccess::AUTHORIZATION, 'token_type' => RouteAccess::TOKEN_ACCESS],
            ['access' => RouteAccess::AUTHENTICATION, 'token_type' => RouteAccess::TOKEN_REFRESH],
        ]);
        $this->addToAssertionCount(1);
    }

    public function test_missing_access_middleware_throws_when_access_route_declared(): void
    {
        [, $refresh] = $this->both();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/AccessTokenMiddleware/');
        AuthMiddlewareRegistrar::assertFullyWired([$refresh], [
            ['access' => RouteAccess::AUTHORIZATION, 'token_type' => RouteAccess::TOKEN_ACCESS],
        ]);
    }

    public function test_missing_refresh_middleware_throws_when_refresh_route_declared(): void
    {
        [$access] = $this->both();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/RefreshTokenMiddleware/');
        AuthMiddlewareRegistrar::assertFullyWired([$access], [
            ['access' => RouteAccess::AUTHENTICATION, 'token_type' => RouteAccess::TOKEN_REFRESH],
        ]);
    }

    public function test_only_public_routes_do_not_require_any_middleware(): void
    {
        AuthMiddlewareRegistrar::assertFullyWired([], [
            ['access' => RouteAccess::PUBLIC, 'token_type' => RouteAccess::TOKEN_ACCESS],
        ]);
        $this->addToAssertionCount(1);
    }
}
