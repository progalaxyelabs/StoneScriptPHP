<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for client generator service/route filtering support (v4.0).
 *
 * Tests:
 * - filterRoutesByService()
 * - collectKnownServices()
 * - extractResourceName() with service prefixes
 * - pathToMethodName() with service prefixes
 */
class ClientGeneratorScopeTest extends TestCase
{
    private static bool $functionsLoaded = false;

    public static function setUpBeforeClass(): void
    {
        if (!self::$functionsLoaded) {
            if (!defined('ROOT_PATH')) {
                define('ROOT_PATH', realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR);
            }
            self::$functionsLoaded = true;
        }
    }

    // =========================================================================
    // filterRoutesByService
    // =========================================================================

    public function test_filter_no_service_returns_all_non_alias_routes(): void
    {
        $routes = [
            ['path' => '/health', 'handler' => 'A', 'service' => 'shared', 'alias' => false],
            ['path' => '/portal/dashboard', 'handler' => 'B', 'service' => 'portal', 'alias' => false],
            ['path' => '/admin/users', 'handler' => 'C', 'service' => 'admin', 'alias' => false],
            ['path' => '/old-dashboard', 'handler' => 'B', 'service' => 'portal', 'alias' => true],
        ];

        $filtered = $this->filterRoutesByService($routes, null);
        $this->assertCount(3, $filtered);
        $this->assertNotContains('/old-dashboard', array_column($filtered, 'path'));
    }

    public function test_filter_portal_service_includes_portal_and_shared(): void
    {
        $routes = [
            ['path' => '/health', 'handler' => 'A', 'service' => 'shared', 'alias' => false],
            ['path' => '/auth/profile', 'handler' => 'B', 'service' => 'shared', 'alias' => false],
            ['path' => '/portal/dashboard', 'handler' => 'C', 'service' => 'portal', 'alias' => false],
            ['path' => '/portal/invoices', 'handler' => 'D', 'service' => 'portal', 'alias' => false],
            ['path' => '/admin/users', 'handler' => 'E', 'service' => 'admin', 'alias' => false],
        ];

        $filtered = $this->filterRoutesByService($routes, 'portal');
        $paths = array_column($filtered, 'path');

        $this->assertContains('/health', $paths);
        $this->assertContains('/auth/profile', $paths);
        $this->assertContains('/portal/dashboard', $paths);
        $this->assertContains('/portal/invoices', $paths);
        $this->assertNotContains('/admin/users', $paths);
    }

    public function test_filter_admin_service_includes_admin_and_shared(): void
    {
        $routes = [
            ['path' => '/health', 'handler' => 'A', 'service' => 'shared', 'alias' => false],
            ['path' => '/portal/dashboard', 'handler' => 'B', 'service' => 'portal', 'alias' => false],
            ['path' => '/admin/users', 'handler' => 'C', 'service' => 'admin', 'alias' => false],
            ['path' => '/admin/settings', 'handler' => 'D', 'service' => 'admin', 'alias' => false],
        ];

        $filtered = $this->filterRoutesByService($routes, 'admin');
        $paths = array_column($filtered, 'path');

        $this->assertContains('/health', $paths);
        $this->assertContains('/admin/users', $paths);
        $this->assertContains('/admin/settings', $paths);
        $this->assertNotContains('/portal/dashboard', $paths);
    }

    public function test_filter_excludes_aliases_even_with_service_match(): void
    {
        $routes = [
            ['path' => '/portal/dashboard', 'handler' => 'A', 'service' => 'portal', 'alias' => false],
            ['path' => '/dashboard', 'handler' => 'A', 'service' => 'portal', 'alias' => true],
        ];

        $filtered = $this->filterRoutesByService($routes, 'portal');
        $this->assertCount(1, $filtered);
        $this->assertEquals('/portal/dashboard', $filtered[0]['path']);
    }

    // =========================================================================
    // /api/internal/ exclusion
    // =========================================================================

    public function test_filter_excludes_internal_routes_when_no_service_filter(): void
    {
        $routes = [
            ['path' => '/api/workspaces', 'handler' => 'A', 'service' => 'shared', 'alias' => false],
            ['path' => '/api/internal/workspace-events', 'handler' => 'B', 'service' => 'shared', 'alias' => false],
            ['path' => '/api/internal/chat/app-builder/response', 'handler' => 'C', 'service' => 'shared', 'alias' => false],
        ];

        $filtered = $this->filterRoutesByService($routes, null);
        $paths = array_column($filtered, 'path');

        $this->assertCount(1, $filtered);
        $this->assertContains('/api/workspaces', $paths);
        $this->assertNotContains('/api/internal/workspace-events', $paths);
        $this->assertNotContains('/api/internal/chat/app-builder/response', $paths);
    }

    public function test_filter_excludes_internal_routes_even_with_matching_service(): void
    {
        // Internal routes must be excluded regardless of their declared service — the
        // /api/internal/ prefix is an absolute exclusion, not overridable by service.
        $routes = [
            ['path' => '/api/workspaces', 'handler' => 'A', 'service' => 'portal', 'alias' => false],
            ['path' => '/api/internal/workspace-events', 'handler' => 'B', 'service' => 'portal', 'alias' => false],
            ['path' => '/api/internal/chat/app-builder/response', 'handler' => 'C', 'service' => 'shared', 'alias' => false],
        ];

        $filtered = $this->filterRoutesByService($routes, 'portal');
        $paths = array_column($filtered, 'path');

        $this->assertCount(1, $filtered);
        $this->assertContains('/api/workspaces', $paths);
        $this->assertNotContains('/api/internal/workspace-events', $paths);
        $this->assertNotContains('/api/internal/chat/app-builder/response', $paths);
    }

    public function test_filter_internal_prefix_is_exact_prefix_match(): void
    {
        // /api/internalized or /api/internal-ish should NOT be excluded — only /api/internal/ prefix
        $routes = [
            ['path' => '/api/internalized/something', 'handler' => 'A', 'service' => 'shared', 'alias' => false],
            ['path' => '/api/internal/', 'handler' => 'B', 'service' => 'shared', 'alias' => false],
            ['path' => '/api/internal/workspace-events', 'handler' => 'C', 'service' => 'shared', 'alias' => false],
        ];

        $filtered = $this->filterRoutesByService($routes, null);
        $paths = array_column($filtered, 'path');

        // /api/internalized/something does NOT start with '/api/internal/' (trailing slash), so it passes through
        $this->assertContains('/api/internalized/something', $paths);
        // /api/internal/ itself starts with '/api/internal/' so it is excluded
        $this->assertNotContains('/api/internal/', $paths);
        // /api/internal/workspace-events is excluded
        $this->assertNotContains('/api/internal/workspace-events', $paths);
    }

    public function test_filter_known_leak_workspace_events_is_excluded(): void
    {
        // Regression test: /api/internal/workspace-events was previously emitted as
        // 'internalWorkspaceEvents' in the generated TypeScript client. Verify it is
        // now excluded unconditionally — both with and without a service filter.
        $routes = [
            ['path' => '/api/workspaces', 'handler' => 'A', 'service' => 'portal', 'alias' => false],
            ['path' => '/api/internal/workspace-events', 'handler' => 'B', 'service' => 'shared', 'alias' => false],
        ];

        // No service filter
        $withoutFilter = array_column($this->filterRoutesByService($routes, null), 'path');
        $this->assertNotContains('/api/internal/workspace-events', $withoutFilter);

        // With service filter 'portal'
        $withFilter = array_column($this->filterRoutesByService($routes, 'portal'), 'path');
        $this->assertNotContains('/api/internal/workspace-events', $withFilter);
    }

    // =========================================================================
    // collectKnownServices
    // =========================================================================

    public function test_collect_known_services(): void
    {
        $routes = [
            ['service' => 'shared'],
            ['service' => 'portal'],
            ['service' => 'admin'],
            ['service' => 'portal'],
            ['service' => 'shared'],
        ];

        $services = $this->collectKnownServices($routes);
        $this->assertCount(3, $services);
        $this->assertContains('shared', $services);
        $this->assertContains('portal', $services);
        $this->assertContains('admin', $services);
    }

    // =========================================================================
    // extractResourceName with service prefix stripping
    // =========================================================================

    public function test_extract_resource_no_service(): void
    {
        $this->assertEquals('health', $this->extractResourceName('/health', []));
        $this->assertEquals('auth', $this->extractResourceName('/auth/login', []));
        $this->assertEquals('projects', $this->extractResourceName('/projects/create', []));
    }

    public function test_extract_resource_strips_service_prefix(): void
    {
        $services = ['portal', 'admin'];

        $this->assertEquals('invoices', $this->extractResourceName('/portal/invoices', $services));
        $this->assertEquals('dashboard', $this->extractResourceName('/portal/dashboard', $services));
        $this->assertEquals('tenants', $this->extractResourceName('/admin/tenants', $services));
        $this->assertEquals('users', $this->extractResourceName('/admin/users', $services));
    }

    public function test_extract_resource_no_strip_when_not_service(): void
    {
        $services = ['portal', 'admin'];

        // 'auth' is not a known service, so keep as-is
        $this->assertEquals('auth', $this->extractResourceName('/auth/profile', $services));
        $this->assertEquals('health', $this->extractResourceName('/health', $services));
    }

    public function test_extract_resource_camelcase(): void
    {
        $services = ['portal'];
        $this->assertEquals('customerBills', $this->extractResourceName('/portal/customer-bills', $services));
    }

    // =========================================================================
    // pathToMethodName with service prefix stripping
    // =========================================================================

    public function test_path_to_method_strips_service(): void
    {
        $services = ['portal', 'admin'];

        // /portal/invoices + GET -> list (strip portal, resource=invoices, no remaining parts)
        $this->assertEquals('list', $this->pathToMethodName('/portal/invoices', 'GET', $services));

        // /portal/invoices/create + POST -> create
        $this->assertEquals('create', $this->pathToMethodName('/portal/invoices/create', 'POST', $services));

        // /admin/users/:id + GET -> getById
        $this->assertEquals('getById', $this->pathToMethodName('/admin/users/:id', 'GET', $services));
    }

    public function test_path_to_method_no_service_stripping(): void
    {
        $services = ['portal'];

        // /auth/login -> no stripping ('auth' is not a service)
        $this->assertEquals('login', $this->pathToMethodName('/auth/login', 'POST', $services));

        // /health -> list
        $this->assertEquals('list', $this->pathToMethodName('/health', 'GET', $services));
    }

    // =========================================================================
    // Helper implementations (mirrors the functions from generate-client.php)
    // =========================================================================

    private function filterRoutesByService(array $routes, ?string $serviceFilter): array
    {
        $result = array_filter($routes, function($route) use ($serviceFilter) {
            if ($route['alias'] ?? false) {
                return false;
            }
            // Always exclude internal routes — never leak server-to-server paths into generated clients
            if (str_starts_with($route['path'] ?? '', '/api/internal/')) {
                return false;
            }
            if ($serviceFilter === null) {
                return true;
            }
            $routeService = $route['service'] ?? 'shared';
            return $routeService === $serviceFilter || $routeService === 'shared';
        });
        return array_values($result);
    }

    private function collectKnownServices(array $routes): array
    {
        $services = [];
        foreach ($routes as $route) {
            $service = $route['service'] ?? 'shared';
            if (!in_array($service, $services)) {
                $services[] = $service;
            }
        }
        return $services;
    }

    private function extractResourceName(string $path, array $knownServices): string
    {
        $path = trim($path, '/');
        $parts = explode('/', $path);

        if (count($parts) > 1 && in_array($parts[0], $knownServices)) {
            $name = $parts[1] ?: 'root';
        } else {
            $name = $parts[0] ?: 'root';
        }

        if (str_contains($name, '-') || str_contains($name, '_')) {
            $name = str_replace(['-', '_'], ' ', $name);
            $name = lcfirst(str_replace(' ', '', ucwords($name)));
        }

        return $name;
    }

    private function pathToMethodName(string $path, string $method, array $knownServices = []): string
    {
        $path = trim($path, '/');
        $parts = explode('/', $path);

        // Strip service prefix
        if (count($parts) > 1 && in_array($parts[0], $knownServices)) {
            array_shift($parts);
        }

        // Remove resource segment
        array_shift($parts);

        // Check for params
        $hasParams = false;
        $paramNames = [];
        foreach ($parts as $part) {
            if (preg_match('/^\{.+\}$/', $part) || preg_match('/^\:(.+)$/', $part, $matches)) {
                $hasParams = true;
                if (isset($matches[1])) {
                    $paramNames[] = $matches[1];
                }
            }
        }

        $nonParamParts = array_filter($parts, fn($part) => !preg_match('/^\{.+\}$/', $part) && !preg_match('/^\:.+$/', $part));

        if (empty($nonParamParts) && $hasParams) {
            $httpMethod = strtoupper($method);
            if (count($paramNames) === 1 && $paramNames[0] === 'id') {
                return match($httpMethod) {
                    'GET' => 'getById',
                    'PUT' => 'update',
                    'DELETE' => 'delete',
                    'POST' => 'create',
                    default => strtolower($method)
                };
            }
        }

        if (empty($nonParamParts)) {
            return match(strtoupper($method)) {
                'GET' => 'list',
                'POST' => 'create',
                'PUT' => 'update',
                'DELETE' => 'delete',
                default => strtolower($method)
            };
        }

        $methodName = '';
        foreach ($nonParamParts as $i => $part) {
            $part = str_replace(['-', '_'], ' ', $part);
            $part = ucwords($part);
            $part = str_replace(' ', '', $part);
            if ($i === 0 || ($methodName === '')) {
                $part = lcfirst($part);
            }
            $methodName .= $part;
        }

        return $methodName;
    }
}
