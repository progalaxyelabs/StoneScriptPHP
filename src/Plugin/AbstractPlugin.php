<?php

declare(strict_types=1);

namespace StoneScriptPHP\Plugin;

use StoneScriptPHP\Tenancy\TenancyStrategyInterface;

/**
 * AbstractPlugin
 *
 * Convenience base class for PluginInterface implementations — every
 * contribution point defaults to "nothing" so a concrete plugin only needs to
 * override the methods it actually uses. Extending this class and overriding
 * nothing is equivalent to not registering the plugin at all.
 *
 * @package StoneScriptPHP\Plugin
 */
abstract class AbstractPlugin implements PluginInterface
{
    public function middleware(): array
    {
        return [];
    }

    public function routes(): array
    {
        return [];
    }

    public function migrationPaths(): array
    {
        return [];
    }

    public function schemaPaths(): array
    {
        return [];
    }

    public function tenancyStrategy(): ?TenancyStrategyInterface
    {
        return null;
    }
}
