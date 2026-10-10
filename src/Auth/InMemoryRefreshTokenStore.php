<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth;

use StoneScriptPHP\Auth\RefreshTokens\RotatingRefreshTokenStore;
use StoneScriptPHP\Auth\RefreshTokens\RotationResult;
use StoneScriptPHP\Auth\RefreshTokens\TokenInspection;

/**
 * InMemoryRefreshTokenStore - reference {@see RotatingRefreshTokenStore} implementation.
 *
 * Process-local, non-persistent. It is the canonical statement of the store semantics (the
 * PostgreSQL store's SQL mirrors it) and the substrate the framework's own tests run against.
 * Production uses {@see RefreshTokens\PostgresRefreshTokenStore} or a platform's own store.
 *
 * Base contract unchanged: revoke() hard-deletes a row, expired rows are absent. Added in 12.x:
 * session families, rotation with reuse detection, session revocation and expiry purge.
 *
 * @package StoneScriptPHP\Auth
 * @since   6.2.0
 */
final class InMemoryRefreshTokenStore implements RotatingRefreshTokenStore
{
    /** @var array<string, array{subject: string, purpose: string, expires_at: int, session_expires_at: int, metadata: array<string,mixed>, family: string, rotated_at: ?int, replaced_by: ?string, grace_used: bool}> */
    private array $rows = [];

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param int $reuseGraceSeconds Window after a rotation in which the old token may be exchanged ONCE more.
     * @param (callable(): int)|null $clock Unix-time source (tests).
     * @param int $sessionMaxSeconds Absolute cap on a session family (never slides).
     */
    public function __construct(
        private readonly int $reuseGraceSeconds = 10,
        ?callable $clock = null,
        private readonly int $sessionMaxSeconds = 15552000,
    ) {
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): int => time();
    }

    public function store(
        string $tokenHash,
        string $subject,
        string $purpose,
        int $expiresAt,
        array $metadata = []
    ): void {
        if (isset($this->rows[$tokenHash])) {
            return; // retried mint: keep the existing row/family
        }
        $session = ($this->clock)() + $this->sessionMaxSeconds;
        $this->rows[$tokenHash] = [
            'subject'            => $subject,
            'purpose'            => $purpose,
            'expires_at'         => min($expiresAt, $session),
            'session_expires_at' => $session,
            'metadata'           => $metadata,
            'family'             => self::uuid(),
            'rotated_at'         => null,
            'replaced_by'        => null,
            'grace_used'         => false,
        ];
    }

    public function exists(string $tokenHash): bool
    {
        return $this->inspect($tokenHash)->usable();
    }

    public function inspect(string $tokenHash): TokenInspection
    {
        $row = $this->rows[$tokenHash] ?? null;
        if ($row === null) {
            return new TokenInspection(TokenInspection::UNKNOWN);
        }
        $now = ($this->clock)();
        $status = match (true) {
            min($row['expires_at'], $row['session_expires_at']) <= $now => TokenInspection::EXPIRED,
            $row['rotated_at'] === null => TokenInspection::VALID,
            !$row['grace_used'] && $row['rotated_at'] > $now - max(0, $this->reuseGraceSeconds) => TokenInspection::GRACE,
            default => TokenInspection::REUSED,
        };
        if ($status === TokenInspection::EXPIRED) {
            unset($this->rows[$tokenHash]); // expired rows are reaped on read
        }
        return new TokenInspection($status, $row['family'], $row['subject'], $row['purpose']);
    }

    public function rotate(string $oldHash, string $newHash, int $newExpiresAt, array $metadata = []): RotationResult
    {
        $row = $this->rows[$oldHash] ?? null;
        if ($row === null) {
            return new RotationResult(RotationResult::UNKNOWN);
        }
        $now = ($this->clock)();
        if (min($row['expires_at'], $row['session_expires_at']) <= $now) {
            unset($this->rows[$oldHash]);
            return new RotationResult(RotationResult::EXPIRED, $row['family'], $row['subject'], $row['purpose']);
        }
        $useGrace = false;
        if ($row['rotated_at'] !== null) {
            if (!$row['grace_used'] && $row['rotated_at'] > $now - max(0, $this->reuseGraceSeconds)) {
                $useGrace = true; // the ONE extra exchange
            } else {
                $this->revokeFamily($row['family']);
                return new RotationResult(RotationResult::REUSED, $row['family'], $row['subject'], $row['purpose']);
            }
        }

        $this->rows[$newHash] = [
            'subject'            => $row['subject'],
            'purpose'            => $row['purpose'],
            'expires_at'         => min($newExpiresAt, $row['session_expires_at']),
            'session_expires_at' => $row['session_expires_at'],
            'metadata'           => $metadata,
            'family'             => $row['family'],
            'rotated_at'         => null,
            'replaced_by'        => null,
            'grace_used'         => false,
        ];
        $this->rows[$oldHash]['rotated_at'] ??= $now;
        $this->rows[$oldHash]['replaced_by'] ??= $newHash;
        $this->rows[$oldHash]['grace_used'] = $this->rows[$oldHash]['grace_used'] || $useGrace;

        return new RotationResult(RotationResult::OK, $row['family'], $row['subject'], $row['purpose']);
    }

    public function revoke(string $tokenHash): void
    {
        // Hard delete - no soft revoked_at flag.
        unset($this->rows[$tokenHash]);
    }

    public function revokeSession(string $tokenHash): int
    {
        $family = $this->rows[$tokenHash]['family'] ?? null;
        return $family === null ? 0 : $this->revokeFamily($family);
    }

    public function revokeFamily(string $familyId): int
    {
        $deleted = 0;
        foreach ($this->rows as $hash => $row) {
            if ($row['family'] === $familyId) {
                unset($this->rows[$hash]);
                $deleted++;
            }
        }
        return $deleted;
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

    public function purgeExpired(int $batch = 1000, int $olderThanSeconds = 0): int
    {
        $cutoff = ($this->clock)() - max(0, $olderThanSeconds);
        $deleted = 0;
        foreach ($this->rows as $hash => $row) {
            if ($deleted >= max(1, $batch)) {
                break;
            }
            if (min($row['expires_at'], $row['session_expires_at']) <= $cutoff) {
                unset($this->rows[$hash]);
                $deleted++;
            }
        }
        return $deleted;
    }

    /** Test/introspection helper - number of rows (including rotated tombstones). */
    public function count(): int
    {
        return count($this->rows);
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
