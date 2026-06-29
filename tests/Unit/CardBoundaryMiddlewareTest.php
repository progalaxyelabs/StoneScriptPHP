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
 * Covers TENANCY-IDENTITY-MODEL §5 authorization invariants:
 *   §5.1 — tenant-less token MUST be rejected on any tenant-scoped route
 *   §5.2 — url.tenantId MUST equal card.tenant_id
 *
 * Task #3139
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

    public function test_url_tenant_matches_card_tenant_passes_through(): void
    {
        $middleware = new TenantUrlMatchMiddleware('tenantId');

        $request = [
            'jwt_claims' => ['tenant_id' => 'tenant-abc'],
            'params'     => ['tenantId'  => 'tenant-abc'],
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
        ];

        $passedThrough = false;
        $middleware->handle($request, function ($req) use (&$passedThrough) {
            $passedThrough = true;
            return null;
        });

        $this->assertTrue($passedThrough);
    }

    public function test_missing_url_param_returns_500_misconfigured(): void
    {
        $middleware = new TenantUrlMatchMiddleware('tenantId');

        $request = [
            'jwt_claims' => ['tenant_id' => 'tenant-abc'],
            'params'     => [],  // tenantId missing from route params
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
        ];

        $response = $middleware->handle($request, fn($r) => null);

        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertSame('error', $response->status);
        $this->assertSame(403, $response->httpStatusCode);
        $this->assertSame('tenant_context_required', $response->data['error']);
    }
}
