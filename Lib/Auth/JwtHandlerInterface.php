<?php

namespace Framework\Lib\Auth;

/**
 * JWT Handler Interface
 *
 * Contract for JWT token generation and verification.
 * Implement this interface to provide custom JWT handling
 * (e.g., RSA keys, Auth0, Firebase Auth, etc.)
 */
interface JwtHandlerInterface
{
    /**
     * Generate a JWT token
     *
     * @param array $payload Data to encode in the token (e.g., ['user_id' => 123, 'email' => '...'])
     * @param int $expiryDays Token expiry in days (default: 30)
     * @return string JWT token
     */
    public function generateToken(array $payload, int $expiryDays = 30): string;

    /**
     * Verify and decode a JWT token
     *
     * @param string $token JWT token to verify
     * @return array|false Decoded payload on success, false on failure
     */
    public function verifyToken(string $token): array|false;
}
