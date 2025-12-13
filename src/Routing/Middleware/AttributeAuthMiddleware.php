<?php

namespace StoneScriptPHP\Routing\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Attributes\RequiresPermission;
use StoneScriptPHP\Attributes\RequiresRole;
use App\Models\User;
use ReflectionClass;

/**
 * Middleware that automatically checks RequiresPermission and RequiresRole attributes
 * on handler classes and their process methods
 */
class AttributeAuthMiddleware implements MiddlewareInterface
{
    public function handle(array $request, callable $next): ?ApiResponse
    {
        // Get the handler class from route match
        // This requires the router to pass handler info in request
        if (!isset($request['handler_class'])) {
            // No handler class info, skip attribute checking
            return $next($request);
        }

        $handlerClass = $request['handler_class'];

        if (!class_exists($handlerClass)) {
            return $next($request);
        }

        // Check if user is authenticated
        if (!isset($request['user']) || !($request['user'] instanceof User)) {
            // Try to proceed - the handler might not require auth
            return $this->checkAttributes($handlerClass, null, $request, $next);
        }

        $user = $request['user'];

        return $this->checkAttributes($handlerClass, $user, $request, $next);
    }

    private function checkAttributes(string $handlerClass, ?User $user, array $request, callable $next): ?ApiResponse
    {
        $reflection = new ReflectionClass($handlerClass);

        // Check class-level attributes
        $classAttributes = $reflection->getAttributes();

        foreach ($classAttributes as $attribute) {
            $attrInstance = $attribute->newInstance();

            if ($attrInstance instanceof RequiresPermission) {
                $result = $this->checkPermissionAttribute($attrInstance, $user);
                if ($result !== null) {
                    return $result;
                }
            }

            if ($attrInstance instanceof RequiresRole) {
                $result = $this->checkRoleAttribute($attrInstance, $user);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        // Check method-level attributes (on process method)
        if ($reflection->hasMethod('process')) {
            $method = $reflection->getMethod('process');
            $methodAttributes = $method->getAttributes();

            foreach ($methodAttributes as $attribute) {
                $attrInstance = $attribute->newInstance();

                if ($attrInstance instanceof RequiresPermission) {
                    $result = $this->checkPermissionAttribute($attrInstance, $user);
                    if ($result !== null) {
                        return $result;
                    }
                }

                if ($attrInstance instanceof RequiresRole) {
                    $result = $this->checkRoleAttribute($attrInstance, $user);
                    if ($result !== null) {
                        return $result;
                    }
                }
            }
        }

        // All attribute checks passed
        return $next($request);
    }

    private function checkPermissionAttribute(RequiresPermission $attribute, ?User $user): ?ApiResponse
    {
        // If user is not authenticated and permission is required
        if ($user === null) {
            log_debug('Attribute auth: Permission required but user not authenticated');
            http_response_code(401);
            return new ApiResponse('error', 'Unauthorized: Authentication required');
        }

        $permissions = $attribute->getPermissions();
        $requireAll = $attribute->requiresAll();

        if ($requireAll) {
            if (!$user->hasAllPermissions($permissions)) {
                log_debug('Attribute auth: User missing required permissions (ALL): ' . implode(', ', $permissions));
                http_response_code(403);
                return new ApiResponse('error', 'Forbidden: Insufficient permissions');
            }
        } else {
            if (!$user->hasAnyPermission($permissions)) {
                log_debug('Attribute auth: User missing required permissions (ANY): ' . implode(', ', $permissions));
                http_response_code(403);
                return new ApiResponse('error', 'Forbidden: Insufficient permissions');
            }
        }

        log_debug('Attribute auth: Permission check passed');
        return null; // Check passed
    }

    private function checkRoleAttribute(RequiresRole $attribute, ?User $user): ?ApiResponse
    {
        // If user is not authenticated and role is required
        if ($user === null) {
            log_debug('Attribute auth: Role required but user not authenticated');
            http_response_code(401);
            return new ApiResponse('error', 'Unauthorized: Authentication required');
        }

        $roles = $attribute->getRoles();
        $requireAll = $attribute->requiresAll();

        if ($requireAll) {
            if (!$user->hasAllRoles($roles)) {
                log_debug('Attribute auth: User missing required roles (ALL): ' . implode(', ', $roles));
                http_response_code(403);
                return new ApiResponse('error', 'Forbidden: Insufficient roles');
            }
        } else {
            if (!$user->hasAnyRole($roles)) {
                log_debug('Attribute auth: User missing required roles (ANY): ' . implode(', ', $roles));
                http_response_code(403);
                return new ApiResponse('error', 'Forbidden: Insufficient roles');
            }
        }

        log_debug('Attribute auth: Role check passed');
        return null; // Check passed
    }
}
