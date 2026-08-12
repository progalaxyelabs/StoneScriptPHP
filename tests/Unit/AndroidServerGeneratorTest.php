<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * cli/generate-android-server.php + the `stone generate android-server`
 * entry point (2026-08-01 android-server track).
 *
 * Two things are tested here, deliberately at different layers:
 *
 * 1. test_stone_cli_actually_invokes_the_generator() drives the REAL `stone`
 *    binary as a subprocess against a scratch fixture project — exactly how
 *    a platform developer runs it. This is the regression test for a real,
 *    confirmed bug (fixed 2026-08-12): `stone`'s special-case subcommand
 *    table (used to redirect e.g. 'route'/'client'/'jwt' to their specific
 *    generator files) unconditionally stripped the subcommand token before
 *    invoking cli/generate-dispatcher.php's OWN internal $generators lookup
 *    — which also reads that same token. 'android-server' was the first
 *    subcommand ever added to generate-dispatcher.php's map without a
 *    matching stone-level special case, so the token vanished before
 *    reaching it: `php stone generate android-server` silently printed
 *    generic dispatcher help and produced NO android-server/ output,
 *    despite exiting 0. This test would have failed before that fix (no
 *    android-server/ directory created) and passes after it.
 *
 * 2. test_generator_output_* directly requires the generator file via a
 *    wrapper (same pattern as ClientGeneratorV4Test::runGenerator()) to pin
 *    the generator's own route-exclusion-policy and schema-manifest logic,
 *    independent of the CLI-wiring layer.
 */
final class AndroidServerGeneratorTest extends TestCase
{
    private string $frameworkRoot;
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->frameworkRoot = realpath(__DIR__ . '/../..');
        $this->fixtureRoot   = sys_get_temp_dir() . '/ssp-android-server-fixture-' . uniqid();
        $this->buildFixtureProject($this->fixtureRoot);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->fixtureRoot);
        parent::tearDown();
    }

    // =========================================================================
    // 1. Real `stone` binary, real subprocess — the actual reported bug.
    // =========================================================================

    public function test_stone_cli_actually_invokes_the_generator(): void
    {
        // Reproduce exactly how a platform's docker/api/ directory looks:
        // stone copied to the project root (stone's own "copied by composer
        // post-install" branch), vendor/progalaxyelabs/stonescriptphp
        // resolving to a full framework checkout (real composer installs
        // copy the whole package there — symlinking the framework repo root
        // itself is the accurate equivalent, not just its vendor/ dir).
        // vendor/autoload.php is symlinked from the framework's own working
        // autoloader — this generator needs no App\-namespaced classes from
        // the fixture, only a working `require_once vendor/autoload.php`.
        copy($this->frameworkRoot . '/stone', $this->fixtureRoot . '/stone');
        mkdir($this->fixtureRoot . '/vendor/progalaxyelabs', 0755, true);
        symlink($this->frameworkRoot, $this->fixtureRoot . '/vendor/progalaxyelabs/stonescriptphp');
        symlink($this->frameworkRoot . '/vendor/autoload.php', $this->fixtureRoot . '/vendor/autoload.php');

        $cmd = 'cd ' . escapeshellarg($this->fixtureRoot)
             . ' && ' . escapeshellarg(PHP_BINARY) . ' stone generate android-server 2>&1';
        exec($cmd, $output, $exitCode);
        $outputText = implode("\n", $output);

        $this->assertSame(0, $exitCode, "stone generate android-server must exit 0.\nOutput:\n$outputText");

        // The decisive assertion: pre-fix, this exact command exited 0 AND
        // printed the generic "Generate Command / Usage:" help text WITHOUT
        // creating android-server/ at all — exit code alone does not catch
        // the bug, the missing output directory does.
        $this->assertDirectoryExists(
            $this->fixtureRoot . '/android-server',
            "android-server/ was not created — got this output instead:\n$outputText"
        );
        $this->assertStringNotContainsString(
            'Usage: php stone generate <subcommand>',
            $outputText,
            'stone must not fall back to printing generic dispatcher help for android-server'
        );
        $this->assertFileExists($this->fixtureRoot . '/android-server/.env');
        $this->assertFileExists($this->fixtureRoot . '/android-server/schema-manifest.json');
        $this->assertFileExists($this->fixtureRoot . '/android-server/src/config/routes.php');
    }

    // =========================================================================
    // 2. Generator internals — direct require via wrapper, no `stone` involved.
    // =========================================================================

    public function test_generator_excludes_admin_and_provision_tenant_routes(): void
    {
        $this->runGeneratorDirectly();

        $manifestJson = file_get_contents($this->fixtureRoot . '/android-server/GENERATED-README.md');
        $this->assertStringContainsString('POST /api/auth/provision-tenant', $manifestJson);
        $this->assertStringContainsString('GET /admin/dashboard', $manifestJson);

        // The included route must still be present in the generated wrapper's
        // underlying original routes file (audit trail — byte-identical copy).
        $original = file_get_contents($this->fixtureRoot . '/android-server/src/config/routes.original.php');
        $this->assertStringContainsString('/portal/tenant/{tenantId}/items', $original);
    }

    public function test_generator_writes_pgandroid_db_mode_in_env(): void
    {
        $this->runGeneratorDirectly();

        $env = file_get_contents($this->fixtureRoot . '/android-server/.env');
        $this->assertStringContainsString('DB_MODE=pgandroid', $env);
    }

    public function test_generator_schema_manifest_reflects_split_layout(): void
    {
        $this->runGeneratorDirectly();

        $manifest = json_decode(
            file_get_contents($this->fixtureRoot . '/android-server/schema-manifest.json'),
            true
        );

        $this->assertSame('split (main/ + tenant/)', $manifest['layout']);
        $this->assertContains('users', $manifest['main']['tables']);
        $this->assertContains('items', $manifest['tenant']['tables']);
    }

    // =========================================================================
    // Fixture + helpers
    // =========================================================================

    private function buildFixtureProject(string $root): void
    {
        mkdir($root . '/src/config', 0755, true);
        mkdir($root . '/src/postgresql/main/postgresql/tables', 0755, true);
        mkdir($root . '/src/postgresql/main/postgresql/functions', 0755, true);
        mkdir($root . '/src/postgresql/tenant/postgresql/tables', 0755, true);
        mkdir($root . '/src/postgresql/tenant/postgresql/functions', 0755, true);
        mkdir($root . '/public', 0755, true);

        file_put_contents($root . '/src/config/routes.php', <<<'PHP'
<?php
return [
    'GET' => [
        '/portal/tenant/{tenantId}/items' => ['handler' => 'ListItemsRoute', 'service' => 'portal', 'group' => 'items', 'access' => 'authorization'],
        '/admin/dashboard'                => ['handler' => 'AdminDashboardRoute', 'service' => 'admin', 'group' => 'admin', 'access' => 'authorization'],
        '/health'                         => ['handler' => 'HealthRoute', 'service' => 'infra', 'access' => 'public'],
    ],
    'POST' => [
        '/api/auth/provision-tenant' => ['handler' => 'ProvisionTenantRoute', 'service' => 'infra', 'group' => 'auth', 'access' => 'authorization'],
    ],
];
PHP
        );

        file_put_contents($root . '/src/postgresql/main/postgresql/tables/users.pgsql', "CREATE TABLE users (id uuid PRIMARY KEY);\n");
        file_put_contents($root . '/src/postgresql/main/postgresql/functions/get_user.pgsql', "CREATE FUNCTION get_user() RETURNS void AS \$\$ BEGIN END; \$\$ LANGUAGE plpgsql;\n");
        file_put_contents($root . '/src/postgresql/tenant/postgresql/tables/items.pgsql', "CREATE TABLE items (id uuid PRIMARY KEY);\n");
        file_put_contents($root . '/src/postgresql/tenant/postgresql/functions/get_item.pgsql', "CREATE FUNCTION get_item() RETURNS void AS \$\$ BEGIN END; \$\$ LANGUAGE plpgsql;\n");

        file_put_contents($root . '/composer.json', json_encode(['name' => 'fixture/android-server-test'], JSON_PRETTY_PRINT) . "\n");
        file_put_contents($root . '/public/index.php', "<?php // fixture\n");
    }

    /**
     * Requires cli/generate-android-server.php directly (no `stone` in the
     * loop) via a small constants-defining wrapper, same pattern
     * ClientGeneratorV4Test::runGenerator() already uses for
     * cli/generate-client.php. Isolates generator-internals tests from the
     * CLI-wiring layer covered by test_stone_cli_actually_invokes_the_generator().
     */
    private function runGeneratorDirectly(): void
    {
        $generatorPath = $this->frameworkRoot . '/cli/generate-android-server.php';
        $vendorAutoload = $this->frameworkRoot . '/vendor/autoload.php';
        $fixtureRoot = $this->fixtureRoot;

        $wrapperPath = sys_get_temp_dir() . '/ssp-android-gen-wrapper-' . uniqid() . '.php';
        $wrapperContent = <<<PHP
<?php
define('ROOT_PATH', '$fixtureRoot/');
require_once '$vendorAutoload';
require '$generatorPath';
PHP;
        file_put_contents($wrapperPath, $wrapperContent);

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($wrapperPath) . ' 2>&1';
        exec($cmd, $output, $exitCode);
        @unlink($wrapperPath);

        $this->assertSame(0, $exitCode, "Generator failed:\n" . implode("\n", $output));
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir) && !is_link($dir)) {
            return;
        }
        if (is_link($dir)) {
            unlink($dir);
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->rmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
