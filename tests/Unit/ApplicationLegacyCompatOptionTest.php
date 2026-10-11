<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Application;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;
use StoneScriptPHP\Env;

/**
 * Top-level auth.legacy_compat must reach ExternalAuthConfig (it used to be dropped by
 * Application::buildAuthRouteOptions(), so the default `true` always won).
 */
class ApplicationLegacyCompatOptionTest extends TestCase
{
    /** @var list<string> */
    private array $setHere = [];

    protected function setUp(): void
    {
        // ExternalAuthConfig reads Env::get_instance(), which needs the gateway keys; set only what is empty
        // and undo it afterwards so no state leaks into env-gated tests.
        foreach (['DB_GATEWAY_URL' => 'http://localhost:9000', 'DB_GATEWAY_PLATFORM' => 'test-platform'] as $k => $v) {
            if (empty(getenv($k))) {
                putenv("$k=$v");
                $this->setHere[] = $k;
            }
        }
        $this->resetEnv();
    }

    protected function tearDown(): void
    {
        foreach ($this->setHere as $k) {
            putenv($k);
        }
        $this->setHere = [];
        $this->resetEnv();
    }

    private function resetEnv(): void
    {
        $prop = new \ReflectionProperty(Env::class, '_instance');
        $prop->setValue(null, null);
    }

    private function options(array $authConfig): array
    {
        $env = (new \ReflectionClass(Env::class))->newInstanceWithoutConstructor();
        $env->AUTH_SERVICE_URL = 'http://auth.test';
        $env->AUTH_ISSUER = 'http://auth.test';
        $m = new \ReflectionMethod(Application::class, 'buildAuthRouteOptions');
        return $m->invoke(null, $authConfig, $env);
    }

    public function test_legacy_compat_false_is_forwarded_and_honoured(): void
    {
        $options = $this->options(['legacy_compat' => false, 'prefix' => '/api/auth']);
        $this->assertFalse($options['legacy_compat']);
        $this->assertFalse((new ExternalAuthConfig($options))->legacyCompat);
    }

    public function test_legacy_compat_true_is_forwarded(): void
    {
        $options = $this->options(['legacy_compat' => true]);
        $this->assertTrue((new ExternalAuthConfig($options))->legacyCompat);
    }

    public function test_absent_key_keeps_documented_default_true(): void
    {
        $options = $this->options([]);
        $this->assertArrayNotHasKey('legacy_compat', $options);
        $this->assertTrue((new ExternalAuthConfig($options))->legacyCompat);
    }
}
