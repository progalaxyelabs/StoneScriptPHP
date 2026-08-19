<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * v9.6.0 (CLIENT-SDK-SPEC §10 amendment) — request-DTO reflection + the
 * opt-in strict typed-contract gate.
 *
 * Covers:
 *  - routeRequestTsType() resolves a `request:` DTO class to a TS type,
 *    mirrors routeResponseTsType() (recursive/deduped reflectDto() reuse).
 *  - routeRequestTsType() returns null when `request` is absent or the class
 *    doesn't exist (graceful fallback, same as the response side).
 *  - buildMethodTs() emits the resolved request type in place of
 *    T.ApiRequestBody for POST/PUT/PATCH/DELETE, and is unaffected on GET.
 *  - typedContractViolations() flags missing response on any method, missing
 *    request only on body-carrying verbs, and returns [] when both declared
 *    (or declared where applicable).
 *  - Router::get()/post()/addRoute() thread a `request:` value all the way
 *    through to getRouteMeta() (structural plumbing test, no DTO needed).
 */
class ClientGeneratorRequestDtoAndStrictGateTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('GENERATE_CLIENT_TESTING')) {
            define('GENERATE_CLIENT_TESTING', true);
        }
        $prevArgv = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = [__FILE__];
        $generatorFile = realpath(__DIR__ . '/../../cli/generate-client.php');
        if ($generatorFile === false || !file_exists($generatorFile)) {
            $this->fail('Generator file not found at expected path relative to test: cli/generate-client.php');
        }
        require_once $generatorFile;
        $_SERVER['argv'] = $prevArgv;

        // Each test gets a clean DTO interface registry (mirrors the
        // per-service reset the real generator does before each package).
        $GLOBALS['__dtoInterfaces'] = [];
        $GLOBALS['__dtoInProgress'] = [];
    }

    // ── routeRequestTsType() ────────────────────────────────────────────

    public function test_route_request_ts_type_resolves_declared_dto(): void
    {
        $route = ['request' => \Tests\Fixtures\Dto\CreateWidgetRequestFixtureDto::class];
        $this->assertSame('T.CreateWidgetRequestFixtureDto', routeRequestTsType($route));
    }

    public function test_route_request_ts_type_null_when_not_declared(): void
    {
        $this->assertNull(routeRequestTsType([]));
        $this->assertNull(routeRequestTsType(['request' => null]));
        $this->assertNull(routeRequestTsType(['request' => '']));
    }

    public function test_route_request_ts_type_null_when_class_missing(): void
    {
        $this->assertNull(routeRequestTsType(['request' => 'Totally\\Nonexistent\\Dto']));
    }

    public function test_route_request_ts_type_registers_interface_in_types_registry(): void
    {
        routeRequestTsType(['request' => \Tests\Fixtures\Dto\CreateWidgetRequestFixtureDto::class]);
        $this->assertArrayHasKey('CreateWidgetRequestFixtureDto', $GLOBALS['__dtoInterfaces']);
        $this->assertStringContainsString('name: string;', $GLOBALS['__dtoInterfaces']['CreateWidgetRequestFixtureDto']);
    }

    // ── buildMethodTs() ─────────────────────────────────────────────────

    public function test_build_method_ts_uses_request_type_for_post_body(): void
    {
        $ts = buildMethodTs('create', 'POST', "'/portal/widgets'", false, null, null, null, 'T.CreateWidgetRequestFixtureDto');
        $this->assertStringContainsString('data?: T.CreateWidgetRequestFixtureDto', $ts);
        $this->assertStringNotContainsString('T.ApiRequestBody', $ts);
    }

    public function test_build_method_ts_falls_back_to_api_request_body_when_no_request_type(): void
    {
        $ts = buildMethodTs('create', 'POST', "'/portal/widgets'", false);
        $this->assertStringContainsString('data?: T.ApiRequestBody', $ts);
    }

    public function test_build_method_ts_ignores_request_type_on_get(): void
    {
        $ts = buildMethodTs('list', 'GET', "'/portal/widgets'", false, null, null, null, 'T.CreateWidgetRequestFixtureDto');
        $this->assertStringNotContainsString('CreateWidgetRequestFixtureDto', $ts);
        $this->assertStringContainsString('params?: HttpParams', $ts);
    }

    // ── typedContractViolations() ──────────────────────────────────────

    public function test_typed_contract_violations_flags_missing_response_on_get(): void
    {
        $violations = typedContractViolations(['method' => 'GET', 'path' => '/portal/widgets']);
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('response', $violations[0]);
    }

    public function test_typed_contract_violations_flags_missing_response_and_request_on_post(): void
    {
        $violations = typedContractViolations(['method' => 'POST', 'path' => '/portal/widgets']);
        $this->assertCount(2, $violations);
    }

    public function test_typed_contract_violations_does_not_require_request_on_get(): void
    {
        $violations = typedContractViolations([
            'method' => 'GET',
            'path' => '/portal/widgets',
            'response' => \Tests\Fixtures\Dto\CreateWidgetRequestFixtureDto::class,
        ]);
        $this->assertSame([], $violations);
    }

    public function test_typed_contract_violations_empty_when_fully_typed_post(): void
    {
        $violations = typedContractViolations([
            'method' => 'POST',
            'path' => '/portal/widgets',
            'response' => \Tests\Fixtures\Dto\CreateWidgetRequestFixtureDto::class,
            'request' => \Tests\Fixtures\Dto\CreateWidgetRequestFixtureDto::class,
        ]);
        $this->assertSame([], $violations);
    }

    public function test_typed_contract_violations_requires_request_on_put_patch_delete(): void
    {
        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $violations = typedContractViolations([
                'method' => $method,
                'path' => '/portal/widgets/{id}',
                'response' => \Tests\Fixtures\Dto\CreateWidgetRequestFixtureDto::class,
            ]);
            $this->assertCount(1, $violations, "expected exactly one violation (missing request) for $method");
            $this->assertStringContainsString('request', $violations[0]);
        }
    }

    // ── Router plumbing: `request:` reaches getRouteMeta() ─────────────

    public function test_router_threads_request_dto_through_get_route_meta(): void
    {
        $router = new \StoneScriptPHP\Routing\Router();
        $router->post(
            '/portal/tenant/{tenantId}/widgets',
            'CreateWidgetRoute',
            group: 'widgets',
            service: 'portal',
            request: \Tests\Fixtures\Dto\CreateWidgetRequestFixtureDto::class,
        );

        $meta = $router->getRouteMeta();
        $this->assertCount(1, $meta);
        $this->assertSame(\Tests\Fixtures\Dto\CreateWidgetRequestFixtureDto::class, $meta[0]['request']);
    }

    public function test_router_load_routes_array_format_threads_request_dto(): void
    {
        $router = new \StoneScriptPHP\Routing\Router();
        $router->loadRoutes([
            'POST' => [
                '/portal/tenant/{tenantId}/widgets' => [
                    'handler' => 'CreateWidgetRoute',
                    'service' => 'portal',
                    'group' => 'widgets',
                    'request' => \Tests\Fixtures\Dto\CreateWidgetRequestFixtureDto::class,
                ],
            ],
        ]);

        $meta = $router->getRouteMeta();
        $this->assertSame(\Tests\Fixtures\Dto\CreateWidgetRequestFixtureDto::class, $meta[0]['request']);
    }
}
