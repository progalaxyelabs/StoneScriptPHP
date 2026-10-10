<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Fixtures;

use StoneScriptPHP\Auth\RefreshTokenStore;

final class ThrowingStore implements RefreshTokenStore
{
    public function store(string $tokenHash, string $subject, string $purpose, int $expiresAt, array $metadata = []): void
    {
        throw new \RuntimeException('db down');
    }

    public function exists(string $tokenHash): bool
    {
        return false;
    }

    public function revoke(string $tokenHash): void
    {
    }

    public function revokeAllForSubject(string $subject, ?string $purpose = null): int
    {
        return 0;
    }
}

