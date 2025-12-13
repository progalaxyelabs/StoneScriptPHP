<?php

namespace StoneScriptPHP\Auth;

/**
 * Token Storage Interface
 *
 * Optional interface for refresh token storage and blacklisting.
 * Implement this interface if you need to:
 * - Store refresh tokens in database
 * - Revoke tokens on logout
 * - Track token usage (IP, user agent, last used)
 * - Implement device management
 * - Support token rotation
 *
 * If you don't implement this interface, the framework will use
 * stateless JWT tokens (rely on expiry only, no revocation).
 *
 * Example implementation:
 *
 * class PostgresTokenStorage implements TokenStorageInterface {
 *     private PDO $db;
 *
 *     public function storeRefreshToken(string $tokenHash, int $userId, int $expiresAt, array $metadata = []): void {
 *         $stmt = $this->db->prepare(
 *             "INSERT INTO refresh_tokens (token_hash, user_id, expires_at, ip_address, user_agent)
 *              VALUES (?, ?, to_timestamp(?), ?, ?)"
 *         );
 *         $stmt->execute([
 *             $tokenHash,
 *             $userId,
 *             $expiresAt,
 *             $metadata['ip_address'] ?? null,
 *             $metadata['user_agent'] ?? null
 *         ]);
 *     }
 *
 *     public function validateRefreshToken(string $tokenHash): bool {
 *         $stmt = $this->db->prepare(
 *             "SELECT COUNT(*) FROM refresh_tokens
 *              WHERE token_hash = ? AND expires_at > NOW() AND revoked_at IS NULL"
 *         );
 *         $stmt->execute([$tokenHash]);
 *         return $stmt->fetchColumn() > 0;
 *     }
 *
 *     public function revokeRefreshToken(string $tokenHash): void {
 *         $stmt = $this->db->prepare(
 *             "UPDATE refresh_tokens SET revoked_at = NOW() WHERE token_hash = ?"
 *         );
 *         $stmt->execute([$tokenHash]);
 *     }
 *
 *     public function revokeAllUserTokens(int $userId): void {
 *         $stmt = $this->db->prepare(
 *             "UPDATE refresh_tokens SET revoked_at = NOW()
 *              WHERE user_id = ? AND revoked_at IS NULL"
 *         );
 *         $stmt->execute([$userId]);
 *     }
 * }
 *
 * Usage with AuthRoutes:
 *   $tokenStorage = new PostgresTokenStorage($db);
 *   AuthRoutes::register($router, ['token_storage' => $tokenStorage]);
 */
interface TokenStorageInterface
{
    /**
     * Store a refresh token in persistent storage
     *
     * @param string $tokenHash SHA256 hash of the refresh token (never store plaintext!)
     * @param int $userId User ID associated with this token
     * @param int $expiresAt Unix timestamp when token expires
     * @param array $metadata Optional metadata (ip_address, user_agent, device_name, etc.)
     * @return void
     */
    public function storeRefreshToken(
        string $tokenHash,
        int $userId,
        int $expiresAt,
        array $metadata = []
    ): void;

    /**
     * Validate that a refresh token exists and is not revoked
     *
     * @param string $tokenHash SHA256 hash of the refresh token
     * @return bool True if token is valid and not revoked, false otherwise
     */
    public function validateRefreshToken(string $tokenHash): bool;

    /**
     * Revoke a specific refresh token
     *
     * This should mark the token as revoked (don't delete it - keep audit trail).
     *
     * @param string $tokenHash SHA256 hash of the refresh token
     * @return void
     */
    public function revokeRefreshToken(string $tokenHash): void;

    /**
     * Revoke all refresh tokens for a user
     *
     * Useful for:
     * - Forced logout from all devices
     * - Password change
     * - Security breach response
     *
     * @param int $userId User ID whose tokens should be revoked
     * @return void
     */
    public function revokeAllUserTokens(int $userId): void;

    /**
     * Update last used timestamp for a token (optional)
     *
     * Track when a token was last used for security monitoring.
     * Return false if not implemented.
     *
     * @param string $tokenHash SHA256 hash of the refresh token
     * @return bool True if updated, false if not implemented
     */
    public function updateLastUsed(string $tokenHash): bool;

    /**
     * Get all active tokens for a user (optional)
     *
     * Useful for device management UI.
     * Return empty array if not implemented.
     *
     * @param int $userId User ID
     * @return array Array of token metadata (id, created_at, last_used_at, ip_address, user_agent, etc.)
     */
    public function getUserTokens(int $userId): array;

    /**
     * Clean up expired tokens (optional)
     *
     * Should be called periodically (e.g., via cron job) to remove
     * expired tokens from storage.
     *
     * @param int|null $olderThanDays Delete tokens expired more than X days ago (default: 30)
     * @return int Number of tokens deleted
     */
    public function cleanupExpiredTokens(?int $olderThanDays = 30): int;
}
