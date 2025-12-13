# Complete RBAC Example

This file demonstrates a complete working example of the RBAC system integration.

## Setup Application with RBAC

```php
<?php
// index.php - Main application entry point

require_once __DIR__ . '/vendor/autoload.php';

use Framework\Routing\Router;
use Framework\Http\Middleware\AuthMiddleware;
use Framework\Http\Middleware\AttributeAuthMiddleware;
use Framework\Http\Middleware\RoleMiddleware;
use Framework\Http\Middleware\PermissionMiddleware;
use App\Repositories\UserRepository;

// Initialize database connection
$db = new PDO('pgsql:host=localhost;dbname=myapp', 'user', 'pass');

// Initialize repositories
$userRepo = new UserRepository($db);

// Global variable to hold authenticated user
$currentUser = null;

// Custom authentication validator
$authValidator = function($token) use ($userRepo, &$currentUser) {
    // In real app, validate JWT or session token
    $userId = validateJWTToken($token); // Your implementation

    if (!$userId) {
        return false;
    }

    // Load user with all RBAC data (roles and permissions)
    $currentUser = $userRepo->findById($userId);

    return $currentUser !== null;
};

// Initialize router
$router = new Router();

// Add global middleware
$router->useMany([
    // Authentication middleware (validates token)
    new AuthMiddleware($authValidator, [
        '/health',
        '/login',
        '/register'
    ]),

    // Inject user into request context
    new class implements Framework\Http\MiddlewareInterface {
        public function handle(array $request, callable $next) {
            global $currentUser;
            if ($currentUser) {
                $request['user'] = $currentUser;
            }
            return $next($request);
        }
    },

    // Attribute-based authorization (checks #[RequiresPermission] and #[RequiresRole])
    new AttributeAuthMiddleware()
]);

// Define routes

// Public routes
$router->get('/health', 'App\Handlers\HealthCheckHandler');
$router->post('/login', 'App\Handlers\LoginHandler');
$router->post('/register', 'App\Handlers\RegisterHandler');

// Admin routes - using middleware
$router->get('/admin/dashboard', 'App\Handlers\Admin\DashboardHandler', [
    new RoleMiddleware(['admin'])
]);

$router->get('/admin/users', 'App\Handlers\Admin\ListUsersHandler', [
    new PermissionMiddleware(['users.list'])
]);

// User management - using attributes on handlers
$router->post('/users', 'App\Handlers\Users\CreateUserHandler');
$router->put('/users/:id', 'App\Handlers\Users\UpdateUserHandler');
$router->delete('/users/:id', 'App\Handlers\Users\DeleteUserHandler');

// Content management - using middleware
$router->post('/content', 'App\Handlers\Content\CreateContentHandler', [
    new PermissionMiddleware(['content.create'])
]);

$router->post('/content/publish', 'App\Handlers\Content\PublishContentHandler', [
    new PermissionMiddleware(['content.create', 'content.publish'], true) // ALL required
]);

// Moderation - require admin OR moderator role
$router->get('/moderation/queue', 'App\Handlers\Moderation\QueueHandler', [
    new RoleMiddleware(['admin', 'moderator'], false) // ANY role
]);

// Dispatch request
$response = $router->dispatch();
echo $response->toJson();
```

## Example Handlers with Attributes

### Create User Handler

```php
<?php

namespace App\Handlers\Users;

use Framework\IRouteHandler;
use Framework\ApiResponse;
use Framework\Attributes\RequiresPermission;
use App\Repositories\UserRepository;

#[RequiresPermission('users.create')]
class CreateUserHandler implements IRouteHandler
{
    public string $name;
    public string $email;

    public function process(): ApiResponse
    {
        global $db;

        // Validation
        if (empty($this->name) || empty($this->email)) {
            http_response_code(400);
            return new ApiResponse('error', 'Name and email are required');
        }

        // Create user
        $result = $db->query(
            "INSERT INTO users (name, email) VALUES ($1, $2) RETURNING *",
            [$this->name, $this->email]
        );

        $userData = $result->fetch();

        http_response_code(201);
        return new ApiResponse('success', 'User created', $userData);
    }
}
```

### Delete User Handler

```php
<?php

namespace App\Handlers\Users;

use Framework\IRouteHandler;
use Framework\ApiResponse;
use Framework\Attributes\RequiresPermission;

#[RequiresPermission('users.delete')]
class DeleteUserHandler implements IRouteHandler
{
    public int $id;

    public function process(): ApiResponse
    {
        global $db;

        // Check if user exists
        $result = $db->query(
            "SELECT user_id FROM users WHERE user_id = $1",
            [$this->id]
        );

        if (!$result->fetch()) {
            http_response_code(404);
            return new ApiResponse('error', 'User not found');
        }

        // Delete user
        $db->query("DELETE FROM users WHERE user_id = $1", [$this->id]);

        return new ApiResponse('success', 'User deleted');
    }
}
```

### Admin Dashboard Handler

```php
<?php

namespace App\Handlers\Admin;

use Framework\IRouteHandler;
use Framework\ApiResponse;
use Framework\Attributes\RequiresRole;

#[RequiresRole('admin')]
class DashboardHandler implements IRouteHandler
{
    public function process(): ApiResponse
    {
        global $db;

        // Get statistics
        $stats = [
            'total_users' => $db->query("SELECT COUNT(*) as count FROM users")->fetch()['count'],
            'total_roles' => $db->query("SELECT COUNT(*) as count FROM roles")->fetch()['count'],
            'total_permissions' => $db->query("SELECT COUNT(*) as count FROM permissions")->fetch()['count'],
        ];

        return new ApiResponse('success', 'Dashboard data', $stats);
    }
}
```

### Publish Content Handler

```php
<?php

namespace App\Handlers\Content;

use Framework\IRouteHandler;
use Framework\ApiResponse;
use Framework\Attributes\RequiresPermission;

// Requires BOTH permissions
#[RequiresPermission(['content.create', 'content.publish'], requireAll: true)]
class PublishContentHandler implements IRouteHandler
{
    public string $title;
    public string $content;

    public function process(): ApiResponse
    {
        global $db, $currentUser;

        // Create and publish content in one step
        $result = $db->query(
            "INSERT INTO content (title, content, author_id, status, published_at)
             VALUES ($1, $2, $3, 'published', CURRENT_TIMESTAMP)
             RETURNING *",
            [$this->title, $this->content, $currentUser->user_id]
        );

        $contentData = $result->fetch();

        http_response_code(201);
        return new ApiResponse('success', 'Content published', $contentData);
    }
}
```

### Moderation Queue Handler

```php
<?php

namespace App\Handlers\Moderation;

use Framework\IRouteHandler;
use Framework\ApiResponse;
use Framework\Attributes\RequiresRole;

// Requires admin OR moderator (ANY role)
#[RequiresRole(['admin', 'moderator'], requireAll: false)]
class QueueHandler implements IRouteHandler
{
    public function process(): ApiResponse
    {
        global $db;

        // Get items pending moderation
        $result = $db->query(
            "SELECT * FROM content WHERE status = 'pending' ORDER BY created_at DESC LIMIT 50"
        );

        $items = $result->fetchAll();

        return new ApiResponse('success', 'Moderation queue', ['items' => $items]);
    }
}
```

## RBAC Management Handlers

### Assign Role to User

```php
<?php

namespace App\Handlers\RBAC;

use Framework\IRouteHandler;
use Framework\ApiResponse;
use Framework\Attributes\RequiresPermission;
use App\Repositories\UserRepository;
use App\Repositories\RoleRepository;

#[RequiresPermission('roles.assign')]
class AssignRoleHandler implements IRouteHandler
{
    public int $user_id;
    public int $role_id;

    public function process(): ApiResponse
    {
        global $db;

        $userRepo = new UserRepository($db);
        $roleRepo = new RoleRepository($db);

        // Validate user exists
        $user = $userRepo->findById($this->user_id);
        if (!$user) {
            http_response_code(404);
            return new ApiResponse('error', 'User not found');
        }

        // Validate role exists
        $role = $roleRepo->findById($this->role_id);
        if (!$role) {
            http_response_code(404);
            return new ApiResponse('error', 'Role not found');
        }

        // Assign role
        $userRepo->assignRole($this->user_id, $this->role_id);

        return new ApiResponse('success', "Role '{$role->name}' assigned to user '{$user->name}'");
    }
}
```

### Create Permission

```php
<?php

namespace App\Handlers\RBAC;

use Framework\IRouteHandler;
use Framework\ApiResponse;
use Framework\Attributes\RequiresPermission;
use App\Repositories\PermissionRepository;

#[RequiresPermission('permissions.create')]
class CreatePermissionHandler implements IRouteHandler
{
    public string $name;
    public string $resource;
    public string $action;
    public ?string $description = null;

    public function process(): ApiResponse
    {
        global $db;

        $permRepo = new PermissionRepository($db);

        // Check if permission already exists
        if ($permRepo->findByName($this->name)) {
            http_response_code(409);
            return new ApiResponse('error', 'Permission already exists');
        }

        // Create permission
        $permission = $permRepo->create([
            'name' => $this->name,
            'resource' => $this->resource,
            'action' => $this->action,
            'description' => $this->description
        ]);

        http_response_code(201);
        return new ApiResponse('success', 'Permission created', $permission->toArray());
    }
}
```

### Grant Permission to Role

```php
<?php

namespace App\Handlers\RBAC;

use Framework\IRouteHandler;
use Framework\ApiResponse;
use Framework\Attributes\RequiresPermission;
use App\Repositories\RoleRepository;
use App\Repositories\PermissionRepository;

#[RequiresPermission('permissions.assign')]
class GrantPermissionToRoleHandler implements IRouteHandler
{
    public int $role_id;
    public int $permission_id;

    public function process(): ApiResponse
    {
        global $db;

        $roleRepo = new RoleRepository($db);
        $permRepo = new PermissionRepository($db);

        // Validate role exists
        $role = $roleRepo->findById($this->role_id);
        if (!$role) {
            http_response_code(404);
            return new ApiResponse('error', 'Role not found');
        }

        // Validate permission exists
        $permission = $permRepo->findById($this->permission_id);
        if (!$permission) {
            http_response_code(404);
            return new ApiResponse('error', 'Permission not found');
        }

        // Grant permission to role
        $roleRepo->assignPermission($this->role_id, $this->permission_id);

        return new ApiResponse(
            'success',
            "Permission '{$permission->name}' granted to role '{$role->name}'"
        );
    }
}
```

## Helper Functions

```php
<?php

/**
 * Validate JWT token and return user ID
 */
function validateJWTToken(string $token): ?int
{
    // Implement your JWT validation logic
    // Example using Firebase JWT library:

    try {
        $decoded = \Firebase\JWT\JWT::decode($token, $secretKey, ['HS256']);
        return $decoded->user_id ?? null;
    } catch (\Exception $e) {
        return null;
    }
}

/**
 * Check if current user has permission
 */
function can(string $permission): bool
{
    global $currentUser;
    return $currentUser && $currentUser->hasPermission($permission);
}

/**
 * Check if current user has role
 */
function hasRole(string $role): bool
{
    global $currentUser;
    return $currentUser && $currentUser->hasRole($role);
}

/**
 * Require permission or throw exception
 */
function requirePermission(string $permission): void
{
    if (!can($permission)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Forbidden: Insufficient permissions']);
        exit;
    }
}

/**
 * Require role or throw exception
 */
function requireRole(string $role): void
{
    if (!hasRole($role)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Forbidden: Insufficient role']);
        exit;
    }
}
```

## Usage in Views/Templates

```php
<?php if (can('users.create')): ?>
    <button onclick="createUser()">Create User</button>
<?php endif; ?>

<?php if (hasRole('admin')): ?>
    <a href="/admin/dashboard">Admin Dashboard</a>
<?php endif; ?>

<?php if (can('content.publish')): ?>
    <button onclick="publishContent()">Publish Now</button>
<?php else: ?>
    <button onclick="saveDraft()">Save Draft</button>
<?php endif; ?>
```

## Testing RBAC

```php
<?php

// Create test user
$user = $userRepo->findById(1);

// Test permission checking
assert($user->hasPermission('users.view') === true);
assert($user->hasPermission('users.delete') === false);

// Test role checking
assert($user->hasRole('admin') === true);
assert($user->hasRole('super_admin') === false);

// Test multiple permissions
assert($user->hasAnyPermission(['users.create', 'users.delete']) === true);
assert($user->hasAllPermissions(['users.view', 'users.create']) === true);

// Test multiple roles
assert($user->hasAnyRole(['admin', 'moderator']) === true);
assert($user->hasAllRoles(['admin', 'user']) === false);

echo "All RBAC tests passed!\n";
```

## Complete Routes Example

```php
<?php

// Public routes
$router->get('/health', 'App\Handlers\HealthCheckHandler');
$router->post('/login', 'App\Handlers\LoginHandler');

// User management (using attributes)
$router->post('/users', 'App\Handlers\Users\CreateUserHandler');
$router->get('/users/:id', 'App\Handlers\Users\GetUserHandler');
$router->put('/users/:id', 'App\Handlers\Users\UpdateUserHandler');
$router->delete('/users/:id', 'App\Handlers\Users\DeleteUserHandler');

// RBAC management
$router->post('/rbac/assign-role', 'App\Handlers\RBAC\AssignRoleHandler');
$router->post('/rbac/revoke-role', 'App\Handlers\RBAC\RevokeRoleHandler');
$router->post('/rbac/grant-permission', 'App\Handlers\RBAC\GrantPermissionToRoleHandler');
$router->post('/rbac/permissions', 'App\Handlers\RBAC\CreatePermissionHandler');
$router->get('/rbac/permissions', 'App\Handlers\RBAC\ListPermissionsHandler');
$router->post('/rbac/roles', 'App\Handlers\RBAC\CreateRoleHandler');
$router->get('/rbac/roles', 'App\Handlers\RBAC\ListRolesHandler');

// Admin routes (using middleware)
$router->get('/admin/dashboard', 'App\Handlers\Admin\DashboardHandler', [
    new RoleMiddleware(['admin'])
]);

$router->get('/admin/users', 'App\Handlers\Admin\ListUsersHandler', [
    new RoleMiddleware(['admin'])
]);

// Content routes
$router->get('/content', 'App\Handlers\Content\ListContentHandler');
$router->post('/content', 'App\Handlers\Content\CreateContentHandler');
$router->post('/content/publish', 'App\Handlers\Content\PublishContentHandler');

// Dispatch
$response = $router->dispatch();
echo $response->toJson();
```

This complete example demonstrates:
- ✅ Global middleware setup
- ✅ Authentication integration
- ✅ Attribute-based authorization
- ✅ Middleware-based authorization
- ✅ RBAC management handlers
- ✅ Repository usage
- ✅ Helper functions
- ✅ Testing examples
