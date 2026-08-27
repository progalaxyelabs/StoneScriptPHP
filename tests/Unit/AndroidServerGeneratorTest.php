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
    // 3. Shipped-surface reduction (2026-08-27): trimmed route table +
    //    handler pruning. Uses a SEPARATE, richer fixture with a real
    //    App\Routes\* tree (generic names only — no platform-specific
    //    naming) so handler files can actually be counted and pruned.
    // =========================================================================

    public function test_full_route_table_prunes_unreferenced_and_excluded_handlers(): void
    {
        $root = sys_get_temp_dir() . '/ssp-android-server-surface-fixture-' . uniqid();
        $this->buildSurfaceReductionFixture($root);

        try {
            $this->runGeneratorDirectlyAgainst($root);

            // route_source must report the FULL routes.php path (no trimmed
            // file present in this variant of the fixture).
            $readme = file_get_contents($root . '/android-server/GENERATED-README.md');
            $this->assertStringContainsString('full (src/config/routes.php', $readme);

            $routesDir = $root . '/android-server/src/App/Routes';
            $kept = $this->listPhpFilesRelative($routesDir);
            sort($kept);

            // Admin + auth-provisioning handlers are excluded from the route
            // table by the existing policy, and — the actual fix under test
            // — their FILES must now also be gone, not just their route
            // entries. Dead code never referenced by any route entry at all
            // must be gone too. The kept, reachable portal/health handlers
            // must survive, and a class only reached via a `use` inside a
            // kept handler's own source must survive too (cross-reference
            // safety net), never orphaned as unreachable dead weight.
            $this->assertSame([
                'Infra/HealthRoute.php',
                'Portal/ListItemsRoute.php',
                'Portal/ListOrdersRoute.php',
                'Portal/SharedRouteTrait.php',
            ], $kept);

            $this->assertFileDoesNotExist($routesDir . '/Admin/AdminDashboardRoute.php');
            $this->assertFileDoesNotExist($routesDir . '/Auth/ProvisionTenantRoute.php');
            $this->assertFileDoesNotExist($routesDir . '/Portal/DeadCodeRoute.php');

            $this->assertStringContainsString('Handler files kept:   4', $readme);
            $this->assertStringContainsString('Handler files pruned: 3', $readme);
            $this->assertStringContainsString('SharedRouteTrait.php', $readme); // logged as kept-because-referenced

            $this->assertBootableUnderPsr4($root, [
                'App\\Routes\\Infra\\HealthRoute',
                'App\\Routes\\Portal\\ListItemsRoute',
                'App\\Routes\\Portal\\ListOrdersRoute',
            ]);
        } finally {
            $this->rmdir($root);
        }
    }

    public function test_trimmed_route_file_ships_fewer_routes_and_fewer_handlers_than_full(): void
    {
        $root = sys_get_temp_dir() . '/ssp-android-server-surface-fixture-' . uniqid();
        $this->buildSurfaceReductionFixture($root);
        // Opt-in trimmed table: only the two routes the offline app needs.
        // Generic placeholder names only — no platform-specific content.
        file_put_contents($root . '/src/config/routes-android-server.php', <<<'PHP'
<?php
return [
    'GET' => [
        '/portal/tenant/{tenantId}/items' => ['handler' => 'App\\Routes\\Portal\\ListItemsRoute', 'service' => 'portal', 'group' => 'items', 'access' => 'authorization'],
        '/health'                         => ['handler' => 'App\\Routes\\Infra\\HealthRoute', 'service' => 'infra', 'access' => 'public'],
    ],
];
PHP
        );

        try {
            $this->runGeneratorDirectlyAgainst($root);

            $readme = file_get_contents($root . '/android-server/GENERATED-README.md');
            $this->assertStringContainsString('trimmed (src/config/routes-android-server.php)', $readme);

            // Fewer ROUTES than the full-table run (2 vs 4).
            $this->assertStringContainsString('Included routes: 2', $readme);

            // The untrimmed routes.php's content must not ship at all —
            // the whole point of trimming is to stop leaking the full
            // route/handler surface, not just gate it at runtime.
            $this->assertFileDoesNotExist($root . '/android-server/src/config/routes.php.bak');
            $original = file_get_contents($root . '/android-server/src/config/routes.original.php');
            $this->assertStringNotContainsString('AdminDashboardRoute', $original);
            $this->assertStringNotContainsString('ProvisionTenantRoute', $original);
            $this->assertStringNotContainsString('ListOrdersRoute', $original);

            $routesDir = $root . '/android-server/src/App/Routes';
            $kept = $this->listPhpFilesRelative($routesDir);
            sort($kept);

            // Fewer HANDLER FILES than the full-table run (3 vs 4) — the
            // orders handler that only the full table used is gone too.
            $this->assertSame([
                'Infra/HealthRoute.php',
                'Portal/ListItemsRoute.php',
                'Portal/SharedRouteTrait.php',
            ], $kept);

            $this->assertFileDoesNotExist($routesDir . '/Portal/ListOrdersRoute.php');
            $this->assertFileDoesNotExist($routesDir . '/Admin/AdminDashboardRoute.php');
            $this->assertFileDoesNotExist($routesDir . '/Auth/ProvisionTenantRoute.php');
            $this->assertFileDoesNotExist($routesDir . '/Portal/DeadCodeRoute.php');

            $this->assertStringContainsString('Handler files kept:   3', $readme);
            $this->assertStringContainsString('Handler files pruned: 4', $readme);

            $this->assertBootableUnderPsr4($root, [
                'App\\Routes\\Infra\\HealthRoute',
                'App\\Routes\\Portal\\ListItemsRoute',
            ]);
        } finally {
            $this->rmdir($root);
        }
    }

    public function test_backward_compat_no_trimmed_file_or_app_routes_dir_matches_original_fixture(): void
    {
        // The ORIGINAL fixture (buildFixtureProject) has no src/App/Routes/
        // directory at all and bare (non-namespaced) handler strings —
        // pruning must be a complete, silent no-op here (no warning even),
        // exactly matching behavior before this capability existed. This is
        // the explicit backward-compat guarantee: a project with neither
        // routes-android-server.php nor an App\Routes\ tree generates
        // identically to the pre-2026-08-27 generator.
        $this->runGeneratorDirectly();

        $readme = file_get_contents($this->fixtureRoot . '/android-server/GENERATED-README.md');
        $this->assertStringContainsString('pruning SKIPPED', $readme);
        $this->assertStringContainsString('no src/App/Routes directory', $readme);
        $this->assertStringContainsString('full (src/config/routes.php', $readme);

        // Same route include/exclude behavior as before (test_generator_excludes_...).
        $this->assertStringContainsString('Included routes: 2', $readme);
        $this->assertStringContainsString('Excluded routes: 2', $readme);
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
        $this->runGeneratorDirectlyAgainst($this->fixtureRoot);
    }

    private function runGeneratorDirectlyAgainst(string $root): void
    {
        $generatorPath = $this->frameworkRoot . '/cli/generate-android-server.php';
        $vendorAutoload = $this->frameworkRoot . '/vendor/autoload.php';

        $wrapperPath = sys_get_temp_dir() . '/ssp-android-gen-wrapper-' . uniqid() . '.php';
        $wrapperContent = <<<PHP
<?php
define('ROOT_PATH', '$root/');
require_once '$vendorAutoload';
require '$generatorPath';
PHP;
        file_put_contents($wrapperPath, $wrapperContent);

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($wrapperPath) . ' 2>&1';
        exec($cmd, $output, $exitCode);
        @unlink($wrapperPath);

        $this->assertSame(0, $exitCode, "Generator failed:\n" . implode("\n", $output));
    }

    /**
     * Richer fixture for the shipped-surface-reduction tests (2026-08-27):
     * a real, generic (no platform-specific names) App\Routes\* tree with
     * five handler files spanning three concerns — reachable portal routes,
     * an admin route (excluded by the existing policy), an auth
     * provisioning route (also excluded), and one file NEVER referenced by
     * any route entry at all (simulated dead code) — plus one file that is
     * only reachable via a `use` statement inside a kept handler's own
     * source (exercises the cross-reference safety net, not a route
     * target). `composer.json` declares a `App\` => `src/App/` PSR-4
     * mapping so assertBootableUnderPsr4() can register a matching
     * autoloader against the GENERATED tree afterwards.
     */
    private function buildSurfaceReductionFixture(string $root): void
    {
        mkdir($root . '/src/config', 0755, true);
        mkdir($root . '/src/postgresql/main/postgresql/tables', 0755, true);
        mkdir($root . '/src/postgresql/tenant/postgresql/tables', 0755, true);
        mkdir($root . '/src/App/Routes/Portal', 0755, true);
        mkdir($root . '/src/App/Routes/Admin', 0755, true);
        mkdir($root . '/src/App/Routes/Auth', 0755, true);
        mkdir($root . '/src/App/Routes/Infra', 0755, true);
        mkdir($root . '/public', 0755, true);

        file_put_contents($root . '/src/config/routes.php', <<<'PHP'
<?php
return [
    'GET' => [
        '/portal/tenant/{tenantId}/items'  => ['handler' => 'App\\Routes\\Portal\\ListItemsRoute', 'service' => 'portal', 'group' => 'items', 'access' => 'authorization'],
        '/portal/tenant/{tenantId}/orders' => ['handler' => 'App\\Routes\\Portal\\ListOrdersRoute', 'service' => 'portal', 'group' => 'orders', 'access' => 'authorization'],
        '/admin/dashboard'                 => ['handler' => 'App\\Routes\\Admin\\AdminDashboardRoute', 'service' => 'admin', 'group' => 'admin', 'access' => 'authorization'],
        '/health'                          => ['handler' => 'App\\Routes\\Infra\\HealthRoute', 'service' => 'infra', 'access' => 'public'],
    ],
    'POST' => [
        '/api/auth/provision-tenant' => ['handler' => 'App\\Routes\\Auth\\ProvisionTenantRoute', 'service' => 'infra', 'group' => 'auth', 'access' => 'authorization'],
    ],
];
PHP
        );

        // A shared leaf helper referenced by ListItemsRoute via `use` — not
        // a route target itself, must survive pruning via the
        // cross-reference safety net.
        file_put_contents($root . '/src/App/Routes/Portal/SharedRouteTrait.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Routes\Portal;
trait SharedRouteTrait
{
    protected function normalizeTenantId(string $tenantId): string
    {
        return strtolower($tenantId);
    }
}
PHP
        );

        file_put_contents($root . '/src/App/Routes/Portal/ListItemsRoute.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Routes\Portal;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\IRouteHandler;
class ListItemsRoute implements IRouteHandler
{
    use SharedRouteTrait;
    public function validation_rules(): array { return []; }
    public function process(): ApiResponse { return res_ok(['items' => []]); }
}
PHP
        );

        file_put_contents($root . '/src/App/Routes/Portal/ListOrdersRoute.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Routes\Portal;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\IRouteHandler;
class ListOrdersRoute implements IRouteHandler
{
    public function validation_rules(): array { return []; }
    public function process(): ApiResponse { return res_ok(['orders' => []]); }
}
PHP
        );

        file_put_contents($root . '/src/App/Routes/Portal/DeadCodeRoute.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Routes\Portal;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\IRouteHandler;
// Never referenced by any routes.php entry — simulated dead code the
// pruner must remove regardless of route source.
class DeadCodeRoute implements IRouteHandler
{
    public function validation_rules(): array { return []; }
    public function process(): ApiResponse { return res_ok([]); }
}
PHP
        );

        file_put_contents($root . '/src/App/Routes/Admin/AdminDashboardRoute.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Routes\Admin;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\IRouteHandler;
class AdminDashboardRoute implements IRouteHandler
{
    public function validation_rules(): array { return []; }
    public function process(): ApiResponse { return res_ok(['dashboard' => []]); }
}
PHP
        );

        file_put_contents($root . '/src/App/Routes/Auth/ProvisionTenantRoute.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Routes\Auth;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\IRouteHandler;
class ProvisionTenantRoute implements IRouteHandler
{
    public function validation_rules(): array { return []; }
    public function process(): ApiResponse { return res_ok(['provisioned' => true]); }
}
PHP
        );

        file_put_contents($root . '/src/App/Routes/Infra/HealthRoute.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Routes\Infra;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\IRouteHandler;
class HealthRoute implements IRouteHandler
{
    public function validation_rules(): array { return []; }
    public function process(): ApiResponse { return res_ok(['status' => 'ok']); }
}
PHP
        );

        file_put_contents($root . '/src/postgresql/main/postgresql/tables/users.pgsql', "CREATE TABLE users (id uuid PRIMARY KEY);\n");
        file_put_contents($root . '/src/postgresql/tenant/postgresql/tables/items.pgsql', "CREATE TABLE items (id uuid PRIMARY KEY);\n");

        file_put_contents($root . '/composer.json', json_encode([
            'name' => 'fixture/android-server-surface-test',
            'autoload' => ['psr-4' => ['App\\' => 'src/App/']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($root . '/public/index.php', "<?php // fixture\n");
    }

    /** @return string[] paths of .php files under $dir, relative to $dir, forward-slash separated */
    private function listPhpFilesRelative(string $dir): array
    {
        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $rel = substr($file->getPathname(), strlen(rtrim($dir, '/')) + 1);
                $result[] = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
            }
        }
        return $result;
    }

    /**
     * Boot/autoload verification: registers a PSR-4 `App\` => `<generated
     * tree>/src/App/` autoloader (mirroring what the real project's
     * composer.json declares) directly against the GENERATED android-server
     * output — not the fixture source — and asserts every kept handler
     * class actually resolves and loads without a fatal error. This is the
     * "the generated app still boots" proof requested: if a kept handler's
     * file were pruned, or a kept handler still referenced a pruned
     * sibling class, class_exists() below would return false or a
     * fatal/undefined-class error would surface here first.
     *
     * Runs in a fresh subprocess since PHP autoloaders are process-global
     * and other tests in this same run register their own conflicting
     * fixture roots.
     *
     * @param string[] $expectedClasses
     */
    private function assertBootableUnderPsr4(string $root, array $expectedClasses): void
    {
        $vendorAutoload = $this->frameworkRoot . '/vendor/autoload.php';
        $appRoot = $root . '/android-server/src/App/';
        $classesPhp = var_export($expectedClasses, true);

        $wrapperPath = sys_get_temp_dir() . '/ssp-android-boot-wrapper-' . uniqid() . '.php';
        $wrapperContent = <<<PHP
<?php
require_once '$vendorAutoload';
spl_autoload_register(function (string \$class) {
    if (!str_starts_with(\$class, 'App\\\\')) {
        return;
    }
    \$relative = substr(\$class, strlen('App\\\\'));
    \$file = '$appRoot' . str_replace('\\\\', '/', \$relative) . '.php';
    if (is_file(\$file)) {
        require \$file;
    }
});
\$expected = $classesPhp;
foreach (\$expected as \$class) {
    if (!class_exists(\$class)) {
        fwrite(STDERR, "MISSING:\$class\\n");
        exit(1);
    }
    \$instance = new \$class();
    \$response = \$instance->process();
    if (!(\$response instanceof \\StoneScriptPHP\\ApiResponse)) {
        fwrite(STDERR, "BAD_RESPONSE:\$class\\n");
        exit(1);
    }
}
echo "BOOT_OK\\n";
PHP;
        file_put_contents($wrapperPath, $wrapperContent);

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($wrapperPath) . ' 2>&1';
        exec($cmd, $output, $exitCode);
        @unlink($wrapperPath);

        $outputText = implode("\n", $output);
        $this->assertSame(0, $exitCode, "Generated handlers failed to boot/instantiate:\n$outputText");
        $this->assertStringContainsString('BOOT_OK', $outputText);
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
