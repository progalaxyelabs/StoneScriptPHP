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
 * - Customer auth server
 * - Employee/admin auth server
 *
 * Configuration:
 * ```php
 * 'auth_servers' => [
 *     'customer' => [
 *         'issuer' => 'https://auth.example.com',
 *         'jwks_url' => 'https://auth.example.com/auth/jwks',
 *         'audience' => 'my-api',
 *         'cache_ttl' => 3600,
 *     ],
 *     'employee' => [
 *         'issuer' => 'https://admin-auth.example.com',
 *         'jwks_url' => 'https://admin-auth.example.com/auth/jwks',
 *         'audience' => 'my-admin-api',
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
    private string $cacheDir;

    /**
     * @param array $authServers Configuration array with issuer details
     * @param string|null $cacheDir Directory for persistent JWKS cache (defaults to system temp dir)
     */
    public function __construct(array $authServers, ?string $cacheDir = null)
    {
        $this->authServers = $authServers;
        $this->cacheDir = $cacheDir ?? sys_get_temp_dir();
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

            $header = JWT::jsonDecode(JWT::urlsafeB64Decode($parts[0]));
            $payload = JWT::jsonDecode(JWT::urlsafeB64Decode($parts[1]));
            $kid = $header->kid ?? null;
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

            // Fetch JWKS for this issuer (kid enables cache-miss detection for key rotation)
            $jwks = $this->getJWKS($issuerType, $serverConfig, $kid);

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
     * Fetch JWKS from issuer's endpoint with persistent caching.
     *
     * Cache strategy:
     * 1. In-memory cache (same PHP-FPM worker, within TTL)
     * 2. Persistent cache (APCu if available, file-based fallback)
     * 3. Fresh fetch from auth service
     *
     * Smart refresh: if JWT's kid is NOT in cached JWKS, force-refetch
     * (signals key rotation). On fetch failure, fall back to stale cache.
     *
     * @param string $issuerType Issuer type key
     * @param array $config Server configuration
     * @param string|null $requiredKid JWT kid header — triggers refetch if missing from cache
     * @return array Parsed JWKS keyed by kid
     * @throws \Exception if JWKS cannot be fetched and no cache exists
     */
    private function getJWKS(string $issuerType, array $config, ?string $requiredKid = null): array
    {
        $now = time();
        $cacheTTL = $config['cache_ttl'] ?? 3600;

        // 1. In-memory cache (same PHP-FPM worker, within TTL)
        if (
            isset($this->jwksCache[$issuerType]) &&
            isset($this->jwksCacheTime[$issuerType]) &&
            ($now - $this->jwksCacheTime[$issuerType]) < $cacheTTL
        ) {
            $keys = $this->jwksCache[$issuerType];
            if ($requiredKid === null || isset($keys[$requiredKid])) {
                return $keys;
            }
            // kid-miss: fall through to refetch
        }

        // 2. Persistent cache (APCu or file-based)
        $staleKeys = null;
        $persistentData = $this->readPersistentCache($issuerType);

        if ($persistentData !== null) {
            try {
                $keys = JWK::parseKeySet($persistentData['jwks']);
                $age = $now - $persistentData['time'];

                if ($age < $cacheTTL && ($requiredKid === null || isset($keys[$requiredKid]))) {
                    // Valid persistent cache hit
                    $this->jwksCache[$issuerType] = $keys;
                    $this->jwksCacheTime[$issuerType] = $persistentData['time'];
                    return $keys;
                }

                // Keep for fallback (stale or kid-miss)
                $staleKeys = $keys;
            } catch (\Exception $e) {
                error_log("JWKS persistent cache parse error for '$issuerType': " . $e->getMessage());
            }
        }

        // 3. Fetch fresh JWKS from auth service
        $jwksUrl = $config['jwks_url'];
        $response = @file_get_contents($jwksUrl);

        if ($response !== false) {
            $jwksData = json_decode($response, true);

            if ($jwksData && isset($jwksData['keys'])) {
                try {
                    $keys = JWK::parseKeySet($jwksData);

                    // Update both caches
                    $this->jwksCache[$issuerType] = $keys;
                    $this->jwksCacheTime[$issuerType] = $now;
                    $this->writePersistentCache($issuerType, $jwksData);

                    return $keys;
                } catch (\Exception $e) {
                    error_log("JWKS parse error from '$jwksUrl': " . $e->getMessage());
                }
            } else {
                error_log("Invalid JWKS response from '$jwksUrl' for issuer type '$issuerType'");
            }
        } else {
            error_log("Failed to fetch JWKS from '$jwksUrl' for issuer type '$issuerType'");
        }

        // 4. Graceful degradation: use stale cache if fetch failed
        if ($staleKeys !== null) {
            error_log("Using stale JWKS cache for '$issuerType' (fetch failed)");
            $this->jwksCache[$issuerType] = $staleKeys;
            return $staleKeys;
        }

        throw new \Exception("Failed to fetch JWKS from '$jwksUrl' for issuer type '$issuerType' and no cache available");
    }

    /**
     * Read JWKS from persistent cache (APCu or file).
     *
     * @return array{jwks: array, time: int}|null Cached data or null
     */
    private function readPersistentCache(string $issuerType): ?array
    {
        $cacheKey = 'stonescriptphp_jwks_' . md5($issuerType);

        // Try APCu first
        if (function_exists('apcu_fetch')) {
            $data = apcu_fetch($cacheKey, $success);
            if ($success && is_array($data) && isset($data['jwks'], $data['time'])) {
                return $data;
            }
        }

        // File-based fallback
        $filePath = $this->cacheDir . '/' . $cacheKey . '.json';
        if (!is_file($filePath)) {
            return null;
        }

        $contents = @file_get_contents($filePath);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);
        if (!is_array($data) || !isset($data['jwks'], $data['time'])) {
            return null;
        }

        return $data;
    }

    /**
     * Write JWKS to persistent cache (APCu and file).
     */
    private function writePersistentCache(string $issuerType, array $jwksData): void
    {
        $cacheKey = 'stonescriptphp_jwks_' . md5($issuerType);
        $data = ['jwks' => $jwksData, 'time' => time()];

        // Write to APCu if available
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $data, 86400); // 24h max TTL in APCu
        }

        // Write to file (atomic via temp + rename)
        $filePath = $this->cacheDir . '/' . $cacheKey . '.json';
        $tmpPath = $filePath . '.' . getmypid() . '.tmp';

        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($tmpPath, $json, LOCK_EX) !== false) {
            @rename($tmpPath, $filePath);
        }
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
