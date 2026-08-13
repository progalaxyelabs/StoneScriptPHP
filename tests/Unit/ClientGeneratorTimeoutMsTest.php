<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * v4.9 — opt-in per-route client-side fetch timeout in the generated client.
 *
 * Root problem: every generated `fetch()` call was UNBOUNDED — no timeout at
 * all — regardless of how slow the route legitimately is. Found live
 * 2026-08-13 investigating a downstream platform's provision-tenant: the route
 * genuinely takes 4.2-5.0s (it creates a whole tenant database + deploys its
 * schema via the gateway), and whatever client/test harness called it had
 * its own ~3s expectation and gave up — the server had no idea and completed
 * successfully 2.8s later, and the caller's retry then collided with the
 * framework's existing-tenant guard (409). A per-route opt-in `timeoutMs`
 * (declared via `client_timeout_ms` in routes.php, mirroring the `access:`
 * pattern) lets a known-slow route get an explicit, generous bound instead
 * of silently inheriting "however long the caller feels like waiting" —
 * without risking a blanket global timeout prematurely aborting some OTHER
 * platform's differently-slow endpoint this change has no visibility into.
 *
 * These tests generate a REAL client via a subprocess (same pattern as
 * ClientGeneratorAccessAwareTokenTest::generateAndRead()) and inspect the
 * actual emitted TypeScript bytes — not a hand-copied mirror of the
 * generator's logic.
 */
final class ClientGeneratorTimeoutMsTest extends TestCase
{
    private string $frameworkRoot;

    protected function setUp(): void
    {
        $this->frameworkRoot = realpath(__DIR__ . '/../..');
    }

    // ── Call-site emission ──────────────────────────────────────────────

    public function test_route_with_client_timeout_ms_emits_timeoutMs_option(): void
    {
        $infraClientTs = $this->generateAndRead($this->fixtureRoutes(), 'infra', 'src/client.ts');

        $this->assertStringContainsString('provisionTenant', $infraClientTs);
        $this->assertStringContainsString(
            "{ tokenMode: 'authentication', timeoutMs: 20000 }",
            $this->extractMethodLine($infraClientTs, 'provisionTenant', includeNextLine: true),
        );
    }

    public function test_route_without_client_timeout_ms_emits_no_timeoutMs(): void
    {
        $portalClientTs = $this->generateAndRead($this->fixtureRoutes(), 'portal', 'src/client.ts');

        $this->assertStringNotContainsString('timeoutMs', $this->extractMethodLine($portalClientTs, 'list'));
    }

    /**
     * A route that sets ONLY client_timeout_ms (no access override) must
     * still emit a bare `{ timeoutMs: ... }` — not silently dropped because
     * accessType was null. Regression guard for the exact bug this refactor
     * could introduce: options-object construction previously branched on
     * $accessType alone.
     */
    public function test_timeout_only_route_emits_bare_timeoutMs_option(): void
    {
        $portalClientTs = $this->generateAndRead($this->fixtureRoutes(), 'portal', 'src/client.ts');

        $this->assertStringContainsString(
            '{ timeoutMs: 15000 }',
            $this->extractMethodLine($portalClientTs, 'slowReport', includeNextLine: true),
        );
    }

    /**
     * The common case (neither access nor client_timeout_ms set) must emit
     * NO extra call-site argument at all — byte-identical to every generated
     * client before this feature existed.
     */
    public function test_default_route_emits_no_extra_argument(): void
    {
        $portalClientTs = $this->generateAndRead($this->fixtureRoutes(), 'portal', 'src/client.ts');

        $this->assertStringContainsString('this.http.get<T.ApiResponse>(`/portal/items/${id}`),', $portalClientTs);
    }

    // ── verbatim runtime file ────────────────────────────────────────────

    public function test_generated_http_ts_has_timeoutMs_option_and_abort_signal(): void
    {
        $httpTs = $this->generateAndRead($this->fixtureRoutes(), 'portal', 'src/http.ts');

        $this->assertStringContainsString('timeoutMs?: number', $httpTs);
        $this->assertStringContainsString('AbortSignal.timeout(options.timeoutMs)', $httpTs);
        $this->assertStringContainsString("networkErr.name === 'TimeoutError'", $httpTs);
        $this->assertStringContainsString("'request_timeout'", $httpTs);
    }

    // ── Fixture + helpers (mirrors ClientGeneratorAccessAwareTokenTest) ──

    private function fixtureRoutes(): string
    {
        $file = sys_get_temp_dir() . '/ssp-timeout-ms-fixture-' . uniqid() . '.php';
        file_put_contents($file, <<<'PHP'
<?php
return [
    'GET' => [
        '/portal/items' => ['handler' => 'ListItemsRoute', 'service' => 'portal', 'group' => 'items'],
        '/portal/items/{id}' => ['handler' => 'GetItemRoute', 'service' => 'portal', 'group' => 'items'],
        '/portal/reports/slow' => ['handler' => 'SlowReportRoute', 'service' => 'portal', 'group' => 'items', 'action' => 'slow-report', 'client_timeout_ms' => 15000],
    ],
    'POST' => [
        '/api/auth/provision-tenant' => ['handler' => 'ProvisionTenantRoute', 'service' => 'infra', 'group' => 'auth', 'access' => 'authentication', 'client_timeout_ms' => 20000],
    ],
];
PHP
        );
        return $file;
    }

    private function generateAndRead(string $routesFile, string $package, string $relativeFile): string
    {
        $frameworkRoot  = $this->frameworkRoot;
        $generatorPath  = $frameworkRoot . '/cli/generate-client.php';
        $vendorAutoload = $frameworkRoot . '/vendor/autoload.php';

        $tmpRoot   = sys_get_temp_dir() . '/ssp-timeout-ms-root-' . uniqid();
        $configDir = $tmpRoot . '/src/config';
        mkdir($configDir, 0755, true);
        copy($routesFile, $configDir . '/routes.php');
        file_put_contents($tmpRoot . '/composer.json', json_encode(['name' => 'fixture/timeout-ms-test', 'require' => new \stdClass()]) . "\n");

        $outputDir = sys_get_temp_dir() . '/ssp-timeout-ms-out-' . uniqid();

        $argsPhp = var_export(['generator', '--output=' . $outputDir, '--tenancy=T2'], true);
        $wrapperPath = sys_get_temp_dir() . '/ssp-timeout-ms-wrapper-' . uniqid() . '.php';
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
        @unlink($routesFile);

        $this->assertSame(0, $exitCode, "Client generation failed:\n" . implode("\n", $output));

        $target = $outputDir . '/' . $package . '/' . $relativeFile;
        $this->assertFileExists($target, "Output:\n" . implode("\n", $output));

        $content = file_get_contents($target);

        $this->removeRecursive($tmpRoot);
        $this->removeRecursive($outputDir);

        return $content;
    }

    private function extractMethodLine(string $ts, string $methodName, bool $includeNextLine = false): string
    {
        $lines = explode("\n", $ts);
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*' . preg_quote($methodName, '/') . '\s*:/', $line)) {
                return $includeNextLine ? $line . "\n" . ($lines[$i + 1] ?? '') : $line;
            }
        }
        return '';
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
