<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Platform;

/** The singleton `platform` row of the platform main database. */
interface PlatformRowStore
{
    /** Record the registration (platform_id, name, slug, registered_at). Idempotent. @throws \Throwable */
    public function register(string $platformId, string $name, string $slug, string $authMode): void;
}
