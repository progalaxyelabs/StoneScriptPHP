<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use StoneScriptPHP\Auth\RsaJwtHandler;
use StoneScriptPHP\Auth\TokenClaims;
use StoneScriptPHP\Env;

/**
 * Verifies RsaJwtHandler::generateToken() stamps the typed `type` and `purpose`
 * claims as first-class framework claims (settled auth-token model, 6.2.0).
 *
 * @covers \StoneScriptPHP\Auth\RsaJwtHandler
 * @covers \StoneScriptPHP\Auth\TokenClaims
 */
class RsaJwtHandlerTypedClaimsTest extends TestCase
{
    private string $keysDir;
    private string $privateKey;
    private string $publicKey;
    private Env $env;

    protected function setUp(): void
    {
        putenv('DB_GATEWAY_URL=http://localhost:9000');
        putenv('DB_GATEWAY_PLATFORM=test-platform');
        $this->env = Env::get_instance();

        $this->keysDir = sys_get_temp_dir() . '/ssp-typed-' . getmypid();
        @mkdir($this->keysDir, 0755, true);
        $this->privateKey = $this->keysDir . '/private.pem';
        $this->publicKey  = $this->keysDir . '/public.pem';

        $res = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($res, $pem);
        file_put_contents($this->privateKey, $pem);
        file_put_contents($this->publicKey, openssl_pkey_get_details($res)['key']);

        $this->env->JWT_PRIVATE_KEY_PATH = $this->privateKey;
        $this->env->JWT_PUBLIC_KEY_PATH  = $this->publicKey;
        $this->env->JWT_ISSUER           = 'https://api.testapp.in';
        $this->env->JWT_PRIVATE_KEY_PASSPHRASE = null;
        $this->env->JWT_ACCESS_TOKEN_EXPIRY  = 900;
        $this->env->JWT_REFRESH_TOKEN_EXPIRY = 15552000;
    }

    protected function tearDown(): void
    {
        @unlink($this->privateKey);
        @unlink($this->publicKey);
        @rmdir($this->keysDir);
        putenv('DB_GATEWAY_URL');
        putenv('DB_GATEWAY_PLATFORM');
    }

    /** @return array<string,mixed> decoded raw claims (no stripping) */
    private function decodeRaw(string $token): array
    {
        return (array) JWT::decode($token, new Key(file_get_contents($this->publicKey), 'RS256'));
    }

    public function test_access_token_stamps_type_access_and_default_purpose_authentication(): void
    {
        $handler = new RsaJwtHandler();
        $token = $handler->generateToken(['user_id' => 'u-1']);
        $claims = $this->decodeRaw($token);

        $this->assertSame(TokenClaims::TYPE_ACCESS, $claims['type']);
        $this->assertSame(TokenClaims::PURPOSE_AUTHENTICATION, $claims['purpose']);
    }

    public function test_refresh_token_type_is_stamped(): void
    {
        $handler = new RsaJwtHandler();
        $token = $handler->generateToken(['user_id' => 'u-1'], null, TokenClaims::TYPE_REFRESH);
        $claims = $this->decodeRaw($token);

        $this->assertSame(TokenClaims::TYPE_REFRESH, $claims['type']);
    }

    public function test_purpose_authorization_can_be_stamped(): void
    {
        $handler = new RsaJwtHandler();
        $token = $handler->generateToken(
            ['user_id' => 'u-1'],
            null,
            TokenClaims::TYPE_ACCESS,
            TokenClaims::PURPOSE_AUTHORIZATION
        );
        $claims = $this->decodeRaw($token);

        $this->assertSame(TokenClaims::PURPOSE_AUTHORIZATION, $claims['purpose']);
    }

    public function test_type_and_purpose_are_distinct_from_token_type_class_claim(): void
    {
        // token_type (card/platform) is the CLASS; type/purpose are orthogonal.
        $handler = new RsaJwtHandler();
        $token = $handler->generateToken(
            ['user_id' => 'u-1', 'token_type' => 'card'],
            null,
            TokenClaims::TYPE_ACCESS,
            TokenClaims::PURPOSE_AUTHORIZATION
        );
        $claims = $this->decodeRaw($token);

        $this->assertSame('card', $claims['token_type']);
        $this->assertSame(TokenClaims::TYPE_ACCESS, $claims['type']);
        $this->assertSame(TokenClaims::PURPOSE_AUTHORIZATION, $claims['purpose']);
    }

    public function test_explicit_payload_purpose_still_wins_for_internal_state_tokens(): void
    {
        // The OAuth state token legitimately reuses `purpose` for its own marker.
        $handler = new RsaJwtHandler();
        $token = $handler->generateToken(['purpose' => 'oauth_state', 'nonce' => 'abc']);
        $claims = $this->decodeRaw($token);

        $this->assertSame('oauth_state', $claims['purpose']);
    }

    public function test_verify_token_returns_type_and_purpose_claims(): void
    {
        // verifyToken strips iss/iat/exp but MUST pass type/purpose through so the
        // access/refresh middleware can assert on them.
        $handler = new RsaJwtHandler();
        $token = $handler->generateToken(['user_id' => 'u-1']);
        $claims = $handler->verifyToken($token);

        $this->assertIsArray($claims);
        $this->assertSame(TokenClaims::TYPE_ACCESS, $claims['type']);
        $this->assertSame(TokenClaims::PURPOSE_AUTHENTICATION, $claims['purpose']);
    }
}
