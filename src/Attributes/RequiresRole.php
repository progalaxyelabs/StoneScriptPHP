<?php

namespace StoneScriptPHP\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class RequiresRole
{
    public array $roles;
    public bool $requireAll;

    /**
     * @param string|array $roles Single role or array of roles
     * @param bool $requireAll If true, user must have ALL roles. If false, ANY role is sufficient
     */
    public function __construct(string|array $roles, bool $requireAll = false)
    {
        $this->roles = is_array($roles) ? $roles : [$roles];
        $this->requireAll = $requireAll;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function requiresAll(): bool
    {
        return $this->requireAll;
    }
}
