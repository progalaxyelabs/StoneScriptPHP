<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use StoneScriptPHP\Auth\Middleware\AuthMiddlewareRegistrar;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ExchangeRoute;
use StoneScriptPHP\Auth\RsaJwtHandler;
use StoneScriptPHP\Env;

/**
 * AuthMiddlewareRegistrar::forMode() round trip (ported from the scaffold, which no longer carries this wiring).
 *
 * Round trip, not shape: mint the token the REAL flow mints, send it through the middleware
 * public/index.php wires, and require the protected route to answer. The two modes mint
 * different tokens (the shape of the wiring alone cannot tell you they are accepted):
 *
 *  - external/hybrid: POST /api/auth/exchange signs an API token with the LOCAL key
 *    (iss = JWT_ISSUER, purpose=authorization). Wiring that trusts only the auth service
 *    rejects every protected call after a successful exchange.
 *  - builtin: login mints purpose=authentication; routes default to access=authorization.
 *    The strict middleware answers 403 to every logged-in user.
 */
class TypedAuthForModeRoundTripTest extends TestCase
{
    private const AUTH_ISSUER = 'https://auth.test.invalid';

    /** @var array<string, string> */
    private array $serverBackup = [];
    private $authKey;
    private string $authPublicPem;
    private string $keyDir = '';
    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;

        // A throwaway environment + key pair: never depend on (or leak into) a developer's real .env.
        $this->keyDir = sys_get_temp_dir() . '/ssp-forMode-' . bin2hex(random_bytes(6));
        mkdir($this->keyDir, 0700, true);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privatePem);
        file_put_contents($this->keyDir . '/private.pem', $privatePem);
        file_put_contents($this->keyDir . '/public.pem', openssl_pkey_get_details($key)['key']);
        foreach ([
            'JWT_ISSUER' => 'https://api.test.invalid',
            'JWT_PRIVATE_KEY_PATH' => $this->keyDir . '/private.pem',
            'JWT_PUBLIC_KEY_PATH' => $this->keyDir . '/public.pem',
            'DB_GATEWAY_URL' => 'http://127.0.0.1:9',
            'DB_GATEWAY_PLATFORM' => 'testapp',
            'DB_GATEWAY_SCHEMA_NAME' => 'main',
            'AUTH_SERVICE_URL' => 'http://127.0.0.1:9',
            'AUTH_ISSUER' => self::AUTH_ISSUER,
            'PLATFORM_CODE' => 'testapp',
        ] as $name => $value) {
            $this->envBackup[$name] = getenv($name);
            putenv("$name=$value");
        }
        Env::resetForTesting();
        $this->authKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->authPublicPem = openssl_pkey_get_details($this->authKey)['key'];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        foreach ($this->envBackup as $name => $old) {
            $old === false ? putenv($name) : putenv("$name=$old");
        }
        $this->envBackup = [];
        Env::resetForTesting();
        foreach (['private.pem', 'public.pem'] as $f) {
            @unlink($this->keyDir . '/' . $f);
        }
        @rmdir($this->keyDir);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function env(): Env
    {
        return Env::get_instance();
    }

    /** @return list<\StoneScriptPHP\Auth\Middleware\AccessTokenMiddleware> */
    private function wiring(string $mode): array
    {
        return AuthMiddlewareRegistrar::forMode($mode, $this->env(), [
            'jwks_keys' => ['auth-1' => new Key($this->authPublicPem, 'RS256')],
        ]);
    }

    /** The token POST /api/auth/exchange really mints: the framework ExchangeRoute, auth service stubbed. */
    private function exchangeToken(): string
    {
        $config = new ExternalAuthConfig([
            'auth_service_url' => 'http://127.0.0.1:9',
            'auth_issuer'      => self::AUTH_ISSUER,
            'platform_code'    => 'testapp',
        ]);
        $route = new class (new ExternalAuthServiceClient('http://127.0.0.1:9', 'testapp'), [], $config, static fn(array $c): array => ['owner'], static fn(array $c): array => [['id' => 't1']]) extends ExchangeRoute {
            protected function extractIdentityToken(): ?string
            {
                return 'identity-token';
            }

            protected function validateIdentity(string $authToken): array
            {
                return ['sub' => 'u1', 'identity_id' => 'u1', 'platform_code' => 'testapp'];
            }
        };
        $route->tenant_id = 't1';
        $response = $route->process();

        $this->assertSame('ok', $response->status, 'exchange must succeed: ' . json_encode($response->data));
        return $response->data['access_token'];
    }

    /** The token an auth service issues at login: identity token, purpose=authentication. */
    private function identityToken(array $override = []): string
    {
        $claims = array_merge([
            'iss' => self::AUTH_ISSUER, 'sub' => 'u1', 'identity_id' => 'u1',
            'iat' => time(), 'exp' => time() + 600, 'type' => 'access', 'purpose' => 'authentication',
        ], $override);
        return JWT::encode($claims, $this->authKey, 'RS256', 'auth-1');
    }

    /** The token the generated login route mints in builtin mode (RsaJwtHandler default purpose). */
    private function builtinLoginToken(): string
    {
        return (new RsaJwtHandler())->generateToken(['user_id' => 'u1', 'sub' => 'u1']);
    }

    private function localToken(array $override, ?string $privateKeyPem = null): string
    {
        $claims = array_merge([
            'iss' => $this->env()->JWT_ISSUER, 'sub' => 'u1', 'iat' => time(), 'exp' => time() + 600,
            'type' => 'access', 'purpose' => 'authorization',
        ], $override);
        return JWT::encode($claims, $privateKeyPem ?? file_get_contents($this->env()->JWT_PRIVATE_KEY_PATH), 'RS256');
    }

    /** Send $token to a protected route (default access: authorization) through the wired middleware. */
    private function call(string $mode, ?string $token, string $access = 'authorization'): ApiResponse
    {
        $_SERVER['HTTP_AUTHORIZATION'] = $token === null ? '' : 'Bearer ' . $token;
        unset($_SERVER['HTTP_AUTHORIZATION']);
        if ($token !== null) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        $middleware = $this->wiring($mode);
        $this->assertCount(1, $middleware);
        $request = ['route' => ['access' => $access, 'token_type' => 'access', 'is_public' => false]];
        $reached = new ApiResponse('ok', 'handler reached');

        return $middleware[0]->handle($request, static fn(array $r): ApiResponse => $reached);
    }

    private function assertAccepted(ApiResponse $r): void
    {
        $this->assertSame('ok', $r->status, 'expected the handler to be reached, got: ' . $r->message);
    }

    private function assertRejected(int $code, ApiResponse $r): void
    {
        $this->assertSame('error', $r->status);
        $this->assertSame($code, $r->httpStatusCode, $r->message);
    }

    // ── external / hybrid ────────────────────────────────────────────────────

    public function test_external_exchange_token_reaches_an_authorization_route(): void
    {
        $this->assertAccepted($this->call('external', $this->exchangeToken()));
        $this->assertAccepted($this->call('hybrid', $this->exchangeToken()));
    }

    public function test_external_exchange_token_is_not_an_authentication_token(): void
    {
        $this->assertRejected(403, $this->call('external', $this->exchangeToken(), 'authentication'));
    }

    public function test_external_identity_token_reaches_an_authentication_route_only(): void
    {
        $this->assertAccepted($this->call('external', $this->identityToken(), 'authentication'));
        $this->assertRejected(403, $this->call('external', $this->identityToken(), 'authorization'));
    }

    public function test_external_rejects_unknown_issuer_forged_signature_expiry_and_refresh_type(): void
    {
        $otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($otherKey, $otherPem);

        $this->assertRejected(401, $this->call('external', $this->localToken(['iss' => 'https://elsewhere.invalid'])));
        $this->assertRejected(401, $this->call('external', $this->localToken([], $otherPem)), 'local iss, foreign key');
        $this->assertRejected(401, $this->call('external', $this->localToken(['exp' => time() - 60])));
        $this->assertRejected(401, $this->call('external', $this->localToken(['type' => 'refresh'])));
        $this->assertRejected(401, $this->call('external', null));
    }

    public function test_external_auth_service_issuer_cannot_be_impersonated_by_the_local_key(): void
    {
        // A token claiming the auth service issuer but signed with the local key must fail.
        $this->assertRejected(401, $this->call('external', $this->localToken(['iss' => self::AUTH_ISSUER, 'purpose' => 'authentication']), 'authentication'));
    }

    public function test_token_signed_by_the_auth_service_key_claiming_the_local_issuer_is_rejected(): void
    {
        // The auth service's key must never be accepted for the LOCAL issuer (issuer-to-key binding both ways).
        $forged = JWT::encode([
            'iss' => $this->env()->JWT_ISSUER, 'sub' => 'u1', 'iat' => time(), 'exp' => time() + 600,
            'type' => 'access', 'purpose' => 'authorization',
        ], $this->authKey, 'RS256', 'auth-1');

        $this->assertRejected(401, $this->call('external', $forged));
        $this->assertRejected(401, $this->call('hybrid', $forged));
    }

    // ── builtin ──────────────────────────────────────────────────────────────

    public function test_builtin_login_token_reaches_a_default_authorization_route(): void
    {
        $token = $this->builtinLoginToken();

        $this->assertAccepted($this->call('builtin', $token, 'authorization'));
        $this->assertAccepted($this->call('builtin', $token, 'authentication'));
    }

    public function test_builtin_rejects_unknown_issuer_forged_signature_expiry_and_refresh_type(): void
    {
        $otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($otherKey, $otherPem);

        $this->assertRejected(401, $this->call('builtin', $this->localToken(['iss' => 'https://elsewhere.invalid'])));
        $this->assertRejected(401, $this->call('builtin', $this->localToken([], $otherPem)));
        $this->assertRejected(401, $this->call('builtin', $this->localToken(['exp' => time() - 60])));
        $this->assertRejected(401, $this->call('builtin', $this->localToken(['type' => 'refresh'])));
        $this->assertRejected(401, $this->call('builtin', null));
    }

    // ── fail loud ────────────────────────────────────────────────────────────

    public function test_missing_issuer_fails_at_boot_naming_the_variable(): void
    {
        putenv('JWT_ISSUER=');
        Env::resetForTesting();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_ISSUER');
        AuthMiddlewareRegistrar::forMode('builtin', $this->env());
    }

    public function test_missing_public_key_fails_at_boot_naming_the_variable(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_PUBLIC_KEY_PATH');
        AuthMiddlewareRegistrar::forMode('builtin', $this->env(), ['local_public_key_path' => $this->keyDir . '/nope.pem']);
    }

    public function test_unconfigured_external_fails_at_boot_naming_the_variables(): void
    {
        putenv('AUTH_SERVICE_URL=');
        putenv('AUTH_ISSUER=');
        Env::resetForTesting();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AUTH_SERVICE_URL');
        AuthMiddlewareRegistrar::forMode('external', $this->env());
    }

    public function test_local_issuer_equal_to_auth_issuer_is_refused(): void
    {
        putenv('JWT_ISSUER=' . self::AUTH_ISSUER);
        Env::resetForTesting();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_ISSUER must differ from AUTH_ISSUER');
        AuthMiddlewareRegistrar::forMode('hybrid', $this->env());
    }

    public function test_relative_key_path_resolves_against_the_project_root_not_the_cwd(): void
    {
        // ROOT_PATH is the framework checkout under PHPUnit; './keys/..' must be joined to it, never to getcwd().
        $rel = new \ReflectionMethod(AuthMiddlewareRegistrar::class, 'projectPath');
        $this->assertSame(ROOT_PATH . 'keys/jwt-public.pem', $rel->invoke(null, './keys/jwt-public.pem'));
        $this->assertSame('/abs/key.pem', $rel->invoke(null, '/abs/key.pem'));
    }

    public function test_a_pinned_local_public_key_replaces_the_env_key_for_the_local_issuer(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $otherPem);
        $mw = AuthMiddlewareRegistrar::forMode('external', $this->env(), [
            'jwks_keys' => ['auth-1' => new Key($this->authPublicPem, 'RS256')],
            'local_public_key_pem' => openssl_pkey_get_details($other)['key'],
        ]);
        $send = function (string $token) use ($mw): ApiResponse {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
            return $mw[0]->handle(
                ['route' => ['access' => 'authorization', 'token_type' => 'access', 'is_public' => false]],
                static fn(array $r): ApiResponse => new ApiResponse('ok', 'reached')
            );
        };
        $this->assertAccepted($send($this->localToken([], $otherPem)));
        $this->assertRejected(401, $send($this->localToken([])), 'the env key is no longer the signer');
    }

    public function test_forMode_never_falls_back_to_the_working_directory(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Auth/Middleware/AuthMiddlewareRegistrar.php');
        $this->assertStringNotContainsString('getcwd', $src);
    }

    public function test_unknown_mode_returns_no_middleware_so_the_boot_guard_refuses(): void
    {
        $this->assertSame([], AuthMiddlewareRegistrar::forMode('nonsense', $this->env()));
    }
}
