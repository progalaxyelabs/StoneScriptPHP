<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Platform;

use StoneScriptPHP\Database;

/** PlatformRowStore over the `platform_upsert` function `php stone generate tenancy` writes. */
final class DatabasePlatformRowStore implements PlatformRowStore
{
    /** @var callable(string,array):array */
    private $fn;

    /** @param callable(string,array):array|null $fn Database::fn-shaped caller (injectable for tests). */
    public function __construct(?callable $fn = null)
    {
        $this->fn = $fn ?? Database::fn(...);
    }

    public function register(string $platformId, string $name, string $slug, string $authMode): void
    {
        ($this->fn)('platform_upsert', [$platformId, $name, $slug, $authMode]);
    }
}
