<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Firebase\JWT\JWT;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\Middleware\AccessTokenMiddleware;
use StoneScriptPHP\Auth\Middleware\AuthMiddlewareRegistrar;
use StoneScriptPHP\Auth\Middleware\RefreshTokenMiddleware;
use StoneScriptPHP\Auth\Middleware\SingleTokenMiddleware;
use StoneScriptPHP\Auth\InMemoryRefreshTokenStore;
use StoneScriptPHP\Auth\TokenClaims;
use StoneScriptPHP\Auth\TrustedIssuerVerifier;
use StoneScriptPHP\Routing\RouteAccess;

/**
 * SingleTokenMiddleware — standalone + no-tenant single-token accept/reject matrix.
 *
 * Locks in the ONE deliberate difference from AccessTokenMiddleware: the
 * authn/authz `purpose` equality gate is dropped (a passport is accepted on an
 * authorization route, and vice-versa), while EVERY other check — signature,
 * issuer, expiry, and `type == access` — is retained exactly as strict.
 *
 * @covers \StoneScriptPHP\Auth\Middleware\SingleTokenMiddleware
 */
class SingleTokenMiddlewareTest extends TestCase
{
    private const ISSUER = 'https://api.testapp.in';

    private \OpenSSLAsymmetricKey $priv;
    private SingleTokenMiddleware $middleware;
    private TrustedIssuerVerifier $verifier;

    protected function setUp(): void
    {
        $res = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->priv = $res;
        $pub = openssl_pkey_get_details($res)['key'];

        $this->verifier = new TrustedIssuerVerifier([
            self::ISSUER => ['kind' => 'local', 'public_key' => $pub],
        ]);
        $this->middleware = new SingleTokenMiddleware($this->verifier);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        AuthContext::clear();
    }

    /** @param array<string,mixed> $extra */
    private function mint(array $extra): string
    {
        return JWT::encode(
            $extra + ['iss' => self::ISSUER, 'sub' => 'u-1', 'iat' => time(), 'exp' => time() + 900],
            $this->priv,
            'RS256'
        );
    }

    private function setBearer(string $token): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }

    /** @param array<string,mixed> $route */
    private function runMw(array $route): array
    {
        $passed = false;
        $request = ['route' => $route];
        $response = $this->middleware->handle($request, function ($req) use (&$passed) {
            $passed = true;
            return null;
        });
        return ['passed' => $passed, 'response' => $response];
    }

    private function authzRoute(): array
    {
        return ['access' => RouteAccess::AUTHORIZATION, 'token_type' => RouteAccess::TOKEN_ACCESS];
    }

    private function authnRoute(): array
    {
        return ['access' => RouteAccess::AUTHENTICATION, 'token_type' => RouteAccess::TOKEN_ACCESS];
    }

    // ── accept: the WHOLE point — purpose is NOT gated ─────────────────────────

    public function test_authentication_purpose_token_passes_on_authorization_route(): void
    {
        // This is EXACTLY what AccessTokenMiddleware rejects with 403 — the
        // single-token relaxation is what makes the login-issued passport usable
        // as the API bearer with no exchange step.
        $this->setBearer($this->mint([
            'type' => TokenClaims::TYPE_ACCESS,
            'purpose' => TokenClaims::PURPOSE_AUTHENTICATION,
        ]));

        $r = $this->runMw($this->authzRoute());

        $this->assertTrue($r['passed'], 'passport (authentication purpose) must pass an authorization route in single-token mode');
        $this->assertNull($r['response']);
    }

    public function test_authorization_purpose_token_passes_on_authentication_route(): void
    {
        $this->setBearer($this->mint([
            'type' => TokenClaims::TYPE_ACCESS,
            'purpose' => TokenClaims::PURPOSE_AUTHORIZATION,
        ]));

        $r = $this->runMw($this->authnRoute());

        $this->assertTrue($r['passed']);
        $this->assertNull($r['response']);
    }

    public function test_token_with_no_purpose_claim_passes(): void
    {
        // A one-key platform may not even stamp a purpose. Accepted.
        $this->setBearer($this->mint(['type' => TokenClaims::TYPE_ACCESS]));

        $r = $this->runMw($this->authzRoute());

        $this->assertTrue($r['passed']);
    }

    public function test_public_route_passes_without_token(): void
    {
        $r = $this->runMw(['access' => RouteAccess::PUBLIC, 'token_type' => RouteAccess::TOKEN_ACCESS]);

        $this->assertTrue($r['passed']);
        $this->assertNull($r['response']);
    }

    // ── reject: every OTHER guarantee is retained ──────────────────────────────

    public function test_missing_bearer_still_returns_401(): void
    {
        $r = $this->runMw($this->authzRoute());

        $this->assertFalse($r['passed']);
        $this->assertInstanceOf(ApiResponse::class, $r['response']);
        $this->assertSame(401, $r['response']->httpStatusCode);
    }

    public function test_wrong_token_type_refresh_still_returns_401(): void
    {
        // type==refresh must NEVER satisfy an access route, even in single-token mode.
        $this->setBearer($this->mint([
            'type' => TokenClaims::TYPE_REFRESH,
            'purpose' => TokenClaims::PURPOSE_AUTHORIZATION,
        ]));

        $r = $this->runMw($this->authzRoute());

        $this->assertFalse($r['passed']);
        $this->assertSame(401, $r['response']->httpStatusCode);
    }

    public function test_untrusted_issuer_still_returns_401(): void
    {
        $otherRes = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $token = JWT::encode(
            ['iss' => 'https://evil.example.com', 'type' => TokenClaims::TYPE_ACCESS,
             'purpose' => TokenClaims::PURPOSE_AUTHORIZATION, 'iat' => time(), 'exp' => time() + 900],
            $otherRes,
            'RS256'
        );
        $this->setBearer($token);

        $r = $this->runMw($this->authzRoute());

        $this->assertFalse($r['passed']);
        $this->assertSame(401, $r['response']->httpStatusCode);
    }

    public function test_expired_token_still_returns_401(): void
    {
        $expired = JWT::encode(
            ['iss' => self::ISSUER, 'sub' => 'u-1', 'type' => TokenClaims::TYPE_ACCESS,
             'purpose' => TokenClaims::PURPOSE_AUTHORIZATION, 'iat' => time() - 2000, 'exp' => time() - 1000],
            $this->priv,
            'RS256'
        );
        $this->setBearer($expired);

        $r = $this->runMw($this->authzRoute());

        $this->assertFalse($r['passed']);
        $this->assertSame(401, $r['response']->httpStatusCode);
    }

    public function test_refresh_route_is_not_handled_by_single_token_middleware(): void
    {
        $r = $this->runMw(['access' => RouteAccess::AUTHENTICATION, 'token_type' => RouteAccess::TOKEN_REFRESH]);

        $this->assertTrue($r['passed'], 'Single-token access middleware must not enforce refresh routes');
    }

    public function test_populates_jwt_claims_on_success(): void
    {
        $this->setBearer($this->mint([
            'type' => TokenClaims::TYPE_ACCESS,
            'purpose' => TokenClaims::PURPOSE_AUTHENTICATION,
        ]));

        $received = null;
        $request = ['route' => $this->authzRoute()];
        $this->middleware->handle($request, function ($req) use (&$received) {
            $received = $req;
            return null;
        });

        $this->assertIsArray($received['jwt_claims']);
        $this->assertSame(TokenClaims::PURPOSE_AUTHENTICATION, $received['jwt_claims']['purpose']);
    }

    // ── registrar wiring: single-token pipeline boots as fully protected ───────

    public function test_registrar_createSingleToken_returns_single_and_refresh_middleware(): void
    {
        [$access, $refresh] = AuthMiddlewareRegistrar::createSingleToken(
            $this->verifier,
            new InMemoryRefreshTokenStore()
        );

        $this->assertInstanceOf(SingleTokenMiddleware::class, $access);
        $this->assertInstanceOf(RefreshTokenMiddleware::class, $refresh);
    }

    public function test_single_token_pipeline_passes_assertFullyWired(): void
    {
        $pipeline = AuthMiddlewareRegistrar::createSingleToken(
            $this->verifier,
            new InMemoryRefreshTokenStore()
        );
        $routeMetas = [
            ['access' => RouteAccess::AUTHORIZATION, 'token_type' => RouteAccess::TOKEN_ACCESS],
            ['access' => RouteAccess::AUTHENTICATION, 'token_type' => RouteAccess::TOKEN_REFRESH],
        ];

        // Must NOT throw — SingleTokenMiddleware is recognised as an access enforcer.
        AuthMiddlewareRegistrar::assertFullyWired($pipeline, $routeMetas);
        $this->assertTrue(true);
    }
}
