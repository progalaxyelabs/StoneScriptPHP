<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the orphan cross-product cleanup added in generator v4.6.
 *
 * Pre-v4.0 generator versions emitted a {consumer}×{service} cross-product layout:
 *   client/{consumer}/{service}/  (e.g. client/admin/finance/, client/ats/finance/)
 * These directories accumulate in git, have no package-lock.json, and cause lint
 * failures in the deploy pipeline. Generator v4.6 removes them automatically at the
 * start of every `php stone generate client` run.
 *
 * Tests verify:
 *   - removeOrphanNestedPackages() removes nested package dirs (those that contain
 *     package.json) inside each flat service package dir
 *   - Flat service packages (client/{service}/) are NOT removed
 *   - Non-package subdirs (src/, dist/, node_modules/, streaming/) are NOT removed
 *   - Dirs without a package.json inside the flat package are NOT removed
 *   - removeDirectoryRecursive() deletes a directory and all its contents
 *   - Returns 0 when the output dir does not exist
 *   - Returns 0 when there are no orphans
 */
class ClientGeneratorOrphanCleanupTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        // Load cleanup functions from the generator without triggering generation.
        // We use a __FILE__-relative path because ROOT_PATH is set by src/bootstrap.php
        // (loaded via composer autoload) to a vendor-install guess that is wrong when
        // running from the framework's own checkout — using ROOT_PATH here would point
        // to the wrong file.
        if (!defined('GENERATE_CLIENT_TESTING')) {
            define('GENERATE_CLIENT_TESTING', true);
        }
        // Suppress argv processing inside the generator (it reads $_SERVER['argv'])
        $prevArgv = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = [__FILE__];
        $generatorFile = realpath(__DIR__ . '/../../cli/generate-client.php');
        if ($generatorFile === false || !file_exists($generatorFile)) {
            $this->fail("Generator file not found at expected path relative to test: cli/generate-client.php");
        }
        require_once $generatorFile;
        $_SERVER['argv'] = $prevArgv;

        // Create a temporary directory for each test
        $this->tmpDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp dir after each test
        if (is_dir($this->tmpDir)) {
            $this->rmdirRecursive($this->tmpDir);
        }
    }

    // =========================================================================
    // removeOrphanNestedPackages — core behaviour
    // =========================================================================

    public function test_removes_nested_package_dirs_inside_flat_service_packages(): void
    {
        // Flat service package: client/admin/ (valid — has package.json)
        $this->mkdir('admin');
        $this->writeJson('admin/package.json', ['name' => 'test-api-admin-client']);
        $this->write('admin/package-lock.json', '{}');
        $this->mkdir('admin/src');

        // Orphan nested packages inside client/admin/
        $this->mkdir('admin/finance/src');
        $this->writeJson('admin/finance/package.json', ['name' => 'test-api-finance-client']);
        $this->mkdir('admin/portal/src');
        $this->writeJson('admin/portal/package.json', ['name' => 'test-api-portal-client']);

        $removed = removeOrphanNestedPackages($this->tmpDir);

        $this->assertSame(2, $removed);
        $this->assertDirectoryDoesNotExist($this->tmpDir . '/admin/finance');
        $this->assertDirectoryDoesNotExist($this->tmpDir . '/admin/portal');
    }

    public function test_does_not_remove_flat_service_packages_themselves(): void
    {
        // Two flat service packages at depth 1 — must NOT be removed
        $this->mkdir('admin');
        $this->writeJson('admin/package.json', ['name' => 'test-api-admin-client']);
        $this->mkdir('portal');
        $this->writeJson('portal/package.json', ['name' => 'test-api-portal-client']);

        $removed = removeOrphanNestedPackages($this->tmpDir);

        $this->assertSame(0, $removed);
        $this->assertDirectoryExists($this->tmpDir . '/admin');
        $this->assertDirectoryExists($this->tmpDir . '/portal');
    }

    public function test_does_not_remove_src_dist_node_modules_streaming_subdirs(): void
    {
        $this->mkdir('portal');
        $this->writeJson('portal/package.json', ['name' => 'test-api-portal-client']);
        // Legitimate subdirs — must NOT be removed
        $this->mkdir('portal/src');
        $this->write('portal/src/client.ts', '// generated');
        $this->mkdir('portal/dist');
        $this->write('portal/dist/index.js', '// built');
        $this->mkdir('portal/node_modules');
        $this->mkdir('portal/streaming');

        $removed = removeOrphanNestedPackages($this->tmpDir);

        $this->assertSame(0, $removed);
        $this->assertDirectoryExists($this->tmpDir . '/portal/src');
        $this->assertDirectoryExists($this->tmpDir . '/portal/dist');
        $this->assertDirectoryExists($this->tmpDir . '/portal/node_modules');
        $this->assertDirectoryExists($this->tmpDir . '/portal/streaming');
    }

    public function test_does_not_remove_nested_dirs_without_package_json(): void
    {
        $this->mkdir('admin');
        $this->writeJson('admin/package.json', ['name' => 'test-api-admin-client']);
        // A nested dir that has no package.json — NOT an orphan package, leave it alone
        $this->mkdir('admin/custom-helper');
        $this->write('admin/custom-helper/helper.ts', '// custom helper');

        $removed = removeOrphanNestedPackages($this->tmpDir);

        $this->assertSame(0, $removed);
        $this->assertDirectoryExists($this->tmpDir . '/admin/custom-helper');
    }

    public function test_skips_depth1_dirs_without_package_json(): void
    {
        // A directory at depth 1 that is NOT a service package (no package.json)
        // — the cleanup must skip it entirely (don't descend into arbitrary dirs)
        $this->mkdir('not-a-service');
        $this->write('not-a-service/readme.txt', 'not a package');
        $this->mkdir('not-a-service/orphan-lookalike');
        $this->write('not-a-service/orphan-lookalike/package.json', '{}');

        $removed = removeOrphanNestedPackages($this->tmpDir);

        $this->assertSame(0, $removed);
        $this->assertDirectoryExists($this->tmpDir . '/not-a-service/orphan-lookalike');
    }

    public function test_handles_multiple_flat_services_each_with_multiple_orphans(): void
    {
        // Simulate btechrecruiter pattern: 8 services × 7 other services = 56 orphans (subset)
        $services = ['admin', 'ats', 'finance', 'payroll'];
        foreach ($services as $svc) {
            $this->mkdir($svc);
            $this->writeJson("$svc/package.json", ['name' => "test-api-{$svc}-client"]);
            $this->write("$svc/package-lock.json", '{}');
            $this->mkdir("$svc/src");

            // Plant orphans inside each service dir
            foreach ($services as $other) {
                $this->mkdir("$svc/$other/src");
                $this->writeJson("$svc/$other/package.json", ['name' => "test-api-{$other}-client"]);
            }
        }

        $removed = removeOrphanNestedPackages($this->tmpDir);

        // 4 services × 4 orphans each = 16 removed
        $this->assertSame(16, $removed);

        // All flat service packages still exist
        foreach ($services as $svc) {
            $this->assertDirectoryExists($this->tmpDir . "/$svc");
            $this->assertFileExists($this->tmpDir . "/$svc/package.json");
            $this->assertDirectoryExists($this->tmpDir . "/$svc/src");

            // All orphans under this service are gone
            foreach ($services as $other) {
                $this->assertDirectoryDoesNotExist($this->tmpDir . "/$svc/$other");
            }
        }
    }

    public function test_returns_zero_when_output_dir_does_not_exist(): void
    {
        $removed = removeOrphanNestedPackages($this->tmpDir . '/does-not-exist');
        $this->assertSame(0, $removed);
    }

    public function test_returns_zero_when_no_orphans_present(): void
    {
        $this->mkdir('admin');
        $this->writeJson('admin/package.json', ['name' => 'test-api-admin-client']);
        $this->mkdir('admin/src');
        $this->write('admin/src/client.ts', '// clean');

        $removed = removeOrphanNestedPackages($this->tmpDir);
        $this->assertSame(0, $removed);
    }

    public function test_removes_orphan_even_when_it_contains_nested_files(): void
    {
        $this->mkdir('portal');
        $this->writeJson('portal/package.json', ['name' => 'test-api-portal-client']);

        // Orphan with multiple nested files (real-world structure)
        $this->mkdir('portal/admin/src');
        $this->writeJson('portal/admin/package.json', ['name' => 'test-api-admin-client']);
        $this->write('portal/admin/tsconfig.json', '{}');
        $this->write('portal/admin/src/client.ts', '// generated');
        $this->write('portal/admin/src/types.ts', '// generated');
        $this->write('portal/admin/src/http.ts', '// generated');

        $removed = removeOrphanNestedPackages($this->tmpDir);

        $this->assertSame(1, $removed);
        $this->assertDirectoryDoesNotExist($this->tmpDir . '/portal/admin');
        $this->assertDirectoryExists($this->tmpDir . '/portal');
    }

    // =========================================================================
    // removeDirectoryRecursive
    // =========================================================================

    public function test_removeDirectoryRecursive_deletes_dir_and_all_contents(): void
    {
        $dir = $this->tmpDir . '/to-delete';
        mkdir($dir . '/sub/deep', 0755, true);
        file_put_contents($dir . '/file.txt', 'x');
        file_put_contents($dir . '/sub/another.txt', 'y');

        removeDirectoryRecursive($dir);

        $this->assertDirectoryDoesNotExist($dir);
    }

    public function test_removeDirectoryRecursive_is_silent_when_dir_does_not_exist(): void
    {
        // Must not throw or produce output
        removeDirectoryRecursive($this->tmpDir . '/ghost');
        $this->assertTrue(true); // reached here = no exception
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function mkdir(string $relPath): void
    {
        $path = $this->tmpDir . '/' . ltrim($relPath, '/');
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    private function write(string $relPath, string $contents): void
    {
        file_put_contents($this->tmpDir . '/' . ltrim($relPath, '/'), $contents);
    }

    /** @param array<string,mixed> $data */
    private function writeJson(string $relPath, array $data): void
    {
        $this->write($relPath, json_encode($data, JSON_PRETTY_PRINT) . "\n");
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }
}
