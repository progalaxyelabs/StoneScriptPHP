<?php

namespace StoneScriptPHP\Auth;

/**
 * Adapter to make MultiAuthJwtValidator compatible with JwtHandlerInterface
 *
 * This allows using MultiAuthJwtValidator (JWKS-based validation) with middleware
 * that expects JwtHandlerInterface. Commonly used in external/hybrid auth modes.
 *
 * @since 2.2.0
 */
class MultiAuthJwtAdapter implements JwtHandlerInterface
{
    private MultiAuthJwtValidator $validator;

    public function __construct(MultiAuthJwtValidator $validator)
    {
        $this->validator = $validator;
    }

    /**
     * Verify JWT token using MultiAuthJwtValidator
     *
     * @param string $jwt JWT token to verify
     * @return array|false Decoded payload or false if invalid
     */
    public function verifyToken(string $jwt): array|false
    {
        $result = $this->validator->validateJWT($jwt);
        return $result ?? false;
    }

    /**
     * Generate token - not supported in validator-only mode
     *
     * This adapter is for VALIDATION only. For token generation, use RsaJwtHandler
     * or implement hybrid mode with separate handlers.
     *
     * @throws \RuntimeException Always throws - use RsaJwtHandler for token generation
     */
    public function generateToken(array $payload, ?int $expirySeconds = null, string $tokenType = 'access'): string
    {
        throw new \RuntimeException(
            'Token generation not supported by MultiAuthJwtAdapter. ' .
            'Use RsaJwtHandler for token generation or implement hybrid auth mode.'
        );
    }
}
