<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 2026-08-12 — T3 platforms with an access:authentication/public route hard-
 * aborted `php stone generate client`.
 *
 * Root cause: v9.1.0 (ClientGeneratorAccessAwareTokenTest) made
 * access:authentication/public routes includable in an otherwise-excluded
 * service (real fleet convention: identity-scoped tier-2 routes like
 * provision-tenant land under service:'infra' purely because they don't
 * belong to a specific business service — see exclusionReason()). But that
 * feature's own test suite generated exclusively with --tenancy=T2, so it
 * never exercised the interaction with assertT3RoutesCarryTenantPrefix()
 * (the v4.7 guard that hard-aborts generation if a T3-tenant-scoped route is
 * missing its /{service}/tenant/{tenantId} URL prefix — see that function's
 * docblock for the production incident it prevents). On a real T3 platform
 * (medstoreapp: /portal/tenant/{tenantId}/... business routes), declaring
 * access:authentication on an infra-tagged route made it newly INCLUDED in
 * that guard's route set — and an identity-scoped route structurally cannot
 * carry a tenant URL prefix (there's no tenant yet), so the guard flagged it
 * as a false positive and aborted generation entirely for that service.
 *
 * Fix: routeIsTenantUrlExempt() (access: authentication|public) is now the
 * shared predicate BOTH the guard and the URL-template builder key off —
 * exempt routes skip the guard AND don't get ${this.t} prepended to their
 * URL (skipping only the guard without also fixing the template builder
 * would have reintroduced the exact doubled-URL 404 bug the guard exists to
 * prevent, just silently instead of loudly).
 *
 * These tests generate a REAL client via a subprocess (same pattern as
 * ClientGeneratorAccessAwareTokenTest::generateAndRead()) with --tenancy=T3,
 * combining a genuinely tenant-scoped 'portal' service with an infra service
 * carrying both an access:authentication route (must be exempt) and a
 * regular route with no access override (must still be caught by the guard —
 * regression coverage for the original v4.7 bug).
 */
final class ClientGeneratorT3TenantExemptionTest extends TestCase
{
    private string $frameworkRoot;

    protected function setUp(): void
    {
        $this->frameworkRoot = realpath(__DIR__ . '/../..');
    }

    public function test_t3_platform_with_authentication_route_does_not_abort(): void
    {
        // Generation itself succeeding (exit 0, files written) is the primary
        // assertion — the original bug was a hard abort (exit 1) here.
        $infraClientTs = $this->generateAndRead($this->fixtureRoutes(), 'infra', 'src/client.ts');

        $this->assertStringContainsString('provisionTenant', $infraClientTs);
    }

    public function test_authentication_route_url_has_no_tenant_prefix_prepended(): void
    {
        $infraClientTs = $this->generateAndRead($this->fixtureRoutes(), 'infra', 'src/client.ts');

        $line = $this->extractMethodLine($infraClientTs, 'provisionTenant', includeNextLine: true);
        $this->assertStringNotContainsString('${this.t}', $line,
            'an identity-scoped route has no tenant yet — ${this.t} here would double the URL and 404');
        $this->assertStringContainsString("'/api/auth/provision-tenant'", $line);
    }

    public function test_genuinely_tenant_scoped_route_missing_prefix_still_aborts(): void
    {
        // Regression guard for the ORIGINAL v4.7 bug: a real tenant-scoped
        // route (no access override) that forgets its /portal/tenant/{id}
        // prefix must still hard-abort generation, not silently pass because
        // the new exemption logic became too broad.
        $routesFile = $this->fixtureRoutesWithBrokenPortalRoute();

        $frameworkRoot  = $this->frameworkRoot;
        [$exitCode, $output] = $this->runGenerator($routesFile, ['--tenancy=T3']);

        $this->assertSame(1, $exitCode, "Expected generation to abort for a tenant-scoped route missing its URL prefix:\n" . implode("\n", $output));
        $this->assertStringContainsString('do not carry the expected', implode("\n", $output));
    }

    public function test_t3_portal_route_with_prefix_still_uses_this_t(): void
    {
        // Sanity: the fix must not accidentally exempt REAL tenant-scoped
        // routes from ${this.t} — only access:authentication|public ones.
        $portalClientTs = $this->generateAndRead($this->fixtureRoutes(), 'portal', 'src/client.ts');

        $line = $this->extractMethodLine($portalClientTs, 'list', includeNextLine: true);
        $this->assertStringContainsString('${this.t}', $line);
    }

    // ── Fixtures + helpers ─────────────────────────────────────────────────

    private function fixtureRoutes(): string
    {
        $file = sys_get_temp_dir() . '/ssp-t3-exempt-fixture-' . uniqid() . '.php';
        file_put_contents($file, <<<'PHP'
<?php
return [
    'GET' => [
        '/portal/tenant/{tenantId}/items' => ['handler' => 'ListItemsRoute', 'service' => 'portal', 'group' => 'items'],
    ],
    'POST' => [
        '/api/auth/provision-tenant' => ['handler' => 'ProvisionTenantRoute', 'service' => 'infra', 'group' => 'auth', 'access' => 'authentication'],
    ],
];
PHP
        );
        return $file;
    }

    private function fixtureRoutesWithBrokenPortalRoute(): string
    {
        $file = sys_get_temp_dir() . '/ssp-t3-exempt-broken-fixture-' . uniqid() . '.php';
        file_put_contents($file, <<<'PHP'
<?php
return [
    'GET' => [
        // Missing the /portal/tenant/{tenantId} prefix — the ORIGINAL v4.7 bug.
        '/portal/items' => ['handler' => 'ListItemsRoute', 'service' => 'portal', 'group' => 'items'],
    ],
];
PHP
        );
        return $file;
    }

    private function generateAndRead(string $routesFile, string $package, string $relativeFile): string
    {
        [$exitCode, $output, $outputDir] = $this->runGenerator($routesFile, ['--tenancy=T3'], keepOutput: true);

        $this->assertSame(0, $exitCode, "Client generation failed:\n" . implode("\n", $output));

        $target = $outputDir . '/' . $package . '/' . $relativeFile;
        $this->assertFileExists($target, "Output:\n" . implode("\n", $output));

        $content = file_get_contents($target);
        $this->removeRecursive($outputDir);

        return $content;
    }

    /**
     * @return array{0:int,1:string[]}|array{0:int,1:string[],2:string}
     */
    private function runGenerator(string $routesFile, array $extraArgs, bool $keepOutput = false): array
    {
        $frameworkRoot  = $this->frameworkRoot;
        $generatorPath  = $frameworkRoot . '/cli/generate-client.php';
        $vendorAutoload = $frameworkRoot . '/vendor/autoload.php';

        $tmpRoot   = sys_get_temp_dir() . '/ssp-t3-exempt-root-' . uniqid();
        $configDir = $tmpRoot . '/src/config';
        mkdir($configDir, 0755, true);
        copy($routesFile, $configDir . '/routes.php');
        file_put_contents($tmpRoot . '/composer.json', json_encode(['name' => 'fixture/t3-exempt-test', 'require' => new \stdClass()]) . "\n");

        $outputDir = sys_get_temp_dir() . '/ssp-t3-exempt-out-' . uniqid();

        $argsPhp = var_export(array_merge(['generator', '--output=' . $outputDir], $extraArgs), true);
        $wrapperPath = sys_get_temp_dir() . '/ssp-t3-exempt-wrapper-' . uniqid() . '.php';
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
        $this->removeRecursive($tmpRoot);

        if (!$keepOutput) {
            $this->removeRecursive($outputDir);
            return [$exitCode, $output];
        }

        return [$exitCode, $output, $outputDir];
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
