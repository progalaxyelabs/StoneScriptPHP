<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * verbatimTokensTs() (cli/generate-client.php) — single-token-mode fallback
 * fix (2026-08-01), found live on medstoreapp's offline Android build.
 *
 * Root cause (see cli/generate-client.php's own docblock above ACCESS_KEY for
 * the full two-pass writeup): in single-token mode, ngx-stonescriptphp-client's
 * TokenService never writes `ssp_api_access_token` at all — it aliases the API
 * token under `ssp_auth_access_token` (localStorage), because in that mode
 * there is only ever one token for the life of the install. The generated
 * TokenStore had no idea that key existed, so get() always returned null and
 * every business-API request went out with no Authorization header.
 *
 * This test generates a REAL client package (subprocess, same pattern as
 * ClientGeneratorV4Test::runGenerator()) and inspects the actual generated
 * tokens.ts file — not a hand-copied mirror of the generator's logic — so it
 * pins the real bytes that ship, not just intent.
 */
final class GeneratedTokenStoreTest extends TestCase
{
    private string $frameworkRoot;

    protected function setUp(): void
    {
        $this->frameworkRoot = realpath(__DIR__ . '/../..');
    }

    public function test_generated_token_store_falls_back_to_single_token_mode_alias(): void
    {
        $tokensTs = $this->generateAndReadTokensTs();

        // Priority order the docblock promises: sessionStorage api-token
        // (standard per-tab multi-tenant case) -> localStorage api-token
        // (defensive) -> localStorage auth-token (single-token-mode alias,
        // last resort).
        $this->assertStringContainsString("AUTH_ACCESS_KEY = 'ssp_auth_access_token'", $tokensTs);
        $this->assertStringContainsString("AUTH_REFRESH_KEY = 'ssp_auth_refresh_token'", $tokensTs);

        // get() must fall through to the auth-token alias when neither
        // session- nor localStorage has the real api-token key.
        $getFn = $this->extractMethodBody($tokensTs, 'get');
        $this->assertStringContainsString('sessionStorage.getItem(ACCESS_KEY)', $getFn);
        $this->assertStringContainsString('localStorage.getItem(ACCESS_KEY)', $getFn);
        $this->assertStringContainsString('localStorage.getItem(AUTH_ACCESS_KEY)', $getFn);

        // set() must write BOTH storages so a single-token-mode client (which
        // reads from localStorage) and a standard multi-tenant client (which
        // reads from sessionStorage) both see a token written by the same
        // generated code, regardless of which mode the consuming app runs.
        $setFn = $this->extractMethodBody($tokensTs, 'set');
        $this->assertStringContainsString('sessionStorage.setItem(ACCESS_KEY', $setFn);
        $this->assertStringContainsString('localStorage.setItem(ACCESS_KEY', $setFn);
    }

    public function test_generated_token_store_get_prefers_session_over_local(): void
    {
        // Regression guard: a real api-token in sessionStorage (the normal,
        // per-tab multi-tenant case) must win over anything in localStorage —
        // the fallback chain must not accidentally invert priority.
        $tokensTs = $this->generateAndReadTokensTs();
        $getFn = $this->extractMethodBody($tokensTs, 'get');

        $sessionPos = strpos($getFn, 'sessionStorage.getItem(ACCESS_KEY)');
        $localPos   = strpos($getFn, 'localStorage.getItem(ACCESS_KEY)');
        $aliasPos   = strpos($getFn, 'localStorage.getItem(AUTH_ACCESS_KEY)');

        $this->assertNotFalse($sessionPos);
        $this->assertNotFalse($localPos);
        $this->assertNotFalse($aliasPos);
        $this->assertLessThan($localPos, $sessionPos, 'sessionStorage check must come before localStorage');
        $this->assertLessThan($aliasPos, $localPos, 'plain localStorage check must come before the auth-token alias fallback');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function generateAndReadTokensTs(): string
    {
        $frameworkRoot  = $this->frameworkRoot;
        $generatorPath  = $frameworkRoot . '/cli/generate-client.php';
        $vendorAutoload = $frameworkRoot . '/vendor/autoload.php';

        $tmpRoot   = sys_get_temp_dir() . '/ssp-tokenstore-root-' . uniqid();
        $configDir = $tmpRoot . '/src/config';
        mkdir($configDir, 0755, true);
        file_put_contents($configDir . '/routes.php', <<<'PHP'
<?php
return [
    'GET' => [
        '/portal/items' => ['handler' => 'ListItemsRoute', 'service' => 'portal', 'group' => 'items'],
    ],
];
PHP
        );
        file_put_contents($tmpRoot . '/composer.json', json_encode(['name' => 'fixture/tokenstore-test', 'require' => new \stdClass()]) . "\n");

        $outputDir = sys_get_temp_dir() . '/ssp-tokenstore-out-' . uniqid();

        // T2 (JWT-tenant) — the fixture route carries no /portal/tenant/{tenantId}
        // URL prefix, so T3 (the default) would abort generation entirely (see
        // ClientGeneratorV4Test's v4.7 guard). Irrelevant to what's under test
        // here (tokens.ts generation is tenancy-mode-agnostic).
        $argsPhp = var_export(['generator', '--output=' . $outputDir, '--tenancy=T2'], true);
        $wrapperPath = sys_get_temp_dir() . '/ssp-tokenstore-wrapper-' . uniqid() . '.php';
        $wrapperContent = <<<PHP
<?php
define('ROOT_PATH',        '$tmpRoot/');
define('SRC_PATH',         '$tmpRoot/src/');
define('CONFIG_PATH',      '$tmpRoot/src/config/');
define('DEBUG_MODE',       1);
define('INDEX_START_TIME', microtime(true));
require_once '$vendorAutoload';
\$argv = $argsPhp;
\$argc = count(\$argv);
\$_SERVER['argv'] = \$argv;
\$_SERVER['argc'] = \$argc;
require '$generatorPath';
PHP;
        file_put_contents($wrapperPath, $wrapperContent);

        $cmd = escapeshellarg(PHP_BINARY) . ' -d error_reporting=E_ALL -d display_errors=stderr '
             . escapeshellarg($wrapperPath) . ' 2>&1';
        exec($cmd, $output, $exitCode);
        @unlink($wrapperPath);

        $this->assertSame(0, $exitCode, "Client generation failed:\n" . implode("\n", $output));

        $tokensFile = $outputDir . '/portal/src/tokens.ts';
        $this->assertFileExists($tokensFile, implode("\n", $output));

        $content = file_get_contents($tokensFile);

        $this->removeRecursive($tmpRoot);
        $this->removeRecursive($outputDir);

        return $content;
    }

    private function extractMethodBody(string $ts, string $methodName): string
    {
        // Matches e.g. "get(): string | null {" up to its closing brace at
        // the same nesting depth — good enough for this file's simple,
        // single-level method bodies (no nested braces inside get()/set()).
        $pattern = '/\b' . preg_quote($methodName, '/') . '\s*\([^)]*\)[^{]*\{(.*?)\n  \}/s';
        $this->assertMatchesRegularExpression($pattern, $ts, "Could not find method '$methodName' in generated tokens.ts");
        preg_match($pattern, $ts, $m);
        return $m[1];
    }

    private function removeRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
