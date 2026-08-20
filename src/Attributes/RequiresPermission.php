<?php

namespace StoneScriptPHP\Attributes;

use Attribute;
use StoneScriptPHP\Binding\TypedArray;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class RequiresPermission
{
    /** @var TypedArray<string> */
    public TypedArray $permissions;
    public bool $requireAll;

    /**
     * @param string|array|TypedArray $permissions Single permission, array of
     *   permission names, or a TypedArray<string> of them.
     * @param bool $requireAll If true, user must have ALL permissions. If false, ANY permission is sufficient
     */
    public function __construct(string|array|TypedArray $permissions, bool $requireAll = true)
    {
        $this->permissions = match (true) {
            $permissions instanceof TypedArray => $permissions,
            is_array($permissions) => new TypedArray('string', $permissions),
            default => new TypedArray('string', [$permissions]),
        };
        $this->requireAll = $requireAll;
    }

    /** @return TypedArray<string> */
    public function getPermissions(): TypedArray
    {
        return $this->permissions;
    }

    public function requiresAll(): bool
    {
        return $this->requireAll;
    }
}
