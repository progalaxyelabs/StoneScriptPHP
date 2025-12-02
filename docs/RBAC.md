# RBAC (Role-Based Access Control) System

## Overview

The StoneScriptPHP RBAC system provides a comprehensive role and permission-based access control mechanism. It includes:

- **Permissions**: Fine-grained access controls for specific actions on resources
- **Roles**: Collections of permissions that can be assigned to users
- **User RBAC**: Methods to check user roles and permissions
- **Middleware**: Route-level protection for roles and permissions
- **Attributes**: Declarative access control using PHP 8 attributes

## Database Schema

### Tables

1. **permissions** - Stores individual permissions
2. **roles** - Stores role definitions
3. **role_permissions** - Junction table linking roles to permissions
4. **user_roles** - Junction table linking users to roles
5. **user_permissions** - Direct user permissions (optional, for granular control)

### Installation

Run the table creation scripts in order:

```bash
psql -d your_database -f src/postgresql/tables/permissions.pgsql
psql -d your_database -f src/postgresql/tables/roles.pgsql
psql -d your_database -f src/postgresql/tables/role_permissions.pgsql
psql -d your_database -f src/postgresql/tables/user_roles.pgsql
psql -d your_database -f src/postgresql/tables/user_permissions.pgsql
```

### Seed Default Data

```bash
psql -d your_database -f src/postgresql/seeders/rbac_seed.pgsql
```

This creates default roles:
- **super_admin** - Full system access
- **admin** - Most privileges
- **moderator** - Content management
- **user** - Basic access
- **guest** - Read-only access

## Models

### Permission Model

```php
use App\Models\Permission;

$permission = new Permission([
    'name' => 'users.create',
    'description' => 'Create new users',
    'resource' => 'users',
    'action' => 'create'
]);

// Get full permission name (resource.action)
$fullName = $permission->getFullName(); // "users.create"
```

### Role Model

```php
use App\Models\Role;
use App\Models\Permission;

$role = new Role([
    'name' => 'editor',
    'description' => 'Content editor',
    'is_system_role' => false
]);

// Add permission to role
$permission = new Permission([...]);
$role->addPermission($permission);

// Check if role has permission
if ($role->hasPermission('content.create')) {
    // Role has permission
}
```

### User Model with RBAC

```php
use App\Models\User;

$user = User::fromDatabase($userData);

// Check single role
if ($user->hasRole('admin')) {
    // User is admin
}

// Check multiple roles (ANY)
if ($user->hasAnyRole(['admin', 'moderator'])) {
    // User has at least one of these roles
}

// Check multiple roles (ALL)
if ($user->hasAllRoles(['user', 'verified'])) {
    // User has all these roles
}

// Check single permission
if ($user->hasPermission('users.create')) {
    // User can create users
}

// Check multiple permissions (ANY)
if ($user->hasAnyPermission(['content.create', 'content.update'])) {
    // User can create OR update content
}

// Check multiple permissions (ALL)
if ($user->hasAllPermissions(['content.create', 'content.publish'])) {
    // User can create AND publish content
}

// Get all permissions (direct + role-based)
$allPermissions = $user->getAllPermissions();
```

## Middleware

### RoleMiddleware

Protect routes based on user roles:

```php
use Framework\Http\Middleware\RoleMiddleware;
use Framework\Routing\Router;

$router = new Router();

// Require admin role
$router->get('/admin/users', 'App\Handlers\AdminUsersHandler', [
    new RoleMiddleware(['admin'])
]);

// Require ANY of multiple roles
$router->get('/dashboard', 'App\Handlers\DashboardHandler', [
    new RoleMiddleware(['admin', 'moderator'], false) // false = ANY role
]);

// Require ALL roles
$router->get('/super-admin', 'App\Handlers\SuperAdminHandler', [
    new RoleMiddleware(['admin', 'verified'], true) // true = ALL roles
]);
```

### PermissionMiddleware

Protect routes based on permissions:

```php
use Framework\Http\Middleware\PermissionMiddleware;

// Require specific permission
$router->post('/users', 'App\Handlers\CreateUserHandler', [
    new PermissionMiddleware(['users.create'])
]);

// Require ALL permissions
$router->post('/publish', 'App\Handlers\PublishHandler', [
    new PermissionMiddleware(['content.create', 'content.publish'], true)
]);

// Require ANY permission
$router->get('/content', 'App\Handlers\ContentHandler', [
    new PermissionMiddleware(['content.view', 'content.list'], false)
]);
```

## Attributes (Decorators)

Use PHP 8 attributes for declarative access control:

### RequiresRole Attribute

```php
use Framework\Attributes\RequiresRole;
use Framework\IRouteHandler;

#[RequiresRole('admin')]
class AdminPanelHandler implements IRouteHandler
{
    public function process()
    {
        // Only accessible by admins
    }
}

#[RequiresRole(['admin', 'moderator'])] // ANY role
class ModerationHandler implements IRouteHandler
{
    public function process()
    {
        // Accessible by admin OR moderator
    }
}

#[RequiresRole(['admin', 'verified'], requireAll: true)] // ALL roles
class SecureHandler implements IRouteHandler
{
    public function process()
    {
        // Requires both admin AND verified roles
    }
}
```

### RequiresPermission Attribute

```php
use Framework\Attributes\RequiresPermission;

#[RequiresPermission('users.create')]
class CreateUserHandler implements IRouteHandler
{
    public function process()
    {
        // Only accessible with users.create permission
    }
}

#[RequiresPermission(['content.create', 'content.publish'])] // ALL by default
class PublishContentHandler implements IRouteHandler
{
    public function process()
    {
        // Requires both permissions
    }
}

#[RequiresPermission(['users.view', 'users.list'], requireAll: false)] // ANY
class ViewUsersHandler implements IRouteHandler
{
    public function process()
    {
        // Requires at least one permission
    }
}
```

## Complete Example

Here's a complete example showing how to use the RBAC system:

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Framework\Routing\Router;
use Framework\Http\Middleware\AuthMiddleware;
use Framework\Http\Middleware\RoleMiddleware;
use Framework\Http\Middleware\PermissionMiddleware;
use App\Models\User;

// Initialize router
$router = new Router();

// Custom auth validator that loads user with roles and permissions
$authValidator = function($token) use (&$userContext) {
    // Validate token and load user from database
    $userId = validateToken($token); // Your token validation logic

    if (!$userId) {
        return false;
    }

    // Load user with roles and permissions from database
    $user = loadUserWithRBAC($userId); // Your database loading logic

    // Store user in global context for middleware
    $userContext = $user;

    return true;
};

// Global auth middleware
$router->use(new AuthMiddleware($authValidator));

// Middleware to inject user into request
$router->use(new class implements Framework\Http\MiddlewareInterface {
    public function handle(array $request, callable $next) {
        global $userContext;
        if ($userContext) {
            $request['user'] = $userContext;
        }
        return $next($request);
    }
});

// Public routes (no RBAC)
$router->get('/health', 'App\Handlers\HealthHandler');

// Role-protected routes
$router->get('/admin/dashboard', 'App\Handlers\AdminDashboardHandler', [
    new RoleMiddleware(['admin'])
]);

$router->get('/moderator/panel', 'App\Handlers\ModeratorPanelHandler', [
    new RoleMiddleware(['admin', 'moderator'], false) // ANY role
]);

// Permission-protected routes
$router->post('/users', 'App\Handlers\CreateUserHandler', [
    new PermissionMiddleware(['users.create'])
]);

$router->delete('/users/:id', 'App\Handlers\DeleteUserHandler', [
    new PermissionMiddleware(['users.delete'])
]);

// Multiple middlewares
$router->post('/content/publish', 'App\Handlers\PublishContentHandler', [
    new RoleMiddleware(['admin', 'editor'], false),
    new PermissionMiddleware(['content.publish'])
]);

// Dispatch request
$response = $router->dispatch();
echo $response->toJson();
```

## Helper Function Example

Create a helper to load users with RBAC data:

```php
function loadUserWithRBAC(int $userId): ?User
{
    global $db; // Your database connection

    // Load user
    $userData = $db->query(
        "SELECT * FROM users WHERE user_id = $1",
        [$userId]
    )->fetch();

    if (!$userData) {
        return null;
    }

    $user = User::fromDatabase($userData);

    // Load user roles with their permissions
    $roles = $db->query("
        SELECT r.*,
               json_agg(
                   json_build_object(
                       'permission_id', p.permission_id,
                       'name', p.name,
                       'description', p.description,
                       'resource', p.resource,
                       'action', p.action
                   )
               ) as permissions
        FROM roles r
        JOIN user_roles ur ON r.role_id = ur.role_id
        LEFT JOIN role_permissions rp ON r.role_id = rp.role_id
        LEFT JOIN permissions p ON rp.permission_id = p.permission_id
        WHERE ur.user_id = $1
        GROUP BY r.role_id
    ", [$userId])->fetchAll();

    foreach ($roles as $roleData) {
        $role = Role::fromDatabase($roleData);

        if ($roleData['permissions']) {
            $permissions = json_decode($roleData['permissions'], true);
            foreach ($permissions as $permData) {
                if ($permData['permission_id']) {
                    $role->addPermission(Permission::fromDatabase($permData));
                }
            }
        }

        $user->addRole($role);
    }

    // Load direct user permissions
    $directPermissions = $db->query("
        SELECT p.*
        FROM permissions p
        JOIN user_permissions up ON p.permission_id = up.permission_id
        WHERE up.user_id = $1
    ", [$userId])->fetchAll();

    foreach ($directPermissions as $permData) {
        $user->addPermission(Permission::fromDatabase($permData));
    }

    return $user;
}
```

## Best Practices

1. **Use Permissions over Roles** - Check for specific permissions rather than roles when possible
2. **Principle of Least Privilege** - Grant minimum necessary permissions
3. **System Roles** - Mark critical roles as `is_system_role = true` to prevent deletion
4. **Naming Convention** - Use `resource.action` format for permission names
5. **Cache User RBAC** - Cache loaded roles/permissions to reduce database queries
6. **Audit Trail** - Log role/permission changes for security auditing
7. **Direct Permissions** - Use user_permissions table sparingly for exceptional cases

## API Endpoints (Example)

Here are example handlers for RBAC management:

### Assign Role to User

```php
#[RequiresPermission('roles.assign')]
class AssignRoleHandler implements IRouteHandler
{
    public int $user_id;
    public int $role_id;

    public function process()
    {
        global $db;

        $db->query(
            "INSERT INTO user_roles (user_id, role_id) VALUES ($1, $2)
             ON CONFLICT (user_id, role_id) DO NOTHING",
            [$this->user_id, $this->role_id]
        );

        return new ApiResponse('success', 'Role assigned successfully');
    }
}
```

### Grant Permission to Role

```php
#[RequiresPermission('permissions.assign')]
class GrantPermissionToRoleHandler implements IRouteHandler
{
    public int $role_id;
    public int $permission_id;

    public function process()
    {
        global $db;

        $db->query(
            "INSERT INTO role_permissions (role_id, permission_id) VALUES ($1, $2)
             ON CONFLICT (role_id, permission_id) DO NOTHING",
            [$this->role_id, $this->permission_id]
        );

        return new ApiResponse('success', 'Permission granted to role');
    }
}
```

## Troubleshooting

- **403 Forbidden** - User lacks required role or permission
- **401 Unauthorized** - User not authenticated (auth middleware failed)
- **User object not in request** - Auth middleware must populate `$request['user']`
- **Permissions not loading** - Check database queries and user loading logic
