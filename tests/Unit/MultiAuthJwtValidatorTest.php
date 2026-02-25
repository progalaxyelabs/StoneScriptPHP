<?php

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\MultiAuthJwtValidator;

class MultiAuthJwtValidatorTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/stonescriptphp_test_' . getmypid();
        @mkdir($this->cacheDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up cache files
        $files = glob($this->cacheDir . '/*');
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($this->cacheDir);
    }

    public function testConstructorAcceptsCacheDir(): void
    {
        $validator = new MultiAuthJwtValidator([], $this->cacheDir);
        $this->assertInstanceOf(MultiAuthJwtValidator::class, $validator);
    }

    public function testConstructorDefaultsCacheDirToTempDir(): void
    {
        $validator = new MultiAuthJwtValidator([]);
        $this->assertInstanceOf(MultiAuthJwtValidator::class, $validator);
    }

    public function testPersistentCacheWriteAndRead(): void
    {
        $jwksData = [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'kid' => 'test-key-1',
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'n' => 'test-modulus',
                    'e' => 'AQAB',
                ],
            ],
        ];

        $validator = new MultiAuthJwtValidator([], $this->cacheDir);

        // Write to persistent cache via reflection
        $writeMethod = new \ReflectionMethod($validator, 'writePersistentCache');
        $writeMethod->setAccessible(true);
        $writeMethod->invoke($validator, 'customer', $jwksData);

        // Verify file was created
        $cacheKey = 'stonescriptphp_jwks_' . md5('customer');
        $filePath = $this->cacheDir . '/' . $cacheKey . '.json';
        $this->assertFileExists($filePath);

        // Read back via reflection (simulates new PHP-FPM request)
        $validator2 = new MultiAuthJwtValidator([], $this->cacheDir);
        $readMethod = new \ReflectionMethod($validator2, 'readPersistentCache');
        $readMethod->setAccessible(true);
        $result = $readMethod->invoke($validator2, 'customer');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('jwks', $result);
        $this->assertArrayHasKey('time', $result);
        $this->assertEquals($jwksData, $result['jwks']);
        $this->assertIsInt($result['time']);
    }

    public function testPersistentCacheReturnsNullForMissingIssuer(): void
    {
        $validator = new MultiAuthJwtValidator([], $this->cacheDir);

        $readMethod = new \ReflectionMethod($validator, 'readPersistentCache');
        $readMethod->setAccessible(true);
        $result = $readMethod->invoke($validator, 'nonexistent');

        $this->assertNull($result);
    }

    public function testPersistentCacheIsAtomicWrite(): void
    {
        $jwksData = ['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256', 'n' => 'n1', 'e' => 'AQAB']]];

        $validator = new MultiAuthJwtValidator([], $this->cacheDir);
        $writeMethod = new \ReflectionMethod($validator, 'writePersistentCache');
        $writeMethod->setAccessible(true);

        // Write twice — no temp files should be left
        $writeMethod->invoke($validator, 'customer', $jwksData);
        $writeMethod->invoke($validator, 'customer', $jwksData);

        $files = glob($this->cacheDir . '/*.tmp');
        $this->assertEmpty($files, 'No temp files should remain after atomic write');
    }

    public function testCacheFileContainsValidJson(): void
    {
        $jwksData = ['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256', 'n' => 'n1', 'e' => 'AQAB']]];

        $validator = new MultiAuthJwtValidator([], $this->cacheDir);
        $writeMethod = new \ReflectionMethod($validator, 'writePersistentCache');
        $writeMethod->setAccessible(true);
        $writeMethod->invoke($validator, 'customer', $jwksData);

        $cacheKey = 'stonescriptphp_jwks_' . md5('customer');
        $filePath = $this->cacheDir . '/' . $cacheKey . '.json';

        $contents = file_get_contents($filePath);
        $decoded = json_decode($contents, true);

        $this->assertNotNull($decoded);
        $this->assertEquals($jwksData, $decoded['jwks']);
        $this->assertEqualsWithDelta(time(), $decoded['time'], 2);
    }

    public function testInvalidJwtFormatReturnsNull(): void
    {
        $validator = new MultiAuthJwtValidator(['customer' => [
            'issuer' => 'https://auth.example.com',
            'jwks_url' => 'https://auth.example.com/jwks',
        ]], $this->cacheDir);

        $this->assertNull($validator->validateJWT('not-a-jwt'));
        $this->assertNull($validator->validateJWT('only.two'));
        $this->assertNull($validator->validateJWT(''));
    }

    public function testUnknownIssuerReturnsNull(): void
    {
        $validator = new MultiAuthJwtValidator(['customer' => [
            'issuer' => 'https://auth.example.com',
            'jwks_url' => 'https://auth.example.com/jwks',
        ]], $this->cacheDir);

        // Create a JWT with unknown issuer (base64url encoded)
        $header = base64_encode(json_encode(['alg' => 'RS256', 'kid' => 'k1']));
        $payload = base64_encode(json_encode(['iss' => 'https://unknown.example.com']));
        $signature = base64_encode('fake-sig');

        $this->assertNull($validator->validateJWT("$header.$payload.$signature"));
    }

    public function testHasIssuerType(): void
    {
        $validator = new MultiAuthJwtValidator([
            'customer' => ['issuer' => 'https://auth.example.com', 'jwks_url' => 'https://auth.example.com/jwks'],
            'employee' => ['issuer' => 'https://admin.example.com', 'jwks_url' => 'https://admin.example.com/jwks'],
        ]);

        $this->assertTrue($validator->hasIssuerType('customer'));
        $this->assertTrue($validator->hasIssuerType('employee'));
        $this->assertFalse($validator->hasIssuerType('unknown'));
    }

    public function testGetAuthServers(): void
    {
        $servers = [
            'customer' => ['issuer' => 'https://auth.example.com', 'jwks_url' => 'https://auth.example.com/jwks'],
        ];

        $validator = new MultiAuthJwtValidator($servers);
        $this->assertEquals($servers, $validator->getAuthServers());
    }
}
