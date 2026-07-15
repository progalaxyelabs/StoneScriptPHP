<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use StoneScriptPHP\Env;
use StoneScriptPHP\Routing\Middleware\CorsMiddleware;

/**
 * Regression suite for the CORS/ALLOWED_ORIGINS fix (2026-07-15).
 *
 * Root cause chain that motivated this whole file:
 *  1. Env::$ALLOWED_ORIGINS used to default to a hardcoded, generic
 *     'http://localhost:3000,http://localhost:4200' — silently wrong for any
 *     platform running its Angular dev server on a different port, with no
 *     loud failure to notice it.
 *  2. Application.php's `$env->ALLOWED_ORIGINS ?? '*'` fallback was dead
 *     code — Env::$ALLOWED_ORIGINS is a non-nullable typed string with an
 *     explicit default, so it is never actually null.
 *  3. src/config/allowed-origins.php has been scaffolded by the skeleton
 *     since day one but was NEVER read by Application.php/Env.php — every
 *     platform that populated it (following the skeleton's implication that
 *     it does something) was configuring dead weight.
 *  4. CorsMiddleware's origin match was plain `in_array()` with no wildcard
 *     semantics — a literal '*' would never match a real Origin header
 *     anyway, and implementing real wildcard matching would be unsafe given
 *     Access-Control-Allow-Credentials is always sent true (Origin:* +
 *     credentials is invalid in browsers and a real security hole).
 *
 * Fix: Env now reads allowed-origins.php (validated at the token level
 * BEFORE execution — see loadOriginsFromFile()/isPureArrayReturnSource())
 * as the base, with the ALLOWED_ORIGINS env var still overriding it exactly
 * like every other typed Env property already does. Unconfigured (neither
 * file nor env var) now means genuinely empty -> CorsMiddleware allows no
 * origin at all (fail-closed), never '*'.
 */
final class AllowedOriginsConfigTest extends TestCase
{
    /** @var list<string> temp files to clean up */
    private array $tempFiles = [];
    /** @var list<string> env keys touched, to clean up */
    private array $touchedEnvKeys = [];

    private ReflectionMethod $loadOriginsFromFile;

    protected function setUp(): void
    {
        $reflection = new \ReflectionClass(Env::class);
        $this->loadOriginsFromFile = new ReflectionMethod(Env::class, 'loadOriginsFromFile');
        $this->loadOriginsFromFile->setAccessible(true);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        foreach ($this->touchedEnvKeys as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
        $this->tempFiles = [];
        $this->touchedEnvKeys = [];

        // Reset the singleton so other test classes rebuild it fresh.
        $prop = new \ReflectionProperty(Env::class, '_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function makeTempPhpFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sspOrigins_') . '.php';
        self::assertNotFalse(file_put_contents($path, $contents));
        $this->tempFiles[] = $path;
        return $path;
    }

    private function newEnvWithoutConstructor(): Env
    {
        $reflection = new \ReflectionClass(Env::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    // =========================================================================
    // loadOriginsFromFile() — the token-gated file reader
    // =========================================================================

    public function test_plain_array_literal_is_accepted(): void
    {
        $path = $this->makeTempPhpFile("<?php\nreturn ['http://localhost:4200'];\n");
        $env = $this->newEnvWithoutConstructor();

        $result = $this->loadOriginsFromFile->invoke($env, $path);

        $this->assertSame('http://localhost:4200', $result);
    }

    public function test_multi_entry_array_literal_is_accepted(): void
    {
        $path = $this->makeTempPhpFile(
            "<?php\nreturn [\n    'http://localhost:3040',\n    'https://www.progalaxy.in',\n];\n"
        );
        $env = $this->newEnvWithoutConstructor();

        $result = $this->loadOriginsFromFile->invoke($env, $path);

        $this->assertSame('http://localhost:3040,https://www.progalaxy.in', $result);
    }

    public function test_legacy_array_syntax_is_accepted(): void
    {
        $path = $this->makeTempPhpFile("<?php\nreturn array('http://localhost:4200');\n");
        $env = $this->newEnvWithoutConstructor();

        $result = $this->loadOriginsFromFile->invoke($env, $path);

        $this->assertSame('http://localhost:4200', $result);
    }

    public function test_nonexistent_file_returns_null(): void
    {
        $env = $this->newEnvWithoutConstructor();

        $result = $this->loadOriginsFromFile->invoke($env, '/tmp/does-not-exist-' . uniqid() . '.php');

        $this->assertNull($result);
    }

    public function test_empty_array_returns_null(): void
    {
        $path = $this->makeTempPhpFile("<?php\nreturn [];\n");
        $env = $this->newEnvWithoutConstructor();

        $result = $this->loadOriginsFromFile->invoke($env, $path);

        $this->assertNull($result);
    }

    /**
     * The exact circular-dependency shape this whole fix is defending
     * against: a config file that calls Env::get_instance(). Must be
     * rejected WITHOUT executing the file at all — if this test hangs or
     * stack-overflows instead of returning null promptly, the token guard
     * has a hole.
     */
    public function test_file_calling_env_get_instance_is_rejected_not_executed(): void
    {
        $path = $this->makeTempPhpFile(
            "<?php\n\$e = \\StoneScriptPHP\\Env::get_instance();\nreturn ['http://localhost:4200'];\n"
        );
        $env = $this->newEnvWithoutConstructor();

        $result = $this->loadOriginsFromFile->invoke($env, $path);

        $this->assertNull($result, 'a file calling Env::get_instance() must be rejected, not executed');
    }

    public function test_file_calling_getenv_is_rejected(): void
    {
        $path = $this->makeTempPhpFile(
            "<?php\nreturn array_merge(['http://localhost:4200'], explode(',', getenv('ALLOWED_ORIGINS') ?: ''));\n"
        );
        $env = $this->newEnvWithoutConstructor();

        $result = $this->loadOriginsFromFile->invoke($env, $path);

        $this->assertNull($result);
    }

    public function test_file_with_closure_is_rejected(): void
    {
        $path = $this->makeTempPhpFile(
            "<?php\nreturn array_filter(['http://localhost:4200', ''], fn(\$o) => !empty(\$o));\n"
        );
        $env = $this->newEnvWithoutConstructor();

        $result = $this->loadOriginsFromFile->invoke($env, $path);

        $this->assertNull($result);
    }

    public function test_file_with_new_instantiation_is_rejected(): void
    {
        $path = $this->makeTempPhpFile("<?php\nreturn [new \\stdClass()];\n");
        $env = $this->newEnvWithoutConstructor();

        $result = $this->loadOriginsFromFile->invoke($env, $path);

        $this->assertNull($result);
    }

    public function test_file_with_static_call_is_rejected(): void
    {
        $path = $this->makeTempPhpFile("<?php\nreturn [\\App\\SomeClass::ORIGIN];\n");
        $env = $this->newEnvWithoutConstructor();

        $result = $this->loadOriginsFromFile->invoke($env, $path);

        $this->assertNull($result);
    }

    // =========================================================================
    // CorsMiddleware::resolveAllowedOrigin() — fail-closed + no wildcard +
    // case-insensitive matching. Tests the pure decision method directly
    // rather than intercepting header() calls (xdebug_get_headers() isn't
    // reliably available — routinely disabled in prod for performance).
    // =========================================================================

    public function test_empty_allowed_origins_matches_nothing(): void
    {
        $middleware = new CorsMiddleware([]);

        $this->assertNull(
            $middleware->resolveAllowedOrigin('http://localhost:3040'),
            'fail-closed: an unconfigured/empty allow-list must not match any origin'
        );
    }

    public function test_wildcard_in_config_is_stripped_not_matched(): void
    {
        $middleware = new CorsMiddleware(['*']);

        $this->assertNull(
            $middleware->resolveAllowedOrigin('http://localhost:3040'),
            "configuring '*' must not result in matching an arbitrary real origin"
        );
    }

    public function test_wildcard_alongside_real_origins_only_matches_the_real_ones(): void
    {
        $middleware = new CorsMiddleware(['*', 'http://localhost:3040']);

        $this->assertNull($middleware->resolveAllowedOrigin('http://evil.example.com'));
        $this->assertSame('http://localhost:3040', $middleware->resolveAllowedOrigin('http://localhost:3040'));
    }

    public function test_configured_origin_matches_case_insensitively(): void
    {
        $middleware = new CorsMiddleware(['http://localhost:3040']);

        $this->assertSame('http://localhost:3040', $middleware->resolveAllowedOrigin('HTTP://LOCALHOST:3040'));
    }

    public function test_unlisted_origin_does_not_match(): void
    {
        $middleware = new CorsMiddleware(['http://localhost:3040']);

        $this->assertNull($middleware->resolveAllowedOrigin('http://evil.example.com'));
    }

    public function test_empty_origin_header_does_not_match(): void
    {
        $middleware = new CorsMiddleware(['http://localhost:3040']);

        $this->assertNull($middleware->resolveAllowedOrigin(''));
    }
}
