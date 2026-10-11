<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\InMemoryRefreshTokenStore;
use StoneScriptPHP\Auth\Middleware\RefreshTokenMiddleware;
use StoneScriptPHP\Auth\RefreshTokens\RefreshTokenIssuer;
use StoneScriptPHP\Auth\Routes\BodyRefreshRoute;
use StoneScriptPHP\Auth\Routes\RefreshRoute;
use StoneScriptPHP\Auth\TrustedIssuerVerifier;
use StoneScriptPHP\Tests\Fixtures\FakeJwt;

/**
 * Contract: every refusal of a refresh token is HTTP 401 with top-level `error_code` = `invalid_grant` (the human
 * `message` is unchanged); persistence not configured is a distinct hard configuration error.
 */
final class RefreshErrorCodeTest extends TestCase
{
    protected function setUp(): void
    {
        RefreshTokenIssuer::configure(null);
        $_SERVER['HTTP_HOST'] = 'api.example.test';
        $_SERVER['HTTPS'] = 'on';
    }

    protected function tearDown(): void
    {
        RefreshTokenIssuer::configure(null);
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS'], $_SERVER['HTTP_X_CSRF_TOKEN']);
        $_COOKIE = [];
    }

    private function body(ApiResponse $r): array
    {
        return json_decode($r->toJson(), true);
    }

    public function test_error_code_is_only_emitted_when_set(): void
    {
        $plain = new ApiResponse('error', 'm');
        $this->assertArrayNotHasKey('error_code', $this->body($plain));
        $coded = new ApiResponse('error', 'm');
        $coded->errorCode = 'invalid_grant';
        $this->assertSame('invalid_grant', $this->body($coded)['error_code']);
    }

    public function test_body_refresh_refusal_is_401_invalid_grant_with_the_same_message(): void
    {
        RefreshTokenIssuer::configure(new RefreshTokenIssuer(new FakeJwt(), new InMemoryRefreshTokenStore()));
        $route = new BodyRefreshRoute();
        $route->refresh_token = 'garbage';
        $res = $route->process();

        $this->assertSame(401, $res->httpStatusCode);
        $this->assertSame('Invalid or expired refresh token', $res->message);
        $json = $this->body($res);
        $this->assertSame('invalid_grant', $json['error_code']);
        $this->assertSame('error', $json['status']);
    }

    public function test_body_refresh_of_a_revoked_token_is_also_invalid_grant(): void
    {
        $store = new InMemoryRefreshTokenStore();
        $issuer = new RefreshTokenIssuer(new FakeJwt(), $store);
        RefreshTokenIssuer::configure($issuer);
        $session = $issuer->issueSession(['user_id' => 'u1']);
        $store->revoke(hash('sha256', $session->refreshToken));

        $route = new BodyRefreshRoute();
        $route->refresh_token = $session->refreshToken;
        $res = $route->process();
        $this->assertSame(401, $res->httpStatusCode);
        $this->assertSame('invalid_grant', $this->body($res)['error_code']);
    }

    public function test_body_refresh_without_persistence_is_a_distinct_501_configuration_error(): void
    {
        $route = new BodyRefreshRoute();
        $route->refresh_token = 'whatever';
        $res = $route->process();

        $this->assertSame(501, $res->httpStatusCode);
        $this->assertSame('Refresh token persistence is not configured.', $res->message);
        $this->assertSame('refresh_store_not_configured', $this->body($res)['error_code']);
    }

    public function test_cookie_refresh_refusals_are_all_invalid_grant(): void
    {
        $route = new RefreshRoute(new FakeJwt());

        // no cookie (CSRF is valid, token is absent)
        $_COOKIE = ['csrf_token' => 'c1'];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'c1';
        $res = @$route->process();
        $this->assertSame('No refresh token provided', $res->message);
        $this->assertSame('invalid_request', $this->body($res)['error_code'], 'RFC 6749: a missing parameter is invalid_request');

        // garbage cookie, no persistence: invalid or expired
        $_COOKIE = ['refresh_token' => 'garbage', 'csrf_token' => 'c1'];
        $res = @$route->process();
        $this->assertSame('invalid_grant', $this->body($res)['error_code']);

        // persisted store, unknown token
        RefreshTokenIssuer::configure(new RefreshTokenIssuer(new FakeJwt(), new InMemoryRefreshTokenStore()));
        $res = @$route->process();
        $this->assertSame('Invalid or expired refresh token', $res->message);
        $this->assertSame('invalid_grant', $this->body($res)['error_code']);
    }

    public function test_csrf_failure_is_not_reported_as_invalid_grant(): void
    {
        $_COOKIE = ['refresh_token' => 'x', 'csrf_token' => 'c1'];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'different';
        $res = @(new RefreshRoute(new FakeJwt()))->process();
        $this->assertArrayNotHasKey('error_code', $this->body($res));
    }

    public function test_refresh_middleware_401s_carry_invalid_grant(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $pub = openssl_pkey_get_details($key)['key'];
        $mw = new RefreshTokenMiddleware(
            new TrustedIssuerVerifier(['https://api.test.invalid' => ['kind' => 'local', 'public_key' => $pub]]),
            new InMemoryRefreshTokenStore()
        );
        $request = static fn (array $body): array => [
            'route' => ['access' => 'authentication', 'token_type' => 'refresh'],
            'body' => $body,
        ];
        $next = static fn (array $r): ApiResponse => new ApiResponse('ok', 'reached');

        $res = @$mw->handle($request([]), $next);
        $this->assertSame(401, $res->httpStatusCode);
        $this->assertSame('invalid_request', $this->body($res)['error_code']);

        $res = @$mw->handle($request(['refresh_token' => 'not-a-jwt']), $next);
        $this->assertSame(401, $res->httpStatusCode);
        $this->assertSame('invalid_grant', $this->body($res)['error_code']);
    }

    // ---- infrastructure failure is NOT a refusal ------------------------------------------------

    private string $keyDir = '';

    private function identities(): array
    {
        $this->keyDir = sys_get_temp_dir() . '/ssp-refresh-keys-' . bin2hex(random_bytes(5));
        mkdir($this->keyDir, 0700, true);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $priv);
        file_put_contents($this->keyDir . '/private.pem', $priv);
        file_put_contents($this->keyDir . '/public.pem', openssl_pkey_get_details($key)['key']);
        $good = new \StoneScriptPHP\Auth\LocalSigningIdentity('https://api.test.invalid', $this->keyDir . '/private.pem', null, $this->keyDir . '/public.pem');
        $noPublicKey = new \StoneScriptPHP\Auth\LocalSigningIdentity('https://api.test.invalid', $this->keyDir . '/private.pem', null, $this->keyDir . '/missing.pem');
        return [$good, $noPublicKey];
    }

    private function cleanKeys(): void
    {
        foreach (['private.pem', 'public.pem'] as $f) {
            @unlink($this->keyDir . '/' . $f);
        }
        @rmdir($this->keyDir);
    }

    private function refreshToken(\StoneScriptPHP\Auth\LocalSigningIdentity $signer): string
    {
        return (new \StoneScriptPHP\Auth\RsaJwtHandler($signer))->generateToken(['user_id' => 'u1', 'sub' => 'u1'], 600, 'refresh');
    }

    public function test_unreadable_public_key_is_503_not_invalid_grant_on_every_refresh_path(): void
    {
        [$good, $broken] = $this->identities();
        try {
            $token = $this->refreshToken($good);

            // Body route, persisted store
            $store = new InMemoryRefreshTokenStore();
            RefreshTokenIssuer::configure(new RefreshTokenIssuer(new \StoneScriptPHP\Auth\RsaJwtHandler($broken), $store));
            $route = new BodyRefreshRoute();
            $route->refresh_token = $token;
            $res = @$route->process();
            $this->assertSame(503, $res->httpStatusCode);
            $this->assertNotSame('invalid_grant', $this->body($res)['error_code']);
            $this->assertSame('token_verifier_unavailable', $this->body($res)['error_code']);

            // Cookie route, persisted store
            $_COOKIE = ['refresh_token' => $token, 'csrf_token' => 'c1'];
            $_SERVER['HTTP_X_CSRF_TOKEN'] = 'c1';
            $res = @(new RefreshRoute(new \StoneScriptPHP\Auth\RsaJwtHandler($broken)))->process();
            $this->assertSame('token_verifier_unavailable', $this->body($res)['error_code']);

            // Cookie route, no store (verifies directly)
            RefreshTokenIssuer::configure(null);
            $res = @(new RefreshRoute(new \StoneScriptPHP\Auth\RsaJwtHandler($broken)))->process();
            $this->assertSame('token_verifier_unavailable', $this->body($res)['error_code']);

            // Middleware with a local issuer whose key cannot be read
            $mw = new RefreshTokenMiddleware(
                new TrustedIssuerVerifier(['https://api.test.invalid' => ['kind' => 'local', 'public_key_path' => $this->keyDir . '/missing.pem']]),
                new InMemoryRefreshTokenStore()
            );
            $res = @$mw->handle(
                ['route' => ['access' => 'authentication', 'token_type' => 'refresh'], 'body' => ['refresh_token' => $token]],
                static fn (array $r): ApiResponse => new ApiResponse('ok', 'reached')
            );
            $this->assertSame(503, $res->httpStatusCode);
            $this->assertSame('token_verifier_unavailable', $this->body($res)['error_code']);
        } finally {
            $this->cleanKeys();
        }
    }

    public function test_jwks_outage_is_503_not_invalid_grant(): void
    {
        // A token from a trusted JWKS issuer whose JWKS endpoint is down (and nothing cached).
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $jwt = \Firebase\JWT\JWT::encode(
            ['iss' => 'https://auth.test.invalid', 'sub' => 'u1', 'iat' => time(), 'exp' => time() + 600, 'type' => 'refresh', 'purpose' => 'authentication'],
            $key,
            'RS256',
            'k1'
        );
        $cache = sys_get_temp_dir() . '/ssp-jwks-empty-' . bin2hex(random_bytes(5));
        mkdir($cache, 0700, true);
        try {
            $verifier = new TrustedIssuerVerifier(
                ['https://auth.test.invalid' => ['kind' => 'jwks', 'jwks_url' => 'http://127.0.0.1:9/jwks']],
                $cache
            );
            $mw = new RefreshTokenMiddleware($verifier, new InMemoryRefreshTokenStore());
            $res = @$mw->handle(
                ['route' => ['access' => 'authentication', 'token_type' => 'refresh'], 'body' => ['refresh_token' => $jwt]],
                static fn (array $r): ApiResponse => new ApiResponse('ok', 'reached')
            );
            $this->assertSame(503, $res->httpStatusCode);
            $this->assertSame('token_verifier_unavailable', $this->body($res)['error_code']);

            // The non-strict verifier API keeps its contract: just "not verified".
            $this->assertNull($verifier->verify($jwt));
            $this->expectException(\StoneScriptPHP\Auth\TokenVerifierUnavailableException::class);
            $verifier->verifyStrict($jwt);
        } finally {
            @rmdir($cache);
        }
    }

    public function test_a_tampered_token_is_a_genuine_refusal_401_invalid_grant(): void
    {
        [$good] = $this->identities();
        try {
            $token = $this->refreshToken($good);
            [$h, $p, $sig] = explode('.', $token);
            $tampered = $h . '.' . $p . '.' . strrev($sig);

            $store = new InMemoryRefreshTokenStore();
            RefreshTokenIssuer::configure(new RefreshTokenIssuer(new \StoneScriptPHP\Auth\RsaJwtHandler($good), $store));
            $route = new BodyRefreshRoute();
            $route->refresh_token = $tampered;
            $res = @$route->process();
            $this->assertSame(401, $res->httpStatusCode);
            $this->assertSame('invalid_grant', $this->body($res)['error_code']);

            $mw = new RefreshTokenMiddleware(
                new TrustedIssuerVerifier(['https://api.test.invalid' => ['kind' => 'local', 'public_key_path' => $this->keyDir . '/public.pem']]),
                $store
            );
            $res = @$mw->handle(
                ['route' => ['access' => 'authentication', 'token_type' => 'refresh'], 'body' => ['refresh_token' => $tampered]],
                static fn (array $r): ApiResponse => new ApiResponse('ok', 'reached')
            );
            $this->assertSame(401, $res->httpStatusCode);
            $this->assertSame('invalid_grant', $this->body($res)['error_code']);
        } finally {
            $this->cleanKeys();
        }
    }
}
