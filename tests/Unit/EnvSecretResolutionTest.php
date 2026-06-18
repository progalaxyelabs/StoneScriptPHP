<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use StoneScriptPHP\Env;

/**
 * Unit tests for the native secret-resolution chain added in v4.1.0:
 *   1. getenv(VAR) / $_ENV[VAR]
 *   2. getenv(VAR_FILE) -> read that file
 *   3. /run/secrets/<lowercase var> -> read that file
 *
 * The chain's tiers 2-3 read FILES inside the PHP worker so they are immune to
 * PHP-FPM clear_env stripping bash-exported vars — that immunity is the whole
 * point of the fix and is asserted explicitly in testClearEnvImmunity().
 *
 * resolveRaw() is a pure, instance-scoped method (it does not read instance
 * state), so we exercise it on an Env built without the constructor to avoid
 * fighting the cached singleton / required-secret boot validation.
 */
final class EnvSecretResolutionTest extends TestCase
{
    private Env $env;
    private ReflectionMethod $resolveRaw;
    /** @var list<string> env keys to clean up after each test */
    private array $touchedEnvKeys = [];
    /** @var list<string> temp files to unlink after each test */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        // Build an Env instance without running the constructor so we don't
        // trip the required-secret boot validation here.
        $reflection = new \ReflectionClass(Env::class);
        $this->env = $reflection->newInstanceWithoutConstructor();

        $this->resolveRaw = new ReflectionMethod(Env::class, 'resolveRaw');
        $this->resolveRaw->setAccessible(true);
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedEnvKeys as $key) {
            putenv($key);          // unset
            unset($_ENV[$key]);
        }
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->touchedEnvKeys = [];
        $this->tempFiles = [];

        // Reset the singleton so other test classes rebuild it from .env and
        // are not poisoned by the synthetic config we set here.
        $prop = new \ReflectionProperty(Env::class, '_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $this->touchedEnvKeys[] = $key;
    }

    private function makeTempSecretFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sspsecret_');
        self::assertNotFalse($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;
        return $path;
    }

    private function resolve(string $name): ?string
    {
        /** @var ?string $result */
        $result = $this->resolveRaw->invoke($this->env, $name);
        return $result;
    }

    // --- Tier 1: direct env var ------------------------------------------

    public function testTier1ResolvesFromGetenv(): void
    {
        $this->setEnv('SSP_TEST_TOKEN', 'from-env');
        self::assertSame('from-env', $this->resolve('SSP_TEST_TOKEN'));
    }

    public function testUnsetKeyResolvesToNull(): void
    {
        self::assertNull($this->resolve('SSP_TEST_DEFINITELY_UNSET_KEY'));
    }

    public function testEmptyEnvVarIsPreservedAsEmptyString(): void
    {
        // An explicitly empty env var ("") must NOT silently fall through to
        // file tiers — callers can distinguish "" from "unset".
        $this->setEnv('SSP_TEST_EMPTY', '');
        self::assertSame('', $this->resolve('SSP_TEST_EMPTY'));
    }

    // --- Tier 2: <VAR>_FILE ----------------------------------------------

    public function testTier2ResolvesFromVarFile(): void
    {
        $file = $this->makeTempSecretFile("file-secret-value\n");
        $this->setEnv('SSP_TEST_TOKEN_FILE', $file);
        self::assertSame('file-secret-value', $this->resolve('SSP_TEST_TOKEN'));
    }

    public function testTier1WinsOverTier2(): void
    {
        $file = $this->makeTempSecretFile('file-value');
        $this->setEnv('SSP_TEST_TOKEN', 'env-value');
        $this->setEnv('SSP_TEST_TOKEN_FILE', $file);
        self::assertSame('env-value', $this->resolve('SSP_TEST_TOKEN'));
    }

    public function testVarFileTrailingNewlineIsTrimmed(): void
    {
        $file = $this->makeTempSecretFile("trimmed-token\r\n");
        $this->setEnv('SSP_TEST_TOKEN_FILE', $file);
        self::assertSame('trimmed-token', $this->resolve('SSP_TEST_TOKEN'));
    }

    public function testVarFilePointingAtMissingFileFallsThrough(): void
    {
        $this->setEnv('SSP_TEST_TOKEN_FILE', '/nonexistent/path/secret');
        self::assertNull($this->resolve('SSP_TEST_TOKEN'));
    }

    // --- clear_env immunity (the whole point) ----------------------------

    public function testClearEnvImmunity(): void
    {
        // Simulate PHP-FPM clear_env=yes: NO env var survives, only the FILE
        // is present. The resolver must still read the secret from the file.
        $file = $this->makeTempSecretFile("survives-clear-env\n");
        $this->setEnv('SSP_TEST_CLEARENV_FILE', $file);

        // Assert the plain var genuinely does NOT exist in this process.
        self::assertFalse(getenv('SSP_TEST_CLEARENV'));
        self::assertArrayNotHasKey('SSP_TEST_CLEARENV', $_ENV);

        self::assertSame('survives-clear-env', $this->resolve('SSP_TEST_CLEARENV'));
    }

    // --- secret() public accessor ----------------------------------------
    //
    // secret() goes through get_instance(), which runs the boot-time required-
    // secret validation. We ensure the required config is present and force a
    // fresh singleton so these tests are deterministic in isolation.

    private function bootSingletonWithRequiredConfig(): void
    {
        $this->setEnv('DB_GATEWAY_URL', 'http://gateway:9000');
        $this->setEnv('DB_GATEWAY_PLATFORM', 'testplatform');
        $this->resetSingleton();
        Env::get_instance(); // build now so any later reads share this instance
    }

    private function resetSingleton(): void
    {
        $prop = new \ReflectionProperty(Env::class, '_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function testSecretAccessorResolvesAdHocKeyViaEnv(): void
    {
        $this->setEnv('SSP_TEST_ADHOC', 'adhoc-value');
        $this->bootSingletonWithRequiredConfig();
        self::assertSame('adhoc-value', Env::secret('SSP_TEST_ADHOC'));
    }

    public function testSecretAccessorReturnsDefaultWhenUnset(): void
    {
        $this->bootSingletonWithRequiredConfig();
        self::assertSame(
            'fallback',
            Env::secret('SSP_TEST_ADHOC_UNSET_KEY', 'fallback')
        );
    }

    public function testSecretAccessorResolvesViaVarFile(): void
    {
        $file = $this->makeTempSecretFile("secret-via-file\n");
        $this->setEnv('SSP_TEST_ADHOC2_FILE', $file);
        $this->bootSingletonWithRequiredConfig();
        self::assertSame('secret-via-file', Env::secret('SSP_TEST_ADHOC2'));
    }

    // --- boot-time required-secret validation (fail-fast) ----------------

    public function testBootFailsFastWhenRequiredSecretMissing(): void
    {
        // No DB_GATEWAY_URL / DB_GATEWAY_PLATFORM in the environment and no
        // .env loaded for this fresh singleton -> construction must throw a
        // clear message rather than booting into a silent runtime 500.
        putenv('DB_GATEWAY_URL');
        unset($_ENV['DB_GATEWAY_URL']);
        putenv('DB_GATEWAY_PLATFORM');
        unset($_ENV['DB_GATEWAY_PLATFORM']);
        $this->resetSingleton();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/DB_GATEWAY_URL.*missing or empty/');

        Env::get_instance();
    }

    public function testBootSucceedsWhenRequiredSecretViaVarFile(): void
    {
        // Validation must accept a required secret delivered ONLY via *_FILE
        // (clear_env-immune path) — proving the boot check uses the full chain.
        $urlFile = $this->makeTempSecretFile("http://gw-from-file:9000\n");
        putenv('DB_GATEWAY_URL');
        unset($_ENV['DB_GATEWAY_URL']);
        $this->setEnv('DB_GATEWAY_URL_FILE', $urlFile);
        $this->setEnv('DB_GATEWAY_PLATFORM', 'fileplatform');
        $this->resetSingleton();

        $env = Env::get_instance();
        self::assertSame('http://gw-from-file:9000', $env->DB_GATEWAY_URL);
        self::assertSame('fileplatform', $env->DB_GATEWAY_PLATFORM);
    }
}
