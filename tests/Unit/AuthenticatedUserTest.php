<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\AuthenticatedUser;

/**
 * Unit tests for AuthenticatedUser::fromPayload() — in particular the
 * change removing `role` from the
 * role_id/user_role fallback chain. A raw passport's `role` claim is now
 * always a fixed neutral sentinel (e.g. "authenticated"), never a real
 * application role, and must never leak into `role_id`/`user_role` even via
 * a permissive `??` fallback — see the class's own inline comment for why.
 *
 * @covers \StoneScriptPHP\Auth\AuthenticatedUser
 */
class AuthenticatedUserTest extends TestCase
{
    public function test_role_id_used_directly_when_present(): void
    {
        $user = AuthenticatedUser::fromPayload([
            'sub'       => 'identity-1',
            'tenant_id' => 'tenant-1',
            'role_id'   => 'owner',
        ]);

        $this->assertSame('owner', $user->role_id);
        $this->assertSame('owner', $user->user_role);
    }

    public function test_falls_back_to_legacy_user_role_when_role_id_absent(): void
    {
        $user = AuthenticatedUser::fromPayload([
            'sub'       => 'identity-1',
            'user_role' => 'cashier',
        ]);

        $this->assertSame('cashier', $user->role_id, 'role_id must fall back to legacy user_role');
        $this->assertSame('cashier', $user->user_role);
    }

    public function test_falls_back_to_first_element_of_legacy_roles_array(): void
    {
        $user = AuthenticatedUser::fromPayload([
            'sub'   => 'identity-1',
            'roles' => ['manager', 'staff'],
        ]);

        $this->assertSame('manager', $user->role_id);
    }

    /**
     * The regression test for the auth role-ownership change: a raw passport carrying auth's neutral
     * `role: "authenticated"` sentinel (or ANY value in `role`) must NEVER
     * populate role_id/user_role. Before this fix, `role` sat in the `??`
     * fallback chain and would have silently done exactly that the moment
     * fromPayload() was called on a passport rather than a card.
     */
    public function test_role_claim_is_never_used_as_a_role_id_or_user_role_fallback(): void
    {
        $user = AuthenticatedUser::fromPayload([
            'sub'  => 'identity-1',
            'role' => 'authenticated', // auth's fixed neutral sentinel value
        ]);

        $this->assertNull($user->role_id, 'role_id must NOT be populated from the passport role claim');
        $this->assertNull($user->user_role, 'user_role must NOT be populated from the passport role claim');
    }

    public function test_role_claim_present_alongside_real_role_id_does_not_get_used_either(): void
    {
        // Even when role_id IS present (a real card), `role` (if it somehow
        // also arrived) must never override or be consulted at all.
        $user = AuthenticatedUser::fromPayload([
            'sub'       => 'identity-1',
            'tenant_id' => 'tenant-1',
            'role_id'   => 'owner',
            'role'      => 'authenticated',
        ]);

        $this->assertSame('owner', $user->role_id);
    }

    public function test_no_role_claims_at_all_yields_null(): void
    {
        $user = AuthenticatedUser::fromPayload(['sub' => 'identity-1']);

        $this->assertNull($user->role_id);
        $this->assertNull($user->user_role);
    }

    public function test_is_card_true_only_when_tenant_id_present(): void
    {
        $passport = AuthenticatedUser::fromPayload(['sub' => 'identity-1']);
        $card     = AuthenticatedUser::fromPayload(['sub' => 'identity-1', 'tenant_id' => 'tenant-1']);

        $this->assertFalse($passport->isCard());
        $this->assertTrue($card->isCard());
    }

    public function test_role_is_excluded_from_custom_claims_bag_too(): void
    {
        // `role` is in the standardClaims allowlist regardless of whether it
        // feeds role_id — it must not leak into the generic custom-claims
        // bag either (customClaims is meant for genuinely platform-specific
        // extra claims, not this now-inert sentinel).
        $user = AuthenticatedUser::fromPayload([
            'sub'  => 'identity-1',
            'role' => 'authenticated',
        ]);

        $this->assertNull($user->getClaim('role'));
    }
}
