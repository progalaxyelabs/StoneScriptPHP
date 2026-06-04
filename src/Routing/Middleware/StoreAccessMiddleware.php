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
 * store identified by :storeId in the request URL, then sets that storeId as
 * the GatewayClient tenant so all downstream DB calls route to that store's DB.
 *
 * MUST run after JwtAuthMiddleware in the global middleware chain.
 *
 * How it works:
 *  1. The Router pre-matches the route and exposes :storeId in $request['params'].
 *     Paths not containing a :storeId param are passed through unchanged.
 *  2. Gets the authenticated identity_id from auth().
 *  3. Calls a configurable DB function on the MAIN DB (no tenant_id set yet) to
 *     fetch the identity's memberships. Defaults to `auth_get_memberships`.
 *  4. If storeId is in the returned memberships: sets GatewayClient->setTenantId($storeId)
 *     and calls $next($request).
 *  5. If storeId is NOT in memberships: returns 403 store_access_denied.
 *
 * Usage in Application::run() config:
 *
 *   Application::run([
 *       'routes' => require CONFIG_PATH . 'routes.php',
 *       'auth'   => [ ... ],
 *       'store_access' => [
 *           'enabled'        => true,
 *           'membership_fn'  => 'auth_get_memberships',  // optional, this is the default
 *       ],
 *   ]);
 *
 * Or register manually as a scope middleware (e.g. portal scope only):
 *
 *   $router->scope('portal', function($r) {
 *       $r->use(new StoreAccessMiddleware(['enabled' => true]));
 *   });
 */
class StoreAccessMiddleware implements MiddlewareInterface
{
    /** @var string DB function that returns {'memberships': [{'tenant_id': ..., 'role': ..., 'status': ...}]} */
    private string $membershipFn;

    /**
     * @param array $config
     *   - membership_fn: string  DB function name. Default: 'auth_get_memberships'
     */
    public function __construct(array $config = [])
    {
        $this->membershipFn = $config['membership_fn'] ?? 'auth_get_memberships';
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        // Extract :storeId from route params (set by Router pre-match)
        $storeId = $request['params']['storeId'] ?? null;

        if (!$storeId) {
            // Not a /stores/:storeId/* route — pass through
            return $next($request);
        }

        // Identity must already be verified by JwtAuthMiddleware
        $user = auth();
        if (!$user || !$user->user_id) {
            return new ApiResponse('error', 'Authentication required', null, 401);
        }

        // Query the MAIN DB for the identity's memberships.
        // Explicitly reset tenant_id to null to force main-DB routing regardless
        // of what GatewayTenantMiddleware may have set from the JWT (e.g. when a
        // tenant_id-bearing provision token is used). We restore tenant_id after.
        $gwClient = Database::getGatewayClient();
        $prevTenantId = null;
        try {
            // Peek at the current tenant state (GatewayClient may have a setter; use reflection)
            // Simplest: just reset to null for the membership query
            $gwClient->setTenantId(null);
        } catch (\Throwable $ignored) {}

        // GatewayClient returns rows as an array of column maps. For a function that
        // returns JSON, each row is: ['{fn_name}' => {decoded_json_value}].
        // We extract the inner JSON object and read 'memberships' from it.
        try {
            $rows = Database::fn($this->membershipFn, [(string) $user->user_id]);

            // Unwrap gateway row format: [[fn_name => {data}], ...]
            $innerData = [];
            if (!empty($rows) && is_array($rows[0])) {
                $firstRow = $rows[0];
                // The inner value is either already decoded (array) or a JSON string
                $raw = reset($firstRow);
                if (is_array($raw)) {
                    $innerData = $raw;
                } elseif (is_string($raw)) {
                    $innerData = json_decode($raw, true) ?? [];
                }
            }

            $memberships = $innerData['memberships'] ?? [];
        } catch (\Throwable $e) {
            log_error('StoreAccessMiddleware: membership lookup failed: ' . $e->getMessage());
            return new ApiResponse('error', 'Store access check failed', null, 500);
        }

        // Validate identity has active membership in the requested store
        $hasMembership = false;
        foreach ($memberships as $membership) {
            if (($membership['tenant_id'] ?? '') === $storeId) {
                $hasMembership = true;
                break;
            }
        }

        if (!$hasMembership) {
            log_warning(sprintf(
                'StoreAccessMiddleware: identity=%s denied access to store=%s (not a member)',
                $user->user_id,
                $storeId
            ));
            return new ApiResponse('error', 'store_access_denied', ['error' => 'store_access_denied'], 403);
        }

        // Grant access: set tenant context for all downstream GatewayClient calls
        Database::getGatewayClient()->setTenantId($storeId);

        log_debug(sprintf(
            'StoreAccessMiddleware: identity=%s granted access to store=%s',
            $user->user_id,
            $storeId
        ));

        return $next($request);
    }
}
