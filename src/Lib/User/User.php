<?php

namespace Framework\Lib\User;

class User
{
    public int $user_id;
    public string $name;
    public string $email;
    public bool $is_email_verified;
    public ?string $email_verified_on;
    public string $created_on;
    public string $last_updated_on;

    public array $roles = [];
    public array $permissions = [];

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            foreach ($data as $key => $value) {
                if (property_exists($this, $key)) {
                    $this->$key = $value;
                }
            }
        }
    }

    public static function fromDatabase(array $row): self
    {
        return new self($row);
    }

    public function toArray(): array
    {
        return [
            'user_id' => $this->user_id ?? null,
            'name' => $this->name ?? null,
            'email' => $this->email ?? null,
            'is_email_verified' => $this->is_email_verified ?? false,
            'email_verified_on' => $this->email_verified_on ?? null,
            'created_on' => $this->created_on ?? null,
            'last_updated_on' => $this->last_updated_on ?? null,
            'roles' => array_map(fn($r) => $r->toArray(), $this->roles),
            'permissions' => array_map(fn($p) => $p->toArray(), $this->permissions),
        ];
    }

    public function hasRole(string $roleName): bool
    {
        foreach ($this->roles as $role) {
            if ($role->name === $roleName) {
                return true;
            }
        }
        return false;
    }

    public function hasAnyRole(array $roleNames): bool
    {
        foreach ($roleNames as $roleName) {
            if ($this->hasRole($roleName)) {
                return true;
            }
        }
        return false;
    }

    public function hasAllRoles(array $roleNames): bool
    {
        foreach ($roleNames as $roleName) {
            if (!$this->hasRole($roleName)) {
                return false;
            }
        }
        return true;
    }

    public function hasPermission(string $permissionName): bool
    {
        // Check direct permissions
        foreach ($this->permissions as $permission) {
            if ($permission->name === $permissionName || $permission->getFullName() === $permissionName) {
                return true;
            }
        }

        // Check role-based permissions
        foreach ($this->roles as $role) {
            if ($role->hasPermission($permissionName)) {
                return true;
            }
        }

        return false;
    }

    public function hasAnyPermission(array $permissionNames): bool
    {
        foreach ($permissionNames as $permissionName) {
            if ($this->hasPermission($permissionName)) {
                return true;
            }
        }
        return false;
    }

    public function hasAllPermissions(array $permissionNames): bool
    {
        foreach ($permissionNames as $permissionName) {
            if (!$this->hasPermission($permissionName)) {
                return false;
            }
        }
        return true;
    }

    public function addRole(Role $role): void
    {
        if (!$this->hasRole($role->name)) {
            $this->roles[] = $role;
        }
    }

    public function addPermission(Permission $permission): void
    {
        if (!$this->hasPermission($permission->name)) {
            $this->permissions[] = $permission;
        }
    }

    public function getAllPermissions(): array
    {
        $allPermissions = $this->permissions;

        foreach ($this->roles as $role) {
            foreach ($role->permissions as $permission) {
                $exists = false;
                foreach ($allPermissions as $existing) {
                    if ($existing->permission_id === $permission->permission_id) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $allPermissions[] = $permission;
                }
            }
        }

        return $allPermissions;
    }
}
