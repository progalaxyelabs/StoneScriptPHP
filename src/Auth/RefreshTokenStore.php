<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth;

/**
 * RefreshTokenStore — framework-owned persistence for ALL refresh tokens.
 *
 * Every refresh token this framework mints — identity/passport (`authentication`)
 * AND card/API (`authorization`) — gets exactly one row here, discriminated by
 * `purpose`. A refresh token is valid iff its row exists. There is no soft state.
 *
 * ## Revoke = DELETE the row (hard)
 *
 * `revoke()` DELETEs. There is no `revoked_at` soft-delete flag: a token is either
 * present (valid) or absent (revoked / never issued / expired-and-reaped). This is
 * deliberate and differs from the older, cookie-flow-only {@see TokenStorageInterface}
 * whose contract said "UPDATE revoked_at, keep an audit trail". That interface is
 * deprecated in favour of this one for the body-mode passport/card flow.
 *
 * ## Why the DB row is the authority (not just JWT expiry)
 *
 * A refresh token is long-lived (e.g. 180 days). Signature + `exp` alone cannot be
 * invalidated before expiry. Gating refresh on `exists()` makes logout, forced
 * re-login, and breach response immediate: delete the row and the next refresh
 * attempt fails, even though the JWT itself is still cryptographically valid.
 *
 * Implementations MUST store only a hash of the refresh token, never plaintext.
 *
 * @package StoneScriptPHP\Auth
 * @since   6.2.0
 */
interface RefreshTokenStore
{
    /**
     * Persist a refresh token row.
     *
     * @param string $tokenHash SHA-256 hash of the refresh token (never plaintext)
     * @param string $subject   Owning identity/subject id (the token's `sub`/`identity_id`)
     * @param string $purpose   Discriminator — TokenClaims::PURPOSE_AUTHENTICATION or PURPOSE_AUTHORIZATION
     * @param int    $expiresAt  Unix timestamp when the token expires
     * @param array<string, mixed> $metadata Optional (ip_address, user_agent, tenant_id, …)
     */
    public function store(
        string $tokenHash,
        string $subject,
        string $purpose,
        int $expiresAt,
        array $metadata = []
    ): void;

    /**
     * True iff a (non-expired) row for this token hash exists — i.e. not revoked.
     *
     * @param string $tokenHash SHA-256 hash of the refresh token
     */
    public function exists(string $tokenHash): bool;

    /**
     * Revoke a single refresh token by DELETING its row (hard).
     *
     * @param string $tokenHash SHA-256 hash of the refresh token
     */
    public function revoke(string $tokenHash): void;

    /**
     * Revoke ALL refresh tokens for a subject by DELETING their rows (hard).
     * Used for logout-everywhere / password-change / breach response.
     *
     * @param string $subject Owning identity/subject id
     * @param string|null $purpose When set, only delete rows of this purpose
     * @return int Number of rows deleted
     */
    public function revokeAllForSubject(string $subject, ?string $purpose = null): int;
}
