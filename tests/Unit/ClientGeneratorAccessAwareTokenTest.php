<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * v4.8 — access-aware token selection in the generated client.
 *
 * Root problem: RouteAccess (public|authentication|authorization, since
 * v6.2.0, enforced at runtime by AccessTokenMiddleware) was completely
 * invisible to cli/generate-client.php — every generated method attached
 * whatever the TokenStore's single API-token slot held, regardless of what
 * the route actually required. A route declaring access:authentication
 * (e.g. provision-tenant, select-tenant, memberships — routes that run
 * BEFORE a tenant-scoped API token exists) had no way to get the right
 * (identity/auth) token from a generated client at all. Downstream
 * platforms independently hand-rolled raw-fetch bypasses for exactly this
 * gap. Separately, such routes are typically registered under
 * service:'infra' (the framework's own bucket for "not a specific business
 * service"), which the generator excludes from output entirely — so a
 * carve-out was needed there too (a route that explicitly declares
 * access:authentication is by definition not a pure infra probe).
 *
 * These tests generate a REAL client via a subprocess (same pattern as
 * ClientGeneratorV4Test::runGenerator()) and inspect the actual emitted
 * TypeScript bytes — not a hand-copied mirror of the generator's logic.
 */
final class ClientGeneratorAccessAwareTokenTest extends TestCase
{
    private string $frameworkRoot;

    protected function setUp(): void
    {
        $this->frameworkRoot = realpath(__DIR__ . '/../..');
    }

    // ── Call-site emission ──────────────────────────────────────────────
    //
    // The fixture has TWO services: 'portal' (items, public-catalog) and
    // 'infra' (auth/provision-tenant, health). The generator emits ONE
    // package per discovered service (confirmed by direct inspection during
    // implementation), so access:authentication/public assertions read from
    // whichever package that route actually lands in — 'portal' for
    // public-catalog (a real portal business route), 'infra' for
    // provision-tenant (carved out of the infra exclusion below).

    public function test_authentication_route_emits_tokenMode_authentication(): void
    {
        $infraClientTs = $this->generateAndRead($this->fixtureRoutes(), 'infra', 'src/client.ts');

        $this->assertStringContainsString("{ tokenMode: 'authentication' }", $infraClientTs);
    }

    public function test_public_route_emits_tokenMode_public(): void
    {
        $portalClientTs = $this->generateAndRead($this->fixtureRoutes(), 'portal', 'src/client.ts');

        $this->assertStringContainsString("{ tokenMode: 'public' }", $portalClientTs);
    }

    /**
     * The common case (access unset, i.e. authorization — today's only
     * behavior) must emit NO extra call-site argument at all — byte-identical
     * to every generated client before this feature existed. Regression guard
     * against the exact mistake caught mid-implementation: naively always
     * inserting an `undefined` placeholder arg for the GET-with-id shape.
     */
    public function test_default_authorization_route_emits_no_extra_argument(): void
    {
        $portalClientTs = $this->generateAndRead($this->fixtureRoutes(), 'portal', 'src/client.ts');

        $this->assertStringNotContainsString('tokenMode', $this->extractMethodLine($portalClientTs, 'list'));
        // GET-with-id shape specifically — the one case with no pre-existing
        // trailing arg, so it's the one most likely to regress silently.
        $this->assertStringContainsString('this.http.get<T.ApiResponse>(`/portal/items/${id}`),', $portalClientTs);
    }

    // ── infra exclusion carve-out ────────────────────────────────────────

    public function test_infra_route_without_access_stays_excluded(): void
    {
        $infraClientTs = $this->generateAndRead($this->fixtureRoutes(), 'infra', 'src/client.ts');

        $this->assertStringNotContainsString('healthProbe', $infraClientTs);
    }

    public function test_infra_route_with_authentication_access_is_included(): void
    {
        $infraClientTs = $this->generateAndRead($this->fixtureRoutes(), 'infra', 'src/client.ts');

        $this->assertStringContainsString('provisionTenant', $infraClientTs);
        $this->assertStringContainsString("{ tokenMode: 'authentication' }", $this->extractMethodLine($infraClientTs, 'provisionTenant', includeNextLine: true));
    }

    // ── verbatim runtime files ───────────────────────────────────────────

    public function test_generated_tokens_ts_has_getAuthToken(): void
    {
        // verbatim files (tokens.ts/http.ts) are identical across every
        // generated package — 'portal' is as good as any to inspect.
        $tokensTs = $this->generateAndRead($this->fixtureRoutes(), 'portal', 'src/tokens.ts');

        $this->assertStringContainsString('getAuthToken(): string | null', $tokensTs);
        $this->assertStringContainsString("AUTH_ACCESS_KEY", $tokensTs);
    }

    public function test_generated_http_ts_has_tokenMode_selection_and_skips_refresh_for_non_authorization(): void
    {
        $httpTs = $this->generateAndRead($this->fixtureRoutes(), 'portal', 'src/http.ts');

        $this->assertStringContainsString('HttpRequestOptions', $httpTs);
        $this->assertStringContainsString("tokenMode === 'authentication'", $httpTs);
        $this->assertStringContainsString('this.tokens.getAuthToken()', $httpTs);
        // 401-retry cycle scoped to 'authorization' only.
        $this->assertStringContainsString("tokenMode === 'authorization') {", $httpTs);
    }

    // ── Fixture + helpers ─────────────────────────────────────────────────

    private function fixtureRoutes(): string
    {
        $file = sys_get_temp_dir() . '/ssp-access-aware-fixture-' . uniqid() . '.php';
        file_put_contents($file, <<<'PHP'
<?php
return [
    'GET' => [
        '/portal/items' => ['handler' => 'ListItemsRoute', 'service' => 'portal', 'group' => 'items'],
        '/portal/items/{id}' => ['handler' => 'GetItemRoute', 'service' => 'portal', 'group' => 'items'],
        '/portal/public-catalog' => ['handler' => 'PublicCatalogRoute', 'service' => 'portal', 'group' => 'items', 'access' => 'public'],
        '/infra/health' => ['handler' => 'HealthProbeRoute', 'service' => 'infra', 'group' => 'infra', 'action' => 'health-probe'],
    ],
    'POST' => [
        '/api/auth/provision-tenant' => ['handler' => 'ProvisionTenantRoute', 'service' => 'infra', 'group' => 'auth', 'access' => 'authentication'],
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

        $tmpRoot   = sys_get_temp_dir() . '/ssp-access-aware-root-' . uniqid();
        $configDir = $tmpRoot . '/src/config';
        mkdir($configDir, 0755, true);
        copy($routesFile, $configDir . '/routes.php');
        file_put_contents($tmpRoot . '/composer.json', json_encode(['name' => 'fixture/access-aware-test', 'require' => new \stdClass()]) . "\n");

        $outputDir = sys_get_temp_dir() . '/ssp-access-aware-out-' . uniqid();

        // T2 (no /tenant/{tenantId} prefix in the fixture routes) + no
        // --service filter, so 'infra' routes are reachable at all (the
        // carve-out is about exclusionReason(), not the service filter).
        $argsPhp = var_export(['generator', '--output=' . $outputDir, '--tenancy=T2'], true);
        $wrapperPath = sys_get_temp_dir() . '/ssp-access-aware-wrapper-' . uniqid() . '.php';
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

        // The generator emits one package per discovered service (confirmed
        // by direct inspection while implementing this) — 'portal' and
        // 'infra' are both real, separate output directories here.
        $target = $outputDir . '/' . $package . '/' . $relativeFile;
        $this->assertFileExists($target, "Output:\n" . implode("\n", $output));

        $content = file_get_contents($target);

        $this->removeRecursive($tmpRoot);
        $this->removeRecursive($outputDir);

        return $content;
    }

    /**
     * Extract the source line(s) for a named generated method — narrows
     * assertions to the relevant call site instead of substring-matching
     * the whole file (which could pass by coincidence if the string appears
     * elsewhere, e.g. in a comment).
     */
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
