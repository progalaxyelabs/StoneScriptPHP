<?php

namespace StoneScriptPHP\Lib\User;

class Role
{
    public int $role_id;
    public string $name;
    public ?string $description;
    public bool $is_system_role;
    public string $created_on;
    public string $last_updated_on;

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
            'role_id' => $this->role_id ?? null,
            'name' => $this->name ?? null,
            'description' => $this->description ?? null,
            'is_system_role' => $this->is_system_role ?? false,
            'created_on' => $this->created_on ?? null,
            'last_updated_on' => $this->last_updated_on ?? null,
            'permissions' => array_map(fn($p) => $p->toArray(), $this->permissions),
        ];
    }

    public function hasPermission(string $permissionName): bool
    {
        foreach ($this->permissions as $permission) {
            if ($permission->name === $permissionName || $permission->getFullName() === $permissionName) {
                return true;
            }
        }
        return false;
    }

    public function addPermission(Permission $permission): void
    {
        if (!$this->hasPermission($permission->name)) {
            $this->permissions[] = $permission;
        }
    }
}
