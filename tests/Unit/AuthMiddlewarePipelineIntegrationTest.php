<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\JwtHandlerInterface;
use StoneScriptPHP\Auth\Middleware\RequireApiTokenMiddleware;
use StoneScriptPHP\Auth\Middleware\RequireRoleMiddleware;
use StoneScriptPHP\Auth\Middleware\RequireTenantMiddleware;
use StoneScriptPHP\Auth\Middleware\TenantUrlMatchMiddleware;
use StoneScriptPHP\Routing\Middleware\JwtAuthMiddleware;
use StoneScriptPHP\Routing\MiddlewarePipeline;

/**
 * Integration test: JwtAuthMiddleware → Require* guards, composed exactly as
 * Application::run() wires them for every platform.
 *
 * This is the end-to-end proof for the fix: before the change, JwtAuthMiddleware
 * never populated $request['jwt_claims'], so every guard middleware downstream
 * (RequireApiTokenMiddleware, RequireTenantMiddleware, RequireRoleMiddleware,
 * TenantUrlMatchMiddleware) silently no-op'd — an auth-token-only (tenant-less)
 * token reached tenant-scoped route handlers instead of getting rejected.
 *
 * @covers \StoneScriptPHP\Routing\Middleware\JwtAuthMiddleware
 * @covers \StoneScriptPHP\Auth\Middleware\RequireApiTokenMiddleware
 * @covers \StoneScriptPHP\Auth\Middleware\RequireTenantMiddleware
 * @covers \StoneScriptPHP\Auth\Middleware\RequireRoleMiddleware
 * @covers \StoneScriptPHP\Auth\Middleware\TenantUrlMatchMiddleware
 */
class AuthMiddlewarePipelineIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        AuthContext::clear();
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        AuthContext::clear();
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    private function fakeJwtHandler(array|false $verifyResult): JwtHandlerInterface
    {
        return new class ($verifyResult) implements JwtHandlerInterface {
            public function __construct(private array|false $result)
            {
            }

            public function generateToken(array $payload, int $expiryDays = 30): string
            {
                return 'fake-token';
            }

            public function verifyToken(string $token): array|false
            {
                return $this->result;
            }
        };
    }

    /**
     * THE live repro from the integration session (2026-07-04), reproduced and
     * proven fixed: an auth-token-only token (no tenant_id) hitting an API-token-required
     * route via the exact JwtAuthMiddleware → RequireApiTokenMiddleware chain
     * Application::run() wires must now get 403 tenant_context_required — not
     * reach the handler.
     */
    public function test_auth_token_only_hitting_api_token_required_route_gets_403_not_handler(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer auth-token';
        $_SERVER['REQUEST_URI'] = '/portal/tenant/tenant-1/orders';

        $payload = ['identity_id' => 'id-1', 'sub' => 'id-1']; // auth token: no tenant_id

        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new JwtAuthMiddleware($this->fakeJwtHandler($payload)));
        $pipeline->pipe(new RequireApiTokenMiddleware());

        $handlerReached = false;
        $request = ['route' => ['is_public' => false]];

        $response = $pipeline->process($request, function ($req) use (&$handlerReached) {
            $handlerReached = true;
            return new ApiResponse('ok', 'should never reach here');
        });

        $this->assertFalse(
            $handlerReached,
            'BUG: auth-token-only token reached the tenant-scoped route handler instead of being 403\'d'
        );
        $this->assertSame('error', $response->status);
        $this->assertSame(403, $response->httpStatusCode);
        $this->assertSame('tenant_context_required', $response->data['error']);
    }

    /**
     * A valid API token on its OWN tenant route → 200 (handler reached).
     */
    public function test_valid_api_token_on_own_tenant_route_reaches_handler(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer api-token';
        $_SERVER['REQUEST_URI'] = '/portal/tenant/tenant-1/orders';

        $payload = [
            'identity_id' => 'id-1',
            'sub'         => 'id-1',
            'tenant_id'   => 'tenant-1',
            'role_id'     => 'owner',
        ];

        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new JwtAuthMiddleware($this->fakeJwtHandler($payload)));
        $pipeline->pipe(new RequireApiTokenMiddleware());
        $pipeline->pipe(new TenantUrlMatchMiddleware('tenantId'));

        $handlerReached = false;
        $request = [
            'route'  => ['is_public' => false, 'pattern' => '/portal/tenant/{tenantId}/orders'],
            'params' => ['tenantId' => 'tenant-1'],
        ];

        $response = $pipeline->process($request, function ($req) use (&$handlerReached) {
            $handlerReached = true;
            return new ApiResponse('ok', 'reached');
        });

        $this->assertTrue($handlerReached, 'Valid API token on its own tenant route must reach the handler');
        $this->assertSame('ok', $response->status);
    }

    /**
     * A valid API token on a FOREIGN tenant route (URL tenantId != API token tenant_id)
     * → 403 tenant_mismatch, handler never reached.
     */
    public function test_valid_api_token_on_foreign_tenant_route_returns_403(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer api-token';
        $_SERVER['REQUEST_URI'] = '/portal/tenant/tenant-999/orders';

        $payload = [
            'identity_id' => 'id-1',
            'sub'         => 'id-1',
            'tenant_id'   => 'tenant-1', // API token belongs to tenant-1
            'role_id'     => 'owner',
        ];

        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new JwtAuthMiddleware($this->fakeJwtHandler($payload)));
        $pipeline->pipe(new RequireApiTokenMiddleware());
        $pipeline->pipe(new TenantUrlMatchMiddleware('tenantId'));

        $handlerReached = false;
        $request = [
            'route'  => ['is_public' => false, 'pattern' => '/portal/tenant/{tenantId}/orders'],
            'params' => ['tenantId' => 'tenant-999'], // URL says a DIFFERENT tenant
        ];

        $response = $pipeline->process($request, function ($req) use (&$handlerReached) {
            $handlerReached = true;
            return new ApiResponse('ok', 'should never reach here');
        });

        $this->assertFalse($handlerReached, 'Foreign-tenant URL must never reach the handler');
        $this->assertSame(403, $response->httpStatusCode);
        $this->assertSame('tenant_mismatch', $response->data['error']);
    }

    /**
     * Flat/non-tenant routes (health, webhooks, admin/auth) are unaffected by
     * TenantUrlMatchMiddleware even when wired globally — it self-skips because
     * the route pattern carries no {tenantId} segment. No 500s.
     */
    public function test_flat_non_tenant_route_unaffected_by_global_tenant_url_match(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer api-token';
        $_SERVER['REQUEST_URI'] = '/health';

        $payload = ['identity_id' => 'id-1', 'sub' => 'id-1', 'tenant_id' => 'tenant-1'];

        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new JwtAuthMiddleware($this->fakeJwtHandler($payload)));
        $pipeline->pipe(new TenantUrlMatchMiddleware('tenantId')); // wired globally, no {tenantId} on this route

        $handlerReached = false;
        $request = [
            'route'  => ['is_public' => true, 'pattern' => '/health'],
            'params' => [],
        ];

        $response = $pipeline->process($request, function ($req) use (&$handlerReached) {
            $handlerReached = true;
            return new ApiResponse('ok', 'healthy');
        });

        $this->assertTrue($handlerReached, 'Flat /health route must not be blocked by TenantUrlMatchMiddleware');
        $this->assertSame('ok', $response->status);
    }

    /**
     * RequireRoleMiddleware also now actually enforces once jwt_claims is populated —
     * insufficient role → 403, correct role → handler reached.
     */
    public function test_require_role_middleware_enforces_once_claims_are_populated(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer api-token';
        $_SERVER['REQUEST_URI'] = '/admin/tenant/tenant-1/settings';

        $payload = [
            'identity_id' => 'id-1',
            'sub'         => 'id-1',
            'tenant_id'   => 'tenant-1',
            'role_id'     => 'cashier', // insufficient
        ];

        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new JwtAuthMiddleware($this->fakeJwtHandler($payload)));
        $pipeline->pipe(new RequireRoleMiddleware(['owner', 'admin']));

        $handlerReached = false;
        $request = ['route' => ['is_public' => false]];

        $response = $pipeline->process($request, function ($req) use (&$handlerReached) {
            $handlerReached = true;
            return new ApiResponse('ok', 'reached');
        });

        $this->assertFalse($handlerReached, 'Insufficient role must be rejected, not reach the handler');
        $this->assertSame('error', $response->status);
        // RequireRoleMiddleware sets the HTTP status via http_response_code() rather
        // than the ApiResponse object (existing framework behaviour, unchanged by
        // this fix) — assert on the actual emitted status code.
        $this->assertSame(403, http_response_code());
    }

    /**
     * The regression proof for the 2026-07-23 fix (see RequireRoleMiddleware's
     * own docblock): before it, this middleware checked `$claims['role']`,
     * which no real API token ever carries (API tokens stamp `role_id`) — so EVERY API token
     * holder, including one with a sufficient role, was unconditionally
     * 403'd. This test would have failed before the fix regardless of the
     * role_id's value; it must pass now that role_id is checked correctly.
     */
    public function test_require_role_middleware_allows_sufficient_role_through(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer api-token';
        $_SERVER['REQUEST_URI'] = '/admin/tenant/tenant-1/settings';

        $payload = [
            'identity_id' => 'id-1',
            'sub'         => 'id-1',
            'tenant_id'   => 'tenant-1',
            'role_id'     => 'owner', // sufficient
        ];

        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new JwtAuthMiddleware($this->fakeJwtHandler($payload)));
        $pipeline->pipe(new RequireRoleMiddleware(['owner', 'admin']));

        $handlerReached = false;
        $request = ['route' => ['is_public' => false]];

        $response = $pipeline->process($request, function ($req) use (&$handlerReached) {
            $handlerReached = true;
            return new ApiResponse('ok', 'reached');
        });

        $this->assertTrue($handlerReached, 'Sufficient role_id must reach the handler, not be 403d');
        $this->assertSame('ok', $response->status);
    }

    /**
     * RequireTenantMiddleware (strict per-route variant, 401 on absent claims)
     * also enforces correctly through the real JwtAuthMiddleware chain.
     */
    public function test_require_tenant_middleware_enforces_through_real_chain(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer auth-token';
        $_SERVER['REQUEST_URI'] = '/portal/tenant/tenant-1/orders';

        $payload = ['identity_id' => 'id-1', 'sub' => 'id-1']; // auth token

        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new JwtAuthMiddleware($this->fakeJwtHandler($payload)));
        $pipeline->pipe(new RequireTenantMiddleware());

        $handlerReached = false;
        $request = ['route' => ['is_public' => false]];

        $response = $pipeline->process($request, function ($req) use (&$handlerReached) {
            $handlerReached = true;
            return new ApiResponse('ok', 'reached');
        });

        $this->assertFalse($handlerReached);
        $this->assertSame(403, $response->httpStatusCode);
        $this->assertSame('tenant_context_required', $response->data['error']);
    }
}
