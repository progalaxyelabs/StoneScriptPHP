<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth;

/**
 * InMemoryRefreshTokenStore — reference {@see RefreshTokenStore} implementation.
 *
 * Process-local, non-persistent. It is the canonical implementation of the
 * "revoke = DELETE the row" contract and the substrate the framework's own tests
 * run against. Production platforms provide a persistent implementation (e.g. a
 * PostgreSQL-backed store); this one documents the exact expected semantics and is
 * usable for single-process CLIs and tests.
 *
 * @package StoneScriptPHP\Auth
 * @since   6.2.0
 */
final class InMemoryRefreshTokenStore implements RefreshTokenStore
{
    /** @var array<string, array{subject: string, purpose: string, expires_at: int, metadata: array<string,mixed>}> */
    private array $rows = [];

    public function store(
        string $tokenHash,
        string $subject,
        string $purpose,
        int $expiresAt,
        array $metadata = []
    ): void {
        $this->rows[$tokenHash] = [
            'subject'    => $subject,
            'purpose'    => $purpose,
            'expires_at' => $expiresAt,
            'metadata'   => $metadata,
        ];
    }

    public function exists(string $tokenHash): bool
    {
        $row = $this->rows[$tokenHash] ?? null;
        if ($row === null) {
            return false;
        }
        // Expired rows are treated as absent (and reaped) — a token past its exp
        // can never be valid, mirroring a `WHERE expires_at > NOW()` DB predicate.
        if ($row['expires_at'] <= time()) {
            unset($this->rows[$tokenHash]);
            return false;
        }
        return true;
    }

    public function revoke(string $tokenHash): void
    {
        // Hard delete — no soft revoked_at flag.
        unset($this->rows[$tokenHash]);
    }

    public function revokeAllForSubject(string $subject, ?string $purpose = null): int
    {
        $deleted = 0;
        foreach ($this->rows as $hash => $row) {
            if ($row['subject'] !== $subject) {
                continue;
            }
            if ($purpose !== null && $row['purpose'] !== $purpose) {
                continue;
            }
            unset($this->rows[$hash]);
            $deleted++;
        }
        return $deleted;
    }

    /** Test/introspection helper — number of live rows. */
    public function count(): int
    {
        return count($this->rows);
    }
}
