<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth;

/**
 * Exception thrown during token exchange operations.
 *
 * Error codes:
 * - INVALID_FORMAT: JWT has invalid format (not 3 parts)
 * - INVALID_ISSUER: Token issuer doesn't match expected
 * - INVALID_AUDIENCE: Token audience doesn't match expected
 * - INVALID_SIGNATURE: JWT signature verification failed
 * - TOKEN_EXPIRED: JWT has expired
 * - VALIDATION_FAILED: Generic validation failure
 * - CONFIG_ERROR: Missing or invalid configuration
 * - KEY_ERROR: Cannot load private key for signing
 * - SIGNING_ERROR: Failed to sign platform token
 * - JWKS_FETCH_ERROR: Cannot fetch JWKS from endpoint
 * - JWKS_INVALID: JWKS response is invalid
 * - JWKS_PARSE_ERROR: Cannot parse JWKS keys
 */
class TokenExchangeException extends \Exception
{
    private string $errorCode;

    public function __construct(string $message, string $errorCode, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
    }

    /**
     * Get the error code for API responses.
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Convert to array for JSON API responses.
     */
    public function toArray(): array
    {
        return [
            'error' => $this->errorCode,
            'message' => $this->getMessage(),
        ];
    }
}
