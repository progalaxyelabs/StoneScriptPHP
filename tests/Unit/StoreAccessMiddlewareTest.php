<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Routing\Middleware\StoreAccessMiddleware;
use StoneScriptPHP\ApiResponse;

/**
 * StoreAccessMiddleware — tenant-scope detection + FAIL-CLOSED behavior (v4.0.1).
 *
 * Pins the security guard mgr required for the storeId->tenantId rename: on a
 * canonical tenant-scoped route (/{service}/tenant/{tenantId}/...), if the
 * {tenantId} param does not resolve, the middleware must DENY (403) — never
 * silently pass through. Also pins that non-tenant routes pass through and that
 * deep `/tenant/` paths (e.g. /api/auth/tenant/{slug}/info) are NOT treated as
 * tenant-scoped.
 *
 * The membership-check happy path makes an HTTP call to the auth service, so it
 * is covered by integration/E2E, not here. These cases all return before any
 * network call.
 */
class StoreAccessMiddlewareTest extends TestCase
{
    private function mw(): StoreAccessMiddleware
    {
        return new StoreAccessMiddleware([
            'auth_service_url' => 'http://auth.invalid',
            'platform_code'    => 'testplatform',
        ]);
    }

    /** @param array $route */
    private function request(array $route, array $params): array
    {
        return [
            'method'  => 'GET',
            'path'    => '/x',
            'params'  => $params,
            'headers' => [],
            'route'   => $route,
        ];
    }

    public function test_fail_closed_when_tenant_scoped_but_tenantId_param_missing(): void
    {
        $called = false;
        $next = function ($req) use (&$called) { $called = true; return new ApiResponse('ok', 'passed', null, 200); };

        // Canonical tenant-scoped pattern, but params has NO tenantId (the drift bug).
        $req = $this->request(
            ['pattern' => '/portal/tenant/{tenantId}/profile', 'service' => 'portal'],
            [] // no tenantId resolved
        );

        $res = $this->mw()->handle($req, $next);

        $this->assertFalse($called, 'fail-closed must NOT call $next');
        $this->assertInstanceOf(ApiResponse::class, $res);
        $this->assertSame('error', $res->status);
        $this->assertSame(403, $res->httpStatusCode);
    }

    public function test_fail_closed_on_param_name_drift_storeId(): void
    {
        $called = false;
        $next = function ($req) use (&$called) { $called = true; return new ApiResponse('ok', 'passed', null, 200); };

        // Pattern uses the OLD param name {storeId} inside the canonical /tenant/ slot.
        // The canonical contract requires `tenantId`; anything else fails closed.
        $req = $this->request(
            ['pattern' => '/portal/tenant/{storeId}/profile', 'service' => 'portal'],
            ['storeId' => '123'] // resolved, but wrong name
        );

        $res = $this->mw()->handle($req, $next);

        $this->assertFalse($called, 'param-name drift must fail closed');
        $this->assertSame('error', $res->status);
    }

    public function test_non_tenant_route_passes_through(): void
    {
        $called = false;
        $next = function ($req) use (&$called) { $called = true; return new ApiResponse('ok', 'passed', null, 200); };

        $req = $this->request(['pattern' => '/health', 'service' => 'shared'], []);
        $res = $this->mw()->handle($req, $next);

        $this->assertTrue($called, 'non-tenant route must pass through');
        $this->assertSame('passed', $res->message);
    }

    public function test_deep_tenant_segment_is_not_treated_as_tenant_scoped(): void
    {
        $called = false;
        $next = function ($req) use (&$called) { $called = true; return new ApiResponse('ok', 'passed', null, 200); };

        // /api/auth/tenant/{slug}/info — `/tenant/` is the THIRD segment, not the
        // canonical second-segment shape, so it must pass through (not fail closed).
        $req = $this->request(
            ['pattern' => '/api/auth/tenant/{slug}/info', 'service' => 'shared'],
            ['slug' => 'acme']
        );
        $res = $this->mw()->handle($req, $next);

        $this->assertTrue($called, 'deep /tenant/ path must not be treated as tenant-scoped');
        $this->assertSame('passed', $res->message);
    }
}
