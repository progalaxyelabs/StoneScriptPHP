<?php

namespace Framework\Http\Middleware;

use Framework\Http\MiddlewareInterface;
use Framework\ApiResponse;
use App\Models\User;

class RoleMiddleware implements MiddlewareInterface
{
    private array $requiredRoles;
    private bool $requireAll;

    /**
     * @param array $requiredRoles Array of role names required
     * @param bool $requireAll If true, user must have ALL roles. If false, ANY role is sufficient
     */
    public function __construct(array $requiredRoles, bool $requireAll = false)
    {
        $this->requiredRoles = $requiredRoles;
        $this->requireAll = $requireAll;
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        // Check if user is in request context (should be set by auth middleware)
        if (!isset($request['user']) || !($request['user'] instanceof User)) {
            log_debug('Role middleware: User not found in request context');
            http_response_code(401);
            return new ApiResponse('error', 'Unauthorized: Authentication required');
        }

        $user = $request['user'];

        // Check role requirements
        if ($this->requireAll) {
            if (!$user->hasAllRoles($this->requiredRoles)) {
                log_debug('Role middleware: User does not have all required roles');
                http_response_code(403);
                return new ApiResponse('error', 'Forbidden: Insufficient roles');
            }
        } else {
            if (!$user->hasAnyRole($this->requiredRoles)) {
                log_debug('Role middleware: User does not have any required role');
                http_response_code(403);
                return new ApiResponse('error', 'Forbidden: Insufficient roles');
            }
        }

        log_debug('Role middleware: User has required roles');
        return $next($request);
    }
}
