<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\CookieHelper;

/**
 * Regression/feature guard for TESTABILITY-SPEC.md T1-1: CookieHelper's
 * read methods (getRefreshToken/getCsrfToken) accept an optional cookie map
 * instead of always reading $_COOKIE directly, so cookie-based auth flows
 * (refresh-token cookie mode, CSRF double-submit) can be unit tested without
 * mutating the real superglobal. Default (no argument) behavior — every
 * existing call site in CsrfHelper/RefreshRoute/LogoutRoute — is unchanged.
 */
class CookieHelperInjectionTest extends TestCase
{
    private ?array $originalCookieSuperglobal = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalCookieSuperglobal = $_COOKIE;
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->originalCookieSuperglobal ?? [];
        parent::tearDown();
    }

    public function test_get_refresh_token_reads_injected_cookie_map(): void
    {
        // Deliberately set $_COOKIE to a DIFFERENT value to prove the injected
        // map wins, not the superglobal.
        $_COOKIE = ['refresh_token' => 'from-superglobal'];

        $token = CookieHelper::getRefreshToken(['refresh_token' => 'injected-token']);

        $this->assertSame('injected-token', $token);
    }

    public function test_get_refresh_token_falls_back_to_superglobal_when_no_map_given(): void
    {
        $_COOKIE = ['refresh_token' => 'from-superglobal'];

        $token = CookieHelper::getRefreshToken(); // no arg — existing call sites use this form

        $this->assertSame('from-superglobal', $token);
    }

    public function test_get_refresh_token_returns_null_when_absent_from_injected_map(): void
    {
        $token = CookieHelper::getRefreshToken([]);

        $this->assertNull($token);
    }

    public function test_get_csrf_token_reads_injected_cookie_map(): void
    {
        $_COOKIE = ['csrf_token' => 'from-superglobal'];

        $token = CookieHelper::getCsrfToken(['csrf_token' => 'injected-csrf']);

        $this->assertSame('injected-csrf', $token);
    }

    public function test_get_csrf_token_falls_back_to_superglobal_when_no_map_given(): void
    {
        $_COOKIE = ['csrf_token' => 'from-superglobal'];

        $token = CookieHelper::getCsrfToken();

        $this->assertSame('from-superglobal', $token);
    }

    public function test_get_csrf_token_returns_null_when_absent_from_injected_map(): void
    {
        $token = CookieHelper::getCsrfToken([]);

        $this->assertNull($token);
    }
}
