<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\BearerToken;

/**
 * Unit tests for the canonical BearerToken::strip() utility (v5.5.5).
 *
 * This class consolidates what used to be two independently-maintained
 * copies of the same "strip a leading Bearer prefix" regex — one in
 * AuthServiceClient::buildAuthHeader() (outbound normalization) and one in
 * BaseExternalAuthRoute::getBearerToken() (inbound extraction) — into a
 * single source of truth that both now delegate to, and that is public so
 * non-route callers (platform config closures, resolvers) can use it too.
 */
final class BearerTokenTest extends TestCase
{
    public function testBareTokenIsReturnedUnchanged(): void
    {
        $this->assertSame('sometoken', BearerToken::strip('sometoken'));
    }

    public function testStripsStandardBearerPrefix(): void
    {
        $this->assertSame('eyJ.header.payload', BearerToken::strip('Bearer eyJ.header.payload'));
    }

    public function testStripIsCaseInsensitive(): void
    {
        $this->assertSame('sometoken', BearerToken::strip('bearer sometoken'));
        $this->assertSame('sometoken', BearerToken::strip('BEARER sometoken'));
        $this->assertSame('sometoken', BearerToken::strip('BeArEr sometoken'));
    }

    public function testStripIsTolerantOfExtraWhitespace(): void
    {
        $this->assertSame('sometoken', BearerToken::strip('Bearer    sometoken'));
        $this->assertSame('sometoken', BearerToken::strip('  Bearer sometoken'));
    }

    public function testNullIsReturnedUnchanged(): void
    {
        $this->assertNull(BearerToken::strip(null));
    }

    public function testEmptyStringIsReturnedUnchanged(): void
    {
        $this->assertSame('', BearerToken::strip(''));
    }

    /**
     * Only a genuine LEADING "Bearer " prefix is stripped — a token that
     * merely contains the substring "bearer" mid-way through must pass
     * through untouched.
     */
    public function testTokenContainingBearerSubstringNotAtStartIsUntouched(): void
    {
        $this->assertSame('abcBearerXYZtoken', BearerToken::strip('abcBearerXYZtoken'));
    }

    /**
     * Idempotency: stripping an already-bare token a second time is a no-op.
     * This is what makes it safe for buildAuthHeader() to call strip() even
     * when the caller already passed a bare token (the normal/expected case).
     */
    public function testStripIsIdempotent(): void
    {
        $once = BearerToken::strip('Bearer sometoken');
        $twice = BearerToken::strip($once);
        $this->assertSame('sometoken', $once);
        $this->assertSame('sometoken', $twice);
    }
}
