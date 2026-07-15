<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Firebase\JWT\JWT;
use StoneScriptPHP\Auth\TrustedIssuerVerifier;

/**
 * TrustedIssuerVerifier — mandatory iss + issuer-selects-the-key.
 *
 * The core security property: verifying an `iss=X` token uses X's key ONLY, so a
 * token whose `iss` was forged to X but was signed by Y's key is rejected.
 *
 * @covers \StoneScriptPHP\Auth\TrustedIssuerVerifier
 */
class TrustedIssuerVerifierTest extends TestCase
{
    private const ISSUER_A = 'https://api.platform-a.in';
    private const ISSUER_B = 'https://auth.platform-b.com';

    /** @var array{priv:\OpenSSLAsymmetricKey,pub:string} */
    private array $keyA;
    /** @var array{priv:\OpenSSLAsymmetricKey,pub:string} */
    private array $keyB;

    private TrustedIssuerVerifier $verifier;

    protected function setUp(): void
    {
        $this->keyA = $this->makeKeypair();
        $this->keyB = $this->makeKeypair();

        $this->verifier = new TrustedIssuerVerifier([
            self::ISSUER_A => ['kind' => 'local', 'public_key' => $this->keyA['pub']],
            self::ISSUER_B => ['kind' => 'local', 'public_key' => $this->keyB['pub']],
        ]);
    }

    /** @return array{priv:\OpenSSLAsymmetricKey,pub:string} */
    private function makeKeypair(): array
    {
        $res = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        return ['priv' => $res, 'pub' => openssl_pkey_get_details($res)['key']];
    }

    /**
     * @param \OpenSSLAsymmetricKey $signingKey
     * @param array<string,mixed> $claims
     */
    private function mint($signingKey, array $claims): string
    {
        $claims += ['iat' => time(), 'exp' => time() + 900];
        return JWT::encode($claims, $signingKey, 'RS256');
    }

    public function test_valid_token_from_trusted_issuer_verifies(): void
    {
        $token = $this->mint($this->keyA['priv'], ['iss' => self::ISSUER_A, 'sub' => 'u-1']);
        $claims = $this->verifier->verify($token);

        $this->assertIsArray($claims);
        $this->assertSame('u-1', $claims['sub']);
        $this->assertSame(self::ISSUER_A, $claims['iss']);
    }

    public function test_issuer_substitution_is_rejected(): void
    {
        // Attacker signs with key B but forges iss=ISSUER_A (which maps to key A).
        // The verifier selects key A by iss → signature check fails → reject.
        $forged = $this->mint($this->keyB['priv'], ['iss' => self::ISSUER_A, 'sub' => 'attacker']);

        $this->assertNull(
            $this->verifier->verify($forged),
            'A token with iss=A but signed by B\'s key MUST be rejected (issuer-substitution hole).'
        );
    }

    public function test_unknown_issuer_is_rejected(): void
    {
        $token = $this->mint($this->keyA['priv'], ['iss' => 'https://evil.example.com', 'sub' => 'x']);

        $this->assertNull(
            $this->verifier->verify($token),
            'An issuer not in the trusted set must be rejected (mandatory iss).'
        );
    }

    public function test_token_with_no_iss_is_rejected(): void
    {
        $token = $this->mint($this->keyA['priv'], ['sub' => 'x']); // no iss

        $this->assertNull($this->verifier->verify($token));
    }

    public function test_expired_token_is_rejected(): void
    {
        $expired = JWT::encode(
            ['iss' => self::ISSUER_A, 'sub' => 'u', 'iat' => time() - 1000, 'exp' => time() - 500],
            $this->keyA['priv'],
            'RS256'
        );

        $this->assertNull($this->verifier->verify($expired));
    }

    public function test_audience_mismatch_is_rejected(): void
    {
        $verifier = new TrustedIssuerVerifier([
            self::ISSUER_A => ['kind' => 'local', 'public_key' => $this->keyA['pub'], 'audience' => 'my-api'],
        ]);

        $wrongAud = $this->mint($this->keyA['priv'], ['iss' => self::ISSUER_A, 'aud' => 'other-api', 'sub' => 'u']);
        $this->assertNull($verifier->verify($wrongAud));

        $rightAud = $this->mint($this->keyA['priv'], ['iss' => self::ISSUER_A, 'aud' => 'my-api', 'sub' => 'u']);
        $this->assertIsArray($verifier->verify($rightAud));
    }

    public function test_malformed_token_is_rejected(): void
    {
        $this->assertNull($this->verifier->verify('not-a-jwt'));
        $this->assertNull($this->verifier->verify('a.b')); // wrong segment count
    }

    public function test_empty_issuer_map_is_rejected_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TrustedIssuerVerifier([]);
    }

    public function test_invalid_kind_is_rejected_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TrustedIssuerVerifier([self::ISSUER_A => ['kind' => 'nonsense']]);
    }

    public function test_jwks_kind_with_pinned_keys_verifies_and_selects_by_iss(): void
    {
        // Exercise the jwks branch offline via pinned Firebase Key objects.
        $keys = ['test-kid' => new \Firebase\JWT\Key($this->keyB['pub'], 'RS256')];
        $verifier = new TrustedIssuerVerifier([
            self::ISSUER_B => ['kind' => 'jwks', 'jwks_keys' => $keys],
        ]);

        $token = JWT::encode(
            ['iss' => self::ISSUER_B, 'sub' => 'passport-user', 'iat' => time(), 'exp' => time() + 900],
            $this->keyB['priv'],
            'RS256',
            'test-kid'
        );

        $claims = $verifier->verify($token);
        $this->assertIsArray($claims);
        $this->assertSame('passport-user', $claims['sub']);
    }
}
