<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Firebase\JWT\JWT;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\InMemoryRefreshTokenStore;
use StoneScriptPHP\Auth\Middleware\RefreshTokenMiddleware;
use StoneScriptPHP\Auth\TokenClaims;
use StoneScriptPHP\Auth\TrustedIssuerVerifier;
use StoneScriptPHP\Routing\RouteAccess;

/**
 * RefreshTokenMiddleware — stateful accept/reject matrix, incl. the DB-row gate and
 * revoke-deletes-row behaviour.
 *
 * @covers \StoneScriptPHP\Auth\Middleware\RefreshTokenMiddleware
 */
class RefreshTokenMiddlewareTest extends TestCase
{
    private const ISSUER = 'https://api.testapp.in';

    private \OpenSSLAsymmetricKey $priv;
    private InMemoryRefreshTokenStore $store;
    private RefreshTokenMiddleware $middleware;

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
        $this->store = new InMemoryRefreshTokenStore();
        $this->middleware = new RefreshTokenMiddleware($verifier, $this->store);
    }

    /** @param array<string,mixed> $extra */
    private function mint(array $extra): string
    {
        return JWT::encode(
            $extra + ['iss' => self::ISSUER, 'sub' => 'u-1', 'iat' => time(), 'exp' => time() + 100000],
            $this->priv,
            'RS256'
        );
    }

    private function persist(string $token, string $purpose): void
    {
        $this->store->store(hash('sha256', $token), 'u-1', $purpose, time() + 100000);
    }

    /**
     * @param array<string,mixed> $route
     * @param array<string,mixed> $body
     */
    private function runMw(array $route, array $body): array
    {
        $passed = false;
        $received = null;
        $request = ['route' => $route, 'body' => $body];
        $response = $this->middleware->handle($request, function ($req) use (&$passed, &$received) {
            $passed = true;
            $received = $req;
            return null;
        });
        return ['passed' => $passed, 'response' => $response, 'received' => $received];
    }

    private function authnRefreshRoute(): array
    {
        return ['access' => RouteAccess::AUTHENTICATION, 'token_type' => RouteAccess::TOKEN_REFRESH];
    }

    // ── accept ───────────────────────────────────────────────────────────────

    public function test_valid_refresh_with_present_row_passes(): void
    {
        $token = $this->mint(['type' => TokenClaims::TYPE_REFRESH, 'purpose' => TokenClaims::PURPOSE_AUTHENTICATION]);
        $this->persist($token, TokenClaims::PURPOSE_AUTHENTICATION);

        $r = $this->runMw($this->authnRefreshRoute(), ['refresh_token' => $token]);

        $this->assertTrue($r['passed']);
        $this->assertNull($r['response']);
        $this->assertIsArray($r['received']['refresh_claims']);
    }

    // ── reject ───────────────────────────────────────────────────────────────

    public function test_missing_refresh_token_returns_401(): void
    {
        $r = $this->runMw($this->authnRefreshRoute(), []);

        $this->assertFalse($r['passed']);
        $this->assertSame(401, $r['response']->httpStatusCode);
    }

    public function test_valid_signature_but_absent_row_returns_401(): void
    {
        // Signature + exp valid, but no DB row was ever stored → reject (the gap the
        // stateless path cannot close). This is also the "old rowless refresh token"
        // case that forces the one-time fleet re-login.
        $token = $this->mint(['type' => TokenClaims::TYPE_REFRESH, 'purpose' => TokenClaims::PURPOSE_AUTHENTICATION]);

        $r = $this->runMw($this->authnRefreshRoute(), ['refresh_token' => $token]);

        $this->assertFalse($r['passed']);
        $this->assertSame(401, $r['response']->httpStatusCode);
    }

    public function test_revoke_deletes_row_then_refresh_is_rejected(): void
    {
        $token = $this->mint(['type' => TokenClaims::TYPE_REFRESH, 'purpose' => TokenClaims::PURPOSE_AUTHENTICATION]);
        $this->persist($token, TokenClaims::PURPOSE_AUTHENTICATION);

        // Works while the row exists.
        $this->assertTrue($this->runMw($this->authnRefreshRoute(), ['refresh_token' => $token])['passed']);

        // Revoke = delete the row.
        $this->store->revoke(hash('sha256', $token));

        // Now rejected even though the JWT is still cryptographically valid.
        $after = $this->runMw($this->authnRefreshRoute(), ['refresh_token' => $token]);
        $this->assertFalse($after['passed']);
        $this->assertSame(401, $after['response']->httpStatusCode);
    }

    public function test_wrong_token_type_access_returns_401(): void
    {
        $token = $this->mint(['type' => TokenClaims::TYPE_ACCESS, 'purpose' => TokenClaims::PURPOSE_AUTHENTICATION]);
        $this->persist($token, TokenClaims::PURPOSE_AUTHENTICATION);

        $r = $this->runMw($this->authnRefreshRoute(), ['refresh_token' => $token]);

        $this->assertFalse($r['passed']);
        $this->assertSame(401, $r['response']->httpStatusCode);
    }

    public function test_wrong_purpose_returns_403(): void
    {
        // Card refresh token (authorization) presented to an authentication route.
        $token = $this->mint(['type' => TokenClaims::TYPE_REFRESH, 'purpose' => TokenClaims::PURPOSE_AUTHORIZATION]);
        $this->persist($token, TokenClaims::PURPOSE_AUTHORIZATION);

        $r = $this->runMw($this->authnRefreshRoute(), ['refresh_token' => $token]);

        $this->assertFalse($r['passed']);
        $this->assertSame(403, $r['response']->httpStatusCode);
    }

    public function test_card_refresh_route_accepts_authorization_refresh_token(): void
    {
        $token = $this->mint(['type' => TokenClaims::TYPE_REFRESH, 'purpose' => TokenClaims::PURPOSE_AUTHORIZATION]);
        $this->persist($token, TokenClaims::PURPOSE_AUTHORIZATION);

        $route = ['access' => RouteAccess::AUTHORIZATION, 'token_type' => RouteAccess::TOKEN_REFRESH];
        $r = $this->runMw($route, ['refresh_token' => $token]);

        $this->assertTrue($r['passed']);
    }

    public function test_non_refresh_route_passes_through_untouched(): void
    {
        // An access-token route is not this middleware's concern.
        $route = ['access' => RouteAccess::AUTHORIZATION, 'token_type' => RouteAccess::TOKEN_ACCESS];
        $r = $this->runMw($route, []);

        $this->assertTrue($r['passed']);
        $this->assertNull($r['response']);
    }

    public function test_explicit_purpose_parameter_overrides_route_access(): void
    {
        // purpose is a PARAMETER on the refresh middleware (not a third class).
        $verifier = new TrustedIssuerVerifier([
            self::ISSUER => ['kind' => 'local', 'public_key' => openssl_pkey_get_details($this->priv)['key']],
        ]);
        $mw = new RefreshTokenMiddleware($verifier, $this->store, TokenClaims::PURPOSE_AUTHORIZATION);

        $token = $this->mint(['type' => TokenClaims::TYPE_REFRESH, 'purpose' => TokenClaims::PURPOSE_AUTHORIZATION]);
        $this->persist($token, TokenClaims::PURPOSE_AUTHORIZATION);

        // Route says token_type=refresh; the explicit purpose param drives the check.
        $passed = false;
        $request = [
            'route' => ['access' => RouteAccess::AUTHENTICATION, 'token_type' => RouteAccess::TOKEN_REFRESH],
            'body' => ['refresh_token' => $token],
        ];
        $mw->handle($request, function ($req) use (&$passed) { $passed = true; return null; });

        $this->assertTrue($passed, 'Explicit purpose param must override route.access');
    }
}
