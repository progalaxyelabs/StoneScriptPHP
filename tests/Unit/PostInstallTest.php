<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `stone post-install`: integrity check, allow-listed schema staging, pruning, idempotence.
 * Builds a throwaway project tree; never touches the real repo (except reading it for the manifest-freshness test).
 */
final class PostInstallTest extends TestCase
{
    private string $root;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/cli/helpers/post-install.php';
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssp-postinstall-' . bin2hex(random_bytes(6));
        $fw = $this->root . '/vendor/progalaxyelabs/stonescriptphp';
        $this->put("$fw/src/a.php", '<?php // a');
        $this->put("$fw/cli/b.php", '<?php // b');
        $this->put("$fw/stone", '#!/usr/bin/env php');
        $this->put("$fw/composer.json", '{"version":"14.4.0"}');
        $this->put("$fw/src/Subscriptions/Schema/tables/subscriptions.pgsql", '-- subs');
        $this->put("$fw/framework-manifest.txt", ssp_format_manifest('14.4.0', ssp_build_framework_manifest($fw)));
        $this->installed(['progalaxyelabs/stonescriptphp' => '14.4.0']);
    }

    protected function tearDown(): void
    {
        ssp_rrmdir($this->root);
    }

    private function put(string $path, string $body): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $body);
    }

    /** @param array<string,string> $versions name => version; extra keys per package via $extra */
    private function installed(array $versions, array $extra = []): void
    {
        $pk = [];
        foreach ($versions as $n => $v) {
            $pk[] = array_merge(['name' => $n, 'version' => $v, 'install-path' => '../' . $n], $extra[$n] ?? []);
        }
        $this->put($this->root . '/vendor/composer/installed.json', json_encode(['packages' => $pk, 'dev-package-names' => []]));
    }

    private function config(array $c): void
    {
        $this->put($this->root . '/stone-packages.json', json_encode($c));
    }

    private function module(string $name, string $layout): void
    {
        $base = $this->root . '/vendor/progalaxyelabs/' . $name;
        if ($layout === 'new' || $layout === 'both') {
            $this->put("$base/postgresql/main/tables/Core/t_$name.pgsql", "-- new $name");
            $this->put("$base/postgresql/main/functions/Core/f_$name.pgsql", "-- fn $name");
        }
        if ($layout === 'old' || $layout === 'both') {
            $this->put("$base/src/Feat/Schema/tables/old_$name.pgsql", "-- old $name");
        }
        $this->put("$base/src/x.php", '<?php');
    }

    // ---- (a) integrity -----------------------------------------------------

    public function test_intact_framework_passes(): void
    {
        $this->assertSame([], ssp_verify_framework_integrity($this->root));
    }

    public function test_modified_framework_file_is_refused(): void
    {
        file_put_contents($this->root . '/vendor/progalaxyelabs/stonescriptphp/src/a.php', '<?php // evil');
        $r = ssp_run_post_install($this->root);
        $this->assertSame(1, $r['exit']);
        $this->assertStringContainsString('modified: src/a.php', implode("\n", $r['lines']));
    }

    public function test_deleted_framework_file_is_refused(): void
    {
        unlink($this->root . '/vendor/progalaxyelabs/stonescriptphp/cli/b.php');
        $this->assertContains('missing: cli/b.php', ssp_verify_framework_integrity($this->root));
    }

    public function test_refusal_stages_nothing_and_leaves_the_project_alone(): void
    {
        unlink($this->root . '/vendor/progalaxyelabs/stonescriptphp/cli/b.php');
        $this->put($this->root . '/src/postgresql/vendor/postgresql/tables/keep.pgsql', 'x');
        ssp_run_post_install($this->root);
        $this->assertFileExists($this->root . '/src/postgresql/vendor/postgresql/tables/keep.pgsql');
        $this->assertFileDoesNotExist($this->root . '/stone');
    }

    public function test_missing_manifest_is_refused(): void
    {
        unlink($this->root . '/vendor/progalaxyelabs/stonescriptphp/framework-manifest.txt');
        $this->assertSame(1, ssp_run_post_install($this->root)['exit']);
    }

    public function test_version_mismatch_with_installed_json_is_refused(): void
    {
        $this->installed(['progalaxyelabs/stonescriptphp' => '14.3.0']);
        $this->assertStringContainsString('installed.json says framework 14.3.0', implode("\n", ssp_verify_framework_integrity($this->root)));
    }

    public function test_shipped_manifest_matches_the_repo_tree(): void
    {
        $repo = dirname(__DIR__, 2);
        $manifest = ssp_parse_manifest((string) file_get_contents($repo . '/framework-manifest.txt'));
        $composer = json_decode((string) file_get_contents($repo . '/composer.json'), true);
        $this->assertSame($composer['version'], $manifest['version'], 'run php scripts/build-framework-manifest.php');
        $this->assertSame(ssp_build_framework_manifest($repo), $manifest['files'], 'framework-manifest.txt is stale — run php scripts/build-framework-manifest.php');
    }

    // ---- (b) stone refresh -------------------------------------------------

    public function test_stone_is_refreshed_and_idempotent(): void
    {
        $r = ssp_run_post_install($this->root);
        $this->assertSame(0, $r['exit']);
        $this->assertSame('#!/usr/bin/env php', file_get_contents($this->root . '/stone'));
        $this->assertTrue(is_executable($this->root . '/stone'));
        $this->assertStringNotContainsString('Updated stone', implode("\n", ssp_run_post_install($this->root)['lines']), 'second run changes nothing');
    }

    // ---- (c) allow-listed staging ------------------------------------------

    public function test_framework_schema_is_always_staged(): void
    {
        ssp_run_post_install($this->root);
        $this->assertFileExists($this->root . '/src/postgresql/vendor/postgresql/tables/subscriptions.pgsql');
    }

    public function test_unlisted_package_with_schema_is_not_staged_and_is_noticed(): void
    {
        $this->module('stonescriptphp-evil', 'new');
        $this->installed(['progalaxyelabs/stonescriptphp' => '14.4.0', 'progalaxyelabs/stonescriptphp-evil' => '1.0.0']);
        $r = ssp_run_post_install($this->root);
        $this->assertFileDoesNotExist($this->root . '/src/postgresql/vendor/postgresql/tables/t_stonescriptphp-evil.pgsql');
        $this->assertStringContainsString('stonescriptphp-evil ships schema but is NOT on the schema allow-list', implode("\n", $r['lines']));
    }

    public function test_listed_package_is_staged_from_the_new_layout(): void
    {
        $this->module('stonescriptphp-pay', 'new');
        $this->installed(['progalaxyelabs/stonescriptphp' => '14.4.0', 'progalaxyelabs/stonescriptphp-pay' => '1.2.3']);
        $this->config(['schema_allowlist' => ['progalaxyelabs/stonescriptphp-pay' => '^1.2']]);
        ssp_run_post_install($this->root);
        $this->assertFileExists($this->root . '/src/postgresql/vendor/postgresql/tables/t_stonescriptphp-pay.pgsql');
        $this->assertFileExists($this->root . '/src/postgresql/vendor/postgresql/functions/f_stonescriptphp-pay.pgsql');
    }

    public function test_listed_package_outside_its_version_constraint_is_not_staged(): void
    {
        $this->module('stonescriptphp-pay', 'new');
        $this->installed(['progalaxyelabs/stonescriptphp' => '14.4.0', 'progalaxyelabs/stonescriptphp-pay' => '2.0.0']);
        $this->config(['schema_allowlist' => ['progalaxyelabs/stonescriptphp-pay' => '^1.2']]);
        $r = ssp_run_post_install($this->root);
        $this->assertFileDoesNotExist($this->root . '/src/postgresql/vendor/postgresql/tables/t_stonescriptphp-pay.pgsql');
        $this->assertStringContainsString("does not satisfy its schema allow-list constraint '^1.2'", implode("\n", $r['lines']));
    }

    public function test_old_schema_layout_is_a_warned_fallback(): void
    {
        $this->module('stonescriptphp-old', 'old');
        $this->installed(['progalaxyelabs/stonescriptphp' => '14.4.0', 'progalaxyelabs/stonescriptphp-old' => '1.0.0']);
        $this->config(['schema_allowlist' => ['progalaxyelabs/stonescriptphp-old' => '*']]);
        $r = ssp_run_post_install($this->root);
        $this->assertFileExists($this->root . '/src/postgresql/vendor/postgresql/tables/old_stonescriptphp-old.pgsql');
        $this->assertStringContainsString('deprecated layout', implode("\n", $r['lines']));
    }

    public function test_both_layouts_new_wins_and_old_is_ignored_with_a_warning(): void
    {
        $this->module('stonescriptphp-both', 'both');
        $this->installed(['progalaxyelabs/stonescriptphp' => '14.4.0', 'progalaxyelabs/stonescriptphp-both' => '1.0.0']);
        $this->config(['schema_allowlist' => ['progalaxyelabs/stonescriptphp-both' => '*']]);
        $r = ssp_run_post_install($this->root);
        $this->assertFileExists($this->root . '/src/postgresql/vendor/postgresql/tables/t_stonescriptphp-both.pgsql');
        $this->assertFileDoesNotExist($this->root . '/src/postgresql/vendor/postgresql/tables/old_stonescriptphp-both.pgsql');
        $this->assertStringContainsString('old Schema/ tree is IGNORED', implode("\n", $r['lines']));
    }

    public function test_new_layout_migrations_bucket_is_slug_namespaced(): void
    {
        $this->module('stonescriptphp-pay', 'new');
        $this->put($this->root . '/vendor/progalaxyelabs/stonescriptphp-pay/postgresql/main/migrations/Core/001_init.pgsql', '-- m');
        $this->installed(['progalaxyelabs/stonescriptphp' => '14.4.0', 'progalaxyelabs/stonescriptphp-pay' => '1.0.0']);
        $this->config(['schema_allowlist' => ['progalaxyelabs/stonescriptphp-pay' => '*']]);
        ssp_run_post_install($this->root);
        $this->assertFileExists($this->root . '/src/postgresql/vendor/postgresql/migrations/progalaxyelabs_stonescriptphp_pay__001_init.pgsql');
    }

    public function test_tenant_scope_is_not_staged(): void
    {
        $this->module('stonescriptphp-pay', 'new');
        $this->put($this->root . '/vendor/progalaxyelabs/stonescriptphp-pay/postgresql/tenant/tables/tenant_only.pgsql', '-- t');
        $this->installed(['progalaxyelabs/stonescriptphp' => '14.4.0', 'progalaxyelabs/stonescriptphp-pay' => '1.0.0']);
        $this->config(['schema_allowlist' => ['progalaxyelabs/stonescriptphp-pay' => '*']]);
        ssp_run_post_install($this->root);
        $this->assertFileDoesNotExist($this->root . '/src/postgresql/vendor/postgresql/tables/tenant_only.pgsql');
    }

    // ---- (d) prune ---------------------------------------------------------

    public function test_stale_staged_files_are_pruned(): void
    {
        $this->put($this->root . '/src/postgresql/vendor/postgresql/tables/stale.pgsql', 'x');
        ssp_run_post_install($this->root);
        $this->assertFileDoesNotExist($this->root . '/src/postgresql/vendor/postgresql/tables/stale.pgsql');
    }

    public function test_prune_never_deletes_outside_the_staging_dir(): void
    {
        $this->put($this->root . '/src/postgresql/tables/own.pgsql', 'mine');
        $this->put($this->root . '/src/postgresql/vendor/postgresql/tables/stale.pgsql', 'x');
        ssp_run_post_install($this->root);
        $this->assertFileExists($this->root . '/src/postgresql/tables/own.pgsql');
    }

    public function test_prune_refuses_a_symlinked_staging_dir_and_does_not_follow_it(): void
    {
        $outside = $this->root . '/precious';
        $this->put("$outside/keep.txt", 'keep');
        mkdir($this->root . '/src/postgresql', 0755, true);
        symlink($outside, $this->root . '/src/postgresql/vendor');
        $r = ssp_run_post_install($this->root);
        $this->assertSame(1, $r['exit']);
        $this->assertFileExists("$outside/keep.txt");
    }

    public function test_idempotent_second_run_yields_identical_staging(): void
    {
        $this->module('stonescriptphp-pay', 'new');
        $this->installed(['progalaxyelabs/stonescriptphp' => '14.4.0', 'progalaxyelabs/stonescriptphp-pay' => '1.0.0']);
        $this->config(['schema_allowlist' => ['progalaxyelabs/stonescriptphp-pay' => '*']]);
        ssp_run_post_install($this->root);
        $a = ssp_build_tree_hash($this->root . '/src/postgresql/vendor');
        ssp_run_post_install($this->root);
        $this->assertSame($a, ssp_build_tree_hash($this->root . '/src/postgresql/vendor'));
    }

    // ---- constraint matcher ------------------------------------------------

    /** @dataProvider constraints */
    public function test_version_constraints(string $v, string $c, bool $expected): void
    {
        $this->assertSame($expected, ssp_version_satisfies($v, $c), "$v vs $c");
    }

    public static function constraints(): array
    {
        return [
            ['1.2.3', '*', true], ['1.2.3', '^1.2', true], ['2.0.0', '^1.2', false], ['1.1.9', '^1.2', false],
            ['0.3.5', '^0.3', true], ['0.4.0', '^0.3', false], ['1.2.9', '~1.2.3', true], ['1.3.0', '~1.2.3', false],
            ['1.9.0', '~1.2', true], ['2.0.0', '~1.2', false], ['1.2.3', '1.2.3', true], ['1.2.4', '1.2.3', false],
            ['1.5.0', '>=1.0,<2.0', true], ['2.0.0', '>=1.0 <2.0', false], ['2.5.0', '^1.0 || ^2.0', true],
            ['3.0.0', '^1.0 || ^2.0', false], ['1.4.0', '1.4.*', true], ['1.5.0', '1.4.*', false],
            ['dev-main', '^1.0', false], ['dev-main', '*', true], ['dev-main', 'dev-main', true], ['1.0.0', 'garbage', false], ['1.0.0', '', false],
        ];
    }
}

function ssp_build_tree_hash(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
        $out[substr($f->getPathname(), strlen($dir))] = hash_file('sha256', $f->getPathname());
    }
    ksort($out);
    return $out;
}
