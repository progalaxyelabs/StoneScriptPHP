<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\MultiAuthJwtValidator;

/**
 * Bounded stale-JWKS serving (max_stale_ttl) — when the auth service is unreachable,
 * a stale key set is served only within a configurable staleness ceiling; past it,
 * fail closed rather than trust a possibly-rotated/revoked key.
 *
 * @covers \StoneScriptPHP\Auth\MultiAuthJwtValidator
 */
class MultiAuthJwksStaleCacheBoundTest extends TestCase
{
    private string $cacheDir;
    private const ISSUER_TYPE = 'stalebound';

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ssp-stale-' . getmypid();
        @mkdir($this->cacheDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cacheDir);
    }

    private function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** Seed a persistent JWKS cache file with a valid RSA key and the given age. */
    private function seedStaleCache(int $ageSeconds): void
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $d = openssl_pkey_get_details($res);
        $jwks = ['keys' => [[
            'kty' => 'RSA',
            'kid' => 'k1',
            'use' => 'sig',
            'alg' => 'RS256',
            'n'   => $this->b64url($d['rsa']['n']),
            'e'   => $this->b64url($d['rsa']['e']),
        ]]];

        $payload = ['jwks' => $jwks, 'time' => time() - $ageSeconds];
        $cacheKey = 'stonescriptphp_jwks_' . md5(self::ISSUER_TYPE);
        file_put_contents($this->cacheDir . '/' . $cacheKey . '.json', json_encode($payload));
    }

    /** @return array{0:MultiAuthJwtValidator,1:\ReflectionMethod,2:array<string,mixed>} */
    private function makeValidator(?int $maxStale): array
    {
        $config = array_filter([
            'issuer'        => 'https://auth.example.com',
            'jwks_url'      => 'http://127.0.0.1:9/unreachable',  // fetch will fail
            'cache_ttl'     => 1,                                  // force "stale" path
            'max_stale_ttl' => $maxStale,
        ], fn($v) => $v !== null);

        $validator = new MultiAuthJwtValidator([self::ISSUER_TYPE => $config], $this->cacheDir);
        $m = new \ReflectionMethod($validator, 'getJWKS');
        $m->setAccessible(true);
        return [$validator, $m, $config];
    }

    public function test_stale_within_bound_is_served_when_fetch_fails(): void
    {
        $this->seedStaleCache(100);                 // 100s old
        [$validator, $m, $config] = $this->makeValidator(3600); // ceiling 1h

        $keys = $m->invoke($validator, self::ISSUER_TYPE, $config, 'k1');
        $this->assertArrayHasKey('k1', $keys, 'Stale-but-within-bound keys should still be served');
    }

    public function test_stale_beyond_bound_fails_closed(): void
    {
        $this->seedStaleCache(7200);                // 2h old
        [$validator, $m, $config] = $this->makeValidator(3600); // ceiling 1h

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/max_stale_ttl/');
        $m->invoke($validator, self::ISSUER_TYPE, $config, 'k1');
    }

    public function test_unset_bound_preserves_unbounded_stale_serving(): void
    {
        $this->seedStaleCache(999999);              // very old
        [$validator, $m, $config] = $this->makeValidator(null); // no ceiling

        $keys = $m->invoke($validator, self::ISSUER_TYPE, $config, 'k1');
        $this->assertArrayHasKey('k1', $keys, 'Without max_stale_ttl, prior unbounded behavior is preserved');
    }
}
