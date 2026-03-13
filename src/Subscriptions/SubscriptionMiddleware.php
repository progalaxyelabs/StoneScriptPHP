<?php

declare(strict_types=1);

namespace StoneScriptPHP\Subscriptions;

use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Database;
use StoneScriptPHP\Routing\MiddlewareInterface;

/**
 * Subscription enforcement middleware.
 *
 * Checks subscription status from the platform's main database.
 * Blocks tenants with expired or inactive subscriptions with HTTP 402 Payment Required.
 *
 * Fail-closed for missing tenants: if sub_get_status() returns NULL (no subscription row),
 * returns 402. Only fail-open on DB errors/exceptions — to avoid blocking users
 * due to transient infrastructure issues.
 *
 * Must be registered AFTER JwtAuthMiddleware (or equivalent) so that the auth() context
 * is available with a resolved tenant_id.
 *
 * Usage:
 *
 *   $router->addMiddleware(new SubscriptionMiddleware(
 *       exempt_paths: ['/health', '/auth/', '/subscription/status', '/account/', '/export'],
 *   ));
 *
 *   // Or via SubscriptionRoutes config:
 *   $middleware = new SubscriptionMiddleware(
 *       exempt_paths: $config->exemptPaths,
 *   );
 *
 * @package StoneScriptPHP\Subscriptions
 */
class SubscriptionMiddleware implements MiddlewareInterface
{
    /** Path prefixes and exact paths that bypass subscription enforcement. */
    private array $exemptPaths;

    /**
     * @param array $exemptPaths Path prefixes / exact paths exempt from enforcement.
     *                           Trailing slash = prefix match (e.g. '/auth/' matches '/auth/login').
     *                           No trailing slash = exact match or path-component prefix.
     */
    public function __construct(
        array $exemptPaths = [
            '/health',
            '/auth/',
            '/subscription/status',
            '/account/',
            '/export',
        ]
    ) {
        $this->exemptPaths = $exemptPaths;
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        $path = $this->extractPath();

        if ($this->isExemptPath($path)) {
            return $next($request);
        }

        // Only enforce for authenticated users — let auth middleware handle unauthenticated
        $user = auth();
        if (!$user || !($user->tenant_id ?? null)) {
            return $next($request);
        }

        $result = $this->checkSubscription((string) $user->tenant_id);

        if ($result === null) {
            // DB query failed — fail open to avoid blocking users during infrastructure issues
            log_debug('SubscriptionMiddleware: query failed, failing open for tenant=' . $user->tenant_id);
            return $next($request);
        }

        if ($result === false) {
            // No subscription row found or subscription inactive — fail closed
            error_log('[SubscriptionMiddleware] Blocked tenant=' . $user->tenant_id . ' — subscription expired or not found');
            http_response_code(402);
            header('Content-Type: application/json');
            echo json_encode([
                'status'     => 'error',
                'message'    => 'Your subscription has expired. Please renew to continue using this service.',
                'error_code' => 'SUBSCRIPTION_EXPIRED',
            ]);
            exit;
        }

        return $next($request);
    }

    /**
     * Query main DB for subscription status via sub_get_status().
     *
     * @param string $tenantId
     * @return bool|null true = active, false = expired/blocked/not found, null = query failed (fail open)
     */
    private function checkSubscription(string $tenantId): ?bool
    {
        try {
            // Always query the main DB (tenant_id = null)
            $gw = Database::getGatewayClient();
            $prevTenant = $gw->getTenantId();
            $gw->setTenantId(null);

            try {
                $result = Database::fn('sub_get_status', [$tenantId]);
            } finally {
                $gw->setTenantId($prevTenant);
            }

            $data = $result[0] ?? null;
            if (is_object($data)) {
                $data = (array) $data;
            }
            // Gateway pre-decodes JSON — handle both string and array forms
            if (isset($data['sub_get_status'])) {
                $data = is_string($data['sub_get_status'])
                    ? json_decode($data['sub_get_status'], true)
                    : $data['sub_get_status'];
            }

            if (!$data) {
                // No subscription row — fail closed (tenant should have been provisioned)
                error_log('[SubscriptionMiddleware] No subscription found for tenant_id=' . $tenantId);
                return false;
            }

            return (bool) ($data['is_active'] ?? false);
        } catch (\Exception $e) {
            error_log('[SubscriptionMiddleware] Error for tenant=' . $tenantId . ': ' . $e->getMessage());
            return null; // fail open on exceptions
        }
    }

    private function extractPath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return parse_url($uri, PHP_URL_PATH) ?? '/';
    }

    /**
     * Returns true if $path matches any exempt prefix or exact path.
     *
     * Trailing slash in exempt paths = prefix match (e.g. '/auth/' matches '/auth/login').
     * No trailing slash = exact match OR path-component prefix (e.g. '/export' matches '/export/csv').
     */
    private function isExemptPath(string $path): bool
    {
        foreach ($this->exemptPaths as $exempt) {
            if (str_ends_with($exempt, '/')) {
                $prefix = rtrim($exempt, '/');
                if ($path === $prefix || str_starts_with($path, $exempt)) {
                    return true;
                }
            } else {
                if ($path === $exempt || str_starts_with($path, $exempt . '/')) {
                    return true;
                }
            }
        }

        return false;
    }
}
