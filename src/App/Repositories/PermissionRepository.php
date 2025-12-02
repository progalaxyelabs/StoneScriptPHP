<?php

namespace App\Repositories;

use App\Models\Permission;

class PermissionRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function findById(int $id): ?Permission
    {
        $result = $this->db->query(
            "SELECT * FROM permissions WHERE permission_id = $1",
            [$id]
        );

        $data = $result->fetch();
        return $data ? Permission::fromDatabase($data) : null;
    }

    public function findByName(string $name): ?Permission
    {
        $result = $this->db->query(
            "SELECT * FROM permissions WHERE name = $1",
            [$name]
        );

        $data = $result->fetch();
        return $data ? Permission::fromDatabase($data) : null;
    }

    public function findByResourceAndAction(string $resource, string $action): ?Permission
    {
        $result = $this->db->query(
            "SELECT * FROM permissions WHERE resource = $1 AND action = $2",
            [$resource, $action]
        );

        $data = $result->fetch();
        return $data ? Permission::fromDatabase($data) : null;
    }

    public function getAll(): array
    {
        $result = $this->db->query("SELECT * FROM permissions ORDER BY resource, action");
        $permissions = [];

        while ($row = $result->fetch()) {
            $permissions[] = Permission::fromDatabase($row);
        }

        return $permissions;
    }

    public function create(array $data): Permission
    {
        $result = $this->db->query(
            "INSERT INTO permissions (name, description, resource, action)
             VALUES ($1, $2, $3, $4)
             RETURNING *",
            [
                $data['name'],
                $data['description'] ?? null,
                $data['resource'],
                $data['action']
            ]
        );

        return Permission::fromDatabase($result->fetch());
    }

    public function update(int $id, array $data): ?Permission
    {
        $result = $this->db->query(
            "UPDATE permissions
             SET name = COALESCE($2, name),
                 description = COALESCE($3, description),
                 resource = COALESCE($4, resource),
                 action = COALESCE($5, action),
                 last_updated_on = CURRENT_TIMESTAMP
             WHERE permission_id = $1
             RETURNING *",
            [
                $id,
                $data['name'] ?? null,
                $data['description'] ?? null,
                $data['resource'] ?? null,
                $data['action'] ?? null
            ]
        );

        $updated = $result->fetch();
        return $updated ? Permission::fromDatabase($updated) : null;
    }

    public function delete(int $id): bool
    {
        $result = $this->db->query(
            "DELETE FROM permissions WHERE permission_id = $1",
            [$id]
        );

        return $result->rowCount() > 0;
    }

    public function getByResource(string $resource): array
    {
        $result = $this->db->query(
            "SELECT * FROM permissions WHERE resource = $1 ORDER BY action",
            [$resource]
        );

        $permissions = [];
        while ($row = $result->fetch()) {
            $permissions[] = Permission::fromDatabase($row);
        }

        return $permissions;
    }
}
