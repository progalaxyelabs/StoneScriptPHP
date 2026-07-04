<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Vendor schema sync tests (IMPROVEMENT-SUGGESTIONS-2026-07.md).
 *
 * Verifies syncVendorSchema() correctly stages opt-in framework schema
 * (e.g. RequestLogging's Schema/{tables,functions}/) from a vendor tree into
 * a platform's src/postgresql/vendor/postgresql/, merging across however many
 * feature modules contribute, and regenerating fresh (never accumulating
 * stale files) on every call.
 */
class VendorSchemaSyncTest extends TestCase
{
    private string $tmpRoot;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/cli/helpers/vendor-schema-sync.php';
    }

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/ssp-vendor-sync-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        ssp_rrmdir($this->tmpRoot);
    }

    private function makeFile(string $path, string $contents = '-- sql'): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $contents);
    }

    public function test_no_vendor_src_dir_returns_zero_and_touches_nothing(): void
    {
        $result = syncVendorSchema($this->tmpRoot . '/does-not-exist', $this->tmpRoot . '/target');
        $this->assertSame(0, $result['copied']);
        $this->assertSame([], $result['features']);
        $this->assertDirectoryDoesNotExist($this->tmpRoot . '/target');
    }

    public function test_vendor_dir_with_no_schema_folders_returns_zero(): void
    {
        $vendorSrc = $this->tmpRoot . '/vendor-src';
        mkdir($vendorSrc . '/SomeFeature', 0755, true); // no Schema/ subfolder
        $result = syncVendorSchema($vendorSrc, $this->tmpRoot . '/target');
        $this->assertSame(0, $result['copied']);
    }

    public function test_single_feature_schema_is_staged_by_subdir(): void
    {
        $vendorSrc = $this->tmpRoot . '/vendor-src';
        $this->makeFile($vendorSrc . '/RequestLogging/Schema/tables/req_001_request_logs.pgsql');
        $this->makeFile($vendorSrc . '/RequestLogging/Schema/functions/rl_insert_request_log.pgsql');

        $target = $this->tmpRoot . '/target';
        $result = syncVendorSchema($vendorSrc, $target);

        $this->assertSame(2, $result['copied']);
        $this->assertSame(['RequestLogging'], $result['features']);
        $this->assertFileExists($target . '/tables/req_001_request_logs.pgsql');
        $this->assertFileExists($target . '/functions/rl_insert_request_log.pgsql');
    }

    public function test_multiple_features_merge_into_the_same_subdir(): void
    {
        $vendorSrc = $this->tmpRoot . '/vendor-src';
        $this->makeFile($vendorSrc . '/RequestLogging/Schema/functions/rl_insert_request_log.pgsql');
        $this->makeFile($vendorSrc . '/SomeOtherFeature/Schema/functions/other_fn.pgsql');

        $target = $this->tmpRoot . '/target';
        $result = syncVendorSchema($vendorSrc, $target);

        $this->assertSame(2, $result['copied']);
        sort($result['features']);
        $this->assertSame(['RequestLogging', 'SomeOtherFeature'], $result['features']);
        $this->assertFileExists($target . '/functions/rl_insert_request_log.pgsql');
        $this->assertFileExists($target . '/functions/other_fn.pgsql');
    }

    public function test_regenerates_fresh_and_drops_files_from_a_removed_feature(): void
    {
        $vendorSrc = $this->tmpRoot . '/vendor-src';
        $target = $this->tmpRoot . '/target';

        // First sync: two features present.
        $this->makeFile($vendorSrc . '/RequestLogging/Schema/functions/rl_insert_request_log.pgsql');
        $this->makeFile($vendorSrc . '/OldFeature/Schema/functions/old_fn.pgsql');
        syncVendorSchema($vendorSrc, $target);
        $this->assertFileExists($target . '/functions/old_fn.pgsql');

        // Simulate a framework downgrade/removal of OldFeature, then re-sync.
        ssp_rrmdir($vendorSrc . '/OldFeature');
        $result = syncVendorSchema($vendorSrc, $target);

        $this->assertSame(1, $result['copied'], 'stale files from a no-longer-present feature must not survive a re-sync');
        $this->assertFileDoesNotExist($target . '/functions/old_fn.pgsql');
        $this->assertFileExists($target . '/functions/rl_insert_request_log.pgsql');
    }

    public function test_only_sql_pgsql_pssql_extensions_are_staged(): void
    {
        $vendorSrc = $this->tmpRoot . '/vendor-src';
        $this->makeFile($vendorSrc . '/Feature/Schema/tables/valid.pgsql');
        $this->makeFile($vendorSrc . '/Feature/Schema/tables/README.md');
        $this->makeFile($vendorSrc . '/Feature/Schema/tables/notes.txt');

        $target = $this->tmpRoot . '/target';
        $result = syncVendorSchema($vendorSrc, $target);

        $this->assertSame(1, $result['copied']);
        $this->assertFileExists($target . '/tables/valid.pgsql');
        $this->assertFileDoesNotExist($target . '/tables/README.md');
    }
}
