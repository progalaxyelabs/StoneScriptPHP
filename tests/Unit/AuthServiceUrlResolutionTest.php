<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use StoneScriptPHP\Application;
use StoneScriptPHP\Env;

/**
 * Kill the silent localhost auth-URL fallback.
 *
 * Before this fix, Env::$AUTH_SERVICE_URL defaulted to 'http://localhost:3139' and
 * Application::buildJwtHandler() / buildAuthRouteOptions() read it via
 * `$env->AUTH_SERVICE_URL ?? 'http://localhost:3139'` — a chain that NEVER fails,
 * because Env's typed property is a non-nullable string with its own hardcoded
 * localhost default. A platform that forgot to set the AUTH_SERVICE_URL env var
 * (many platforms have no ROOT_PATH/config/auth.php shim) would silently boot
 * with the auth service pointed at loopback, and every login/register/JWKS call
 * failed deep inside a curl error ("Failed to connect to localhost port 3139")
 * instead of at boot where a misconfiguration belongs.
 *
 * The fix: Env::$AUTH_SERVICE_URL now defaults to '' (no silent value, mirroring
 * AUTH_ISSUER's existing pattern), and the shared resolver
 * Application::resolveAuthServiceUrl() throws a RuntimeException when neither an
 * explicit `auth.server.url` config value nor a non-empty AUTH_SERVICE_URL env var
 * resolves to something. We exercise that private static resolver directly via
 * reflection — Application::run() itself dispatches HTTP and can't be unit tested.
 *
 * @covers \StoneScriptPHP\Application
 */
final class AuthServiceUrlResolutionTest extends TestCase
{
    /** @var string[] Env vars this test process-wide putenv()'d, so tearDown() can undo exactly them. */
    private array $envVarsSetByThisTest = [];

    protected function setUp(): void
    {
        $this->setEnvIfEmpty('DB_GATEWAY_URL', 'http://localhost:9000');
        $this->setEnvIfEmpty('DB_GATEWAY_PLATFORM', 'test-platform');
        $this->resetEnvSingleton();
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

    /**
     * @param array<string,mixed> $authConfig
     */
    private function resolve(array $authConfig, Env $env, string $mode): string
    {
        $m = new ReflectionMethod(Application::class, 'resolveAuthServiceUrl');
        $m->setAccessible(true);
        return $m->invoke(null, $authConfig, $env, $mode);
    }

    /** Env::$AUTH_SERVICE_URL must default to '' — no baked-in localhost value. */
    public function testEnvAuthServiceUrlHasNoDefault(): void
    {
        putenv('AUTH_SERVICE_URL');
        unset($_ENV['AUTH_SERVICE_URL']);
        $this->resetEnvSingleton();

        $env = Env::get_instance();
        $this->assertSame('', $env->AUTH_SERVICE_URL,
            'AUTH_SERVICE_URL must have no silent localhost default — the framework must '
            . 'fail loud when it is genuinely unconfigured, not quietly resolve to loopback.');
    }

    /** Genuinely unconfigured (no env, no explicit config) → loud RuntimeException, not localhost. */
    public function testEmptyAuthServiceUrlThrowsRuntimeExceptionInsteadOfDefaultingToLocalhost(): void
    {
        putenv('AUTH_SERVICE_URL');
        unset($_ENV['AUTH_SERVICE_URL']);
        $this->resetEnvSingleton();
        $env = Env::get_instance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/AUTH_SERVICE_URL/');

        $this->resolve([], $env, 'external');
    }

    /** Non-empty AUTH_SERVICE_URL env var is used as-is — no substitution, no mutation. */
    public function testEnvAuthServiceUrlIsUsedWhenSet(): void
    {
        putenv('AUTH_SERVICE_URL=http://auth:3139');
        $this->envVarsSetByThisTest[] = 'AUTH_SERVICE_URL';
        $this->resetEnvSingleton();
        $env = Env::get_instance();

        $this->assertSame('http://auth:3139', $this->resolve([], $env, 'external'));
    }

    /** Explicit auth.server.url config takes precedence over the env var. */
    public function testExplicitConfigUrlOverridesEnv(): void
    {
        putenv('AUTH_SERVICE_URL=http://auth:3139');
        $this->envVarsSetByThisTest[] = 'AUTH_SERVICE_URL';
        $this->resetEnvSingleton();
        $env = Env::get_instance();

        $resolved = $this->resolve(
            ['server' => ['url' => 'http://explicit-auth:9999']],
            $env,
            'external'
        );

        $this->assertSame('http://explicit-auth:9999', $resolved);
    }

    /** Regression guard: the literal string 'http://localhost:3139' must never appear in the resolved value
     *  unless the caller explicitly asked for it (i.e. it must never be an implicit default). */
    public function testResolvedUrlIsNeverAnImplicitLocalhostDefault(): void
    {
        putenv('AUTH_SERVICE_URL=http://auth:3139');
        $this->envVarsSetByThisTest[] = 'AUTH_SERVICE_URL';
        $this->resetEnvSingleton();
        $env = Env::get_instance();

        $this->assertSame('http://auth:3139', $this->resolve([], $env, 'hybrid'));
        $this->assertNotSame('http://localhost:3139', $this->resolve([], $env, 'hybrid'));
    }
}
