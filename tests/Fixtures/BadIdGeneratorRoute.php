<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Fixtures;

/** A platform id generator that emits an id the tenant-id rule refuses (underscore, upper case). */
class BadIdGeneratorRoute
{
    protected function generateUuid(): string
    {
        return 'Tenant_' . bin2hex(random_bytes(4));
    }
}
