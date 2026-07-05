<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\Middleware\RequireCardMiddleware;

/**
 * Tests for RequireCardMiddleware — card-model global enforcement with public-route pass-through.
 *
 * Validates framework-spec.md §6 §5.1 + the public-route pass-through behaviour
 * that allows the exchange endpoint (and other explicitly excluded paths) to be
 * wired as global middleware without self-blocking.
 *
 * @covers \StoneScriptPHP\Auth\Middleware\RequireCardMiddleware
 */
class RequireCardMiddlewareTest extends TestCase
{
    private RequireCardMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new RequireCardMiddleware();
    }

    // ── Public-route pass-through ────────────────────────────────────────────────

    /**
     * Public routes (exchange, login, register, etc.) reach this middleware with NO
     * jwt_claims (JwtAuthMiddleware excluded them). They MUST pass through — the route
     * handles its own auth validation.
     */
    public function test_no_jwt_claims_passes_through_for_public_routes(): void
    {
        $request = [];  // no jwt_claims key at all

        $passedThrough = false;
        $next = function ($req) use (&$passedThrough) {
            $passedThrough = true;
            return null;
        };

        $this->middleware->handle($request, $next);

        $this->assertTrue(
            $passedThrough,
            'Public routes (no jwt_claims) must pass through RequireCardMiddleware'
        );
    }

    /**
     * Empty jwt_claims also passes through (consistent with absent key).
     */
    public function test_empty_jwt_claims_passes_through(): void
    {
        $request = ['jwt_claims' => []];

        $passedThrough = false;
        $next = function ($req) use (&$passedThrough) {
            $passedThrough = true;
            return null;
        };

        $this->middleware->handle($request, $next);

        $this->assertTrue($passedThrough, 'Empty jwt_claims should also pass through');
    }

    // ── Card tokens (tenant_id present) ─────────────────────────────────────────

    /**
     * A valid card (has tenant_id) MUST pass through to the route handler.
     */
    public function test_card_with_tenant_id_passes_through(): void
    {
        $request = [
            'jwt_claims' => [
                'identity_id' => 'id-abc',
                'tenant_id'   => 'tenant-uuid-123',
                'role_id'     => 'owner',
            ],
        ];

        $passedThrough = false;
        $next = function ($req) use (&$passedThrough) {
            $passedThrough = true;
            return null;
        };

        $this->middleware->handle($request, $next);

        $this->assertTrue($passedThrough, 'Card with tenant_id should pass through');
    }

    // ── Passport on business route → 403 ────────────────────────────────────────

    /**
     * A passport (identity JWT, no tenant_id) on a tenant-scoped route MUST be
     * rejected with 403 tenant_context_required (framework-spec.md §6 §5.1).
     *
     * This is the core guard: the exchange endpoint is excluded from JwtAuthMiddleware
     * so passports reach the exchange route normally. But if a client sends a passport
     * to a business route (e.g. GET /portal/warehouses), it gets 403 here.
     */
    public function test_passport_on_business_route_returns_403_tenant_context_required(): void
    {
        $request = [
            'jwt_claims' => [
                'identity_id' => 'id-xyz',
                'sub'         => 'id-xyz',
                // No tenant_id — this is a passport, not a card
            ],
        ];

        $next = fn($req) => null;  // should NOT be called
        $response = $this->middleware->handle($request, $next);

        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertSame('error', $response->status);
        $this->assertSame(403, $response->httpStatusCode);
        $this->assertSame('tenant_context_required', $response->data['error']);
    }

    /**
     * Claims with null tenant_id also yield 403 (defensive — treat null as missing).
     */
    public function test_null_tenant_id_in_claims_returns_403(): void
    {
        $request = [
            'jwt_claims' => [
                'identity_id' => 'id-1',
                'tenant_id'   => null,  // explicitly null
            ],
        ];

        $response = $this->middleware->handle($request, fn($r) => null);

        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertSame(403, $response->httpStatusCode);
        $this->assertSame('tenant_context_required', $response->data['error']);
    }

    // ── Contrast: difference from RequireTenantMiddleware ────────────────────────

    /**
     * RequireCardMiddleware differs from RequireTenantMiddleware in one key way:
     * when jwt_claims is absent, RequireTenantMiddleware returns 401, but
     * RequireCardMiddleware passes through.
     *
     * This test documents the intended difference — the public-route use case.
     */
    public function test_absent_claims_do_not_return_401_unlike_require_tenant_middleware(): void
    {
        $request = [];  // no claims — public route

        /** @var ApiResponse|null $response */
        $response = $this->middleware->handle($request, fn($r) => null);

        // RequireCardMiddleware passes through → callable returns null
        // (We pass fn($r) => null so response IS null if pass-through worked)
        $this->assertNull(
            $response,
            'RequireCardMiddleware must NOT return 401 on absent claims — it passes through (unlike RequireTenantMiddleware)'
        );
    }

    // ── Tenant-agnostic (tier-2) path exemption — 2026-07-05 regression fix ──────
    //
    // Regression coverage for the real fleet incident: RequireCardMiddleware, wired
    // globally with an empty exemption list, 403'd ExternalAuthRoutes' own tier-2
    // routes (provision-tenant, select-tenant, etc.) the moment JwtAuthMiddleware
    // started reliably populating jwt_claims for them. See framework-spec.md §5.5
    // and the class docblock for the full chain.

    /**
     * A passport on a path that IS in the exemption list must pass through —
     * this is the whole point of the fix (e.g. POST /api/auth/provision-tenant).
     */
    public function test_passport_on_exempt_tenant_agnostic_path_passes_through(): void
    {
        $middleware = new RequireCardMiddleware(['/api/auth/provision-tenant', '/api/auth/me']);

        $request = [
            'jwt_claims' => ['identity_id' => 'id-1', 'sub' => 'id-1'],  // passport, no tenant_id
            'route' => ['pattern' => '/api/auth/provision-tenant'],
        ];

        $passedThrough = false;
        $next = function ($req) use (&$passedThrough) {
            $passedThrough = true;
            return null;
        };

        $middleware->handle($request, $next);

        $this->assertTrue(
            $passedThrough,
            'A passport on an exempt tenant-agnostic path must pass through, not 403'
        );
    }

    /**
     * A passport on a path that is NOT in the exemption list must still 403 —
     * the exemption must be precise, not a blanket bypass. Guards against a lazy
     * fix that accidentally re-opens the exact hole RequireTenantMiddleware exists
     * to close.
     */
    public function test_passport_on_non_exempt_path_still_returns_403(): void
    {
        $middleware = new RequireCardMiddleware(['/api/auth/provision-tenant']);

        $request = [
            'jwt_claims' => ['identity_id' => 'id-1', 'sub' => 'id-1'],
            'route' => ['pattern' => '/portal/tenant/{tenantId}/dashboard'],
        ];

        $response = $middleware->handle($request, fn($r) => null);

        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertSame(403, $response->httpStatusCode);
        $this->assertSame('tenant_context_required', $response->data['error']);
    }

    /**
     * A passport with no 'route'/'pattern' key at all (should not normally happen —
     * this middleware always runs post-routing — but defensive) must still 403,
     * never silently pass through for lack of information to check against.
     */
    public function test_passport_with_no_route_pattern_still_returns_403(): void
    {
        $middleware = new RequireCardMiddleware(['/api/auth/provision-tenant']);

        $request = [
            'jwt_claims' => ['identity_id' => 'id-1', 'sub' => 'id-1'],
            // no 'route' key at all
        ];

        $response = $middleware->handle($request, fn($r) => null);

        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertSame(403, $response->httpStatusCode);
    }

    /**
     * Default construction (no argument) must reproduce the pre-fix, fully-strict
     * behavior — an empty exemption list means nothing is exempted, preserving
     * backward compatibility for any existing manual `new RequireCardMiddleware()`
     * call sites until they're migrated to pass the real list.
     */
    public function test_default_construction_has_empty_exemption_list(): void
    {
        $middleware = new RequireCardMiddleware();  // no args — legacy call shape

        $request = [
            'jwt_claims' => ['identity_id' => 'id-1', 'sub' => 'id-1'],
            'route' => ['pattern' => '/api/auth/provision-tenant'],
        ];

        $response = $middleware->handle($request, fn($r) => null);

        $this->assertInstanceOf(
            ApiResponse::class,
            $response,
            'Default (no-arg) construction must NOT exempt anything — matches documented legacy behavior'
        );
        $this->assertSame(403, $response->httpStatusCode);
    }

    // ── Next callable receives the request unchanged ─────────────────────────────

    /**
     * The request array passed to $next must not be mutated by this middleware.
     */
    public function test_request_is_passed_unchanged_to_next(): void
    {
        $original = [
            'jwt_claims' => [
                'identity_id' => 'id-1',
                'tenant_id'   => 'tenant-1',
                'role_id'     => 'cashier',
            ],
            'body' => ['some_param' => 'value'],
        ];

        $receivedRequest = null;
        $next = function ($req) use (&$receivedRequest) {
            $receivedRequest = $req;
            return null;
        };

        $this->middleware->handle($original, $next);

        $this->assertSame($original, $receivedRequest, 'RequireCardMiddleware must not mutate the request');
    }
}
