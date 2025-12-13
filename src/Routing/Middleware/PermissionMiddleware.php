<?php

namespace Framework\Routing\Middleware;

use Framework\Routing\MiddlewareInterface;
use Framework\ApiResponse;
use App\Models\User;

class PermissionMiddleware implements MiddlewareInterface
{
    private array $requiredPermissions;
    private bool $requireAll;

    /**
     * @param array $requiredPermissions Array of permission names required
     * @param bool $requireAll If true, user must have ALL permissions. If false, ANY permission is sufficient
     */
    public function __construct(array $requiredPermissions, bool $requireAll = true)
    {
        $this->requiredPermissions = $requiredPermissions;
        $this->requireAll = $requireAll;
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        // Check if user is in request context (should be set by auth middleware)
        if (!isset($request['user']) || !($request['user'] instanceof User)) {
            log_debug('Permission middleware: User not found in request context');
            http_response_code(401);
            return new ApiResponse('error', 'Unauthorized: Authentication required');
        }

        $user = $request['user'];

        // Check permission requirements
        if ($this->requireAll) {
            if (!$user->hasAllPermissions($this->requiredPermissions)) {
                log_debug('Permission middleware: User does not have all required permissions');
                http_response_code(403);
                return new ApiResponse('error', 'Forbidden: Insufficient permissions');
            }
        } else {
            if (!$user->hasAnyPermission($this->requiredPermissions)) {
                log_debug('Permission middleware: User does not have any required permission');
                http_response_code(403);
                return new ApiResponse('error', 'Forbidden: Insufficient permissions');
            }
        }

        log_debug('Permission middleware: User has required permissions');
        return $next($request);
    }
}
