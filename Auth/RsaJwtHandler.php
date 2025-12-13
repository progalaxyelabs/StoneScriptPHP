<?php

namespace Framework\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * RSA JWT Handler
 *
 * Advanced JWT implementation using RSA public/private key pairs (RS256).
 * More secure for distributed systems and microservices.
 *
 * Requirements:
 * - Generate RSA keypair: php generate-openssl-keypair.sh
 * - JWT_PRIVATE_KEY_PATH and JWT_PUBLIC_KEY_PATH in .env
 *
 * Key generation:
 *   $ ssh-keygen -t rsa -m pkcs8 -f keys/jwt-private.pem
 *   $ chmod 600 keys/jwt-private.pem
 *
 * Example usage:
 *   $handler = new RsaJwtHandler();
 *   $token = $handler->generateToken(['user_id' => 123]);
 *   $payload = $handler->verifyToken($token);
 */
class RsaJwtHandler implements JwtHandlerInterface
{
    private const ALGORITHM = 'RS256';

    /**
     * Generate a JWT token using RSA private key
     *
     * @param array $payload Data to encode in the token
     * @param int $expiryDays Token expiry in days (default: 30)
     * @return string JWT token
     * @throws \RuntimeException if private key cannot be loaded
     */
    public function generateToken(array $payload, int $expiryDays = 30): string
    {
        $privateKeyPath = $this->getPrivateKeyPath();
        $privateKeyContent = file_get_contents($privateKeyPath);

        if ($privateKeyContent === false) {
            throw new \RuntimeException("Cannot read private key file: $privateKeyPath");
        }

        $privateKey = openssl_pkey_get_private($privateKeyContent);

        if (!$privateKey) {
            throw new \RuntimeException('Unable to load private key for JWT signing');
        }

        $issuedAt = time();
        $expire = $issuedAt + ($expiryDays * 24 * 60 * 60);

        $data = [
            'iat' => $issuedAt,
            'exp' => $expire,
            'data' => $payload
        ];

        return JWT::encode($data, $privateKey, self::ALGORITHM);
    }

    /**
     * Verify and decode a JWT token using RSA public key
     *
     * @param string $token JWT token to verify
     * @return array|false Decoded payload on success, false on failure
     */
    public function verifyToken(string $token): array|false
    {
        try {
            $publicKeyPath = $this->getPublicKeyPath();
            $publicKey = file_get_contents($publicKeyPath);

            if ($publicKey === false) {
                error_log("Cannot read public key file: $publicKeyPath");
                return false;
            }

            $decoded = JWT::decode($token, new Key($publicKey, self::ALGORITHM));
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
     * Get private key path from environment
     *
     * @return string
     * @throws \RuntimeException if JWT_PRIVATE_KEY_PATH is not set
     */
    private function getPrivateKeyPath(): string
    {
        $path = env('JWT_PRIVATE_KEY_PATH');

        if (empty($path)) {
            throw new \RuntimeException(
                'JWT_PRIVATE_KEY_PATH environment variable is not set. ' .
                'Please add JWT_PRIVATE_KEY_PATH to your .env file.'
            );
        }

        // Convert relative path to absolute
        if (!str_starts_with($path, '/')) {
            $path = ROOT_PATH . $path;
        }

        return $path;
    }

    /**
     * Get public key path from environment
     *
     * @return string
     * @throws \RuntimeException if JWT_PUBLIC_KEY_PATH is not set
     */
    private function getPublicKeyPath(): string
    {
        $path = env('JWT_PUBLIC_KEY_PATH');

        if (empty($path)) {
            throw new \RuntimeException(
                'JWT_PUBLIC_KEY_PATH environment variable is not set. ' .
                'Please add JWT_PUBLIC_KEY_PATH to your .env file.'
            );
        }

        // Convert relative path to absolute
        if (!str_starts_with($path, '/')) {
            $path = ROOT_PATH . $path;
        }

        return $path;
    }
}
