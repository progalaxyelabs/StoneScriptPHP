<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\JwtHandler;
use StoneScriptPHP\Auth\RsaJwtHandler;
use StoneScriptPHP\Env;

/**
 * Tests that JWT handlers produce flat (top-level) claim structure per AUTH-SPEC §4.
 *
 * Prior to this fix, both JwtHandler and RsaJwtHandler wrapped all custom claims
 * inside a 'data' sub-key: { "iss": "...", "data": { "user_id": 1 } }.
 * The spec requires claims at top level: { "iss": "...", "user_id": 1 }.
 *
 * @covers \StoneScriptPHP\Auth\JwtHandler
 * @covers \StoneScriptPHP\Auth\RsaJwtHandler
 */
class JwtHandlerFlatClaimsTest extends TestCase
{
    private string $testKeysDir;
    private string $testPrivateKey;
    private string $testPublicKey;
    private Env $env;

    protected function setUp(): void
    {
        // Env::get_instance() validates DB_GATEWAY_URL and DB_GATEWAY_PLATFORM.
        // Set placeholders so unit tests don't fail on missing gateway config.
        if (empty(getenv('DB_GATEWAY_URL'))) {
            putenv('DB_GATEWAY_URL=http://localhost:9000');
        }
        if (empty(getenv('DB_GATEWAY_PLATFORM'))) {
            putenv('DB_GATEWAY_PLATFORM=test-platform');
        }

        $this->env = Env::get_instance();

        // --- HS256 setup ---
        // JWT_SECRET is not a declared Env property but is accessed dynamically via env()
        // helper as $env->JWT_SECRET. Setting it here works because PHP allows dynamic
        // properties on non-readonly classes. PHP 8.2+ emits a deprecation, which is
        // acceptable in tests — suppress with @.
        @($this->env->JWT_SECRET = 'test-secret-key-for-unit-tests-only');

        // --- RSA setup ---
        $this->testKeysDir = sys_get_temp_dir() . '/ssp-jwt-test-' . getmypid();
        mkdir($this->testKeysDir, 0755, true);

        $this->testPrivateKey = $this->testKeysDir . '/private.pem';
        $this->testPublicKey  = $this->testKeysDir . '/public.pem';

        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $privateKeyPem);
        file_put_contents($this->testPrivateKey, $privateKeyPem);
        $pubKeyDetails = openssl_pkey_get_details($res);
        file_put_contents($this->testPublicKey, $pubKeyDetails['key']);

        $this->env->JWT_PRIVATE_KEY_PATH = $this->testPrivateKey;
        $this->env->JWT_PUBLIC_KEY_PATH  = $this->testPublicKey;
        $this->env->JWT_ISSUER           = 'test.stonescriptphp.com';
        $this->env->JWT_PRIVATE_KEY_PASSPHRASE = null;
        $this->env->JWT_ACCESS_TOKEN_EXPIRY  = 900;
        $this->env->JWT_REFRESH_TOKEN_EXPIRY = 15552000;
    }

    protected function tearDown(): void
    {
        @unlink($this->testPrivateKey);
        @unlink($this->testPublicKey);
        @rmdir($this->testKeysDir);
    }

    // ─── RsaJwtHandler tests ─────────────────────────────────────────────────

    public function testRsaGenerateTokenPutsClaimsAtTopLevel(): void
    {
        $handler = new RsaJwtHandler();
        $token = $handler->generateToken(['user_id' => 42, 'email' => 'alice@example.com']);

        $rawPayload = $this->decodeJwtPayloadRaw($token);

        // Claims must be at top level
        $this->assertSame(42, $rawPayload['user_id'], 'user_id must be at top level');
        $this->assertSame('alice@example.com', $rawPayload['email'], 'email must be at top level');

        // Must NOT be wrapped in 'data'
        $this->assertArrayNotHasKey('data', $rawPayload, 'JWT payload must NOT have a data wrapper');
    }

    public function testRsaGenerateTokenHasStandardClaimsAtTopLevel(): void
    {
        $handler = new RsaJwtHandler();
        $token = $handler->generateToken(['user_id' => 1]);

        $rawPayload = $this->decodeJwtPayloadRaw($token);

        $this->assertArrayHasKey('iss', $rawPayload, 'iss claim missing');
        $this->assertArrayHasKey('iat', $rawPayload, 'iat claim missing');
        $this->assertArrayHasKey('exp', $rawPayload, 'exp claim missing');
        $this->assertSame('test.stonescriptphp.com', $rawPayload['iss']);
    }

    public function testRsaVerifyTokenReturnsFlatClaims(): void
    {
        $handler = new RsaJwtHandler();
        $token = $handler->generateToken(['user_id' => 99, 'tenant_id' => 'abc-123']);

        $claims = $handler->verifyToken($token);

        $this->assertIsArray($claims, 'verifyToken must return an array');
        $this->assertSame(99, $claims['user_id'], 'user_id must be at top level in verified claims');
        $this->assertSame('abc-123', $claims['tenant_id'], 'tenant_id must be at top level in verified claims');
        $this->assertArrayNotHasKey('data', $claims, 'verified claims must NOT contain data wrapper');
    }

    public function testRsaVerifyTokenStripsStandardJwtFields(): void
    {
        $handler = new RsaJwtHandler();
        $token = $handler->generateToken(['user_id' => 1]);

        $claims = $handler->verifyToken($token);

        // Standard JWT fields should be stripped from the returned claims
        $this->assertArrayNotHasKey('iss', $claims, 'iss should be stripped from returned claims');
        $this->assertArrayNotHasKey('iat', $claims, 'iat should be stripped from returned claims');
        $this->assertArrayNotHasKey('exp', $claims, 'exp should be stripped from returned claims');
    }

    public function testRsaVerifyTokenReturnsFalseForInvalidToken(): void
    {
        $handler = new RsaJwtHandler();
        $result = $handler->verifyToken('not.a.valid.jwt.token');
        $this->assertFalse($result, 'Invalid token must return false');
    }

    public function testRsaRoundtripAllAuthSpecClaims(): void
    {
        $handler = new RsaJwtHandler();
        $inputClaims = [
            'identity_id'   => 'uuid-identity-1',
            'tenant_id'     => 'uuid-tenant-1',
            'tenant_slug'   => 'my-store',
            'platform_code' => 'acme-store',
            'roles'         => ['owner'],
            'token_type'    => 'platform',
        ];

        $token = $handler->generateToken($inputClaims);
        $verified = $handler->verifyToken($token);

        $this->assertIsArray($verified);
        foreach ($inputClaims as $key => $expected) {
            $this->assertArrayHasKey($key, $verified, "Claim '$key' missing from verified token");
            $this->assertEquals($expected, $verified[$key], "Claim '$key' value mismatch");
        }
        $this->assertArrayNotHasKey('data', $verified, 'No data wrapper in verified token');
    }

    // ─── JwtHandler (HS256) tests ─────────────────────────────────────────────

    public function testHs256GenerateTokenPutsClaimsAtTopLevel(): void
    {
        $handler = new JwtHandler();
        $token = $handler->generateToken(['user_id' => 7, 'role' => 'admin']);

        $rawPayload = $this->decodeJwtPayloadRaw($token);

        $this->assertSame(7, $rawPayload['user_id'], 'user_id must be at top level');
        $this->assertSame('admin', $rawPayload['role'], 'role must be at top level');
        $this->assertArrayNotHasKey('data', $rawPayload, 'JWT payload must NOT have a data wrapper');
    }

    public function testHs256VerifyTokenReturnsFlatClaims(): void
    {
        $handler = new JwtHandler();
        $token = $handler->generateToken(['user_id' => 55, 'email' => 'bob@example.com']);

        $claims = $handler->verifyToken($token);

        $this->assertIsArray($claims, 'verifyToken must return an array');
        $this->assertSame(55, $claims['user_id'], 'user_id must be at top level in verified claims');
        $this->assertSame('bob@example.com', $claims['email'], 'email must be at top level in verified claims');
        $this->assertArrayNotHasKey('data', $claims, 'verified claims must NOT contain data wrapper');
    }

    public function testHs256VerifyTokenStripsStandardJwtFields(): void
    {
        $handler = new JwtHandler();
        $token = $handler->generateToken(['user_id' => 1]);

        $claims = $handler->verifyToken($token);

        $this->assertArrayNotHasKey('iat', $claims, 'iat should be stripped from returned claims');
        $this->assertArrayNotHasKey('exp', $claims, 'exp should be stripped from returned claims');
    }

    public function testHs256VerifyTokenReturnsFalseForInvalidToken(): void
    {
        $handler = new JwtHandler();
        $result = $handler->verifyToken('not.a.jwt');
        $this->assertFalse($result, 'Invalid token must return false');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Decode the raw JWT payload without signature verification.
     * Used to assert the actual JWT wire structure.
     */
    private function decodeJwtPayloadRaw(string $token): array
    {
        $parts = explode('.', $token);
        $this->assertCount(3, $parts, 'JWT must have 3 parts');
        $decoded = base64_decode(strtr($parts[1], '-_', '+/') . '==');
        $payload = json_decode($decoded, true);
        $this->assertIsArray($payload, 'JWT payload must be valid JSON');
        return $payload;
    }
}
