<?php

namespace StoneScriptPHP\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

/**
 * Multi-Auth JWT Validator
 *
 * Validates JWT tokens from multiple authentication issuers.
 * Each issuer has its own JWKS endpoint and configuration.
 *
 * Use case: Shared platform APIs accepting tokens from:
 * - Customer auth server (progalaxyelabs-auth)
 * - Employee auth server (pel-admin-auth)
 *
 * Configuration:
 * ```php
 * 'auth_servers' => [
 *     'customer' => [
 *         'issuer' => 'https://auth.progalaxyelabs.com',
 *         'jwks_url' => 'https://auth.progalaxyelabs.com/auth/jwks',
 *         'audience' => 'progalaxyelabs-api',
 *         'cache_ttl' => 3600,
 *     ],
 *     'employee' => [
 *         'issuer' => 'https://admin-auth.progalaxyelabs.com',
 *         'jwks_url' => 'https://admin-auth.progalaxyelabs.com/auth/jwks',
 *         'audience' => 'pel-admin-api',
 *         'cache_ttl' => 3600,
 *     ],
 * ]
 * ```
 *
 * Authorization rules can be applied based on issuer type:
 * - Customer tokens: standard user permissions
 * - Employee tokens: elevated admin/support permissions
 */
class MultiAuthJwtValidator
{
    private array $authServers;
    private array $jwksCache = [];
    private array $jwksCacheTime = [];

    /**
     * @param array $authServers Configuration array with issuer details
     */
    public function __construct(array $authServers)
    {
        $this->authServers = $authServers;
    }

    /**
     * Validate JWT token against configured issuers
     *
     * @param string $jwt JWT token to validate
     * @return array|null Decoded claims with 'issuer_type' added, or null if invalid
     */
    public function validateJWT(string $jwt): ?array
    {
        // First decode without verification to get the issuer claim
        try {
            // Manually decode JWT payload to extract issuer (avoids "empty key" error in jwt v6+)
            $parts = explode('.', $jwt);
            if (count($parts) !== 3) {
                error_log("JWT validation failed: Invalid JWT format");
                return null;
            }

            $payload = JWT::jsonDecode(JWT::urlsafeB64Decode($parts[1]));
            $issuer = $payload->iss ?? null;

            if (!$issuer) {
                error_log("JWT validation failed: Token has no 'iss' claim");
                return null;
            }

            // Find matching auth server by issuer
            $issuerType = $this->findIssuerType($issuer);
            if (!$issuerType) {
                error_log("JWT validation failed: Unknown issuer '$issuer'");
                return null;
            }

            $serverConfig = $this->authServers[$issuerType];

            // Fetch JWKS for this issuer
            $jwks = $this->getJWKS($issuerType, $serverConfig);

            // Decode and verify with proper JWKS
            $decoded = JWT::decode($jwt, $jwks);

            // Verify audience if configured
            if (isset($serverConfig['audience'])) {
                $audience = $decoded->aud ?? null;
                if ($audience !== $serverConfig['audience']) {
                    error_log("JWT validation failed: Invalid audience. Expected '{$serverConfig['audience']}', got '$audience'");
                    return null;
                }
            }

            // Convert to array and add issuer type for authorization rules
            $claims = (array) $decoded;
            $claims['issuer_type'] = $issuerType;

            return $claims;
        } catch (\Firebase\JWT\ExpiredException $e) {
            error_log("JWT validation failed: Token expired - " . $e->getMessage());
            return null;
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            error_log("JWT validation failed: Invalid signature - " . $e->getMessage());
            return null;
        } catch (\Exception $e) {
            error_log("JWT validation failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find issuer type by matching issuer URL
     *
     * @param string $issuer Issuer claim from JWT
     * @return string|null Issuer type key or null if not found
     */
    private function findIssuerType(string $issuer): ?string
    {
        foreach ($this->authServers as $type => $config) {
            if (isset($config['issuer']) && $config['issuer'] === $issuer) {
                return $type;
            }
        }
        return null;
    }

    /**
     * Fetch JWKS from issuer's endpoint (with caching)
     *
     * @param string $issuerType Issuer type key
     * @param array $config Server configuration
     * @return array Parsed JWKS
     * @throws \Exception if JWKS cannot be fetched
     */
    private function getJWKS(string $issuerType, array $config): array
    {
        $now = time();
        $cacheTTL = $config['cache_ttl'] ?? 3600;

        // Return cached JWKS if still valid
        if (
            isset($this->jwksCache[$issuerType]) &&
            isset($this->jwksCacheTime[$issuerType]) &&
            ($now - $this->jwksCacheTime[$issuerType]) < $cacheTTL
        ) {
            return $this->jwksCache[$issuerType];
        }

        // Fetch fresh JWKS
        $jwksUrl = $config['jwks_url'];
        $response = @file_get_contents($jwksUrl);

        if ($response === false) {
            throw new \Exception("Failed to fetch JWKS from '$jwksUrl' for issuer type '$issuerType'");
        }

        $jwksData = json_decode($response, true);
        if (!$jwksData) {
            throw new \Exception("Invalid JWKS response from '$jwksUrl'");
        }

        $this->jwksCache[$issuerType] = JWK::parseKeySet($jwksData);
        $this->jwksCacheTime[$issuerType] = $now;

        return $this->jwksCache[$issuerType];
    }

    /**
     * Get configured auth servers
     *
     * @return array
     */
    public function getAuthServers(): array
    {
        return $this->authServers;
    }

    /**
     * Check if an issuer type is configured
     *
     * @param string $issuerType
     * @return bool
     */
    public function hasIssuerType(string $issuerType): bool
    {
        return isset($this->authServers[$issuerType]);
    }
}
