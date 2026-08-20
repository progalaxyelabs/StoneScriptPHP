<?php

namespace StoneScriptPHP\Attributes;

use Attribute;
use StoneScriptPHP\Binding\TypedArray;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class RequiresRole
{
    /** @var TypedArray<string> */
    public TypedArray $roles;
    public bool $requireAll;

    /**
     * @param string|array|TypedArray $roles Single role, array of role
     *   names, or a TypedArray<string> of them.
     * @param bool $requireAll If true, user must have ALL roles. If false, ANY role is sufficient
     */
    public function __construct(string|array|TypedArray $roles, bool $requireAll = false)
    {
        $this->roles = match (true) {
            $roles instanceof TypedArray => $roles,
            is_array($roles) => new TypedArray('string', $roles),
            default => new TypedArray('string', [$roles]),
        };
        $this->requireAll = $requireAll;
    }

    /** @return TypedArray<string> */
    public function getRoles(): TypedArray
    {
        return $this->roles;
    }

    public function requiresAll(): bool
    {
        return $this->requireAll;
    }
}
