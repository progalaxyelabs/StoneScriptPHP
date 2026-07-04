<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\Client\AuthServiceClient;

/**
 * Kill the silent localhost auth-URL fallback.
 *
 * AuthServiceClient::getDefaultAuthServiceUrl() is the third code path named in
 * the original bug evidence (alongside bootstrap.php's TokenValidator factory and
 * Application::buildJwtHandler()/buildAuthRouteOptions()): it used to fall back to
 * a hardcoded 'http://auth:3139' (previously 'http://localhost:3139') whenever a
 * caller instantiated an AuthServiceClient subclass (MembershipClient,
 * InvitationClient, ExternalAuthServiceClient) WITHOUT passing an explicit URL and
 * without AUTH_SERVICE_URL/legacy config resolving anything.
 *
 * The framework's primary path (ExternalAuthRoutes -> ExternalAuthConfig ->
 * Application::resolveAuthServiceUrl()) already fails loud and always passes an
 * explicit, validated URL into the constructor, so this fallback was reachable
 * only by callers that bypass that resolution entirely — exactly the callers a
 * silent default could strand on an unconfigured/unreachable host. The fix removes
 * the hardcoded default and throws a RuntimeException instead.
 *
 * @covers \StoneScriptPHP\Auth\Client\AuthServiceClient
 */
final class AuthServiceClientDefaultUrlTest extends TestCase
{
    /** @var string[] */
    private array $envVarsSetByThisTest = [];

    protected function tearDown(): void
    {
        foreach ($this->envVarsSetByThisTest as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
        $this->envVarsSetByThisTest = [];
    }

    public function testNoExplicitUrlAndNoEnvThrowsRuntimeExceptionInsteadOfDefaulting(): void
    {
        putenv('AUTH_SERVICE_URL');
        unset($_ENV['AUTH_SERVICE_URL']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Auth service URL is required/');

        new TestableAuthServiceClientNoUrl();
    }

    public function testExplicitUrlPassedToConstructorIsUsedWithoutTouchingEnv(): void
    {
        putenv('AUTH_SERVICE_URL');
        unset($_ENV['AUTH_SERVICE_URL']);

        $client = new TestableAuthServiceClientNoUrl('http://explicit-auth:3139');
        $this->assertSame('http://explicit-auth:3139', $client->publicAuthServiceUrl());
    }

    public function testEnvVarIsUsedWhenNoExplicitUrlPassed(): void
    {
        putenv('AUTH_SERVICE_URL=http://auth:3139');
        $this->envVarsSetByThisTest[] = 'AUTH_SERVICE_URL';

        $client = new TestableAuthServiceClientNoUrl();
        $this->assertSame('http://auth:3139', $client->publicAuthServiceUrl());
    }
}

/**
 * Minimal concrete subclass exposing the resolved authServiceUrl for direct
 * unit testing, with no network I/O involved.
 */
final class TestableAuthServiceClientNoUrl extends AuthServiceClient
{
    public function publicAuthServiceUrl(): string
    {
        return $this->authServiceUrl;
    }
}
