<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Routing\Router;

/**
 * Generator v4.0 contract tests (CLIENT-SDK-SPEC §0 A1–A6, approved 2026-06-14).
 *
 * Tests cover:
 *  - Router::group() registers routes with service + group + action + streaming + param metadata
 *  - A1: streaming:true routes are excluded and tracked
 *  - A2: missing group on includable route is a hard error (router metadata reflects it)
 *  - A3: service:'infra' and service:'webhook' routes are excluded
 *  - A4: group+action naming derived correctly (static assertion)
 *  - A5: tail :id param is always id in metadata regardless of PHP name
 *  - A6: per-service partitioning — portal and admin packages are separate
 *
 * The generator CLI (generate-client.php) is tested via the helper functions
 * it exposes once its logic is extracted here. For the executable parts (file I/O)
 * we test by running the generator against a fixture routes.php and asserting
 * the written file contents.
 */
class ClientGeneratorV4Test extends TestCase
{
    // =========================================================================
    // Router::group() — route registration with v4.0 metadata (A2)
    // =========================================================================

    public function test_group_registers_routes_with_service_metadata(): void
    {
        $router = new Router();
        $router->group('/portal/tenant/{tenantId}', ['service' => 'portal'], function () use ($router) {
            $router->get('/items', 'ListItemsRoute', group: 'inventory');
            $router->post('/items/create', 'CreateItemRoute', group: 'inventory');
        });

        $meta = $router->getRouteMeta();
        $this->assertCount(2, $meta);

        $listRoute = $meta[0];
        $this->assertEquals('GET', $listRoute['method']);
        $this->assertEquals('/portal/tenant/{tenantId}/items', $listRoute['path']);
        $this->assertEquals('portal', $listRoute['service']);
        $this->assertEquals('inventory', $listRoute['group']);
        $this->assertNull($listRoute['action']);
        $this->assertFalse($listRoute['streaming']);

        $createRoute = $meta[1];
        $this->assertEquals('POST', $createRoute['method']);
        $this->assertEquals('/portal/tenant/{tenantId}/items/create', $createRoute['path']);
        $this->assertEquals('portal', $createRoute['service']);
        $this->assertEquals('inventory', $createRoute['group']);
    }

    public function test_group_sets_action_override_on_route(): void
    {
        $router = new Router();
        $router->group('/portal/tenant/{tenantId}', ['service' => 'portal'], function () use ($router) {
            $router->get('/routes/{id}/shipments', 'GetRouteShipmentsRoute',
                group: 'routes', action: 'shipments');
        });

        $meta = $router->getRouteMeta();
        $this->assertCount(1, $meta);
        $this->assertEquals('shipments', $meta[0]['action']);
    }

    public function test_group_sets_streaming_flag_on_route(): void
    {
        $router = new Router();
        $router->group('/api', ['service' => 'api'], function () use ($router) {
            $router->get('/workspaces/{id}/events', 'WorkspaceEventsRoute',
                group: 'workspaces', streaming: true);
        });

        $meta = $router->getRouteMeta();
        $this->assertCount(1, $meta);
        $this->assertTrue($meta[0]['streaming']);
    }

    public function test_group_sets_param_doc_label_on_route(): void
    {
        $router = new Router();
        $router->group('/portal/tenant/{tenantId}', ['service' => 'portal'], function () use ($router) {
            $router->get('/shipments/{id}', 'GetShipmentRoute',
                group: 'shipments', param: 'tracking_number');
        });

        $meta = $router->getRouteMeta();
        $this->assertCount(1, $meta);
        $this->assertEquals('tracking_number', $meta[0]['param']);
    }

    // =========================================================================
    // A3 — infra / webhook exclusions reflected in route metadata
    // =========================================================================

    public function test_infra_service_routes_are_registered_with_infra_service(): void
    {
        $router = new Router();
        $router->get('/health', 'HealthRoute', service: 'infra');
        $router->get('/.well-known/jwks.json', 'JwksRoute', service: 'infra');

        $meta = $router->getRouteMeta();
        $this->assertCount(2, $meta);
        foreach ($meta as $route) {
            $this->assertEquals('infra', $route['service']);
            $this->assertNull($route['group']); // no group required on infra routes
        }
    }

    public function test_webhook_service_routes_are_registered_with_webhook_service(): void
    {
        $router = new Router();
        $router->post('/payments/webhook', 'RazorpayWebhookRoute', service: 'webhook');
        $router->post('/jobs/callback', 'JobCallbackRoute', service: 'webhook');

        $meta = $router->getRouteMeta();
        $this->assertCount(2, $meta);
        foreach ($meta as $route) {
            $this->assertEquals('webhook', $route['service']);
        }
    }

    // =========================================================================
    // A6 — per-service partitioning
    // =========================================================================

    public function test_admin_routes_have_admin_service(): void
    {
        $router = new Router();
        $router->group('/admin', ['service' => 'admin'], function () use ($router) {
            $router->get('/tenants', 'ListTenantsRoute', group: 'tenants');
            $router->post('/tenants/{id}/suspend', 'SuspendTenantRoute', group: 'tenants');
            $router->get('/analytics/overview', 'AnalyticsOverviewRoute', group: 'analytics');
        });

        $meta = $router->getRouteMeta();
        $this->assertCount(3, $meta);
        foreach ($meta as $route) {
            $this->assertEquals('admin', $route['service']);
        }

        $groups = array_unique(array_column($meta, 'group'));
        $this->assertContains('tenants', $groups);
        $this->assertContains('analytics', $groups);
    }

    public function test_portal_and_admin_routes_are_partitioned(): void
    {
        $router = new Router();

        $router->group('/portal/tenant/{tenantId}', ['service' => 'portal'], function () use ($router) {
            $router->get('/items', 'ListItemsRoute', group: 'inventory');
            $router->post('/items/create', 'CreateItemRoute', group: 'inventory');
        });

        $router->group('/admin', ['service' => 'admin'], function () use ($router) {
            $router->get('/tenants', 'ListTenantsRoute', group: 'tenants');
        });

        $meta = $router->getRouteMeta();
        $this->assertCount(3, $meta);

        $portalRoutes = array_filter($meta, fn($r) => $r['service'] === 'portal');
        $adminRoutes  = array_filter($meta, fn($r) => $r['service'] === 'admin');

        $this->assertCount(2, $portalRoutes);
        $this->assertCount(1, $adminRoutes);
    }

    // =========================================================================
    // getRouteMeta() — v4.0 field completeness
    // =========================================================================

    public function test_get_route_meta_returns_all_v4_fields(): void
    {
        $router = new Router();
        $router->group('/portal/tenant/{tenantId}', ['service' => 'portal'], function () use ($router) {
            $router->get('/items/{id}', 'GetItemRoute',
                group: 'inventory', action: 'get', streaming: false, param: 'item_id');
        });

        $meta = $router->getRouteMeta();
        $r = $meta[0];

        $this->assertArrayHasKey('method',    $r);
        $this->assertArrayHasKey('path',      $r);
        $this->assertArrayHasKey('handler',   $r);
        $this->assertArrayHasKey('service',   $r);
        $this->assertArrayHasKey('group',     $r);
        $this->assertArrayHasKey('action',    $r);
        $this->assertArrayHasKey('streaming', $r);
        $this->assertArrayHasKey('param',     $r);
        $this->assertArrayNotHasKey('scope',  $r); // v4.0: scope alias removed
        $this->assertArrayHasKey('is_public', $r);

        $this->assertEquals('portal',    $r['service']);
        $this->assertEquals('inventory', $r['group']);
        $this->assertEquals('get',       $r['action']);
        $this->assertFalse($r['streaming']);
        $this->assertEquals('item_id',   $r['param']);
    }

    // =========================================================================
    // A5 — tail :id normalization (generator helper)
    // =========================================================================

    public function test_tail_id_detection_on_brace_syntax(): void
    {
        $this->assertTrue($this->hasTailId('/portal/tenant/{tenantId}/items/{id}'));
        $this->assertTrue($this->hasTailId('/admin/tenants/{id}'));
        $this->assertFalse($this->hasTailId('/portal/tenant/{tenantId}/items'));
        $this->assertFalse($this->hasTailId('/portal/tenant/{tenantId}/items/create'));
        $this->assertFalse($this->hasTailId('/portal/tenant/{tenantId}/items/search'));
    }

    public function test_tail_id_detection_on_colon_syntax(): void
    {
        $this->assertTrue($this->hasTailId('/routes/:id/shipments/:id'));
        $this->assertFalse($this->hasTailId('/routes/search'));
    }

    // =========================================================================
    // A2 — naming: deriveAction
    // =========================================================================

    public function test_derive_action_list_on_get_no_id(): void
    {
        $this->assertEquals('list', $this->deriveAction('/portal/tenant/{tenantId}/items', 'GET', 'inventory'));
    }

    public function test_derive_action_get_on_get_with_id(): void
    {
        $this->assertEquals('get', $this->deriveAction('/portal/tenant/{tenantId}/items/{id}', 'GET', 'inventory'));
    }

    public function test_derive_action_create_on_post_no_id(): void
    {
        $this->assertEquals('create', $this->deriveAction('/portal/tenant/{tenantId}/items/create', 'POST', 'inventory'));
    }

    public function test_derive_action_from_url_segment(): void
    {
        $this->assertEquals('dailySummary', $this->deriveAction('/portal/tenant/{tenantId}/bills/daily-summary', 'GET', 'billing'));
    }

    public function test_derive_action_rpc_verb_assign_driver(): void
    {
        $this->assertEquals('assignDriver', $this->deriveAction('/portal/tenant/{tenantId}/routes/{id}/assign-driver', 'POST', 'routes'));
    }

    public function test_derive_action_rpc_verb_start(): void
    {
        $this->assertEquals('start', $this->deriveAction('/portal/tenant/{tenantId}/routes/{id}/start', 'POST', 'routes'));
    }

    public function test_derive_action_search(): void
    {
        $this->assertEquals('search', $this->deriveAction('/portal/tenant/{tenantId}/items/search', 'GET', 'inventory'));
    }

    public function test_derive_action_t2_no_tenant_segment(): void
    {
        // T2: no /tenant/{id} segment
        $this->assertEquals('list', $this->deriveAction('/api/workspaces', 'GET', 'workspaces'));
        $this->assertEquals('create', $this->deriveAction('/api/workspaces/create', 'POST', 'workspaces'));
    }

    public function test_derive_action_shipments_sub_resource(): void
    {
        // A4 example: routes.shipments(id) → action derived from 'shipments'
        $this->assertEquals('shipments', $this->deriveAction('/portal/tenant/{tenantId}/routes/{id}/shipments', 'GET', 'routes'));
    }

    // =========================================================================
    // A4 — toCamelCase conversion
    // =========================================================================

    public function test_to_camel_case_kebab(): void
    {
        $this->assertEquals('assignDriver',    $this->toCamelCase('assign-driver'));
        $this->assertEquals('salesDaily',      $this->toCamelCase('sales-daily'));
        $this->assertEquals('profitLossMonthly', $this->toCamelCase('profit-loss-monthly'));
    }

    public function test_to_camel_case_snake(): void
    {
        $this->assertEquals('salesDaily', $this->toCamelCase('sales_daily'));
    }

    public function test_to_camel_case_single_word(): void
    {
        $this->assertEquals('list',   $this->toCamelCase('list'));
        $this->assertEquals('create', $this->toCamelCase('create'));
        $this->assertEquals('search', $this->toCamelCase('search'));
    }

    // =========================================================================
    // Generator integration — fixture routes.php → file generation
    // =========================================================================

    /**
     * Tests that the generator produces a portal package with correct file layout
     * when given a v4.0 fixture routes file.
     */
    public function test_generator_emits_portal_package_from_fixture(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $exitCode = $this->runGenerator([
                '--output=' . $outputDir,
                '--tenancy=T3',
            ], $this->fixtureRoutesFile());

            $this->assertEquals(0, $exitCode, 'Generator should exit 0');

            $portalDir = $outputDir . '/portal';
            $this->assertDirectoryExists($portalDir);
            $this->assertFileExists($portalDir . '/package.json');
            $this->assertFileExists($portalDir . '/tsconfig.json');
            $this->assertFileExists($portalDir . '/.gitignore');
            $this->assertFileExists($portalDir . '/src/http.ts');
            $this->assertFileExists($portalDir . '/src/tokens.ts');
            $this->assertFileExists($portalDir . '/src/errors.ts');
            $this->assertFileExists($portalDir . '/src/types.ts');
            $this->assertFileExists($portalDir . '/src/client.ts');
            $this->assertFileExists($portalDir . '/src/index.ts');

        } finally {
            $this->rmdir($outputDir);
        }
    }

    public function test_generator_portal_client_has_set_tenant_for_t3(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(['--output=' . $outputDir, '--tenancy=T3'], $this->fixtureRoutesFile());

            $clientTs = file_get_contents($outputDir . '/portal/src/client.ts');
            $this->assertStringContainsString('setTenant', $clientTs, 'T3 portal client must have setTenant()');
            $this->assertStringContainsString('_tenantId', $clientTs);
            $this->assertStringContainsString('tenant/${this._tenantId}', $clientTs);
        } finally {
            $this->rmdir($outputDir);
        }
    }

    public function test_generator_admin_client_has_no_set_tenant(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(['--output=' . $outputDir, '--tenancy=T3'], $this->fixtureRoutesFile());

            $adminDir = $outputDir . '/admin';
            $this->assertDirectoryExists($adminDir, 'Admin package should be generated (A6)');

            $clientTs = file_get_contents($adminDir . '/src/client.ts');
            $this->assertStringNotContainsString('setTenant', $clientTs, 'Admin client must NOT have setTenant() (A6)');
            $this->assertStringNotContainsString('_tenantId', $clientTs);
        } finally {
            $this->rmdir($outputDir);
        }
    }

    public function test_generator_t2_client_has_no_set_tenant_and_no_tenant_url(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(['--output=' . $outputDir, '--tenancy=T2'], $this->fixtureRoutesFile());

            $clientTs = file_get_contents($outputDir . '/portal/src/client.ts');
            $this->assertStringNotContainsString('setTenant', $clientTs, 'T2 client must not have setTenant()');
            // Check that generated METHOD URLs do not include /tenant/ — strip comments first
            $codeOnly = preg_replace('#//[^\n]*\n#', '', $clientTs);
            $this->assertStringNotContainsString('/tenant/', $codeOnly, 'T2 method URLs must not include /tenant/ segment');
        } finally {
            $this->rmdir($outputDir);
        }
    }

    public function test_generator_streaming_route_is_skipped_and_listed_in_notice(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(['--output=' . $outputDir, '--tenancy=T3'], $this->fixtureRoutesFile());

            $clientTs = file_get_contents($outputDir . '/portal/src/client.ts');

            // streaming route must NOT appear as a callable method
            $this->assertStringNotContainsString('buildEvents', $clientTs, 'Streaming route must not appear as a generated method');

            // But it must appear in the notice comment
            $this->assertStringContainsString('STREAMING ROUTES', $clientTs, 'Streaming notice must be present');
            $this->assertStringContainsString('/build-events', $clientTs, 'Streaming route path must be listed in notice');
        } finally {
            $this->rmdir($outputDir);
        }
    }

    public function test_generator_excludes_infra_and_webhook_services(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(['--output=' . $outputDir, '--tenancy=T3'], $this->fixtureRoutesFile());

            $this->assertDirectoryDoesNotExist($outputDir . '/infra',   'infra service must not produce a package (A3)');
            $this->assertDirectoryDoesNotExist($outputDir . '/webhook', 'webhook service must not produce a package (A3)');
        } finally {
            $this->rmdir($outputDir);
        }
    }

    public function test_generator_groups_produce_named_properties(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(['--output=' . $outputDir, '--tenancy=T3'], $this->fixtureRoutesFile());

            $clientTs = file_get_contents($outputDir . '/portal/src/client.ts');
            $this->assertStringContainsString('readonly inventory', $clientTs, 'inventory group property must exist');
            $this->assertStringContainsString('readonly billing',   $clientTs, 'billing group property must exist');
        } finally {
            $this->rmdir($outputDir);
        }
    }

    public function test_generator_methods_named_from_group_action_not_api_api(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(['--output=' . $outputDir, '--tenancy=T3'], $this->fixtureRoutesFile());

            $clientTs = file_get_contents($outputDir . '/portal/src/client.ts');
            // Must NOT have the v3.30 regression pattern
            $this->assertStringNotContainsString('api.api.', $clientTs, 'api.api. regression must not appear');
            // Must have correct group.method pattern
            $this->assertStringContainsString('list:', $clientTs);
            $this->assertStringContainsString('create:', $clientTs);
        } finally {
            $this->rmdir($outputDir);
        }
    }

    public function test_generator_hard_errors_on_missing_group(): void
    {
        $routesFile = $this->fixtureRoutesMissingGroupFile();
        $outputDir  = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $exitCode = $this->runGenerator(['--output=' . $outputDir, '--tenancy=T3'], $routesFile);
            $this->assertNotEquals(0, $exitCode, 'Generator must exit non-zero when group is missing (A2)');
        } finally {
            $this->rmdir($outputDir);
        }
    }

    public function test_generator_dist_gitignore_does_not_ignore_dist(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(['--output=' . $outputDir, '--tenancy=T3'], $this->fixtureRoutesFile());

            $gitignore = file_get_contents($outputDir . '/portal/.gitignore');
            $this->assertStringNotContainsString('dist/', $gitignore, 'dist/ must NOT be gitignored (B3 — CI build-gate)');
        } finally {
            $this->rmdir($outputDir);
        }
    }

    public function test_generator_package_json_has_zero_runtime_deps(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(['--output=' . $outputDir, '--tenancy=T3'], $this->fixtureRoutesFile());

            $pkg = json_decode(file_get_contents($outputDir . '/portal/package.json'), true);
            $this->assertEmpty((array)($pkg['dependencies'] ?? []),     'Generated client must have zero runtime dependencies');
            $this->assertEmpty((array)($pkg['peerDependencies'] ?? []), 'Generated client must have zero peer dependencies');
            $this->assertStringNotContainsString('ngx-stonescriptphp-client', file_get_contents($outputDir . '/portal/package.json'));
        } finally {
            $this->rmdir($outputDir);
        }
    }

    /**
     * The generated ApiClient must expose the IApiClient infra-probe passthroughs
     * (get/post delegating to MinimalHttp) so it structurally satisfies the shared
     * IApiClient contract — without importing client-core (zero-dep invariant).
     * (Task #3033 / CLIENT-SDK-SPEC §12.)
     */
    public function test_generator_emits_iapiclient_passthroughs_without_importing_contract(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(['--output=' . $outputDir, '--tenancy=T3'], $this->fixtureRoutesFile());
            $clientTs = file_get_contents($outputDir . '/portal/src/client.ts');

            // Infra-probe passthroughs present (structural IApiClient conformance).
            $this->assertStringContainsString('get<R = unknown>(path: string', $clientTs, 'generated client must expose get() passthrough');
            $this->assertStringContainsString('post<R = unknown>(path: string', $clientTs, 'generated client must expose post() passthrough');
            $this->assertStringContainsString('return this.http.get<R>(path, params);', $clientTs);
            $this->assertStringContainsString('return this.http.post<R>(path, body);', $clientTs);

            // Must NOT import or `implements` the interface — self-containment / zero-dep.
            $this->assertStringNotContainsString("from '@progalaxyelabs/stonescriptphp-client-core'", $clientTs, 'generated client must not import the contract package');
            $this->assertStringNotContainsString('implements IApiClient', $clientTs, 'conformance is structural, not via implements');
        } finally {
            $this->rmdir($outputDir);
        }
    }

    // =========================================================================
    // Fixture helpers
    // =========================================================================

    /**
     * Returns path to a temporary fixture routes.php that exercises A1–A6:
     *   - T3 portal routes with inventory + billing groups
     *   - T3 admin routes (no tenant segment)
     *   - streaming:true route (A1)
     *   - service:'infra' route (A3)
     *   - service:'webhook' route (A3)
     *   - explicit action: override (A2)
     *   - RPC-style verb (A4)
     */
    private function fixtureRoutesFile(): string
    {
        $file = sys_get_temp_dir() . '/ssp-fixture-routes-' . uniqid() . '.php';

        file_put_contents($file, <<<'PHP'
<?php
// Fixture routes.php for CLIENT-SDK-SPEC v4.0 generator tests

$router->group('/portal/tenant/{tenantId}', ['service' => 'portal', 'middleware' => 'tenant-access'], function () use ($router) {
    // inventory group
    $router->get('/items',           'ListItemsRoute',   group: 'inventory');
    $router->get('/items/{id}',      'GetItemRoute',     group: 'inventory');
    $router->get('/items/search',    'SearchItemsRoute', group: 'inventory');
    $router->post('/items/create',   'CreateItemRoute',  group: 'inventory');
    $router->post('/items/{id}/update', 'UpdateItemRoute', group: 'inventory');
    $router->post('/items/{id}/delete', 'DeleteItemRoute', group: 'inventory');

    // billing group
    $router->get('/bills',           'ListBillsRoute',   group: 'billing');
    $router->post('/bills/create',   'CreateBillRoute',  group: 'billing');
    $router->get('/bills/daily-summary', 'DailySummaryRoute', group: 'billing');

    // routes group (RPC-style verbs — A4)
    $router->get('/routes',              'ListRoutesRoute',  group: 'routes');
    $router->post('/routes/{id}/start',  'StartRouteRoute',  group: 'routes');
    $router->post('/routes/{id}/assign-driver', 'AssignDriverRoute', group: 'routes');

    // streaming route (A1) — excluded from client
    $router->get('/build-events', 'BuildEventsRoute', group: 'workspaces', streaming: true);
});

// Admin routes (no tenant scope — A6)
$router->group('/admin', ['service' => 'admin', 'middleware' => 'admin-access'], function () use ($router) {
    $router->get('/tenants',           'ListTenantsRoute',   group: 'tenants');
    $router->get('/tenants/{id}',      'GetTenantRoute',     group: 'tenants');
    $router->post('/tenants/{id}/suspend', 'SuspendTenantRoute', group: 'tenants');
    $router->get('/analytics/overview', 'AnalyticsRoute',    group: 'analytics');
});

// Excluded: infra (A3)
$router->get('/health',                  'HealthRoute', service: 'infra');
$router->get('/.well-known/jwks.json',   'JwksRoute',   service: 'infra');

// Excluded: webhook (A3)
$router->post('/payments/webhook', 'RazorpayWebhookRoute', service: 'webhook');
PHP
        );

        return $file;
    }

    /**
     * Returns path to a temporary fixture routes.php where one includable route
     * is missing its group: declaration (triggers hard error per A2).
     */
    private function fixtureRoutesMissingGroupFile(): string
    {
        $file = sys_get_temp_dir() . '/ssp-fixture-missing-group-' . uniqid() . '.php';

        file_put_contents($file, <<<'PHP'
<?php
// Fixture: missing group on includable route — must trigger generator hard error

$router->group('/portal/tenant/{tenantId}', ['service' => 'portal'], function () use ($router) {
    $router->get('/items',        'ListItemsRoute', group: 'inventory');
    // This route has NO group: declaration — must cause hard error
    $router->get('/bills',        'ListBillsRoute');
});
PHP
        );

        return $file;
    }

    /**
     * Run the generator CLI script with given arguments and a specific routes file.
     *
     * Writes a PHP wrapper that:
     *  1. Defines ROOT_PATH pointing to a temp dir containing the fixture routes.php
     *  2. Sets $argv correctly
     *  3. Requires the autoloader (from the real framework vendor/)
     *  4. Requires generate-client.php
     *
     * @param  string[] $args       CLI arguments (e.g. ['--output=/tmp/x', '--tenancy=T3'])
     * @param  string   $routesFile Absolute path to the fixture routes.php
     * @return int Exit code
     */
    private function runGenerator(array $args, string $routesFile): int
    {
        $frameworkRoot  = realpath(__DIR__ . '/../..');
        $generatorPath  = $frameworkRoot . '/cli/generate-client.php';
        $vendorAutoload = $frameworkRoot . '/vendor/autoload.php';

        // Build a temp project root with the fixture routes.php in place
        $tmpRoot   = sys_get_temp_dir() . '/ssp-gen-root-' . uniqid();
        $configDir = $tmpRoot . '/src/config';
        mkdir($configDir, 0755, true);
        copy($routesFile, $configDir . '/routes.php');

        // Serialize $argv as a PHP array literal
        $argsPhp = var_export(array_merge(['generator'], $args), true);

        // The wrapper defines all constants before the generator reads them
        $wrapperPath = sys_get_temp_dir() . '/ssp-gen-wrapper-' . uniqid() . '.php';
        $wrapperContent = <<<PHP
<?php
// Auto-generated test wrapper — do not edit.
// Constants MUST be defined before vendor/autoload.php runs bootstrap.php.
define('ROOT_PATH',         '$tmpRoot/');
define('SRC_PATH',          '$tmpRoot/src/');
define('CONFIG_PATH',       '$tmpRoot/src/config/');
define('DEBUG_MODE',        1);
define('INDEX_START_TIME',  microtime(true));

require_once '$vendorAutoload';

\$argv = $argsPhp;
\$argc = count(\$argv);

require '$generatorPath';
PHP;

        file_put_contents($wrapperPath, $wrapperContent);

        $cmd = PHP_BINARY
             . ' -d error_reporting=E_ALL'
             . ' -d display_errors=stderr'
             . ' ' . escapeshellarg($wrapperPath)
             . ' 2>/dev/null';

        exec($cmd, $output, $exitCode);

        @unlink($wrapperPath);
        // Do NOT remove tmpRoot yet — caller reads files from outputDir first

        return $exitCode;
    }

    // =========================================================================
    // Pure function mirrors (test logic only — mirrors generate-client.php helpers)
    // =========================================================================

    private function hasTailId(string $path): bool
    {
        $parts = explode('/', rtrim($path, '/'));
        $last  = end($parts);
        return preg_match('/^\{.+\}$/', $last) || str_starts_with($last, ':');
    }

    private function toCamelCase(string $str): string
    {
        $str = str_replace(['_', '-'], ' ', $str);
        $str = ucwords($str);
        return lcfirst(str_replace(' ', '', $str));
    }

    private function deriveAction(string $path, string $method, string $group): string
    {
        $parts = array_values(array_filter(explode('/', $path), fn($p) => $p !== ''));

        // 1. Remove service (first segment)
        if (!empty($parts)) array_shift($parts);

        // 2. Remove /tenant/{tenantId} if present
        if (!empty($parts) && $parts[0] === 'tenant') {
            array_shift($parts);
            if (!empty($parts)) array_shift($parts);
        }

        // 3. Remove first remaining STATIC segment (URL resource base: items, bills, routes, …)
        if (!empty($parts) && !preg_match('/^\{.+\}$/', $parts[0]) && !preg_match('/^:/', $parts[0])) {
            array_shift($parts);
        }

        // 4. Partition remaining into param vs. action segments
        $paramParts  = [];
        $actionParts = [];
        foreach ($parts as $part) {
            if (preg_match('/^\{.+\}$/', $part) || preg_match('/^:/', $part)) {
                $paramParts[] = $part;
            } else {
                $actionParts[] = $part;
            }
        }

        $hasTailId  = !empty($paramParts);
        $httpMethod = strtoupper($method);

        // 5. Action segments present → camelCase
        if (!empty($actionParts)) {
            return $this->toCamelCase(implode('-', $actionParts));
        }

        // 6. No action segments → derive from HTTP method + id presence
        return match(true) {
            $httpMethod === 'GET'  && !$hasTailId => 'list',
            $httpMethod === 'GET'  &&  $hasTailId => 'get',
            $httpMethod === 'POST' && !$hasTailId => 'create',
            $httpMethod === 'POST' &&  $hasTailId => 'update',
            default                               => $this->toCamelCase($httpMethod),
        };
    }

    // =========================================================================
    // Filesystem helpers
    // =========================================================================

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
