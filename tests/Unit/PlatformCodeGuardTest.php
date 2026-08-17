<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\PlatformCodeGuard;

/**
 * Unit tests for the shared PlatformCodeGuard rule — pins the decision table
 * documented on the class itself. Every identity-token-authenticated route
 * (ProvisionTenantRoute, ExchangeRoute, SelectTenantRoute, MembershipsRoute,
 * UpdateMembershipRoute, ChangePasswordRoute, ProfileRoute) and
 * InvitationCompletionService funnel through this single check.
 *
 * @covers \StoneScriptPHP\Auth\PlatformCodeGuard
 */
final class PlatformCodeGuardTest extends TestCase
{
    public function test_matching_claim_and_configured_code_is_ok(): void
    {
        $this->assertNull(PlatformCodeGuard::check('testapp', 'testapp'));
    }

    public function test_mismatched_claim_is_rejected(): void
    {
        $this->assertSame(PlatformCodeGuard::MISMATCH, PlatformCodeGuard::check('otherapp', 'testapp'));
    }

    public function test_missing_claim_with_configured_code_is_rejected(): void
    {
        $this->assertSame(PlatformCodeGuard::MISSING_CLAIM, PlatformCodeGuard::check(null, 'testapp'));
    }

    public function test_empty_string_claim_with_configured_code_is_rejected(): void
    {
        $this->assertSame(PlatformCodeGuard::MISSING_CLAIM, PlatformCodeGuard::check('', 'testapp'));
    }

    public function test_unconfigured_platform_code_is_reported_regardless_of_claim(): void
    {
        $this->assertSame(PlatformCodeGuard::UNCONFIGURED, PlatformCodeGuard::check('anything', null));
        $this->assertSame(PlatformCodeGuard::UNCONFIGURED, PlatformCodeGuard::check('anything', ''));
        $this->assertSame(PlatformCodeGuard::UNCONFIGURED, PlatformCodeGuard::check(null, ''));
    }

    public function test_isRejection_true_only_for_missing_claim_and_mismatch(): void
    {
        $this->assertTrue(PlatformCodeGuard::isRejection(PlatformCodeGuard::MISMATCH));
        $this->assertTrue(PlatformCodeGuard::isRejection(PlatformCodeGuard::MISSING_CLAIM));
        $this->assertFalse(PlatformCodeGuard::isRejection(PlatformCodeGuard::UNCONFIGURED));
        $this->assertFalse(PlatformCodeGuard::isRejection(PlatformCodeGuard::OK));
        $this->assertFalse(PlatformCodeGuard::isRejection(null));
    }

    public function test_case_sensitive_comparison(): void
    {
        // Platform codes are exact identifiers, not case-insensitive labels —
        // 'TestApp' must not silently equal 'testapp'.
        $this->assertSame(PlatformCodeGuard::MISMATCH, PlatformCodeGuard::check('TestApp', 'testapp'));
    }
}
