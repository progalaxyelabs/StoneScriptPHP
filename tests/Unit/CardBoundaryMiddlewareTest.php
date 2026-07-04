<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\Middleware\RequireTenantMiddleware;
use StoneScriptPHP\Auth\Middleware\TenantUrlMatchMiddleware;

/**
 * Tests for card model boundary enforcement middleware.
 *
 * Covers framework-spec.md §6 authorization invariants:
 *   §5.1 — tenant-less token MUST be rejected on any tenant-scoped route
 *   §5.2 — url.tenantId MUST equal card.tenant_id
 */
class CardBoundaryMiddlewareTest extends TestCase
{
    // ── RequireTenantMiddleware (§5.1) ────────────────────────────────────────

    public function test_tenant_less_passport_rejected_with_tenant_context_required(): void
    {
        $middleware = new RequireTenantMiddleware();

        // Simulate a passport token: no tenant_id in claims
        $request = [
            'jwt_claims' => ['identity_id' => 'id-1', 'sub' => 'id-1'],
        ];

        $next = fn($req) => null;  // should not be called
        $response = $middleware->handle($request, $next);

        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertSame('error', $response->status);
        $this->assertSame(403, $response->httpStatusCode);
        $this->assertSame('tenant_context_required', $response->data['error']);
    }

    public function test_card_token_with_tenant_id_passes_through(): void
    {
        $middleware = new RequireTenantMiddleware();

        // Simulate a card token: has tenant_id
        $request = [
            'jwt_claims' => [
                'identity_id' => 'id-1',
                'tenant_id'   => 'tenant-uuid-123',
                'role_id'     => 'owner',
            ],
        ];

        $passedThrough = false;
        $next = function ($req) use (&$passedThrough) {
            $passedThrough = true;
            return null;
        };

        $middleware->handle($request, $next);

        $this->assertTrue($passedThrough, 'Card token with tenant_id should pass through');
    }

    public function test_missing_jwt_claims_returns_401(): void
    {
        $middleware = new RequireTenantMiddleware();

        // No jwt_claims at all — not authenticated
        $request = [];

        $response = $middleware->handle($request, fn($r) => null);

        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertSame('error', $response->status);
        $this->assertSame(401, $response->httpStatusCode);
    }

    // ── TenantUrlMatchMiddleware (§5.2) ───────────────────────────────────────
    //
    // NOTE: TenantUrlMatchMiddleware now self-skips based on the
    // MATCHED ROUTE PATTERN ($request['route']['pattern']) rather than blindly
    // requiring the URL param — this is what makes it safe to wire globally via
    // Application::run()'s 'tenant_url_match' config without 500ing flat routes
    // (health, webhooks, admin auth). Every test below therefore sets a
    // 'route' => ['pattern' => ...] that DOES declare the tenant param, so the
    // self-skip guard doesn't short-circuit the actual enforcement being tested.
    // Self-skip behaviour itself is covered by the dedicated tests further down.

    public function test_url_tenant_matches_card_tenant_passes_through(): void
    {
        $middleware = new TenantUrlMatchMiddleware('tenantId');

        $request = [
            'jwt_claims' => ['tenant_id' => 'tenant-abc'],
            'params'     => ['tenantId'  => 'tenant-abc'],
            'route'      => ['pattern' => '/portal/tenant/{tenantId}/orders'],
        ];

        $passedThrough = false;
        $next = function ($req) use (&$passedThrough) {
            $passedThrough = true;
            return null;
        };

        $middleware->handle($request, $next);

        $this->assertTrue($passedThrough, 'Matching tenant ID should pass through');
    }

    public function test_url_tenant_different_from_card_tenant_rejected_403(): void
    {
        $middleware = new TenantUrlMatchMiddleware('tenantId');

        $request = [
            'jwt_claims' => ['tenant_id' => 'tenant-abc'],  // card says abc
            'params'     => ['tenantId'  => 'tenant-xyz'],  // URL says xyz → mismatch!
            'route'      => ['pattern' => '/portal/tenant/{tenantId}/orders'],
        ];

        $response = $middleware->handle($request, fn($r) => null);

        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertSame('error', $response->status);
        $this->assertSame(403, $response->httpStatusCode);
        $this->assertSame('tenant_mismatch', $response->data['error']);
    }

    public function test_custom_url_param_name_is_respected(): void
    {
        $middleware = new TenantUrlMatchMiddleware('orgId');  // custom param name

        $request = [
            'jwt_claims' => ['tenant_id' => 'org-1'],
            'params'     => ['orgId'     => 'org-1'],  // different param name
            'route'      => ['pattern' => '/portal/org/{orgId}/dashboard'],
        ];

        $passedThrough = false;
        $middleware->handle($request, function ($req) use (&$passedThrough) {
            $passedThrough = true;
            return null;
        });

        $this->assertTrue($passedThrough);
    }

    /**
     * The route PATTERN declares {tenantId}, but the router failed to resolve
     * it into $request['params'] — a genuine bug (param-name drift, regex
     * mismatch), not an unrelated flat route. Must still fail closed 500.
     */
    public function test_missing_url_param_returns_500_misconfigured(): void
    {
        $middleware = new TenantUrlMatchMiddleware('tenantId');

        $request = [
            'jwt_claims' => ['tenant_id' => 'tenant-abc'],
            'params'     => [],  // tenantId missing from route params
            'route'      => ['pattern' => '/portal/tenant/{tenantId}/orders'], // pattern DOES declare it
        ];

        $response = $middleware->handle($request, fn($r) => null);

        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertSame('error', $response->status);
        $this->assertSame(500, $response->httpStatusCode);
        $this->assertSame('middleware_misconfigured', $response->data['error']);
    }

    public function test_no_tenant_in_card_returns_403(): void
    {
        $middleware = new TenantUrlMatchMiddleware();

        $request = [
            'jwt_claims' => ['identity_id' => 'id-1'],  // no tenant_id — passport
            'params'     => ['tenantId' => 'some-tenant'],
            'route'      => ['pattern' => '/portal/tenant/{tenantId}/orders'],
        ];

        $response = $middleware->handle($request, fn($r) => null);

        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertSame('error', $response->status);
        $this->assertSame(403, $response->httpStatusCode);
        $this->assertSame('tenant_context_required', $response->data['error']);
    }

    // ── Self-skip: safe to wire globally (scope-aware gap closure) ──

    /**
     * A route whose pattern carries NO {tenantId} segment (e.g. /health,
     * /admin/auth/login) must be passed through untouched, even with no
     * jwt_claims at all — this is what makes wiring TenantUrlMatchMiddleware
     * GLOBALLY (Application::run()'s 'tenant_url_match' config) safe.
     */
    public function test_route_without_tenant_param_in_pattern_self_skips(): void
    {
        $middleware = new TenantUrlMatchMiddleware('tenantId');

        $request = [
            // no jwt_claims at all — public route
            'params' => [],
            'route'  => ['pattern' => '/health'],
        ];

        $passedThrough = false;
        $middleware->handle($request, function ($req) use (&$passedThrough) {
            $passedThrough = true;
            return null;
        });

        $this->assertTrue($passedThrough, 'Non-tenant route pattern must self-skip, never 500 or 403');
    }

    /**
     * Same self-skip applies even when the request DOES carry jwt_claims with a
     * tenant_id (e.g. a card token hitting an admin/auth-only endpoint) — the
     * route simply isn't tenant-scoped, so the middleware has nothing to check.
     */
    public function test_non_tenant_route_self_skips_even_with_card_claims_present(): void
    {
        $middleware = new TenantUrlMatchMiddleware('tenantId');

        $request = [
            'jwt_claims' => ['tenant_id' => 'tenant-abc'],
            'params'     => [],
            'route'      => ['pattern' => '/admin/settings'],
        ];

        $passedThrough = false;
        $middleware->handle($request, function ($req) use (&$passedThrough) {
            $passedThrough = true;
            return null;
        });

        $this->assertTrue($passedThrough);
    }

    /**
     * No 'route' key at all (e.g. a hand-rolled request array in older test
     * code, or a middleware chain that runs before routing) must also self-skip
     * rather than crash or fail closed — defensive default.
     */
    public function test_absent_route_key_self_skips(): void
    {
        $middleware = new TenantUrlMatchMiddleware('tenantId');

        $request = [
            'jwt_claims' => ['tenant_id' => 'tenant-abc'],
            'params'     => ['tenantId' => 'tenant-abc'],
            // no 'route' key
        ];

        $passedThrough = false;
        $middleware->handle($request, function ($req) use (&$passedThrough) {
            $passedThrough = true;
            return null;
        });

        $this->assertTrue($passedThrough);
    }
}
