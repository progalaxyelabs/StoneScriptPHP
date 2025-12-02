<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\Role;
use App\Models\Permission;

class UserRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function findById(int $id): ?User
    {
        $result = $this->db->query(
            "SELECT * FROM users WHERE user_id = $1",
            [$id]
        );

        $data = $result->fetch();
        if (!$data) {
            return null;
        }

        $user = User::fromDatabase($data);
        $this->loadRoles($user);
        $this->loadDirectPermissions($user);

        return $user;
    }

    public function findByEmail(string $email): ?User
    {
        $result = $this->db->query(
            "SELECT * FROM users WHERE email = $1",
            [$email]
        );

        $data = $result->fetch();
        if (!$data) {
            return null;
        }

        $user = User::fromDatabase($data);
        $this->loadRoles($user);
        $this->loadDirectPermissions($user);

        return $user;
    }

    public function assignRole(int $userId, int $roleId): bool
    {
        $this->db->query(
            "INSERT INTO user_roles (user_id, role_id)
             VALUES ($1, $2)
             ON CONFLICT (user_id, role_id) DO NOTHING",
            [$userId, $roleId]
        );

        return true;
    }

    public function removeRole(int $userId, int $roleId): bool
    {
        $result = $this->db->query(
            "DELETE FROM user_roles WHERE user_id = $1 AND role_id = $2",
            [$userId, $roleId]
        );

        return $result->rowCount() > 0;
    }

    public function grantPermission(int $userId, int $permissionId): bool
    {
        $this->db->query(
            "INSERT INTO user_permissions (user_id, permission_id)
             VALUES ($1, $2)
             ON CONFLICT (user_id, permission_id) DO NOTHING",
            [$userId, $permissionId]
        );

        return true;
    }

    public function revokePermission(int $userId, int $permissionId): bool
    {
        $result = $this->db->query(
            "DELETE FROM user_permissions WHERE user_id = $1 AND permission_id = $2",
            [$userId, $permissionId]
        );

        return $result->rowCount() > 0;
    }

    public function getUserRoles(int $userId): array
    {
        $result = $this->db->query(
            "SELECT r.*
             FROM roles r
             JOIN user_roles ur ON r.role_id = ur.role_id
             WHERE ur.user_id = $1
             ORDER BY r.name",
            [$userId]
        );

        $roles = [];
        while ($row = $result->fetch()) {
            $role = Role::fromDatabase($row);
            $this->loadRolePermissions($role);
            $roles[] = $role;
        }

        return $roles;
    }

    public function getUserDirectPermissions(int $userId): array
    {
        $result = $this->db->query(
            "SELECT p.*
             FROM permissions p
             JOIN user_permissions up ON p.permission_id = up.permission_id
             WHERE up.user_id = $1
             ORDER BY p.resource, p.action",
            [$userId]
        );

        $permissions = [];
        while ($row = $result->fetch()) {
            $permissions[] = Permission::fromDatabase($row);
        }

        return $permissions;
    }

    private function loadRoles(User $user): void
    {
        $roles = $this->getUserRoles($user->user_id);
        foreach ($roles as $role) {
            $user->addRole($role);
        }
    }

    private function loadDirectPermissions(User $user): void
    {
        $permissions = $this->getUserDirectPermissions($user->user_id);
        foreach ($permissions as $permission) {
            $user->addPermission($permission);
        }
    }

    private function loadRolePermissions(Role $role): void
    {
        $result = $this->db->query(
            "SELECT p.*
             FROM permissions p
             JOIN role_permissions rp ON p.permission_id = rp.permission_id
             WHERE rp.role_id = $1
             ORDER BY p.resource, p.action",
            [$role->role_id]
        );

        while ($row = $result->fetch()) {
            $role->addPermission(Permission::fromDatabase($row));
        }
    }
}
