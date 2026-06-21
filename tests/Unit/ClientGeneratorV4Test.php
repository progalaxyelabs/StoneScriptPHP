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
    // §10 — typed returns: response/collection threaded through route metadata
    // =========================================================================

    public function test_route_meta_carries_response_and_collection(): void
    {
        $router = new Router();
        $router->loadRoutes(['GET' => [
            '/portal/warehouses' => [
                'handler'    => 'ListWarehousesRoute',
                'service'    => 'portal',
                'group'      => 'warehouses',
                'action'     => 'list',
                'response'   => 'App\\Models\\Warehouse',
                'collection' => true,
            ],
            '/portal/trucks' => [
                'handler' => 'ListTrucksRoute',
                'service' => 'portal',
                'group'   => 'trucks',
                'action'  => 'list',
            ],
        ]]);

        $meta = $router->getRouteMeta();
        $byPath = [];
        foreach ($meta as $r) { $byPath[$r['path']] = $r; }

        $this->assertEquals('App\\Models\\Warehouse', $byPath['/portal/warehouses']['response']);
        $this->assertTrue($byPath['/portal/warehouses']['collection']);

        // Undeclared route → response null, collection false (fallback).
        $this->assertNull($byPath['/portal/trucks']['response']);
        $this->assertFalse($byPath['/portal/trucks']['collection']);
    }

    public function test_route_meta_response_defaults_when_absent(): void
    {
        $router = new Router();
        $router->group('/portal/tenant/{tenantId}', ['service' => 'portal'], function () use ($router) {
            $router->get('/items', 'ListItemsRoute', group: 'inventory');
        });

        $r = $router->getRouteMeta()[0];
        $this->assertArrayHasKey('response', $r);
        $this->assertArrayHasKey('collection', $r);
        $this->assertNull($r['response']);
        $this->assertFalse($r['collection']);
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
                'portal',
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
            $this->runGenerator(['portal', '--output=' . $outputDir, '--tenancy=T3'], $this->fixtureRoutesFile());

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
            $this->runGenerator(['portal', '--output=' . $outputDir, '--tenancy=T3'], $this->fixtureRoutesFile());

            $adminDir = $outputDir . '/admin';
            $this->assertDirectoryExists($adminDir, 'Admin package should be generated (A6)');

            $clientTs = file_get_contents($adminDir . '/src/client.ts');
            $this->assertStringNotContainsString('setTenant', $clientTs, 'Admin client must NOT have setTenant() (A6)');
            $this->assertStringNotContainsString('_tenantId', $clientTs);
            // A6: admin is not tenant-scoped — no escapePath() and the escape hatch
            // must be a PLAIN passthrough (no /portal/tenant/{id}/ rewriting).
            $this->assertStringNotContainsString('escapePath', $clientTs, 'Admin client must NOT have escapePath() (A6)');
            $this->assertStringContainsString('return this.http.get<R>(path, params);', $clientTs, 'Admin escape hatch must be a plain passthrough');
            $this->assertStringContainsString('return this.http.post<R>(path, body);', $clientTs);
        } finally {
            $this->rmdir($outputDir);
        }
    }

    public function test_generator_t2_client_has_no_set_tenant_and_no_tenant_url(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(['portal', '--output=' . $outputDir, '--tenancy=T2'], $this->fixtureRoutesFile());

            $clientTs = file_get_contents($outputDir . '/portal/src/client.ts');
            $this->assertStringNotContainsString('setTenant', $clientTs, 'T2 client must not have setTenant()');
            // T2 is not url-tenant-scoped — no escapePath(); escape hatch is a plain passthrough.
            $this->assertStringNotContainsString('escapePath', $clientTs, 'T2 client must NOT have escapePath()');
            $this->assertStringContainsString('return this.http.get<R>(path, params);', $clientTs, 'T2 escape hatch must be a plain passthrough');
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
            $this->runGenerator(['portal', '--output=' . $outputDir, '--tenancy=T3'], $this->fixtureRoutesFile());

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
            $this->runGenerator(['portal', '--output=' . $outputDir, '--tenancy=T3'], $this->fixtureRoutesFile());

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
            $this->runGenerator(['portal', '--output=' . $outputDir, '--tenancy=T3'], $this->fixtureRoutesFile());

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
            $this->runGenerator(['portal', '--output=' . $outputDir, '--tenancy=T3'], $this->fixtureRoutesFile());

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
            $exitCode = $this->runGenerator(['portal', '--output=' . $outputDir, '--tenancy=T3'], $routesFile);
            $this->assertNotEquals(0, $exitCode, 'Generator must exit non-zero when group is missing (A2)');
        } finally {
            $this->rmdir($outputDir);
        }
    }

    public function test_generator_dist_gitignore_does_not_ignore_dist(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(['portal', '--output=' . $outputDir, '--tenancy=T3'], $this->fixtureRoutesFile());

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
            $this->runGenerator(['portal', '--output=' . $outputDir, '--tenancy=T3'], $this->fixtureRoutesFile());

            $pkg = json_decode(file_get_contents($outputDir . '/portal/package.json'), true);
            $this->assertEmpty((array)($pkg['dependencies'] ?? []),     'Generated client must have zero runtime dependencies');
            $this->assertEmpty((array)($pkg['peerDependencies'] ?? []), 'Generated client must have zero peer dependencies');
            $this->assertStringNotContainsString('ngx-stonescriptphp-client', file_get_contents($outputDir . '/portal/package.json'));
        } finally {
            $this->rmdir($outputDir);
        }
    }

    // =========================================================================
    // v4.4/v4.5 — Package naming rule (generate-api-client-spec.md §"Package Naming")
    // =========================================================================

    /**
     * Generated package.json `name` MUST be `{composer-name}-{serviceName}-client`.
     *
     *   - {composer-name} = `name` field from composer.json AS-IS (no npm org scope added/stripped).
     *   - {serviceName}   = the service name declared in routes.php (portal, admin, www, …).
     *   - No `@org/` prefix of any kind is emitted for non-vendor-prefixed composer names.
     *
     * When a routes.php declares multiple services, each package gets its OWN correct name:
     *   portal service → hello-world-portal-client
     *   admin  service → hello-world-admin-client
     *
     * The CLI <scope> arg is deprecated (v4.5) and no longer affects package naming.
     */
    public function test_generator_package_name_uses_service_name_not_scope_arg(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            // Run generator with scope='www' — a scope that does NOT match either service
            // in the fixture (portal + admin). The correct behavior is that each service
            // package uses its own service name, ignoring the scope arg entirely.
            $this->runGenerator(
                ['www', '--output=' . $outputDir, '--tenancy=T3'],
                $this->fixtureRoutesFile(),
                ['composer.json' => json_encode(['name' => 'hello-world', 'require' => new \stdClass()]) . "\n"]
            );

            // portal package must be named for the portal service, NOT for 'www'
            $portalPkg = json_decode(file_get_contents($outputDir . '/portal/package.json'), true);
            $this->assertEquals(
                'hello-world-portal-client',
                $portalPkg['name'],
                'portal/ package name must be derived from service name "portal", not from the CLI scope "www"'
            );

            // admin package must be named for the admin service, NOT for 'www'
            $adminPkg = json_decode(file_get_contents($outputDir . '/admin/package.json'), true);
            $this->assertEquals(
                'hello-world-admin-client',
                $adminPkg['name'],
                'admin/ package name must be derived from service name "admin", not from the CLI scope "www"'
            );

            // Confirm no scope-arg leakage in either package name
            $this->assertStringNotContainsString('-www-', $portalPkg['name'],
                'portal/ package must not contain the CLI scope "www" in its name');
            $this->assertStringNotContainsString('-www-', $adminPkg['name'],
                'admin/ package must not contain the CLI scope "www" in its name');

            // Must NOT carry a hardcoded npm org scope — the old @stonescript/... convention is gone.
            $this->assertStringNotContainsString('@stonescript/', $portalPkg['name']);
            $this->assertStringNotContainsString('@progalaxyelabs/', $portalPkg['name']);
        } finally {
            $this->rmdir($outputDir);
        }
    }

    /**
     * v4.5 multi-scope clobber regression test.
     *
     * Bug (v4.4): when a routes.php declares portal + admin services and the generator
     * runs twice — once with scope=portal then once with scope=admin — the second run
     * CLOBBERS the first run's portal/package.json name with the admin scope, leaving
     * both portal/ and admin/ named "...-admin-client".
     *
     * Fix (v4.5): package name uses $serviceName (from routes.php), not $scopeArg (the
     * CLI arg). Each run independently produces correct per-service names regardless of
     * what scope arg was passed.
     *
     * This test exercises the EXACT failure mode reported: sequential runs on a
     * multi-service platform (medstoreapp, logisticsapp, emcircuitsystems, …).
     */
    public function test_multi_scope_sequential_runs_do_not_clobber_package_names(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();
        $extraFiles = ['composer.json' => json_encode(['name' => 'myapp-api', 'require' => new \stdClass()]) . "\n"];

        try {
            // Run 1: stone generate client portal (as a platform's CI might do)
            $exitCode = $this->runGenerator(
                ['portal', '--output=' . $outputDir, '--tenancy=T3'],
                $this->fixtureRoutesFile(),
                $extraFiles
            );
            $this->assertEquals(0, $exitCode);

            // After run 1: portal gets portal-name, admin also gets portal-name (old bug)
            // OR portal gets portal-name and admin gets admin-name (correct v4.5)
            $portalNameAfterRun1 = json_decode(file_get_contents($outputDir . '/portal/package.json'), true)['name'];
            $adminNameAfterRun1  = json_decode(file_get_contents($outputDir . '/admin/package.json'), true)['name'];

            // Run 2: stone generate client admin (second angular service)
            $exitCode = $this->runGenerator(
                ['admin', '--output=' . $outputDir, '--tenancy=T3'],
                $this->fixtureRoutesFile(),
                $extraFiles
            );
            $this->assertEquals(0, $exitCode);

            $portalNameAfterRun2 = json_decode(file_get_contents($outputDir . '/portal/package.json'), true)['name'];
            $adminNameAfterRun2  = json_decode(file_get_contents($outputDir . '/admin/package.json'), true)['name'];

            // INVARIANT: portal/package.json name must be the same before and after run 2.
            // If run 2 clobbers it, this assertion fails — that is the bug.
            $this->assertEquals(
                $portalNameAfterRun1,
                $portalNameAfterRun2,
                'Run 2 (scope=admin) must NOT clobber the portal/ package name written by run 1. ' .
                "Before: '$portalNameAfterRun1', After: '$portalNameAfterRun2'"
            );

            // Each service package must have its own service-specific name in both runs.
            $this->assertEquals('myapp-api-portal-client', $portalNameAfterRun2,
                'portal/ package must always be named myapp-api-portal-client regardless of CLI scope arg');
            $this->assertEquals('myapp-api-admin-client', $adminNameAfterRun2,
                'admin/ package must always be named myapp-api-admin-client regardless of CLI scope arg');
        } finally {
            $this->rmdir($outputDir);
        }
    }

    /**
     * Test the canonical platform examples (matching real medstoreapp + logisticsapp):
     *   progalaxyelabs/medstoreapp-api, service portal → @progalaxyelabs/medstoreapp-api-portal-client
     *   progalaxyelabs/medstoreapp-api, service admin  → @progalaxyelabs/medstoreapp-api-admin-client
     *   medstoreapp-api (bare), service portal         → medstoreapp-api-portal-client
     */
    public function test_generator_package_naming_canonical_examples(): void
    {
        // Vendor-prefixed composer name: each service gets its own @-scoped package
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();
        try {
            $this->runGenerator(
                ['portal', '--output=' . $outputDir, '--tenancy=T3'],
                $this->fixtureRoutesFile(),
                ['composer.json' => json_encode(['name' => 'progalaxyelabs/myapp-api', 'require' => new \stdClass()]) . "\n"]
            );
            $portalPkg = json_decode(file_get_contents($outputDir . '/portal/package.json'), true);
            $adminPkg  = json_decode(file_get_contents($outputDir . '/admin/package.json'), true);
            $this->assertEquals('@progalaxyelabs/myapp-api-portal-client', $portalPkg['name'],
                'vendor-prefixed: service portal → @vendor/pkg-portal-client');
            $this->assertEquals('@progalaxyelabs/myapp-api-admin-client', $adminPkg['name'],
                'vendor-prefixed: service admin → @vendor/pkg-admin-client');
        } finally {
            $this->rmdir($outputDir);
        }

        // Bare (non-vendor-prefixed) composer name
        $outputDir2 = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();
        try {
            $this->runGenerator(
                ['portal', '--output=' . $outputDir2, '--tenancy=T3'],
                $this->fixtureRoutesFile(),
                ['composer.json' => json_encode(['name' => 'medstoreapp-api', 'require' => new \stdClass()]) . "\n"]
            );
            $portalPkg = json_decode(file_get_contents($outputDir2 . '/portal/package.json'), true);
            $adminPkg  = json_decode(file_get_contents($outputDir2 . '/admin/package.json'), true);
            $this->assertEquals('medstoreapp-api-portal-client', $portalPkg['name'],
                'bare: service portal → name-portal-client');
            $this->assertEquals('medstoreapp-api-admin-client', $adminPkg['name'],
                'bare: service admin → name-admin-client');
        } finally {
            $this->rmdir($outputDir2);
        }
    }

    /**
     * Generator must succeed (exit 0) when <scope> positional arg is omitted (v4.5).
     * The scope is now optional; omitting it is no longer an error.
     */
    public function test_generator_succeeds_when_scope_arg_omitted(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();
        try {
            // Pass no scope arg — only flags. Must succeed and produce correct packages.
            $exitCode = $this->runGenerator(
                ['--output=' . $outputDir, '--tenancy=T3'],
                $this->fixtureRoutesFile(),
                ['composer.json' => json_encode(['name' => 'myapp-api', 'require' => new \stdClass()]) . "\n"]
            );
            $this->assertEquals(0, $exitCode,
                'Generator must exit 0 when <scope> argument is omitted (v4.5: scope is now optional)');

            // Each service package must have its own name derived from the service name.
            $portalPkg = json_decode(file_get_contents($outputDir . '/portal/package.json'), true);
            $adminPkg  = json_decode(file_get_contents($outputDir . '/admin/package.json'), true);
            $this->assertEquals('myapp-api-portal-client', $portalPkg['name'],
                'Without scope arg: portal/ must be named myapp-api-portal-client');
            $this->assertEquals('myapp-api-admin-client', $adminPkg['name'],
                'Without scope arg: admin/ must be named myapp-api-admin-client');
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
            $this->runGenerator(['portal', '--output=' . $outputDir, '--tenancy=T3'], $this->fixtureRoutesFile());
            $clientTs = file_get_contents($outputDir . '/portal/src/client.ts');

            // Infra-probe passthroughs present (structural IApiClient conformance).
            $this->assertStringContainsString('get<R = unknown>(path: string', $clientTs, 'generated client must expose get() passthrough');
            $this->assertStringContainsString('post<R = unknown>(path: string', $clientTs, 'generated client must expose post() passthrough');
            // T3 (tenant-scoped, non-admin) escape hatch is TENANT-AWARE (CLIENT-SDK-SPEC
            // §12, proven by the #3033 medstoreapp e2e): get/post route the logical
            // `/portal/...` path through escapePath() so the CLIENT applies the active
            // tenant prefix. (Admin/T2 clients use a plain passthrough — asserted in
            // test_generator_admin_client_has_no_set_tenant / _t2_client_*.)
            $this->assertStringContainsString('return this.http.get<R>(this.escapePath(path), params);', $clientTs);
            $this->assertStringContainsString('return this.http.post<R>(this.escapePath(path), body);', $clientTs);

            // Must NOT import or `implements` the interface — self-containment / zero-dep.
            $this->assertStringNotContainsString("from '@progalaxyelabs/stonescriptphp-client-core'", $clientTs, 'generated client must not import the contract package');
            $this->assertStringNotContainsString('implements IApiClient', $clientTs, 'conformance is structural, not via implements');
        } finally {
            $this->rmdir($outputDir);
        }
    }

    /**
     * v4.3.1 — escape-hatch must expose all five HTTP verbs (get/post/put/patch/delete).
     *
     * MinimalHttp has carried put/patch/delete since v4.2.0. The generator previously
     * wired only get/post to the escape-hatch surface. Services calling PUT/DELETE/PATCH
     * routes via the escape hatch (rather than typed api.<group>.<action>() methods) hit
     * a TypeScript compile error — those methods simply did not exist on ApiClient.
     * CLIENT-SDK-SPEC §12 / fix in v4.3.1.
     */
    public function test_generator_emits_put_patch_delete_escape_hatch_methods_t3_portal(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(['portal', '--output=' . $outputDir, '--tenancy=T3'], $this->fixtureRoutesFile());
            $clientTs = file_get_contents($outputDir . '/portal/src/client.ts');

            // All five escape-hatch methods must be present (CLIENT-SDK-SPEC §12 / v4.3.1).
            $this->assertStringContainsString('put<R = unknown>(path: string, body?: unknown): Promise<R>',    $clientTs, 'T3 portal client must expose put() escape hatch (v4.3.1)');
            $this->assertStringContainsString('patch<R = unknown>(path: string, body?: unknown): Promise<R>',  $clientTs, 'T3 portal client must expose patch() escape hatch (v4.3.1)');
            $this->assertStringContainsString('delete<R = unknown>(path: string, body?: unknown): Promise<R>', $clientTs, 'T3 portal client must expose delete() escape hatch (v4.3.1)');

            // T3 portal client is tenant-scoped: put/patch/delete MUST route through escapePath()
            // (same as post) so /portal/* paths receive the active tenant prefix.
            $this->assertStringContainsString('return this.http.put<R>(this.escapePath(path), body);',    $clientTs, 'T3 portal put() must be tenant-aware via escapePath()');
            $this->assertStringContainsString('return this.http.patch<R>(this.escapePath(path), body);',  $clientTs, 'T3 portal patch() must be tenant-aware via escapePath()');
            $this->assertStringContainsString('return this.http.delete<R>(this.escapePath(path), body);', $clientTs, 'T3 portal delete() must be tenant-aware via escapePath()');
        } finally {
            $this->rmdir($outputDir);
        }
    }

    public function test_generator_emits_put_patch_delete_escape_hatch_methods_admin(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(['portal', '--output=' . $outputDir, '--tenancy=T3'], $this->fixtureRoutesFile());
            $adminClientTs = file_get_contents($outputDir . '/admin/src/client.ts');

            // Admin client (not tenant-scoped): put/patch/delete must be plain passthroughs
            // (no escapePath — same as the get/post admin escape hatch asserted in
            // test_generator_admin_client_has_no_set_tenant).
            $this->assertStringContainsString('put<R = unknown>(path: string, body?: unknown): Promise<R>',    $adminClientTs, 'admin client must expose put() escape hatch (v4.3.1)');
            $this->assertStringContainsString('patch<R = unknown>(path: string, body?: unknown): Promise<R>',  $adminClientTs, 'admin client must expose patch() escape hatch (v4.3.1)');
            $this->assertStringContainsString('delete<R = unknown>(path: string, body?: unknown): Promise<R>', $adminClientTs, 'admin client must expose delete() escape hatch (v4.3.1)');

            // Admin is NOT tenant-scoped — must NOT use escapePath() on any escape-hatch method.
            $this->assertStringContainsString('return this.http.put<R>(path, body);',    $adminClientTs, 'admin put() must be a plain passthrough (no escapePath)');
            $this->assertStringContainsString('return this.http.patch<R>(path, body);',  $adminClientTs, 'admin patch() must be a plain passthrough (no escapePath)');
            $this->assertStringContainsString('return this.http.delete<R>(path, body);', $adminClientTs, 'admin delete() must be a plain passthrough (no escapePath)');
        } finally {
            $this->rmdir($outputDir);
        }
    }

    public function test_generator_emits_put_patch_delete_escape_hatch_methods_t2(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(['portal', '--output=' . $outputDir, '--tenancy=T2'], $this->fixtureRoutesFile());
            $clientTs = file_get_contents($outputDir . '/portal/src/client.ts');

            // T2 client is not URL-tenant-scoped: put/patch/delete must be plain passthroughs.
            $this->assertStringContainsString('put<R = unknown>(path: string, body?: unknown): Promise<R>',    $clientTs, 'T2 client must expose put() escape hatch (v4.3.1)');
            $this->assertStringContainsString('patch<R = unknown>(path: string, body?: unknown): Promise<R>',  $clientTs, 'T2 client must expose patch() escape hatch (v4.3.1)');
            $this->assertStringContainsString('delete<R = unknown>(path: string, body?: unknown): Promise<R>', $clientTs, 'T2 client must expose delete() escape hatch (v4.3.1)');

            $this->assertStringContainsString('return this.http.put<R>(path, body);',    $clientTs, 'T2 put() must be a plain passthrough');
            $this->assertStringContainsString('return this.http.patch<R>(path, body);',  $clientTs, 'T2 patch() must be a plain passthrough');
            $this->assertStringContainsString('return this.http.delete<R>(path, body);', $clientTs, 'T2 delete() must be a plain passthrough');
        } finally {
            $this->rmdir($outputDir);
        }
    }

    // =========================================================================
    // §10 — typed returns: generator emits typed methods + reflected interface
    // =========================================================================

    public function test_generator_emits_typed_collection_method_and_interface(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(
                ['portal', '--output=' . $outputDir, '--tenancy=T3'],
                $this->fixtureRoutesWithResponseDto(),
                $this->fixtureDtoFiles()
            );

            $clientTs = file_get_contents($outputDir . '/portal/src/client.ts');
            $typesTs  = file_get_contents($outputDir . '/portal/src/types.ts');

            // Declared route → typed collection return.
            $this->assertStringContainsString('this.http.get<T.WarehouseDto[]>(', $clientTs,
                'declared collection route must emit a typed Dto[] http generic');

            // Undeclared route in the same group → ApiResponse fallback.
            $this->assertStringContainsString('this.http.get<T.ApiResponse>(', $clientTs,
                'undeclared route must keep the ApiResponse (unknown) fallback');

            // Reflected interface present with correct PHP→TS mapping.
            $this->assertStringContainsString('export interface WarehouseDto {', $typesTs);
            $this->assertStringContainsString('id: number;', $typesTs);           // int → number
            $this->assertStringContainsString('capacity: number;', $typesTs);     // float → number
            $this->assertStringContainsString('name: string;', $typesTs);         // string → string
            $this->assertStringContainsString('active: boolean;', $typesTs);      // bool → boolean
            $this->assertStringContainsString('postal_code?: string | null;', $typesTs); // ?string → optional | null
        } finally {
            $this->rmdir($outputDir);
        }
    }

    public function test_generator_single_response_dto_is_not_array(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(
                ['portal', '--output=' . $outputDir, '--tenancy=T3'],
                $this->fixtureRoutesWithResponseDto(),
                $this->fixtureDtoFiles()
            );

            $clientTs = file_get_contents($outputDir . '/portal/src/client.ts');
            // The single (non-collection) get-by-id route returns Promise<WarehouseDto> — not array.
            $this->assertStringContainsString('this.http.get<T.WarehouseDto>(`${this.t}/warehouses/${id}`)', $clientTs,
                'single response route must emit Dto (not Dto[])');
        } finally {
            $this->rmdir($outputDir);
        }
    }

    // =========================================================================
    // v4.4.1 Bug 1 — scope arg parsed from dispatcher-adjusted $_SERVER['argv']
    // =========================================================================

    /**
     * Bug 1 regression test (v4.4.0): when invoked via `php stone generate client <scope>`,
     * the stone dispatcher sets $_SERVER['argv'] = [$scriptPath, $scope, ...flags] and then
     * require's generate-client.php. The global $argv still contains the full stone
     * invocation (stone, generate, client, www) — if the generator reads $argv instead of
     * $_SERVER['argv'], it picks up "generate" as the scope and the scope arg is mis-parsed.
     *
     * Although v4.5 no longer uses the scope arg for naming, the generator must still
     * correctly parse $_SERVER['argv'] (not raw $argv) so that it does not misidentify
     * stone subcommand tokens ("generate", "client") as other arguments. The test verifies
     * the generator exits 0 and produces correct service-named packages on the dispatcher path.
     *
     * This test exercises that exact path: the runGeneratorViaStoneDispatch() helper
     * simulates the stone dispatcher by setting $_SERVER['argv'] correctly and leaving
     * the raw $argv containing the stone invocation prefix.
     */
    public function test_scope_parsed_from_dispatcher_adjusted_argv_not_raw_argv(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $exitCode = $this->runGeneratorViaStoneDispatch(
                scope: 'www',
                flags: ['--output=' . $outputDir, '--tenancy=T3'],
                routesFile: $this->fixtureRoutesFile(),
                extraFiles: ['composer.json' => json_encode(['name' => 'hello-world', 'require' => new \stdClass()]) . "\n"],
            );

            $this->assertEquals(0, $exitCode, 'Generator must exit 0 on the stone dispatcher invocation path');

            // In v4.5 package names come from service names, not the scope arg.
            // The portal service always gets "hello-world-portal-client" regardless of
            // whether scope="www" was passed via the dispatcher.
            $pkg = json_decode(file_get_contents($outputDir . '/portal/package.json'), true);
            $this->assertEquals(
                'hello-world-portal-client',
                $pkg['name'],
                'portal/ package must be named from the service name "portal", not from the CLI scope arg or ' .
                'subcommand tokens. Got "' . $pkg['name'] . '"'
            );

            // The generator must NOT misparse the stone subcommand tokens in $argv as the scope.
            // If Bug 1 regressed, the name would end in "-generate-client" (from "generate" token)
            // or "-client-client" (from "client" token).
            $this->assertStringNotContainsString('-generate-client', $pkg['name'],
                'Package name must not contain "generate" — that is a stone subcommand token, not a service name');
            $this->assertStringNotContainsString('-client-client', $pkg['name'],
                'Package name must not contain "client-client" — that is a stone subcommand token, not a service name');
        } finally {
            $this->rmdir($outputDir);
        }
    }

    // =========================================================================
    // v4.4.1 Bug 2 — vendor-prefixed composer name → valid npm scoped package name
    // =========================================================================

    /**
     * When the composer.json name has a vendor prefix (e.g. 'progalaxyelabs/progalaxy-api'),
     * the plain {name}-{service}-client rule would produce 'progalaxyelabs/progalaxy-api-portal-client'
     * which is an INVALID npm package name (slash without @).
     *
     * Fix (v4.4.1): detect a slash in the composer name and emit the valid npm scoped form:
     *   @{vendor}/{pkg}-{service}-client
     *   e.g. 'progalaxyelabs/progalaxy-api', service 'portal' → '@progalaxyelabs/progalaxy-api-portal-client'
     */
    public function test_vendor_prefixed_composer_name_emits_npm_scoped_package_name(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(
                ['portal', '--output=' . $outputDir, '--tenancy=T3'],
                $this->fixtureRoutesFile(),
                ['composer.json' => json_encode(['name' => 'progalaxyelabs/progalaxy-api', 'require' => new \stdClass()]) . "\n"]
            );

            $pkg = json_decode(file_get_contents($outputDir . '/portal/package.json'), true);
            $this->assertEquals(
                '@progalaxyelabs/progalaxy-api-portal-client',
                $pkg['name'],
                'vendor/pkg composer name must emit @vendor/pkg-{service}-client (valid npm scoped name)'
            );

            // Must start with '@' — confirm it is an npm-scoped form, not an invalid bare-slash name
            $this->assertStringStartsWith('@', $pkg['name'],
                'Vendor-prefixed composer name must emit an @-scoped npm package name, not a bare-slash invalid name');
        } finally {
            $this->rmdir($outputDir);
        }
    }

    /**
     * Vendor-prefixed composer name: each service gets its own scoped npm name.
     * The CLI scope arg is ignored for naming (v4.5); service names from routes.php drive names.
     *   progalaxyelabs/progalaxy-api, service portal → @progalaxyelabs/progalaxy-api-portal-client
     *   progalaxyelabs/progalaxy-api, service admin  → @progalaxyelabs/progalaxy-api-admin-client
     */
    public function test_vendor_prefix_each_service_gets_distinct_scoped_name(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(
                ['www', '--output=' . $outputDir, '--tenancy=T3'],
                $this->fixtureRoutesFile(),
                ['composer.json' => json_encode(['name' => 'progalaxyelabs/progalaxy-api', 'require' => new \stdClass()]) . "\n"]
            );

            // portal service → @progalaxyelabs/progalaxy-api-portal-client (service name, not scope 'www')
            $portalPkg = json_decode(file_get_contents($outputDir . '/portal/package.json'), true);
            $this->assertEquals(
                '@progalaxyelabs/progalaxy-api-portal-client',
                $portalPkg['name'],
                'vendor-prefixed: portal service → @vendor/pkg-portal-client (service name drives naming, not CLI scope)'
            );

            // admin service → @progalaxyelabs/progalaxy-api-admin-client
            $adminPkg = json_decode(file_get_contents($outputDir . '/admin/package.json'), true);
            $this->assertEquals(
                '@progalaxyelabs/progalaxy-api-admin-client',
                $adminPkg['name'],
                'vendor-prefixed: admin service → @vendor/pkg-admin-client'
            );
        } finally {
            $this->rmdir($outputDir);
        }
    }

    /**
     * Non-vendor-prefixed composer name (no slash) must keep the unscoped form.
     * Each service gets its own name: {composer-name}-{service}-client.
     */
    public function test_non_vendor_prefixed_composer_name_keeps_unscoped_form(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(
                ['portal', '--output=' . $outputDir, '--tenancy=T3'],
                $this->fixtureRoutesFile(),
                ['composer.json' => json_encode(['name' => 'medstoreapp-api', 'require' => new \stdClass()]) . "\n"]
            );

            // portal service → medstoreapp-api-portal-client
            $portalPkg = json_decode(file_get_contents($outputDir . '/portal/package.json'), true);
            $this->assertEquals(
                'medstoreapp-api-portal-client',
                $portalPkg['name'],
                'No-slash composer name: portal service → {name}-portal-client (unscoped)'
            );

            // admin service → medstoreapp-api-admin-client
            $adminPkg = json_decode(file_get_contents($outputDir . '/admin/package.json'), true);
            $this->assertEquals(
                'medstoreapp-api-admin-client',
                $adminPkg['name'],
                'No-slash composer name: admin service → {name}-admin-client (unscoped)'
            );

            $this->assertStringNotContainsString('@', $portalPkg['name'],
                'Unscoped form must not have an @ prefix');
            $this->assertStringNotContainsString('@', $adminPkg['name'],
                'Unscoped form must not have an @ prefix');
        } finally {
            $this->rmdir($outputDir);
        }
    }

    /**
     * Empty / missing composer name must fall back to {serviceName}-client.
     * v4.5: service name (from routes.php) is the fallback token, not the CLI scope.
     */
    public function test_empty_composer_name_falls_back_to_service_name_client(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            // No composer.json injected → generator falls back to $composerName = ''
            // portal service → portal-client  (not "www-client" or "scope-client")
            // admin service  → admin-client
            $this->runGenerator(
                ['www', '--output=' . $outputDir, '--tenancy=T3'],
                $this->fixtureRoutesFile()
                // no extraFiles → no composer.json in tmpRoot
            );

            $portalPkg = json_decode(file_get_contents($outputDir . '/portal/package.json'), true);
            $this->assertEquals(
                'portal-client',
                $portalPkg['name'],
                'Missing composer.json: portal service must fall back to "portal-client" (service name), not "www-client" (scope arg)'
            );

            $adminPkg = json_decode(file_get_contents($outputDir . '/admin/package.json'), true);
            $this->assertEquals(
                'admin-client',
                $adminPkg['name'],
                'Missing composer.json: admin service must fall back to "admin-client" (service name)'
            );
        } finally {
            $this->rmdir($outputDir);
        }
    }

    // =========================================================================
    // v4.6.0 — mid-path {id} parameter bug (TS2304 regression guard)
    // =========================================================================

    /**
     * Regression test for the v4.6.0 fix: routes where {id} appears in a non-tail
     * position (followed by an action segment like /start, /suspend, /assign-driver)
     * MUST declare `id: string | number` in their TypeScript method signature.
     *
     * Pre-fix: hasTailId() only checked the LAST path segment, so
     *   POST /routes/{id}/start → url template `${this.t}/routes/${id}/start`
     * but method signature `(data?) =>`  ← NO `id` declared → TS2304 under strict tsc.
     *
     * Fix: templateNeedsIdParam() scans ALL non-tenant path segments. Any route with
     * a {param} anywhere in its non-tenant path produces a method with id in its sig.
     *
     * Failing platforms: webmeteor, btechrecruiter, instituteapp (2026-06-21).
     * Pattern: resource group with GET /things/{id} + POST /things/{id}/action siblings.
     */
    public function test_mid_path_id_param_declared_in_sibling_post_methods_t3(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(
                ['--output=' . $outputDir, '--tenancy=T3'],
                $this->fixtureRoutesMidPathIdFile()
            );

            $clientTs = file_get_contents($outputDir . '/portal/src/client.ts');

            // The list route (no {id}) must NOT have id in signature.
            $this->assertStringContainsString(
                'list: (params?: HttpParams) =>',
                $clientTs,
                'list route must take HttpParams, not id'
            );

            // The get route (tail {id}) must have id in signature.
            $this->assertStringContainsString(
                'get: (id: string | number) =>',
                $clientTs,
                'get route (tail {id}) must declare id in signature'
            );

            // start has {id} in mid-path — MUST declare id.
            // Pre-fix: this would be `start: (data?) =>` (missing id) → TS2304.
            $this->assertStringContainsString(
                'start: (id: string | number, data?: T.ApiRequestBody) =>',
                $clientTs,
                'start route with mid-path {id} must declare id in signature (v4.6.0 fix)'
            );

            // assignDriver: same pattern.
            $this->assertStringContainsString(
                'assignDriver: (id: string | number, data?: T.ApiRequestBody) =>',
                $clientTs,
                'assignDriver route with mid-path {id} must declare id in signature (v4.6.0 fix)'
            );

            // URL templates must still contain ${id} in the correct position.
            $this->assertStringContainsString(
                '`${this.t}/routes/${id}/start`',
                $clientTs,
                'start URL template must interpolate ${id}'
            );
            $this->assertStringContainsString(
                '`${this.t}/routes/${id}/assign-driver`',
                $clientTs,
                'assignDriver URL template must interpolate ${id}'
            );
        } finally {
            $this->rmdir($outputDir);
        }
    }

    /**
     * Same bug in admin (non-tenant-scoped) service: POST /tenants/{id}/suspend
     * must declare id in its method signature.
     */
    public function test_mid_path_id_param_declared_in_admin_sibling_post_methods(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(
                ['--output=' . $outputDir, '--tenancy=T3'],
                $this->fixtureRoutesMidPathIdFile()
            );

            $clientTs = file_get_contents($outputDir . '/admin/src/client.ts');

            // suspend: POST /tenants/{id}/suspend — {id} is mid-path, not tail.
            $this->assertStringContainsString(
                'suspend: (id: string | number, data?: T.ApiRequestBody) =>',
                $clientTs,
                'admin suspend route with mid-path {id} must declare id in signature (v4.6.0 fix)'
            );
            $this->assertStringContainsString(
                '`/admin/tenants/${id}/suspend`',
                $clientTs,
                'suspend URL template must interpolate ${id}'
            );
        } finally {
            $this->rmdir($outputDir);
        }
    }

    /**
     * Same bug for portal routes with POST /items/{id}/update and /items/{id}/delete —
     * {id} is mid-path (followed by 'update'/'delete' action segments).
     */
    public function test_mid_path_id_param_declared_in_update_delete_action_methods(): void
    {
        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $this->runGenerator(
                ['--output=' . $outputDir, '--tenancy=T3'],
                $this->fixtureRoutesMidPathIdFile()
            );

            $clientTs = file_get_contents($outputDir . '/portal/src/client.ts');

            // update: POST /items/{id}/update — {id} mid-path.
            $this->assertStringContainsString(
                'update: (id: string | number, data?: T.ApiRequestBody) =>',
                $clientTs,
                'update route with mid-path {id} must declare id in signature (v4.6.0 fix)'
            );
            // delete: POST /items/{id}/delete — {id} mid-path.
            $this->assertStringContainsString(
                'delete: (id: string | number, data?: T.ApiRequestBody) =>',
                $clientTs,
                'delete route with mid-path {id} must declare id in signature (v4.6.0 fix)'
            );
        } finally {
            $this->rmdir($outputDir);
        }
    }

    // =========================================================================
    // v4.6.0 — strict tsc compile gate (the systemic gap that let this ship)
    // =========================================================================

    /**
     * The generator test suite must verify that emitted TypeScript COMPILES under
     * strict tsc — the same strictness that platform prod Docker builds use.
     *
     * This test closes the systemic gap: prior to v4.6.0, all generator tests
     * checked string patterns in client.ts but never compiled it. Broken TypeScript
     * (TS2304, TS2551, etc.) shipped green because "dev builds" mount pre-built
     * dist and never run tsc on the generated client. The first place tsc ran was
     * prod Docker build — too late.
     *
     * This test:
     *   1. Generates a full portal + admin package from the multi-shape fixture
     *      (which exercises mid-path params, RPC verbs, streaming, infra exclusion).
     *   2. Runs tsc --project tsconfig.json --noEmit on each package (strict mode
     *      is ON in the generated tsconfig.json — matches prod build config exactly).
     *   3. Fails if tsc exits non-zero, surfacing the exact compiler errors.
     *
     * The tsc binary is resolved from the stonescriptphp-client-core package which
     * always has typescript installed and is colocated in the same repo tree.
     *
     * If this test breaks: a generator change emits TypeScript that doesn't compile
     * under strict mode. Fix the emission, not this test.
     */
    public function test_generated_portal_client_compiles_under_strict_tsc(): void
    {
        $tscPath = $this->findTscBinary();
        if ($tscPath === null) {
            $this->markTestSkipped(
                'tsc binary not found in the stonescriptphp repo tree. ' .
                'Run `npm install` in stonescriptphp-client-core or stonescriptphp-auth-client to install typescript.'
            );
        }

        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $exitCode = $this->runGenerator(
                ['--output=' . $outputDir, '--tenancy=T3'],
                $this->fixtureRoutesMidPathIdFile()
            );
            $this->assertEquals(0, $exitCode, 'Generator must exit 0 before tsc check');

            // Compile portal package — strict mode is on in the generated tsconfig.json
            $tscOutput = [];
            $tscExit   = 0;
            exec(
                escapeshellarg($tscPath)
                . ' --project ' . escapeshellarg($outputDir . '/portal/tsconfig.json')
                . ' --noEmit 2>&1',
                $tscOutput,
                $tscExit
            );

            $this->assertEquals(
                0,
                $tscExit,
                "Generated portal/src/client.ts does not compile under strict tsc.\n" .
                "tsc output:\n" . implode("\n", $tscOutput) . "\n\n" .
                "This means the generator emits TypeScript that would fail prod Docker builds. " .
                "Fix the emission in generate-client.php."
            );
        } finally {
            $this->rmdir($outputDir);
        }
    }

    /**
     * Same strict tsc compile check for the admin (non-tenant-scoped) package.
     * Admin clients have a different code path (no setTenant, no escapePath) so
     * they need their own compile assertion.
     */
    public function test_generated_admin_client_compiles_under_strict_tsc(): void
    {
        $tscPath = $this->findTscBinary();
        if ($tscPath === null) {
            $this->markTestSkipped(
                'tsc binary not found in the stonescriptphp repo tree.'
            );
        }

        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $exitCode = $this->runGenerator(
                ['--output=' . $outputDir, '--tenancy=T3'],
                $this->fixtureRoutesMidPathIdFile()
            );
            $this->assertEquals(0, $exitCode, 'Generator must exit 0 before tsc check');

            $tscOutput = [];
            $tscExit   = 0;
            exec(
                escapeshellarg($tscPath)
                . ' --project ' . escapeshellarg($outputDir . '/admin/tsconfig.json')
                . ' --noEmit 2>&1',
                $tscOutput,
                $tscExit
            );

            $this->assertEquals(
                0,
                $tscExit,
                "Generated admin/src/client.ts does not compile under strict tsc.\n" .
                "tsc output:\n" . implode("\n", $tscOutput) . "\n\n" .
                "This means the generator emits TypeScript that would fail prod Docker builds. " .
                "Fix the emission in generate-client.php."
            );
        } finally {
            $this->rmdir($outputDir);
        }
    }

    /**
     * Compile gate for T2 (non-URL-tenant-scoped) generated client.
     * T2 clients strip the /tenant/{param} from URLs; the resulting code path
     * differs from T3 — needs its own compile assertion.
     */
    public function test_generated_t2_client_compiles_under_strict_tsc(): void
    {
        $tscPath = $this->findTscBinary();
        if ($tscPath === null) {
            $this->markTestSkipped(
                'tsc binary not found in the stonescriptphp repo tree.'
            );
        }

        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $exitCode = $this->runGenerator(
                ['--output=' . $outputDir, '--tenancy=T2'],
                $this->fixtureRoutesMidPathIdFile()
            );
            $this->assertEquals(0, $exitCode, 'Generator must exit 0 before tsc check');

            $tscOutput = [];
            $tscExit   = 0;
            exec(
                escapeshellarg($tscPath)
                . ' --project ' . escapeshellarg($outputDir . '/portal/tsconfig.json')
                . ' --noEmit 2>&1',
                $tscOutput,
                $tscExit
            );

            $this->assertEquals(
                0,
                $tscExit,
                "Generated T2 portal/src/client.ts does not compile under strict tsc.\n" .
                "tsc output:\n" . implode("\n", $tscOutput)
            );
        } finally {
            $this->rmdir($outputDir);
        }
    }

    /**
     * Compile gate using the original fixture (A1–A6 shapes including streaming,
     * infra exclusion, explicit action overrides, RPC verbs). This is the full-fidelity
     * test that would have caught every generator emission bug in a single run.
     */
    public function test_full_fixture_compiles_under_strict_tsc(): void
    {
        $tscPath = $this->findTscBinary();
        if ($tscPath === null) {
            $this->markTestSkipped(
                'tsc binary not found in the stonescriptphp repo tree.'
            );
        }

        $outputDir = sys_get_temp_dir() . '/ssp-gen-test-' . uniqid();

        try {
            $exitCode = $this->runGenerator(
                ['--output=' . $outputDir, '--tenancy=T3'],
                $this->fixtureRoutesFile()
            );
            $this->assertEquals(0, $exitCode, 'Generator must exit 0 before tsc check');

            foreach (['portal', 'admin'] as $service) {
                $tscOutput = [];
                $tscExit   = 0;
                exec(
                    escapeshellarg($tscPath)
                    . ' --project ' . escapeshellarg($outputDir . '/' . $service . '/tsconfig.json')
                    . ' --noEmit 2>&1',
                    $tscOutput,
                    $tscExit
                );

                $this->assertEquals(
                    0,
                    $tscExit,
                    "Generated $service/src/client.ts does not compile under strict tsc " .
                    "(full A1-A6 fixture).\ntsc output:\n" . implode("\n", $tscOutput)
                );
            }
        } finally {
            $this->rmdir($outputDir);
        }
    }

    // =========================================================================
    // Fixture helpers
    // =========================================================================

    /**
     * Routes fixture that declares response DTOs on two warehouse routes
     * (collection list + single get-by-id) and leaves a sibling route undeclared.
     */
    private function fixtureRoutesWithResponseDto(): string
    {
        $file = sys_get_temp_dir() . '/ssp-fixture-resp-routes-' . uniqid() . '.php';
        file_put_contents($file, <<<'PHP'
<?php
// Fixture: §10 typed-return routes

$router->group('/portal/tenant/{tenantId}', ['service' => 'portal'], function () use ($router) {
    $router->get('/warehouses',      'ListWarehousesRoute', group: 'warehouses', action: 'list',
        response: 'App\\Dto\\WarehouseDto', collection: true);
    $router->get('/warehouses/{id}', 'GetWarehouseRoute',   group: 'warehouses', action: 'get',
        response: 'App\\Dto\\WarehouseDto');
    // Undeclared sibling — must fall back to ApiResponse (unknown)
    $router->get('/trucks',          'ListTrucksRoute',     group: 'trucks',     action: 'list');
});
PHP
        );
        return $file;
    }

    /**
     * DTO class files dropped into the temp project's src/App/Dto/ so the
     * generator's reflection can resolve App\Dto\WarehouseDto.
     *
     * @return array<string,string> relative-path => php-source
     */
    private function fixtureDtoFiles(): array
    {
        return [
            'src/App/Dto/WarehouseDto.php' => <<<'PHP'
<?php
namespace App\Dto;

class WarehouseDto
{
    public int $id;
    public string $name;
    public ?string $postal_code;
    public float $capacity;
    public bool $active;
}
PHP,
        ];
    }

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
     * Returns path to a temporary fixture routes.php that exercises the
     * mid-path {id} bug (v4.6.0 regression guard).
     *
     * Contains:
     *   - portal group "inventory": GET /items (no id), GET /items/{id} (tail id),
     *     POST /items/create (no id), POST /items/{id}/update (mid-path id),
     *     POST /items/{id}/delete (mid-path id)
     *   - portal group "routes": GET /routes (no id), POST /routes/{id}/start (mid-path),
     *     POST /routes/{id}/assign-driver (mid-path)
     *   - admin group "tenants": GET /tenants (no id), GET /tenants/{id} (tail),
     *     POST /tenants/{id}/suspend (mid-path)
     *
     * Each mid-path {id} route would produce `${id}` in the URL template but (pre-fix)
     * would not declare `id: string | number` in the method signature → TS2304.
     */
    private function fixtureRoutesMidPathIdFile(): string
    {
        $file = sys_get_temp_dir() . '/ssp-fixture-mid-path-id-' . uniqid() . '.php';

        file_put_contents($file, <<<'PHP'
<?php
// Fixture: mid-path {id} parameter bug (v4.6.0 regression guard)

$router->group('/portal/tenant/{tenantId}', ['service' => 'portal'], function () use ($router) {
    // inventory group — covers tail-id, no-id, and mid-path-id shapes
    $router->get('/items',              'ListItemsRoute',   group: 'inventory');
    $router->get('/items/{id}',         'GetItemRoute',     group: 'inventory');
    $router->get('/items/search',       'SearchItemsRoute', group: 'inventory');
    $router->post('/items/create',      'CreateItemRoute',  group: 'inventory');
    $router->post('/items/{id}/update', 'UpdateItemRoute',  group: 'inventory'); // mid-path
    $router->post('/items/{id}/delete', 'DeleteItemRoute',  group: 'inventory'); // mid-path

    // routes group — RPC-style: POST /routes/{id}/start and /assign-driver are mid-path
    $router->get('/routes',                        'ListRoutesRoute',  group: 'routes');
    $router->post('/routes/{id}/start',            'StartRouteRoute',  group: 'routes'); // mid-path
    $router->post('/routes/{id}/assign-driver',    'AssignDriverRoute', group: 'routes'); // mid-path
});

// Admin routes — POST /tenants/{id}/suspend is mid-path
$router->group('/admin', ['service' => 'admin'], function () use ($router) {
    $router->get('/tenants',              'ListTenantsRoute',   group: 'tenants');
    $router->get('/tenants/{id}',         'GetTenantRoute',     group: 'tenants'); // tail
    $router->post('/tenants/{id}/suspend', 'SuspendTenantRoute', group: 'tenants'); // mid-path
});
PHP
        );

        return $file;
    }

    /**
     * Locate a `tsc` binary suitable for strict-compile tests.
     *
     * Searches the stonescriptphp repo tree for a `typescript/bin/tsc` installed
     * as a devDependency of our own packages (stonescriptphp-client-core,
     * stonescriptphp-auth-client, etc.) — no separate install required.
     *
     * Returns the absolute path to tsc, or null when none is found (test is
     * then skipped gracefully via markTestSkipped).
     */
    private function findTscBinary(): ?string
    {
        // Ordered preference: packages most likely to be installed.
        //
        // Directory layout:
        //   divisions/opensource/stonescriptphp/
        //     StoneScriptPHP/          ← $repoRoot (this framework)
        //       tests/Unit/            ← __DIR__
        //     stonescriptphp-client-core/
        //     stonescriptphp-auth-client/
        //     ngx-stonescriptphp-client/
        //     stonescriptphp-ts-client/
        //
        // dirname($repoRoot) = divisions/opensource/stonescriptphp/
        // sibling packages live there
        $repoRoot    = realpath(__DIR__ . '/../..');
        $siblingRoot = dirname($repoRoot);         // .../opensource/stonescriptphp/
        $searchRoots = [
            $siblingRoot . '/stonescriptphp-client-core/node_modules/.bin/tsc',
            $siblingRoot . '/stonescriptphp-auth-client/node_modules/.bin/tsc',
            $siblingRoot . '/ngx-stonescriptphp-client/node_modules/.bin/tsc',
            $siblingRoot . '/stonescriptphp-ts-client/node_modules/.bin/tsc',
        ];

        foreach ($searchRoots as $candidate) {
            if (file_exists($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
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
     * @param  array<string,string> $extraFiles relative-path => php-source files to drop
     *                              into the temp project root (e.g. response DTO classes
     *                              the generator's reflection must resolve).
     * @return int Exit code
     */
    private function runGenerator(array $args, string $routesFile, array $extraFiles = []): int
    {
        $frameworkRoot  = realpath(__DIR__ . '/../..');
        $generatorPath  = $frameworkRoot . '/cli/generate-client.php';
        $vendorAutoload = $frameworkRoot . '/vendor/autoload.php';

        // Build a temp project root with the fixture routes.php in place
        $tmpRoot   = sys_get_temp_dir() . '/ssp-gen-root-' . uniqid();
        $configDir = $tmpRoot . '/src/config';
        mkdir($configDir, 0755, true);
        copy($routesFile, $configDir . '/routes.php');

        // Drop any extra fixture files (e.g. response DTO classes) into the temp root.
        foreach ($extraFiles as $relPath => $source) {
            $dest = $tmpRoot . '/' . ltrim($relPath, '/');
            $dir  = dirname($dest);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($dest, $source);
        }

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

// generate-client.php reads \$_SERVER['argv'] (dispatcher-adjusted) — set it here
// so the generator sees the correct args. \$argv is also set for completeness but
// the generator no longer reads it (that was Bug 1).
\$argv = $argsPhp;
\$argc = count(\$argv);
\$_SERVER['argv'] = \$argv;
\$_SERVER['argc'] = \$argc;

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

    /**
     * Simulate the stone dispatcher path for Bug 1 regression testing.
     *
     * The stone CLI sets $_SERVER['argv'] = [$scriptPath, $scope, ...flags] and leaves
     * the raw $argv containing the full stone invocation (stone, generate, client, $scope, ...).
     * This helper replicates that environment so the test exercises the REAL dispatch path
     * rather than calling the generator with a pre-shifted argv.
     *
     * Key difference from runGenerator(): the PHP wrapper sets BOTH:
     *   $_SERVER['argv'] = [$scriptPath, $scope, ...flags]   ← what stone sets
     *   $argv            = ['stone', 'generate', 'client', $scope, ...flags]  ← raw argv before dispatch
     *
     * If the generator reads $argv instead of $_SERVER['argv'] it will pick up "generate"
     * as the scope and produce a wrong package name.
     */
    private function runGeneratorViaStoneDispatch(
        string $scope,
        array  $flags,
        string $routesFile,
        array  $extraFiles = [],
    ): int {
        $frameworkRoot  = realpath(__DIR__ . '/../..');
        $generatorPath  = $frameworkRoot . '/cli/generate-client.php';
        $vendorAutoload = $frameworkRoot . '/vendor/autoload.php';

        $tmpRoot   = sys_get_temp_dir() . '/ssp-gen-root-' . uniqid();
        $configDir = $tmpRoot . '/src/config';
        mkdir($configDir, 0755, true);
        copy($routesFile, $configDir . '/routes.php');

        foreach ($extraFiles as $relPath => $source) {
            $dest = $tmpRoot . '/' . ltrim($relPath, '/');
            $dir  = dirname($dest);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($dest, $source);
        }

        // $_SERVER['argv'] as the stone dispatcher sets it: [$scriptPath, $scope, ...flags]
        $dispatcherArgv = array_merge([$generatorPath, $scope], $flags);
        $dispatcherArgvPhp = var_export($dispatcherArgv, true);

        // Raw $argv as it exists BEFORE stone's dispatcher rewrites $_SERVER['argv']:
        // the full stone invocation including the "generate" and "client" subcommand tokens.
        $rawArgv = array_merge(['stone', 'generate', 'client', $scope], $flags);
        $rawArgvPhp = var_export($rawArgv, true);

        $wrapperPath = sys_get_temp_dir() . '/ssp-gen-dispatch-wrapper-' . uniqid() . '.php';
        $wrapperContent = <<<PHP
<?php
// Stone-dispatch simulation wrapper for Bug 1 regression test.
// Replicates exactly what the stone CLI does before require-ing generate-client.php.
define('ROOT_PATH',        '$tmpRoot/');
define('SRC_PATH',         '$tmpRoot/src/');
define('CONFIG_PATH',      '$tmpRoot/src/config/');
define('DEBUG_MODE',       1);
define('INDEX_START_TIME', microtime(true));

require_once '$vendorAutoload';

// stone dispatcher sets \$_SERVER['argv'] to the script-only argv (post-shift):
\$_SERVER['argv'] = $dispatcherArgvPhp;
\$_SERVER['argc'] = count(\$_SERVER['argv']);

// The raw \$argv (PHP global) still holds the full stone invocation — stone does NOT change it.
// This is the root of Bug 1: if generate-client.php reads \$argv instead of \$_SERVER['argv'],
// it picks up "generate" as the scope.
\$argv = $rawArgvPhp;
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
