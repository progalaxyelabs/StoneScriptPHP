<?php

namespace Framework\Lib\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Default JWT Handler (HMAC-based)
 *
 * Simple, secure JWT implementation using HMAC (HS256).
 * Suitable for 90% of use cases.
 *
 * Requirements:
 * - JWT_SECRET environment variable must be set
 *
 * For advanced use cases (RSA keys, Auth0, etc.), implement JwtHandlerInterface
 *
 * Example usage:
 *   $handler = new JwtHandler();
 *   $token = $handler->generateToken(['user_id' => 123, 'email' => 'user@example.com']);
 *   $payload = $handler->verifyToken($token);
 */
class JwtHandler implements JwtHandlerInterface
{
    private const ALGORITHM = 'HS256';

    /**
     * Generate a JWT token
     *
     * @param array $payload Data to encode in the token
     * @param int $expiryDays Token expiry in days (default: 30)
     * @return string JWT token
     * @throws \RuntimeException if JWT_SECRET is not set
     */
    public function generateToken(array $payload, int $expiryDays = 30): string
    {
        $issuedAt = time();
        $expire = $issuedAt + ($expiryDays * 24 * 60 * 60);

        $data = [
            'iat' => $issuedAt,
            'exp' => $expire,
            'data' => $payload
        ];

        return JWT::encode($data, $this->getSecretKey(), self::ALGORITHM);
    }

    /**
     * Verify and decode a JWT token
     *
     * @param string $token JWT token to verify
     * @return array|false Decoded payload on success, false on failure
     */
    public function verifyToken(string $token): array|false
    {
        try {
            $decoded = JWT::decode($token, new Key($this->getSecretKey(), self::ALGORITHM));
            return (array) $decoded->data;
        } catch (\Firebase\JWT\ExpiredException $e) {
            error_log('JWT token expired: ' . $e->getMessage());
            return false;
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            error_log('JWT signature invalid: ' . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            error_log('JWT verification failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the secret key from environment
     *
     * @return string
     * @throws \RuntimeException if JWT_SECRET is not set
     */
    private function getSecretKey(): string
    {
        $secret = env('JWT_SECRET');

        if (empty($secret)) {
            throw new \RuntimeException(
                'JWT_SECRET environment variable is not set. ' .
                'Please add JWT_SECRET to your .env file.'
            );
        }

        return $secret;
    }
}
