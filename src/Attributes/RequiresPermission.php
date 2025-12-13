<?php

namespace StoneScriptPHP\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class RequiresPermission
{
    public array $permissions;
    public bool $requireAll;

    /**
     * @param string|array $permissions Single permission or array of permissions
     * @param bool $requireAll If true, user must have ALL permissions. If false, ANY permission is sufficient
     */
    public function __construct(string|array $permissions, bool $requireAll = true)
    {
        $this->permissions = is_array($permissions) ? $permissions : [$permissions];
        $this->requireAll = $requireAll;
    }

    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function requiresAll(): bool
    {
        return $this->requireAll;
    }
}
