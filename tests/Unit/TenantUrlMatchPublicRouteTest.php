<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\Middleware\TenantUrlMatchMiddleware;

/**
 * TenantUrlMatchMiddleware honours the matched route's declared access:
 * public routes are exempt, every other route still fails closed.
 */
final class TenantUrlMatchPublicRouteTest extends TestCase
{
    private const PATTERN = '/portal/tenant/{tenantId}/invitations/{token}';

    private function request(array $route, array $claims = [], string $urlTenant = 'tenant-a'): array
    {
        $req = [
            'route'  => ['pattern' => self::PATTERN] + $route,
            'params' => ['tenantId' => $urlTenant, 'token' => 'abc'],
        ];
        if ($claims !== []) {
            $req['jwt_claims'] = $claims;
        }
        return $req;
    }

    private function pass(array $request, &$reached): ?ApiResponse
    {
        $reached = false;
        return (new TenantUrlMatchMiddleware())->handle($request, function () use (&$reached) {
            $reached = true;
            return null;
        });
    }

    public function test_public_access_route_without_token_passes(): void
    {
        $r = $this->pass($this->request(['access' => 'public', 'is_public' => true]), $reached);
        $this->assertNull($r);
        $this->assertTrue($reached);
    }

    public function test_public_route_with_a_token_for_another_tenant_passes(): void
    {
        $r = $this->pass($this->request(['access' => 'public', 'is_public' => true], ['tenant_id' => 'tenant-b']), $reached);
        $this->assertNull($r);
        $this->assertTrue($reached);
    }

    public function test_legacy_is_public_flag_without_access_is_public(): void
    {
        $this->pass($this->request(['is_public' => true]), $reached);
        $this->assertTrue($reached);
    }

    public function test_authorization_route_without_tenant_claim_is_refused(): void
    {
        $r = $this->pass($this->request(['access' => 'authorization', 'is_public' => false], ['sub' => 'u1']), $reached);
        $this->assertFalse($reached);
        $this->assertSame(403, $r->httpStatusCode);
        $this->assertSame('tenant_context_required', $r->data['error']);
    }

    public function test_authentication_route_with_mismatching_tenant_is_refused(): void
    {
        $r = $this->pass($this->request(['access' => 'authentication'], ['tenant_id' => 'tenant-b']), $reached);
        $this->assertFalse($reached);
        $this->assertSame('tenant_mismatch', $r->data['error']);
    }

    public function test_matching_tenant_on_protected_route_passes(): void
    {
        $this->pass($this->request(['access' => 'authorization'], ['tenant_id' => 'tenant-a']), $reached);
        $this->assertTrue($reached);
    }

    public function test_route_metadata_without_access_information_fails_closed(): void
    {
        $r = $this->pass($this->request([]), $reached);
        $this->assertFalse($reached);
        $this->assertSame('tenant_context_required', $r->data['error']);
    }

    public function test_access_wins_over_a_stale_is_public_flag(): void
    {
        $r = $this->pass($this->request(['access' => 'authorization', 'is_public' => true]), $reached);
        $this->assertFalse($reached);
        $this->assertSame(403, $r->httpStatusCode);
    }
}
