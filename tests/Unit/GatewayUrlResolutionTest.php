<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Env;

/**
 * Kill the silent localhost auth-URL fallback.
 *
 * Before this fix, src/bootstrap.php's TokenValidator factory resolved its gateway
 * URL as `$authConfig['gateway_url'] ?? 'http://localhost:9000'`, where $authConfig
 * came from an optional ROOT_PATH/config/auth.php file that only a couple of
 * downstream platforms had. Any platform without that file silently got a
 * TokenValidator pointed at loopback instead of the real gateway. The fix extracts the resolution into the
 * standalone, testable stonescript_resolve_gateway_url() (src/helpers.php), sourced
 * from Env::$DB_GATEWAY_URL (a framework-required secret — Env::__construct() already
 * throws at boot if it's empty) instead of a hardcoded localhost default.
 *
 * @covers ::stonescript_resolve_gateway_url
 */
final class GatewayUrlResolutionTest extends TestCase
{
    /** @var string[] */
    private array $envVarsSetByThisTest = [];

    protected function setUp(): void
    {
        $this->setEnvIfEmpty('DB_GATEWAY_PLATFORM', 'test-platform');
    }

    protected function tearDown(): void
    {
        $this->resetEnvSingleton();
        foreach ($this->envVarsSetByThisTest as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
        $this->envVarsSetByThisTest = [];
    }

    private function setEnvIfEmpty(string $name, string $value): void
    {
        if (empty(getenv($name))) {
            putenv("{$name}={$value}");
            $this->envVarsSetByThisTest[] = $name;
        }
    }

    private function resetEnvSingleton(): void
    {
        $ref = new \ReflectionClass(Env::class);
        $prop = $ref->getProperty('_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function envWithGatewayUrl(string $url): Env
    {
        putenv("DB_GATEWAY_URL={$url}");
        $this->envVarsSetByThisTest[] = 'DB_GATEWAY_URL';
        $this->resetEnvSingleton();
        return Env::get_instance();
    }

    /** No legacy config override → resolves from Env::$DB_GATEWAY_URL, NOT localhost:9000. */
    public function testResolvesFromEnvWhenNoLegacyConfigOverride(): void
    {
        $env = $this->envWithGatewayUrl('http://gateway:9000');

        $resolved = \stonescript_resolve_gateway_url([], $env);

        $this->assertSame('http://gateway:9000', $resolved);
        $this->assertNotSame('http://localhost:9000', $resolved,
            'Must never silently resolve to the old hardcoded localhost default.');
    }

    /** Legacy $authConfig['gateway_url'] override still wins (backward compatibility). */
    public function testLegacyConfigOverrideTakesPrecedence(): void
    {
        $env = $this->envWithGatewayUrl('http://gateway:9000');

        $resolved = \stonescript_resolve_gateway_url(['gateway_url' => 'http://custom-gateway:9001'], $env);

        $this->assertSame('http://custom-gateway:9001', $resolved);
    }

    /**
     * Genuinely empty gateway_url (simulated directly, since Env itself would already
     * have thrown at construction time for a real empty DB_GATEWAY_URL) fails loud.
     */
    public function testEmptyGatewayUrlThrowsRuntimeException(): void
    {
        $env = $this->envWithGatewayUrl('http://gateway:9000');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Gateway URL/');

        // Explicit empty-string override simulates the "genuinely unconfigured" case
        // without relying on Env's own required-secret guard (which would throw
        // earlier, for a different reason, if DB_GATEWAY_URL itself were empty).
        \stonescript_resolve_gateway_url(['gateway_url' => ''], $env);
    }
}
