<?php

namespace StoneScriptPHP\Auth;

use StoneScriptPHP\Tenancy\Tenant;
use StoneScriptPHP\Database;

/**
 * RequestContextTrait
 *
 * Provides convenient access to authentication and tenant context
 * from JWT claims stored in the request by ValidateJwtMiddleware.
 *
 * Usage in controllers:
 * ```php
 * class MyController {
 *     use RequestContextTrait;
 *
 *     public function handle(array $request): ApiResponse {
 *         $identity = $this->identity($request);
 *         $tenant = $this->tenant($request);
 *         $user = $this->localUser($request);
 *
 *         // ... use context
 *     }
 * }
 * ```
 */
trait RequestContextTrait
{
    /**
     * Get Identity from JWT claims
     *
     * @param array $request Request array with jwt_claims
     * @return Identity|null Identity object or null if no identity in token
     */
    protected function identity(array $request): ?Identity
    {
        $claims = $this->getJwtClaims($request);
        if ($claims === null) {
            return null;
        }

        return Identity::fromJWT($claims);
    }

    /**
     * Get Membership from JWT claims
     *
     * @param array $request Request array with jwt_claims
     * @return Membership|null Membership object or null if no membership info
     */
    protected function membership(array $request): ?Membership
    {
        $claims = $this->getJwtClaims($request);
        if ($claims === null) {
            return null;
        }

        return Membership::fromJWT($claims);
    }

    /**
     * Get Tenant from JWT claims
     *
     * @param array $request Request array with jwt_claims
     * @return Tenant|null Tenant object or null if no tenant info
     */
    protected function tenant(array $request): ?Tenant
    {
        $claims = $this->getJwtClaims($request);
        if ($claims === null) {
            return null;
        }

        try {
            return Tenant::fromJWT($claims);
        } catch (\InvalidArgumentException $e) {
            // No tenant_id in claims
            return null;
        }
    }

    /**
     * Fetch local user from tenant database
     *
     * Fetches the user record from the tenant's database using the local_user_id
     * from JWT claims. Returns raw array from database.
     *
     * Note: Override this method in your controller to return a custom User model
     * or call a specific database function for your application.
     *
     * @param array $request Request array with jwt_claims
     * @param string $functionName Optional database function name (default: 'get_user_by_id')
     * @return array|null User record or null if not found
     */
    protected function localUser(array $request, string $functionName = 'get_user_by_id'): ?array
    {
        $claims = $this->getJwtClaims($request);
        if ($claims === null) {
            return null;
        }

        $localUserId = $claims['local_user_id'] ?? $claims['luid'] ?? null;
        if ($localUserId === null) {
            return null;
        }

        try {
            // Call database function to fetch user
            // Assumes function signature: get_user_by_id(user_id int) returns table
            $result = Database::fn($functionName, ['user_id' => $localUserId]);

            return !empty($result) ? $result[0] : null;
        } catch (\Exception $e) {
            error_log("Failed to fetch local user: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get identity ID from JWT claims
     *
     * @param array $request Request array with jwt_claims
     * @return string|null Identity ID or null
     */
    protected function identityId(array $request): ?string
    {
        $identity = $this->identity($request);
        return $identity ? (string) $identity->id : null;
    }

    /**
     * Get tenant ID from JWT claims
     *
     * @param array $request Request array with jwt_claims
     * @return string|null Tenant ID or null
     */
    protected function tenantId(array $request): ?string
    {
        $tenant = $this->tenant($request);
        return $tenant ? (string) $tenant->id : null;
    }

    /**
     * Get role from JWT claims
     *
     * @param array $request Request array with jwt_claims
     * @return string|null Role name or null
     */
    protected function role(array $request): ?string
    {
        $identity = $this->identity($request);
        return $identity?->role;
    }

    /**
     * Check if user has a specific role
     *
     * @param array $request Request array with jwt_claims
     * @param string $role Role to check
     * @return bool True if user has the role
     */
    protected function hasRole(array $request, string $role): bool
    {
        $userRole = $this->role($request);
        return $userRole !== null && $userRole === $role;
    }

    /**
     * Require a specific role, throw 403 if not met
     *
     * @param array $request Request array with jwt_claims
     * @param string $role Required role
     * @throws \RuntimeException With 403 code if role requirement not met
     */
    protected function requireRole(array $request, string $role): void
    {
        if (!$this->hasRole($request, $role)) {
            http_response_code(403);
            throw new \RuntimeException(
                "Forbidden: This action requires '{$role}' role",
                403
            );
        }
    }

    /**
     * Get JWT claims from request
     *
     * @param array $request Request array
     * @return array|null JWT claims or null if not present
     */
    private function getJwtClaims(array $request): ?array
    {
        return $request['jwt_claims'] ?? null;
    }
}
