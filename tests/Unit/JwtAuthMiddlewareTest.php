<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\JwtHandlerInterface;
use StoneScriptPHP\Routing\Middleware\JwtAuthMiddleware;

/**
 * Tests for JwtAuthMiddleware — the framework's canonical, globally-wired JWT
 * authentication middleware (wired by Application::run() for every platform).
 *
 * ## Why this test file exists (framework security fix)
 *
 * JwtAuthMiddleware historically stored the authenticated user ONLY in
 * AuthContext::setUser(), and never populated $request['jwt_claims']. Every
 * guard middleware in the Auth\Middleware\* family (RequireApiTokenMiddleware,
 * RequireTenantMiddleware, RequireRoleMiddleware, RequireIssuerMiddleware,
 * TenantUrlMatchMiddleware) — plus RequestContextTrait used by controllers —
 * reads authorization state exclusively from $request['jwt_claims']. Because
 * that key was never set, every guard middleware always observed an empty/
 * absent jwt_claims and treated EVERY authenticated request as if it were an
 * unauthenticated public route, silently passing it through instead of
 * enforcing API-token/role/tenant/issuer requirements. Confirmed live: an
 * auth-token-only (tenant-less) token reached a tenant-scoped route handler
 * instead of getting a 403 tenant_context_required.
 *
 * The fix converges both middleware families on ONE populated field:
 * JwtAuthMiddleware now sets $request['jwt_claims'] = $payload from the SAME
 * validated payload used to build AuthContext's AuthenticatedUser, so the two
 * can never drift apart again. These tests lock that contract down.
 *
 * @covers \StoneScriptPHP\Routing\Middleware\JwtAuthMiddleware
 */
class JwtAuthMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        AuthContext::clear();
        $_SERVER['REQUEST_URI'] = '/portal/tenant/tenant-1/orders';
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

    // ── Core contract: jwt_claims is populated on success ───────────────────────

    /**
     * The core bug fix: a valid token must populate BOTH AuthContext AND
     * $request['jwt_claims'] so downstream Require* guards actually see it.
     */
    public function test_valid_card_token_populates_jwt_claims_on_request(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid-api-token';

        $payload = [
            'identity_id' => 'id-1',
            'sub'         => 'id-1',
            'tenant_id'   => 'tenant-1',
            'role_id'     => 'owner',
        ];

        $middleware = new JwtAuthMiddleware($this->fakeJwtHandler($payload));

        $request = ['route' => ['is_public' => false]];

        $receivedRequest = null;
        $next = function ($req) use (&$receivedRequest) {
            $receivedRequest = $req;
            return null;
        };

        $middleware->handle($request, $next);

        $this->assertNotNull($receivedRequest, 'next() must be called on valid token');
        $this->assertArrayHasKey(
            'jwt_claims',
            $receivedRequest,
            'JwtAuthMiddleware MUST populate $request[\'jwt_claims\'] — this is the exact contract ' .
            'every Require* guard middleware and RequestContextTrait read from. Without it, guards ' .
            'silently no-op.'
        );
        $this->assertSame($payload, $receivedRequest['jwt_claims']);
    }

    /**
     * AuthContext and $request['jwt_claims'] must be built from the SAME payload —
     * this is the anti-drift assertion. If a future change updates one path but not
     * the other, this test catches it.
     */
    public function test_auth_context_and_jwt_claims_agree_on_same_payload(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid-api-token';

        $payload = [
            'identity_id' => 'id-42',
            'sub'         => 'id-42',
            'tenant_id'   => 'tenant-42',
            'role_id'     => 'cashier',
        ];

        $middleware = new JwtAuthMiddleware($this->fakeJwtHandler($payload));

        $request = ['route' => ['is_public' => false]];
        $receivedRequest = null;
        $middleware->handle($request, function ($req) use (&$receivedRequest) {
            $receivedRequest = $req;
            return null;
        });

        $this->assertTrue(AuthContext::check(), 'AuthContext must be populated (used by auth()/GatewayTenantMiddleware)');
        $user = AuthContext::getUser();

        $this->assertSame($payload['identity_id'], $user->user_id);
        $this->assertSame($payload['tenant_id'], $user->tenant_id);
        $this->assertSame($payload['role_id'], $user->role_id);

        // Same source of truth: jwt_claims carries the identical raw claims.
        $this->assertSame($payload['tenant_id'], $receivedRequest['jwt_claims']['tenant_id']);
        $this->assertSame($payload['identity_id'], $receivedRequest['jwt_claims']['identity_id']);
    }

    /**
     * Auth (tenant-less) tokens also populate jwt_claims — WITHOUT a
     * tenant_id key. It's the downstream guard's job (RequireApiTokenMiddleware /
     * RequireTenantMiddleware) to reject auth tokens on tenant-scoped routes;
     * JwtAuthMiddleware's only job is faithfully passing the validated claims
     * through.
     */
    public function test_auth_token_populates_jwt_claims_without_tenant_id(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid-auth-token';

        $payload = [
            'identity_id' => 'id-1',
            'sub'         => 'id-1',
            // no tenant_id, no role_id — auth token
        ];

        $middleware = new JwtAuthMiddleware($this->fakeJwtHandler($payload));

        $request = ['route' => ['is_public' => false]];
        $receivedRequest = null;
        $middleware->handle($request, function ($req) use (&$receivedRequest) {
            $receivedRequest = $req;
            return null;
        });

        $this->assertArrayHasKey('jwt_claims', $receivedRequest);
        $this->assertArrayNotHasKey('tenant_id', $receivedRequest['jwt_claims']);
    }

    // ── Existing behaviour must remain intact ───────────────────────────────────

    public function test_public_route_passes_through_without_jwt_claims(): void
    {
        $middleware = new JwtAuthMiddleware($this->fakeJwtHandler(false));

        $request = ['route' => ['is_public' => true]];

        $receivedRequest = null;
        $middleware->handle($request, function ($req) use (&$receivedRequest) {
            $receivedRequest = $req;
            return null;
        });

        $this->assertNotNull($receivedRequest, 'Public route must pass through');
        $this->assertArrayNotHasKey('jwt_claims', $receivedRequest);
    }

    public function test_missing_token_returns_401(): void
    {
        $middleware = new JwtAuthMiddleware($this->fakeJwtHandler(false));

        $request = ['route' => ['is_public' => false]];
        $called = false;

        $response = $middleware->handle($request, function ($req) use (&$called) {
            $called = true;
            return null;
        });

        $this->assertFalse($called, 'next() must NOT be called without a token');
        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertSame('error', $response->status);
    }

    public function test_invalid_token_returns_401_and_does_not_set_claims(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer garbage-token';

        $middleware = new JwtAuthMiddleware($this->fakeJwtHandler(false));

        $request = ['route' => ['is_public' => false]];
        $called = false;

        $response = $middleware->handle($request, function ($req) use (&$called) {
            $called = true;
            return null;
        });

        $this->assertFalse($called);
        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertFalse(AuthContext::check(), 'AuthContext must remain empty on invalid token');
    }
}
