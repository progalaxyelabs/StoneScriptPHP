<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\HybridCardJwtHandler;
use StoneScriptPHP\Auth\RsaJwtHandler;
use StoneScriptPHP\Env;

/**
 * Tests for HybridCardJwtHandler — the card-model JWT validator.
 *
 * Validates that:
 *   1. A platform-minted CARD (signed with the platform's RSA key) is accepted.
 *   2. A passport-shaped token signed with a WRONG key is rejected (returns false).
 *   3. Token GENERATION delegates to the platform RSA handler (same as RsaJwtHandler).
 *   4. JWT_ISSUER fail-loud: generateToken() throws when JWT_ISSUER is unset.
 *
 * JWKS fallback (for auth-service passports) is not tested here because it requires a
 * live JWKS endpoint. The JWKS code path is exercised in integration tests.
 *
 * @covers \StoneScriptPHP\Auth\HybridCardJwtHandler
 * @covers \StoneScriptPHP\Auth\RsaJwtHandler
 */
class HybridCardJwtHandlerTest extends TestCase
{
    private string $testKeysDir;
    private string $testPrivateKey;
    private string $testPublicKey;
    private Env $env;

    protected function setUp(): void
    {
        if (empty(getenv('DB_GATEWAY_URL'))) {
            putenv('DB_GATEWAY_URL=http://localhost:9000');
        }
        if (empty(getenv('DB_GATEWAY_PLATFORM'))) {
            putenv('DB_GATEWAY_PLATFORM=test-platform');
        }

        // Reset Env singleton so our env changes take effect.
        $ref = new \ReflectionClass(Env::class);
        $prop = $ref->getProperty('_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $this->env = Env::get_instance();

        // Generate a fresh RSA keypair for each test.
        $this->testKeysDir    = sys_get_temp_dir() . '/ssp-hybrid-test-' . getmypid();
        $this->testPrivateKey = $this->testKeysDir . '/private.pem';
        $this->testPublicKey  = $this->testKeysDir . '/public.pem';
        mkdir($this->testKeysDir, 0755, true);

        $res = openssl_pkey_new([
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($res, $privateKeyPem);
        file_put_contents($this->testPrivateKey, $privateKeyPem);
        $pubKeyDetails = openssl_pkey_get_details($res);
        file_put_contents($this->testPublicKey, $pubKeyDetails['key']);

        $this->env->JWT_PRIVATE_KEY_PATH      = $this->testPrivateKey;
        $this->env->JWT_PUBLIC_KEY_PATH       = $this->testPublicKey;
        $this->env->JWT_ISSUER                = 'https://api.testplatform.example.com';
        $this->env->JWT_PRIVATE_KEY_PASSPHRASE = null;
        $this->env->JWT_ACCESS_TOKEN_EXPIRY   = 3600;
        $this->env->JWT_REFRESH_TOKEN_EXPIRY  = 15552000;

        // Prevent server-side request vars from being absent in CLI.
        $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $_SERVER['REQUEST_URI']    = $_SERVER['REQUEST_URI']    ?? '/';
    }

    protected function tearDown(): void
    {
        @unlink($this->testPrivateKey);
        @unlink($this->testPublicKey);
        @rmdir($this->testKeysDir);
    }

    // ── Platform card validation (RSA path) ─────────────────────────────────────

    /**
     * A card minted by the platform's RSA key MUST be accepted by HybridCardJwtHandler.
     *
     * This is the load-bearing scenario: `Application::run()` in external mode now
     * defaults to HybridCardJwtHandler. When the exchange route mints a card with the
     * platform key, subsequent business-route requests carry that card — and HybridCardJwtHandler
     * must validate it via the RSA path.
     */
    public function test_platform_card_validates_via_rsa_path(): void
    {
        $rsaHandler = new RsaJwtHandler();
        $card = $rsaHandler->generateToken([
            'identity_id' => 'id-abc',
            'tenant_id'   => 'tenant-xyz',
            'role_id'     => 'owner',
        ]);

        $hybrid = new HybridCardJwtHandler(
            authServiceUrl: 'http://auth.unreachable:9999',  // JWKS never reached
            authIssuer:     'https://auth.unreachable.example.com'
        );

        $claims = $hybrid->verifyToken($card);

        $this->assertIsArray($claims, 'Platform card should validate via RSA path');
        $this->assertSame('id-abc', $claims['identity_id']);
        $this->assertSame('tenant-xyz', $claims['tenant_id']);
        $this->assertSame('owner', $claims['role_id']);
        // Standard JWT fields are stripped by RsaJwtHandler::verifyToken()
        $this->assertArrayNotHasKey('iss', $claims);
        $this->assertArrayNotHasKey('exp', $claims);
    }

    /**
     * A token signed by a different (unknown) key MUST be rejected.
     *
     * This guards against accepting tokens from a third-party or a rotated key.
     */
    public function test_token_signed_by_wrong_key_returns_false(): void
    {
        // Generate a DIFFERENT keypair — simulates a foreign or rotated key.
        $foreignKeysDir = sys_get_temp_dir() . '/ssp-foreign-' . getmypid();
        mkdir($foreignKeysDir, 0755, true);
        $foreignPriv = $foreignKeysDir . '/private.pem';

        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $foreignPrivPem);
        file_put_contents($foreignPriv, $foreignPrivPem);

        // Temporarily swap the env key path to sign with the foreign key.
        $this->env->JWT_PRIVATE_KEY_PATH = $foreignPriv;
        $foreignRsa = new RsaJwtHandler();
        $foreignToken = $foreignRsa->generateToken(['identity_id' => 'id-foreign']);

        // Restore platform key.
        $this->env->JWT_PRIVATE_KEY_PATH = $this->testPrivateKey;

        $hybrid = new HybridCardJwtHandler(
            authServiceUrl: 'http://auth.unreachable:9999',
            authIssuer:     'https://auth.unreachable.example.com'
        );

        // RSA path fails (wrong key) → JWKS path also fails (wrong server/issuer) → false
        $result = $hybrid->verifyToken($foreignToken);
        $this->assertFalse($result, 'Token signed by a foreign key should be rejected');

        @unlink($foreignPriv);
        @rmdir($foreignKeysDir);
    }

    // ── Token generation delegates to RsaJwtHandler ─────────────────────────────

    /**
     * HybridCardJwtHandler::generateToken() should produce the same card as RsaJwtHandler.
     */
    public function test_generate_token_produces_rsa_signed_card(): void
    {
        $hybrid = new HybridCardJwtHandler(
            authServiceUrl: 'http://auth.unreachable:9999',
            authIssuer:     'https://auth.unreachable.example.com'
        );

        $payload = ['identity_id' => 'id-1', 'tenant_id' => 'tenant-1', 'role_id' => 'owner'];
        $card = $hybrid->generateToken($payload, 3600);

        // Verify the card with the same handler's RSA verifier.
        $claims = $hybrid->verifyToken($card);

        $this->assertIsArray($claims);
        $this->assertSame('id-1', $claims['identity_id']);
        $this->assertSame('tenant-1', $claims['tenant_id']);
        $this->assertSame('owner', $claims['role_id']);
    }

    // ── JWT_ISSUER fail-loud (Defect 4) ─────────────────────────────────────────

    /**
     * RsaJwtHandler::generateToken() MUST throw when JWT_ISSUER is empty string.
     *
     * The old behaviour (default to 'example.com') silently produced cards with a wrong
     * issuer, causing intermittent 401 errors that were hard to diagnose. Fail-loud
     * catches the misconfiguration at the first mint attempt.
     *
     * Env::$JWT_ISSUER is typed `string` (not nullable), so we test with empty string
     * which is the new default (was 'example.com', changed in v5.4.0).
     */
    public function test_rsa_generate_token_throws_when_jwt_issuer_is_empty_string(): void
    {
        $this->env->JWT_ISSUER = '';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/JWT_ISSUER/i');

        $handler = new RsaJwtHandler();
        $handler->generateToken(['identity_id' => 'id-1']);
    }

    /**
     * RsaJwtHandler::verifyToken() with empty JWT_ISSUER should still work
     * (skip issuer check rather than throw — safe fallback for the RSA-then-JWKS chain).
     */
    public function test_rsa_verify_token_with_empty_jwt_issuer_skips_issuer_check(): void
    {
        // First mint a token with JWT_ISSUER set.
        $handler = new RsaJwtHandler();
        $token = $handler->generateToken(['identity_id' => 'id-1']);

        // Then clear JWT_ISSUER — verifyToken should still succeed (skip check, not throw).
        $this->env->JWT_ISSUER = '';
        $result = $handler->verifyToken($token);

        $this->assertIsArray($result, 'verifyToken should succeed even with empty JWT_ISSUER (skip check)');
        $this->assertSame('id-1', $result['identity_id']);
    }

    // ── Hybrid passes through invalid tokens cleanly ────────────────────────────

    /**
     * A completely invalid JWT (malformed) should return false — not throw.
     */
    public function test_invalid_jwt_returns_false(): void
    {
        $hybrid = new HybridCardJwtHandler(
            authServiceUrl: 'http://auth.unreachable:9999',
            authIssuer:     'https://auth.unreachable.example.com'
        );

        $result = $hybrid->verifyToken('not.a.valid.jwt.at.all');
        $this->assertFalse($result);
    }
}
