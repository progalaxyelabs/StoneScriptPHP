<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * gateway:migrate-vendor-main --only=<Feature>: apply one staged opt-in feature without the others
 * (enabling the refresh-token store must not also create the Subscriptions tables, which flip lapsed
 * tenants to read-only).
 */
final class VendorSchemaOnlyTest extends TestCase
{
    private string $root;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/cli/helpers/vendor-schema-sync.php';
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssp-vendor-only-' . bin2hex(random_bytes(6));
        $src = $this->root . '/vendor/progalaxyelabs/stonescriptphp/src';
        foreach ([
            'Subscriptions/Schema/tables/subscriptions.pgsql',
            'Subscriptions/Schema/functions/sub_activate.pgsql',
            'Auth/RefreshTokens/Schema/tables/refresh_tokens.pgsql',
            'Auth/RefreshTokens/Schema/functions/refresh_token_issue.pgsql',
            'Analytics/Schema/tables/events.pgsql',
        ] as $rel) {
            mkdir(dirname("$src/$rel"), 0755, true);
            file_put_contents("$src/$rel", '-- ' . basename($rel));
        }
        syncAllVendorSchema($this->root, $this->root . '/src/postgresql/vendor/postgresql');
    }

    protected function tearDown(): void
    {
        ssp_rrmdir($this->root);
    }

    private function names(string $dir): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $out[] = $f->getFilename();
        }
        sort($out);
        return $out;
    }

    public function test_default_staging_is_unchanged_and_a_manifest_lists_each_feature(): void
    {
        $staged = $this->names($this->root . '/src/postgresql/vendor/postgresql');
        $this->assertSame(
            ['events.pgsql', 'refresh_token_issue.pgsql', 'refresh_tokens.pgsql', 'sub_activate.pgsql', 'subscriptions.pgsql'],
            $staged
        );
        $manifest = ssp_read_vendor_manifest($this->root . '/src/postgresql/vendor');
        $keys = array_map('basename', array_keys($manifest));
        sort($keys);
        $this->assertSame(['Analytics', 'RefreshTokens', 'Subscriptions'], $keys);
        // the manifest must not sit inside the uploaded postgresql/ tree
        $this->assertFileDoesNotExist($this->root . '/src/postgresql/vendor/postgresql/manifest.json');
    }

    public function test_only_selects_exactly_the_named_feature(): void
    {
        $r = ssp_materialize_selected_vendor_schema($this->root . '/src/postgresql', 'vendor-selected', ['refreshtokens']);

        $this->assertSame(2, $r['files']);
        $this->assertSame(
            ['refresh_token_issue.pgsql', 'refresh_tokens.pgsql'],
            $this->names($this->root . '/src/postgresql/vendor-selected/postgresql')
        );
    }

    public function test_only_accepts_several_and_the_package_qualified_name(): void
    {
        $r = ssp_materialize_selected_vendor_schema(
            $this->root . '/src/postgresql',
            'vendor-selected',
            ['RefreshTokens', 'progalaxyelabs/stonescriptphp/Analytics']
        );
        $this->assertSame(3, $r['files']);
    }

    public function test_unknown_feature_fails_listing_what_is_available_and_applies_nothing(): void
    {
        try {
            ssp_materialize_selected_vendor_schema($this->root . '/src/postgresql', 'vendor-selected', ['Nope']);
            $this->fail('expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Unknown vendor schema 'Nope'", $e->getMessage());
            $this->assertStringContainsString('RefreshTokens', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->root . '/src/postgresql/vendor-selected');
    }

    public function test_restaging_removes_stale_manifest_when_nothing_is_staged(): void
    {
        ssp_rrmdir($this->root . '/vendor');
        mkdir($this->root . '/vendor', 0755, true);
        syncAllVendorSchema($this->root, $this->root . '/src/postgresql/vendor/postgresql');
        $this->assertSame([], ssp_read_vendor_manifest($this->root . '/src/postgresql/vendor'));
    }
}
