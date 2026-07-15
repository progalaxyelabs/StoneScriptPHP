<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Firebase\JWT\JWT;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\Middleware\AccessTokenMiddleware;
use StoneScriptPHP\Auth\TokenClaims;
use StoneScriptPHP\Auth\TrustedIssuerVerifier;
use StoneScriptPHP\Routing\RouteAccess;

/**
 * AccessTokenMiddleware — stateless accept/reject matrix.
 *
 * @covers \StoneScriptPHP\Auth\Middleware\AccessTokenMiddleware
 */
class AccessTokenMiddlewareTest extends TestCase
{
    private const ISSUER = 'https://api.testapp.in';

    private \OpenSSLAsymmetricKey $priv;
    private AccessTokenMiddleware $middleware;

    protected function setUp(): void
    {
        $res = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->priv = $res;
        $pub = openssl_pkey_get_details($res)['key'];

        $verifier = new TrustedIssuerVerifier([
            self::ISSUER => ['kind' => 'local', 'public_key' => $pub],
        ]);
        $this->middleware = new AccessTokenMiddleware($verifier);
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

    // ── accept ───────────────────────────────────────────────────────────────

    public function test_valid_access_token_matching_purpose_passes(): void
    {
        $this->setBearer($this->mint([
            'type' => TokenClaims::TYPE_ACCESS,
            'purpose' => TokenClaims::PURPOSE_AUTHORIZATION,
            'tenant_id' => 't-1',
        ]));

        $r = $this->runMw($this->authzRoute());

        $this->assertTrue($r['passed']);
        $this->assertNull($r['response']);
    }

    public function test_public_route_passes_without_token(): void
    {
        $r = $this->runMw(['access' => RouteAccess::PUBLIC, 'token_type' => RouteAccess::TOKEN_ACCESS]);

        $this->assertTrue($r['passed']);
        $this->assertNull($r['response']);
    }

    // ── reject ───────────────────────────────────────────────────────────────

    public function test_missing_bearer_returns_401(): void
    {
        $r = $this->runMw($this->authzRoute());

        $this->assertFalse($r['passed']);
        $this->assertInstanceOf(ApiResponse::class, $r['response']);
        $this->assertSame(401, $r['response']->httpStatusCode);
    }

    public function test_wrong_token_type_refresh_returns_401(): void
    {
        $this->setBearer($this->mint([
            'type' => TokenClaims::TYPE_REFRESH,           // wrong — route wants access
            'purpose' => TokenClaims::PURPOSE_AUTHORIZATION,
        ]));

        $r = $this->runMw($this->authzRoute());

        $this->assertFalse($r['passed']);
        $this->assertSame(401, $r['response']->httpStatusCode);
    }

    public function test_wrong_purpose_returns_403(): void
    {
        $this->setBearer($this->mint([
            'type' => TokenClaims::TYPE_ACCESS,
            'purpose' => TokenClaims::PURPOSE_AUTHENTICATION,  // wrong — route wants authorization
        ]));

        $r = $this->runMw($this->authzRoute());

        $this->assertFalse($r['passed']);
        $this->assertSame(403, $r['response']->httpStatusCode);
    }

    public function test_authentication_route_accepts_authentication_access_token(): void
    {
        $this->setBearer($this->mint([
            'type' => TokenClaims::TYPE_ACCESS,
            'purpose' => TokenClaims::PURPOSE_AUTHENTICATION,
        ]));

        $r = $this->runMw(['access' => RouteAccess::AUTHENTICATION, 'token_type' => RouteAccess::TOKEN_ACCESS]);

        $this->assertTrue($r['passed']);
    }

    public function test_untrusted_issuer_token_returns_401(): void
    {
        // Sign with a different key/issuer not in the trusted set.
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

    public function test_refresh_route_is_not_handled_by_access_middleware(): void
    {
        // A refresh-token route passes THROUGH (RefreshTokenMiddleware owns it).
        $r = $this->runMw(['access' => RouteAccess::AUTHENTICATION, 'token_type' => RouteAccess::TOKEN_REFRESH]);

        $this->assertTrue($r['passed'], 'Access middleware must not enforce refresh routes');
    }

    public function test_populates_jwt_claims_on_success(): void
    {
        $this->setBearer($this->mint([
            'type' => TokenClaims::TYPE_ACCESS,
            'purpose' => TokenClaims::PURPOSE_AUTHORIZATION,
            'tenant_id' => 't-1',
        ]));

        $received = null;
        $request = ['route' => $this->authzRoute()];
        $this->middleware->handle($request, function ($req) use (&$received) {
            $received = $req;
            return null;
        });

        $this->assertIsArray($received['jwt_claims']);
        $this->assertSame('t-1', $received['jwt_claims']['tenant_id']);
    }
}
