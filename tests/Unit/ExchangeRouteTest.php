<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\ExternalAuth\Routes\ExchangeRoute;
use StoneScriptPHP\Auth\TokenExchangeException;

/**
 * Unit tests for ExchangeRoute (task #2877).
 *
 * The route's process() orchestration is the part most likely to break:
 *   extract token -> validate -> resolve roles -> sign -> envelope.
 * We exercise it with a testable subclass that overrides the three external
 * seams (header extraction, JWKS validation, JWT signing) so no network call,
 * private key, or real identity token is needed.
 *
 * Covered cases (per task plan):
 *   - valid identity token  -> platform token + roles envelope
 *   - missing / invalid token -> 401 invalid_identity_token
 *   - no roles_resolver configured -> 501 (never guess)
 *   - roles_resolver receives the decoded identity claims
 */
class ExchangeRouteTest extends TestCase
{
    protected function setUp(): void
    {
        // ExternalAuthConfig -> Env::get_instance() needs gateway placeholders.
        if (empty(getenv('DB_GATEWAY_URL'))) {
            putenv('DB_GATEWAY_URL=http://localhost:9000');
        }
        if (empty(getenv('DB_GATEWAY_PLATFORM'))) {
            putenv('DB_GATEWAY_PLATFORM=test-platform');
        }
        if (empty(getenv('AUTH_SERVICE_URL'))) {
            putenv('AUTH_SERVICE_URL=http://localhost:3139');
        }
        $ref = new \ReflectionClass(\StoneScriptPHP\Env::class);
        $prop = $ref->getProperty('_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        // res_error() (helpers.php) reads these from $_SERVER for its log line;
        // they are absent in the CLI test context.
        $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'POST';
        $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/api/auth/exchange';
    }

    private function makeConfig(array $options = []): ExternalAuthConfig
    {
        return new ExternalAuthConfig(array_merge([
            'platform_code' => 'testapp',
        ], $options));
    }

    private function makeClient(): ExternalAuthServiceClient
    {
        return new ExternalAuthServiceClient('http://localhost:3139', 'testapp');
    }

    // ── valid token -> envelope ──────────────────────────────────────────────

    public function test_valid_token_returns_platform_token_with_roles(): void
    {
        $route = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(['exchange_ttl' => 1800]),
            fn(array $claims) => ['owner', 'admin']
        );
        $route->stubToken = 'identity.jwt.token';
        $route->stubClaims = ['sub' => 'id-123', 'tenant_id' => 't-1', 'platform_code' => 'testapp'];
        $route->stubSignedToken = 'platform.jwt.signed';

        $response = $route->process();

        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertSame('ok', $response->status);
        $this->assertSame('platform.jwt.signed', $response->data['access_token']);
        $this->assertSame('Bearer', $response->data['token_type']);
        $this->assertSame(1800, $response->data['expires_in']);
        $this->assertSame(['owner', 'admin'], $response->data['roles']);
    }

    // ── missing token -> 401 ─────────────────────────────────────────────────

    public function test_missing_token_returns_401(): void
    {
        $route = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(),
            fn(array $claims) => ['owner']
        );
        $route->stubToken = null; // no Authorization header

        $response = $route->process();

        $this->assertSame('error', $response->status);
        $this->assertSame(401, $response->httpStatusCode);
        $this->assertSame('invalid_identity_token', $response->data['error']);
    }

    // ── invalid token (validation throws) -> 401 ─────────────────────────────

    public function test_invalid_token_returns_401(): void
    {
        $route = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(),
            fn(array $claims) => ['owner']
        );
        $route->stubToken = 'bad.token';
        $route->validateThrows = new TokenExchangeException('bad sig', 'INVALID_SIGNATURE');

        $response = $route->process();

        $this->assertSame('error', $response->status);
        $this->assertSame(401, $response->httpStatusCode);
        $this->assertSame('invalid_identity_token', $response->data['error']);
    }

    // ── no resolver -> 501, never guess ──────────────────────────────────────

    public function test_no_resolver_returns_501(): void
    {
        $route = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(),
            null // no roles_resolver
        );
        $route->stubToken = 'identity.jwt.token';
        $route->stubClaims = ['sub' => 'id-123', 'tenant_id' => 't-1'];

        $response = $route->process();

        $this->assertSame('error', $response->status);
        $this->assertSame(501, $response->httpStatusCode);
    }

    // ── resolver receives the decoded identity claims ────────────────────────

    public function test_resolver_receives_identity_claims(): void
    {
        $received = null;
        $route = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(),
            function (array $claims) use (&$received) {
                $received = $claims;
                return ['owner'];
            }
        );
        $route->stubToken = 'identity.jwt.token';
        $route->stubClaims = ['sub' => 'id-xyz', 'tenant_id' => 't-9', 'email' => 'u@test.com'];
        $route->stubSignedToken = 'platform.jwt.signed';

        $route->process();

        $this->assertSame('id-xyz', $received['sub']);
        $this->assertSame('t-9', $received['tenant_id']);
        $this->assertSame('u@test.com', $received['email']);
    }

    // ── token missing identity id claim -> 401 ───────────────────────────────

    public function test_claims_without_identity_id_returns_401(): void
    {
        $route = new TestableExchangeRoute(
            $this->makeClient(),
            [],
            $this->makeConfig(),
            fn(array $claims) => ['owner']
        );
        $route->stubToken = 'identity.jwt.token';
        $route->stubClaims = ['tenant_id' => 't-1']; // no sub / identity_id

        $response = $route->process();

        $this->assertSame('error', $response->status);
        $this->assertSame(401, $response->httpStatusCode);
        $this->assertSame('invalid_identity_token', $response->data['error']);
    }
}

/**
 * Testable subclass: overrides the three external seams so process() can be
 * unit-tested without network/JWKS/private keys.
 */
class TestableExchangeRoute extends ExchangeRoute
{
    public ?string $stubToken = null;
    public array $stubClaims = [];
    public string $stubSignedToken = 'platform.jwt.signed';
    public ?TokenExchangeException $validateThrows = null;

    protected function extractIdentityToken(): ?string
    {
        return $this->stubToken;
    }

    protected function validateIdentity(string $identityToken): array
    {
        if ($this->validateThrows !== null) {
            throw $this->validateThrows;
        }
        return $this->stubClaims;
    }

    protected function signPlatformToken(array $claims, array $roles): string
    {
        return $this->stubSignedToken;
    }
}
