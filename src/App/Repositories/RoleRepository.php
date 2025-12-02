<?php

namespace App\Repositories;

use App\Models\Role;
use App\Models\Permission;

class RoleRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function findById(int $id): ?Role
    {
        $result = $this->db->query(
            "SELECT * FROM roles WHERE role_id = $1",
            [$id]
        );

        $data = $result->fetch();
        if (!$data) {
            return null;
        }

        $role = Role::fromDatabase($data);
        $this->loadPermissions($role);

        return $role;
    }

    public function findByName(string $name): ?Role
    {
        $result = $this->db->query(
            "SELECT * FROM roles WHERE name = $1",
            [$name]
        );

        $data = $result->fetch();
        if (!$data) {
            return null;
        }

        $role = Role::fromDatabase($data);
        $this->loadPermissions($role);

        return $role;
    }

    public function getAll(): array
    {
        $result = $this->db->query("SELECT * FROM roles ORDER BY name");
        $roles = [];

        while ($row = $result->fetch()) {
            $role = Role::fromDatabase($row);
            $this->loadPermissions($role);
            $roles[] = $role;
        }

        return $roles;
    }

    public function create(array $data): Role
    {
        $result = $this->db->query(
            "INSERT INTO roles (name, description, is_system_role)
             VALUES ($1, $2, $3)
             RETURNING *",
            [
                $data['name'],
                $data['description'] ?? null,
                $data['is_system_role'] ?? false
            ]
        );

        return Role::fromDatabase($result->fetch());
    }

    public function update(int $id, array $data): ?Role
    {
        $result = $this->db->query(
            "UPDATE roles
             SET name = COALESCE($2, name),
                 description = COALESCE($3, description),
                 last_updated_on = CURRENT_TIMESTAMP
             WHERE role_id = $1
             RETURNING *",
            [
                $id,
                $data['name'] ?? null,
                $data['description'] ?? null
            ]
        );

        $updated = $result->fetch();
        if (!$updated) {
            return null;
        }

        $role = Role::fromDatabase($updated);
        $this->loadPermissions($role);

        return $role;
    }

    public function delete(int $id): bool
    {
        $result = $this->db->query(
            "DELETE FROM roles WHERE role_id = $1 AND is_system_role = false",
            [$id]
        );

        return $result->rowCount() > 0;
    }

    public function assignPermission(int $roleId, int $permissionId): bool
    {
        $result = $this->db->query(
            "INSERT INTO role_permissions (role_id, permission_id)
             VALUES ($1, $2)
             ON CONFLICT (role_id, permission_id) DO NOTHING",
            [$roleId, $permissionId]
        );

        return true;
    }

    public function removePermission(int $roleId, int $permissionId): bool
    {
        $result = $this->db->query(
            "DELETE FROM role_permissions WHERE role_id = $1 AND permission_id = $2",
            [$roleId, $permissionId]
        );

        return $result->rowCount() > 0;
    }

    public function getPermissions(int $roleId): array
    {
        $result = $this->db->query(
            "SELECT p.*
             FROM permissions p
             JOIN role_permissions rp ON p.permission_id = rp.permission_id
             WHERE rp.role_id = $1
             ORDER BY p.resource, p.action",
            [$roleId]
        );

        $permissions = [];
        while ($row = $result->fetch()) {
            $permissions[] = Permission::fromDatabase($row);
        }

        return $permissions;
    }

    private function loadPermissions(Role $role): void
    {
        $permissions = $this->getPermissions($role->role_id);
        foreach ($permissions as $permission) {
            $role->addPermission($permission);
        }
    }
}
