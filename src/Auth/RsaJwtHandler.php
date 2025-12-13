<?php

namespace StoneScriptPHP\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use StoneScriptPHP\Env;

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
     * @param int $expirySeconds Token expiry in seconds (default: 900 = 15 minutes)
     * @param string $tokenType 'access' or 'refresh' (determines which expiry to use)
     * @return string JWT token
     * @throws \RuntimeException if private key cannot be loaded
     */
    public function generateToken(array $payload, ?int $expirySeconds = null, string $tokenType = 'access'): string
    {
        $privateKeyPath = $this->getPrivateKeyPath();
        $privateKeyContent = file_get_contents($privateKeyPath);

        if ($privateKeyContent === false) {
            throw new \RuntimeException("Cannot read private key file: $privateKeyPath");
        }

        // Support passphrase-protected keys
        $env = Env::get_instance();
        $passphrase = $env->JWT_PRIVATE_KEY_PASSPHRASE;
        $privateKey = $passphrase
            ? openssl_pkey_get_private($privateKeyContent, $passphrase)
            : openssl_pkey_get_private($privateKeyContent);

        if (!$privateKey) {
            throw new \RuntimeException('Unable to load private key for JWT signing. Check JWT_PRIVATE_KEY_PASSPHRASE if using encrypted key.');
        }

        // Use custom expiry from .env if not provided
        if ($expirySeconds === null) {
            $expirySeconds = $tokenType === 'refresh'
                ? ($env->JWT_REFRESH_TOKEN_EXPIRY ?? 15552000)  // 180 days
                : ($env->JWT_ACCESS_TOKEN_EXPIRY ?? 900);        // 15 minutes
        }

        $issuedAt = time();
        $expire = $issuedAt + $expirySeconds;

        // Support custom issuer from .env
        $issuer = $env->JWT_ISSUER ?? 'example.com';

        $data = [
            'iss' => $issuer,
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
     * @param bool $verifyIssuer Whether to verify the issuer claim
     * @return array|false Decoded payload on success, false on failure
     */
    public function verifyToken(string $token, bool $verifyIssuer = true): array|false
    {
        try {
            $publicKeyPath = $this->getPublicKeyPath();
            $publicKey = file_get_contents($publicKeyPath);

            if ($publicKey === false) {
                error_log("Cannot read public key file: $publicKeyPath");
                return false;
            }

            $decoded = JWT::decode($token, new Key($publicKey, self::ALGORITHM));

            // Optionally verify issuer
            if ($verifyIssuer) {
                $env = Env::get_instance();
                $expectedIssuer = $env->JWT_ISSUER ?? 'example.com';
                if (isset($decoded->iss) && $decoded->iss !== $expectedIssuer) {
                    error_log("JWT issuer mismatch: expected '$expectedIssuer', got '{$decoded->iss}'");
                    return false;
                }
            }

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
        $env = Env::get_instance();
        $path = $env->JWT_PRIVATE_KEY_PATH;

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
        $env = Env::get_instance();
        $path = $env->JWT_PUBLIC_KEY_PATH;

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
