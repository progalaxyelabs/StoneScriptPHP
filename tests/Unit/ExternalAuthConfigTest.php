<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig;

/**
 * ExternalAuthConfig unit tests — AUTH-SPEC §S1 prefix migration
 *
 * Covers the v3.26.0 changes:
 * - Default prefix changed from /auth to /api/auth
 * - legacyCompat property added (default true)
 * - Explicit /auth prefix still accepted; legacy compat auto-skipped
 */
class ExternalAuthConfigTest extends TestCase
{
    protected function setUp(): void
    {
        // ExternalAuthConfig calls Env::get_instance() which requires DB_GATEWAY_URL
        // and DB_GATEWAY_PLATFORM. Set placeholders so unit tests don't fail on
        // infrastructure checks unrelated to ExternalAuthConfig prefix logic.
        if (empty(getenv('DB_GATEWAY_URL'))) {
            putenv('DB_GATEWAY_URL=http://localhost:9000');
        }
        if (empty(getenv('DB_GATEWAY_PLATFORM'))) {
            putenv('DB_GATEWAY_PLATFORM=test-platform');
        }
        if (empty(getenv('AUTH_SERVICE_URL'))) {
            putenv('AUTH_SERVICE_URL=http://localhost:3139');
        }
        // Reset Env singleton between tests to pick up the env vars above.
        $ref = new \ReflectionClass(\StoneScriptPHP\Env::class);
        $prop = $ref->getProperty('_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    protected function tearDown(): void
    {
        // Restore Env singleton to a clean state after each test.
        $ref = new \ReflectionClass(\StoneScriptPHP\Env::class);
        $prop = $ref->getProperty('_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    // ── Default prefix ──────────────────────────────────────────────────────

    public function test_default_prefix_is_api_auth(): void
    {
        $config = new ExternalAuthConfig([]);
        $this->assertSame('/api/auth', $config->prefix,
            'AUTH-SPEC §S1: default prefix must be /api/auth (not /auth)');
    }

    public function test_explicit_prefix_is_honoured(): void
    {
        $config = new ExternalAuthConfig(['prefix' => '/api/auth']);
        $this->assertSame('/api/auth', $config->prefix);
    }

    public function test_explicit_legacy_prefix_is_honoured(): void
    {
        // Platforms that still explicitly set '/auth' keep working.
        $config = new ExternalAuthConfig(['prefix' => '/auth']);
        $this->assertSame('/auth', $config->prefix);
    }

    public function test_trailing_slash_is_stripped(): void
    {
        $config = new ExternalAuthConfig(['prefix' => '/api/auth/']);
        $this->assertSame('/api/auth', $config->prefix);
    }

    // ── legacyCompat ────────────────────────────────────────────────────────

    public function test_legacy_compat_defaults_to_true(): void
    {
        $config = new ExternalAuthConfig([]);
        $this->assertTrue($config->legacyCompat,
            'legacyCompat must default to true so existing clients keep working');
    }

    public function test_legacy_compat_can_be_disabled(): void
    {
        $config = new ExternalAuthConfig(['legacy_compat' => false]);
        $this->assertFalse($config->legacyCompat);
    }

    public function test_legacy_compat_true_when_explicitly_set(): void
    {
        $config = new ExternalAuthConfig(['legacy_compat' => true]);
        $this->assertTrue($config->legacyCompat);
    }

    // ── publicPaths dual-prefix behaviour ───────────────────────────────────

    /**
     * With default options (prefix=/api/auth, legacyCompat=true), publicPaths()
     * must include paths under BOTH /api/auth/* AND /auth/* so the JWT middleware
     * excludes requests to either prefix during the migration window.
     */
    public function test_public_paths_includes_legacy_prefix_when_compat_on(): void
    {
        $paths = \StoneScriptPHP\Auth\ExternalAuth\ExternalAuthRoutes::publicPaths([]);

        // Canonical paths present
        $this->assertContains('/api/auth/login', $paths,
            'canonical /api/auth/login must be in publicPaths');
        $this->assertContains('/api/auth/register', $paths,
            'canonical /api/auth/register must be in publicPaths');

        // Legacy compat paths also present
        $this->assertContains('/auth/login', $paths,
            'legacy /auth/login must be in publicPaths when legacyCompat=true');
        $this->assertContains('/auth/register', $paths,
            'legacy /auth/register must be in publicPaths when legacyCompat=true');
    }

    /**
     * With legacyCompat=false, publicPaths() must only return the canonical prefix.
     */
    public function test_public_paths_excludes_legacy_when_compat_off(): void
    {
        $paths = \StoneScriptPHP\Auth\ExternalAuth\ExternalAuthRoutes::publicPaths([
            'legacy_compat' => false,
        ]);

        $this->assertContains('/api/auth/login', $paths);

        foreach ($paths as $path) {
            $this->assertStringStartsWith('/api/auth', $path,
                "No /auth/* paths expected when legacyCompat=false — found: $path");
        }
    }

    /**
     * When caller explicitly sets prefix to /auth (old style), publicPaths() must
     * NOT double-register — the legacy compat check (prefix !== /auth) skips it.
     */
    public function test_public_paths_no_double_register_for_explicit_old_prefix(): void
    {
        $paths = \StoneScriptPHP\Auth\ExternalAuth\ExternalAuthRoutes::publicPaths([
            'prefix' => '/auth',
        ]);

        // /auth/login should appear exactly once
        $loginPaths = array_filter($paths, fn($p) => $p === '/auth/login');
        $this->assertCount(1, $loginPaths,
            '/auth/login must appear exactly once when prefix=/auth (no double-registration)');

        // /api/auth/* must NOT appear (compat is skipped, not swapped)
        foreach ($paths as $path) {
            $this->assertStringStartsWith('/auth', $path,
                "Only /auth/* paths expected when prefix=/auth — found: $path");
        }
    }

    // ── getRouteDefinitions default ─────────────────────────────────────────

    public function test_get_route_definitions_uses_api_auth_default(): void
    {
        $routes = \StoneScriptPHP\Auth\ExternalAuth\ExternalAuthRoutes::getRouteDefinitions([]);

        // Login route must be under /api/auth
        $this->assertArrayHasKey('/api/auth/login', $routes['POST'],
            'getRouteDefinitions must use /api/auth as default prefix (AUTH-SPEC §S1)');

        $this->assertArrayNotHasKey('/auth/login', $routes['POST'],
            'getRouteDefinitions must NOT produce /auth/login with default options');
    }

    public function test_get_route_definitions_honours_explicit_prefix(): void
    {
        $routes = \StoneScriptPHP\Auth\ExternalAuth\ExternalAuthRoutes::getRouteDefinitions([
            'prefix' => '/auth',
        ]);

        $this->assertArrayHasKey('/auth/login', $routes['POST']);
        $this->assertArrayNotHasKey('/api/auth/login', $routes['POST']);
    }

    // ── Token exchange config (task #2877) ──────────────────────────────────

    public function test_exchange_enabled_by_default(): void
    {
        $config = new ExternalAuthConfig([]);
        $this->assertTrue($config->isEnabled('exchange'),
            'exchange feature must default to enabled');
    }

    public function test_exchange_can_be_disabled(): void
    {
        $config = new ExternalAuthConfig(['exchange' => false]);
        $this->assertFalse($config->isEnabled('exchange'));
    }

    public function test_jwks_url_defaults_from_auth_service_url(): void
    {
        $config = new ExternalAuthConfig(['auth_service_url' => 'http://auth:3139']);
        $this->assertSame('http://auth:3139/api/auth/jwks', $config->jwksUrl,
            'jwksUrl must default to {authServiceUrl}/api/auth/jwks');
    }

    public function test_jwks_url_override_is_respected(): void
    {
        $config = new ExternalAuthConfig(['jwks_url' => 'https://auth.example.com/.well-known/jwks.json']);
        $this->assertSame('https://auth.example.com/.well-known/jwks.json', $config->jwksUrl);
    }

    public function test_exchange_ttl_defaults_to_3600(): void
    {
        $config = new ExternalAuthConfig([]);
        $this->assertSame(3600, $config->exchangeTtl);
    }

    public function test_exchange_ttl_override_is_respected(): void
    {
        $config = new ExternalAuthConfig(['exchange_ttl' => 900]);
        $this->assertSame(900, $config->exchangeTtl);
    }

    public function test_signing_issuer_override_is_respected(): void
    {
        $config = new ExternalAuthConfig(['signing_issuer' => 'https://api.myplatform.com']);
        $this->assertSame('https://api.myplatform.com', $config->signingIssuer);
    }

    public function test_signing_private_key_path_override_is_respected(): void
    {
        $config = new ExternalAuthConfig(['signing_private_key_path' => '/keys/custom.pem']);
        $this->assertSame('/keys/custom.pem', $config->signingPrivateKeyPath);
    }
}
