<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\RefreshTokens;

use StoneScriptPHP\Auth\RefreshTokenStore;

/**
 * A {@see RefreshTokenStore} that also groups tokens into session families and supports
 * rotation with reuse detection (OAuth 2.0 Security BCP RFC 9700 section 4.14.2, RFC 6819
 * section 5.2.2.3), session revocation and expiry purge.
 *
 * Contract additions over the base interface (which keeps its meaning):
 *  - `store()` starts a new family.
 *  - `exists()` is true only for a token that may be presented right now (VALID or GRACE).
 *  - a rotated token is NOT deleted: it stays as a tombstone until it expires, because a
 *    deleted row is indistinguishable from "never issued" and replay could not be detected.
 *  - only hashes ever reach a store; implementations never see the raw token.
 */
interface RotatingRefreshTokenStore extends RefreshTokenStore
{
    public function inspect(string $tokenHash): TokenInspection;

    /**
     * Atomically exchange $oldHash for $newHash (same family, subject and purpose).
     * Must be race-safe: of two concurrent rotations of one VALID token exactly one is the
     * first; the other lands in the grace window. Reuse beyond the grace window revokes the
     * whole family in the same atomic step and returns REUSED.
     *
     * @param array<string, mixed> $metadata
     */
    public function rotate(string $oldHash, string $newHash, int $newExpiresAt, array $metadata = []): RotationResult;

    /** Delete every token of the session that owns $tokenHash (logout). @return int rows deleted */
    public function revokeSession(string $tokenHash): int;

    /** Delete every token of one family. @return int rows deleted */
    public function revokeFamily(string $familyId): int;

    /**
     * Delete up to $batch rows that expired more than $olderThanSeconds ago.
     * Call until it returns 0; each call is a small transaction.
     *
     * @return int rows deleted
     */
    public function purgeExpired(int $batch = 1000, int $olderThanSeconds = 0): int;
}
