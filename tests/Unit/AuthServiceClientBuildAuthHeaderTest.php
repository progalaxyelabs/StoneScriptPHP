<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\Client\AuthServiceClient;

/**
 * Regression test for AuthServiceClient::buildAuthHeader() (2026-07-02).
 *
 * ## The bug
 *
 * Every AuthServiceClient method that forwards an inbound token (e.g.
 * ExternalAuthServiceClient::getMemberships()) calls buildAuthHeader($token),
 * which ALWAYS prepended "Bearer " unconditionally. Callers are expected to
 * pass a *bare* token — matching BaseExternalAuthRoute::getBearerToken(),
 * which strips the "Bearer " prefix before handing the raw token onward.
 *
 * In practice, several downstream platforms' tenants_resolver closures read
 * $_SERVER['HTTP_AUTHORIZATION'] directly — which already carries the
 * "Bearer " prefix as sent by the client — and forwarded that raw value
 * straight into getMemberships(). buildAuthHeader() then re-prepended
 * "Bearer ", producing "Authorization: Bearer Bearer <token>" on the
 * outbound call to the auth service. The auth service's bearer-parsing only
 * strips a single "Bearer " prefix, so the "token" it tries to verify is
 * itself "Bearer <token>" — an invalid JWT shape — verification fails, and
 * the auth service returns 401. Callers that catch the resulting
 * AuthServiceException (as every one of those tenants_resolver closures
 * does, treating any failure as "no tenants") silently returned an empty
 * tenants list. Downstream, ExchangeRoute then reported this as
 * 403 tenant_access_denied — for an identity that verifiably DOES have an
 * active, correct membership row in the database (a live production bug
 * caught during triage).
 *
 * ## The fix
 *
 * buildAuthHeader() now strips any pre-existing "Bearer " prefix (case
 * insensitive, tolerant of extra whitespace) before adding it back — making
 * it correct regardless of whether the caller passes a bare token or a raw
 * header value that already has the prefix. This is a single framework-level
 * fix: every platform's tenants_resolver (and any other caller of
 * getMemberships()/similar) is corrected automatically on the next
 * `composer update`, with zero platform-code changes required — no need to
 * duplicate a stripping helper into each of the 6+ affected platform repos.
 */
final class AuthServiceClientBuildAuthHeaderTest extends TestCase
{
    private function client(): TestableAuthServiceClient
    {
        return new TestableAuthServiceClient('http://localhost:1');
    }

    public function testBareTokenGetsSingleBearerPrefix(): void
    {
        $header = $this->client()->publicBuildAuthHeader('sometoken');
        $this->assertSame(['Authorization: Bearer sometoken'], $header);
    }

    public function testRawHeaderValueWithBearerPrefixIsNotDoubled(): void
    {
        // Exactly what $_SERVER['HTTP_AUTHORIZATION'] holds when a client
        // sends "Authorization: Bearer <token>" — the bug scenario.
        $header = $this->client()->publicBuildAuthHeader('Bearer eyJ.header.payload');
        $this->assertSame(['Authorization: Bearer eyJ.header.payload'], $header);
        $this->assertStringNotContainsString('Bearer Bearer', $header[0]);
    }

    public function testPrefixStrippingIsCaseInsensitive(): void
    {
        $header = $this->client()->publicBuildAuthHeader('bearer sometoken');
        $this->assertSame(['Authorization: Bearer sometoken'], $header);
    }

    public function testPrefixStrippingTolerantOfExtraWhitespace(): void
    {
        $header = $this->client()->publicBuildAuthHeader('Bearer    sometoken');
        $this->assertSame(['Authorization: Bearer sometoken'], $header);
    }

    public function testNullTokenReturnsEmptyHeaderArray(): void
    {
        $this->assertSame([], $this->client()->publicBuildAuthHeader(null));
    }

    public function testEmptyStringTokenReturnsEmptyHeaderArray(): void
    {
        $this->assertSame([], $this->client()->publicBuildAuthHeader(''));
    }

    /**
     * A token that merely contains the substring "bearer" mid-way through
     * (not as a prefix) must be passed through untouched — only a genuine
     * leading "Bearer " prefix is stripped.
     */
    public function testTokenContainingBearerSubstringNotAtStartIsUntouched(): void
    {
        $header = $this->client()->publicBuildAuthHeader('abcBearerXYZtoken');
        $this->assertSame(['Authorization: Bearer abcBearerXYZtoken'], $header);
    }
}

/**
 * Minimal concrete subclass exposing the protected buildAuthHeader() seam for
 * direct unit testing, with no network I/O involved.
 */
final class TestableAuthServiceClient extends AuthServiceClient
{
    public function publicBuildAuthHeader(?string $token): array
    {
        return $this->buildAuthHeader($token);
    }
}
