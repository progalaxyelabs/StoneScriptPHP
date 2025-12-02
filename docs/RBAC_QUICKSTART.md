# RBAC Quick Start Guide

## Installation

### 1. Create Database Tables

```bash
psql -d your_database -f src/postgresql/tables/permissions.pgsql
psql -d your_database -f src/postgresql/tables/roles.pgsql
psql -d your_database -f src/postgresql/tables/role_permissions.pgsql
psql -d your_database -f src/postgresql/tables/user_roles.pgsql
psql -d your_database -f src/postgresql/tables/user_permissions.pgsql
```

### 2. Seed Default Roles and Permissions

```bash
psql -d your_database -f src/postgresql/seeders/rbac_seed.pgsql
```

## Quick Examples

### Protect a Route with Role

```php
use Framework\Http\Middleware\RoleMiddleware;

$router->get('/admin/dashboard', 'App\Handlers\AdminDashboard', [
    new RoleMiddleware(['admin'])
]);
```

### Protect a Route with Permission

```php
use Framework\Http\Middleware\PermissionMiddleware;

$router->post('/users', 'App\Handlers\CreateUser', [
    new PermissionMiddleware(['users.create'])
]);
```

### Use Attributes on Handler

```php
use Framework\Attributes\RequiresPermission;
use Framework\IRouteHandler;

#[RequiresPermission('users.create')]
class CreateUserHandler implements IRouteHandler
{
    public function process()
    {
        // Handler logic
    }
}
```

### Check User Permissions in Code

```php
if ($user->hasPermission('users.delete')) {
    // User can delete users
}

if ($user->hasRole('admin')) {
    // User is an admin
}

if ($user->hasAnyPermission(['content.create', 'content.update'])) {
    // User can create OR update content
}
```

## Default Roles

After seeding, these roles are available:

- **super_admin** - All permissions
- **admin** - Most management permissions
- **moderator** - Content management
- **user** - Basic content creation
- **guest** - Read-only access

## File Structure

```
src/
├── postgresql/
│   ├── tables/
│   │   ├── permissions.pgsql
│   │   ├── roles.pgsql
│   │   ├── role_permissions.pgsql
│   │   ├── user_roles.pgsql
│   │   └── user_permissions.pgsql
│   └── seeders/
│       └── rbac_seed.pgsql
├── App/
│   ├── Models/
│   │   ├── Permission.php
│   │   ├── Role.php
│   │   └── User.php
│   └── Repositories/
│       ├── PermissionRepository.php
│       ├── RoleRepository.php
│       └── UserRepository.php
└── Framework/
    ├── Http/
    │   └── Middleware/
    │       ├── RoleMiddleware.php
    │       └── PermissionMiddleware.php
    └── Attributes/
        ├── RequiresPermission.php
        └── RequiresRole.php
```

## Common Tasks

### Assign Role to User

```php
use App\Repositories\UserRepository;

$userRepo = new UserRepository($db);
$userRepo->assignRole($userId, $roleId);
```

### Grant Permission to Role

```php
use App\Repositories\RoleRepository;

$roleRepo = new RoleRepository($db);
$roleRepo->assignPermission($roleId, $permissionId);
```

### Create New Permission

```php
use App\Repositories\PermissionRepository;

$permRepo = new PermissionRepository($db);
$permission = $permRepo->create([
    'name' => 'posts.publish',
    'description' => 'Publish blog posts',
    'resource' => 'posts',
    'action' => 'publish'
]);
```

### Create New Role

```php
$roleRepo = new RoleRepository($db);
$role = $roleRepo->create([
    'name' => 'editor',
    'description' => 'Content editor',
    'is_system_role' => false
]);
```

## Middleware Options

### RoleMiddleware

```php
// Require specific role
new RoleMiddleware(['admin'])

// Require ANY of multiple roles
new RoleMiddleware(['admin', 'moderator'], false)

// Require ALL roles
new RoleMiddleware(['admin', 'verified'], true)
```

### PermissionMiddleware

```php
// Require specific permission (default: ALL)
new PermissionMiddleware(['users.create'])

// Require ALL permissions
new PermissionMiddleware(['content.create', 'content.publish'], true)

// Require ANY permission
new PermissionMiddleware(['content.view', 'content.list'], false)
```

## For More Details

See [RBAC.md](./RBAC.md) for comprehensive documentation including:
- Complete database schema
- Model API reference
- Advanced usage examples
- Troubleshooting guide
- Best practices
