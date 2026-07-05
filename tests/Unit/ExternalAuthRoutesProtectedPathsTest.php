<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthRoutes;

/**
 * Unit tests for ExternalAuthRoutes::protectedPaths() — the single source of truth
 * for RequireCardMiddleware's tenant-agnostic (tier-2) exemption list.
 *
 * Added 2026-07-05 alongside the RequireCardMiddleware fix (see its class docblock
 * and framework-spec.md §5.5). This was previously untested code; per house rule,
 * modifying it means owning its test coverage from here on.
 *
 * @covers \StoneScriptPHP\Auth\ExternalAuth\ExternalAuthRoutes::protectedPaths
 */
class ExternalAuthRoutesProtectedPathsTest extends TestCase
{
    /**
     * Env vars this test process-wide putenv()'d because they were empty on entry.
     * Mirrors ExchangeRouteTest's cleanup discipline — ExternalAuthConfig ->
     * Env::get_instance() needs gateway placeholders, and putenv() otherwise leaks
     * for the rest of the single-process PHPUnit run.
     */
    private array $envVarsSetByThisTest = [];

    protected function setUp(): void
    {
        $this->setEnvIfEmpty('DB_GATEWAY_URL', 'http://localhost:9000');
        $this->setEnvIfEmpty('DB_GATEWAY_PLATFORM', 'test-platform');
        $this->setEnvIfEmpty('AUTH_SERVICE_URL', 'http://localhost:3139');
        $this->setEnvIfEmpty('AUTH_ISSUER', 'http://localhost:3139');
        $ref = new \ReflectionClass(\StoneScriptPHP\Env::class);
        $prop = $ref->getProperty('_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(\StoneScriptPHP\Env::class);
        $prop = $ref->getProperty('_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        foreach ($this->envVarsSetByThisTest as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
        $this->envVarsSetByThisTest = [];
    }

    private function setEnvIfEmpty(string $name, string $value): void
    {
        if (empty(getenv($name))) {
            putenv("{$name}={$value}");
            $this->envVarsSetByThisTest[] = $name;
        }
    }

    /**
     * With default options (canonical prefix /api/auth, legacy_compat true by
     * default, provision_tenant explicitly enabled), the exact tier-2 route set
     * used to fix the 2026-07-05 fleet incident (RequireCardMiddleware) must be present.
     */
    public function test_default_options_return_the_full_tier2_route_set(): void
    {
        $paths = ExternalAuthRoutes::protectedPaths(['provision_tenant' => true]);

        $this->assertContains('/api/auth/select-tenant', $paths);
        $this->assertContains('/api/auth/provision-tenant', $paths);
        $this->assertContains('/api/auth/change-password', $paths);
        $this->assertContains('/api/auth/invite-member', $paths);
        $this->assertContains('/api/auth/memberships', $paths);
        $this->assertContains('/api/auth/me', $paths);
    }

    /**
     * REGRESSION COVERAGE (2026-07-05): protectedPaths() previously only returned
     * canonical-prefix paths, silently omitting the legacy /auth/* duplicates even
     * though legacy_compat defaults to true (the same platforms this exemption list
     * protects are, by default, also answering requests on the legacy prefix).
     * A platform relying on the default config would have the exemption correctly
     * applied on /api/auth/provision-tenant but NOT on /auth/provision-tenant,
     * silently 403-ing any client still on the legacy prefix.
     */
    public function test_legacy_compat_default_true_also_returns_legacy_prefix_paths(): void
    {
        // Default options: legacy_compat defaults true, prefix defaults /api/auth.
        $paths = ExternalAuthRoutes::protectedPaths(['provision_tenant' => true]);

        $this->assertContains('/auth/select-tenant', $paths, 'legacy prefix must be included by default');
        $this->assertContains('/auth/provision-tenant', $paths, 'legacy prefix must be included by default');
        $this->assertContains('/auth/change-password', $paths, 'legacy prefix must be included by default');
        $this->assertContains('/auth/invite-member', $paths, 'legacy prefix must be included by default');
        $this->assertContains('/auth/memberships', $paths, 'legacy prefix must be included by default');
        $this->assertContains('/auth/me', $paths, 'legacy prefix must be included by default');
    }

    /**
     * legacy_compat: false must NOT include the /auth/* duplicates — only the
     * canonical prefix.
     */
    public function test_legacy_compat_false_excludes_legacy_prefix_paths(): void
    {
        $paths = ExternalAuthRoutes::protectedPaths([
            'provision_tenant' => true,
            'legacy_compat' => false,
        ]);

        $this->assertContains('/api/auth/provision-tenant', $paths);
        $this->assertNotContains('/auth/provision-tenant', $paths);
    }

    /**
     * When the canonical prefix IS already /auth, there must be no duplicate
     * entries (mirrors publicPaths()'s existing no-double-register guard).
     */
    public function test_canonical_prefix_already_auth_has_no_duplicates(): void
    {
        $paths = ExternalAuthRoutes::protectedPaths([
            'provision_tenant' => true,
            'prefix' => '/auth',
        ]);

        $occurrences = array_count_values($paths);
        $this->assertSame(1, $occurrences['/auth/provision-tenant'] ?? 0);
    }

    /**
     * Disabled features are omitted entirely — the list only reflects routes
     * ExternalAuthRoutes::registerForPrefix() actually registers.
     */
    public function test_disabled_feature_is_omitted(): void
    {
        $paths = ExternalAuthRoutes::protectedPaths([
            'provision_tenant' => false,
            'select_tenant' => false,
        ]);

        $this->assertNotContains('/api/auth/provision-tenant', $paths);
        $this->assertNotContains('/api/auth/select-tenant', $paths);
        // memberships/change_password/invite/profile default to enabled — still present.
        $this->assertContains('/api/auth/memberships', $paths);
    }
}
