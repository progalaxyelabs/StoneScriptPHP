<?php

declare(strict_types=1);

namespace StoneScriptPHP\Routing\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Database;

/**
 * StoreAccessMiddleware — T3 url-tenant access control (AUTH-SPEC §T3).
 *
 * Validates that the authenticated identity has an active membership in the
 * store identified by :storeId in the request URL by calling the auth service
 * over HTTP — no local mirror table, no cross-DB query.
 *
 * The auth service owns membership data (its own tenant_memberships table).
 * Platforms call it over HTTP. This keeps the boundary clean.
 *
 * MUST run after JwtAuthMiddleware in the global middleware chain.
 *
 * How it works:
 *  1. Router pre-matches the route; :storeId is in $request['params'].
 *     Paths without :storeId pass through unchanged.
 *  2. Gets the Bearer token from the Authorization header.
 *  3. Calls GET {auth_service_url}/api/auth/memberships?platform_code={platform}
 *     with the Bearer token — auth service returns memberships for this identity.
 *  4. If storeId is in the active memberships: sets GatewayClient tenant_id and
 *     calls $next($request).
 *  5. If storeId is NOT in memberships: returns 403 store_access_denied.
 *
 * Usage in Application::run() config:
 *
 *   Application::run([
 *       'routes' => require CONFIG_PATH . 'routes.php',
 *       'auth'   => [ 'server' => ['url' => '...'], 'platform' => ['code' => '...'] ],
 *       'store_access' => [
 *           'enabled' => true,
 *           // auth_service_url and platform_code are read from auth config if not set here
 *       ],
 *   ]);
 */
class StoreAccessMiddleware implements MiddlewareInterface
{
    private string $authServiceUrl;
    private string $platformCode;

    /**
     * @param array $config
     *   - auth_service_url: string  Base URL of the auth service (e.g. http://auth:3139)
     *   - platform_code:    string  Platform code to scope membership lookup
     */
    public function __construct(array $config = [])
    {
        $this->authServiceUrl = rtrim($config['auth_service_url'] ?? '', '/');
        $this->platformCode   = $config['platform_code'] ?? '';
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        // Extract :storeId from route params (set by Router pre-match)
        $storeId = $request['params']['storeId'] ?? null;

        if (!$storeId) {
            // Not a /stores/:storeId/* route — pass through
            return $next($request);
        }

        // JwtAuthMiddleware already verified the token; get the raw Bearer for the auth call
        $authHeader = $request['headers']['Authorization']
            ?? $request['headers']['authorization']
            ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

        $bearerToken = str_starts_with($authHeader, 'Bearer ')
            ? substr($authHeader, 7)
            : '';

        if (!$bearerToken) {
            return new ApiResponse('error', 'Authentication required', null, 401);
        }

        // Call auth service HTTP API — source of truth for memberships
        try {
            $memberships = $this->fetchMemberships($bearerToken);
        } catch (\Throwable $e) {
            log_error('StoreAccessMiddleware: auth service call failed: ' . $e->getMessage());
            return new ApiResponse('error', 'Store access check failed', null, 500);
        }

        // Validate identity has active membership in the requested store
        $hasMembership = false;
        foreach ($memberships as $membership) {
            if (
                ($membership['tenant_id'] ?? '') === $storeId &&
                ($membership['status']    ?? '') === 'active'
            ) {
                $hasMembership = true;
                break;
            }
        }

        if (!$hasMembership) {
            $user = auth();
            log_warning(sprintf(
                'StoreAccessMiddleware: identity=%s denied access to store=%s',
                $user?->user_id ?? 'unknown',
                $storeId
            ));
            return new ApiResponse('error', 'store_access_denied', ['error' => 'store_access_denied'], 403);
        }

        // Grant access: set tenant context for all downstream GatewayClient calls
        Database::getGatewayClient()->setTenantId($storeId);

        return $next($request);
    }

    /**
     * Call GET /api/auth/memberships on the auth service and return the memberships array.
     *
     * @return array<array{tenant_id: string, role: string, status: string, ...}>
     * @throws \RuntimeException on HTTP failure or invalid response
     */
    private function fetchMemberships(string $bearerToken): array
    {
        $url = $this->authServiceUrl
            . '/api/auth/memberships'
            . ($this->platformCode ? '?platform_code=' . urlencode($this->platformCode) : '');

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $bearerToken,
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlErr) {
            throw new \RuntimeException('Auth service unreachable: ' . $curlErr);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Auth service returned invalid JSON (HTTP ' . $httpCode . ')');
        }

        // The auth server (progalaxyelabs-auth) returns its native flat shape
        // {"memberships":[...]}. Older/enveloped responses use
        // {"status":"ok","data":{"memberships":[...]}}. Accept either so the middleware
        // doesn't 500 on the live auth contract (AUTH-SPEC §6 — /api/auth/memberships
        // is a flat {memberships}).
        if ($httpCode >= 200 && $httpCode < 300) {
            if (isset($decoded['memberships']) && is_array($decoded['memberships'])) {
                return $decoded['memberships'];
            }
            if (isset($decoded['data']['memberships']) && is_array($decoded['data']['memberships'])) {
                return $decoded['data']['memberships'];
            }
            return [];
        }

        throw new \RuntimeException(
            'Auth service returned HTTP ' . $httpCode . ': ' . ($decoded['message'] ?? 'unknown error')
        );
    }
}
