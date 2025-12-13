<?php

namespace StoneScriptPHP\Lib\User;

class Permission
{
    public int $permission_id;
    public string $name;
    public ?string $description;
    public string $resource;
    public string $action;
    public string $created_on;
    public string $last_updated_on;

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
            'permission_id' => $this->permission_id ?? null,
            'name' => $this->name ?? null,
            'description' => $this->description ?? null,
            'resource' => $this->resource ?? null,
            'action' => $this->action ?? null,
            'created_on' => $this->created_on ?? null,
            'last_updated_on' => $this->last_updated_on ?? null,
        ];
    }

    public function getFullName(): string
    {
        return "{$this->resource}.{$this->action}";
    }
}
