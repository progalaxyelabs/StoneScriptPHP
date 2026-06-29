<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

/**
 * Token Exchange Service
 *
 * Validates identity tokens (passports from the central auth service) and mints
 * platform tokens (cards). Central to the passport/card tenancy model:
 *
 *   Passport = identity JWT from auth service. Tenant-less. Proves *who you are*.
 *   Card     = platform JWT from this service. Carries identity_id + tenant_id +
 *              single active role_id. Authorises all tenant-scoped requests.
 *
 * ## Canonical flow (framework-spec.md §6)
 *
 * 1. Client authenticates with auth service → gets **passport** (identity JWT)
 * 2. Client calls platform's POST /api/auth/exchange with:
 *      Authorization: Bearer <passport>
 *      Body: { tenant_id, role_id? }
 * 3. Platform validates passport via JWKS
 * 4. Platform looks up identity's memberships (tenants + roles)
 * 5. Platform issues a **card** via exchangeCard()
 *
 * @package StoneScriptPHP\Auth
 */
class TokenExchangeService
{
    private const ALGORITHM = 'RS256';

    /** @var array<string, array{keys: array, time: int}> In-memory JWKS cache */
    private array $jwksCache = [];

    /** @var int JWKS cache TTL in seconds */
    private int $cacheTTL = 3600;

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Validate an identity token (passport) against a JWKS endpoint.
     *
     * @param string $token JWT passport from identity provider
     * @param string $jwksUrl JWKS endpoint URL (e.g., https://auth.example.com/.well-known/jwks.json)
     * @param string $expectedIssuer Expected issuer claim (e.g., https://auth.example.com)
     * @param string|null $expectedAudience Optional audience to verify
     * @return array Decoded passport claims
     * @throws TokenExchangeException If validation fails
     */
    public function validateIdentityToken(
        string $token,
        string $jwksUrl,
        string $expectedIssuer,
        ?string $expectedAudience = null
    ): array {
        try {
            // Decode header to get kid for key lookup
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                throw new TokenExchangeException('Invalid JWT format', 'INVALID_FORMAT');
            }

            $header = JWT::jsonDecode(JWT::urlsafeB64Decode($parts[0]));
            $kid = $header->kid ?? null;

            // Fetch JWKS (with caching)
            $keys = $this->fetchJWKS($jwksUrl, $kid);

            // Decode and verify signature
            $decoded = JWT::decode($token, $keys);
            $claims = (array) $decoded;

            // Verify issuer
            $issuer = $claims['iss'] ?? null;
            if ($issuer !== $expectedIssuer) {
                throw new TokenExchangeException(
                    "Invalid issuer: expected '$expectedIssuer', got '$issuer'",
                    'INVALID_ISSUER'
                );
            }

            // Verify audience if specified
            if ($expectedAudience !== null) {
                $audience = $claims['aud'] ?? null;
                // Audience can be string or array
                $audienceMatch = is_array($audience)
                    ? in_array($expectedAudience, $audience, true)
                    : $audience === $expectedAudience;

                if (!$audienceMatch) {
                    throw new TokenExchangeException(
                        "Invalid audience: expected '$expectedAudience'",
                        'INVALID_AUDIENCE'
                    );
                }
            }

            return $claims;

        } catch (\Firebase\JWT\ExpiredException $e) {
            throw new TokenExchangeException('Identity token has expired', 'TOKEN_EXPIRED');
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            throw new TokenExchangeException('Invalid token signature', 'INVALID_SIGNATURE');
        } catch (TokenExchangeException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new TokenExchangeException(
                'Token validation failed: ' . $e->getMessage(),
                'VALIDATION_FAILED'
            );
        }
    }

    /**
     * Mint a **card token** — the canonical platform JWT in the passport/card model.
     *
     * The card carries:
     *   - identity_id (preserved from passport)
     *   - tenant_id (from the merged claims / chosen at exchange time)
     *   - role_id (single active role — NOT an array)
     *   - iss = platform API (not the auth service)
     *
     * framework-spec.md §6 — there is one card shape for all multi-tenant platforms.
     *
     * @param array  $identityClaimsWithTenant Passport claims + 'tenant_id' merged in
     * @param string $activeRoleId             The single active role to stamp on the card
     * @param array  $config Platform signing configuration:
     *   - private_key_path: string - Path to RSA private key (PEM format)
     *   - private_key_passphrase: string|null - Passphrase if key is encrypted
     *   - issuer: string - Platform issuer URL (iss = platform API)
     *   - audience: string|null - Optional audience claim
     *   - ttl: int - Token TTL in seconds (default: 3600)
     *   - custom_claims: array - Extra claims merged into the card
     * @return string Signed card JWT
     * @throws TokenExchangeException If token generation fails
     */
    public function exchangeCard(
        array $identityClaimsWithTenant,
        string $activeRoleId,
        array $config
    ): string {
        return $this->mintToken(
            $identityClaimsWithTenant,
            $config,
            static function (array $base) use ($activeRoleId): array {
                $base['token_type'] = 'card';
                $base['role_id']    = $activeRoleId;
                return $base;
            }
        );
    }

    /**
     * Exchange identity claims for a platform token with a roles array.
     *
     * @deprecated Use exchangeCard() for the passport/card tenancy model.
     *   This method retains the legacy `roles` array claim and is kept for
     *   backward compatibility with platforms that have not yet migrated.
     *
     * @param array $identityClaims Claims from validated identity token
     * @param array $roles Roles array from platform's tenant DB (e.g., ['owner', 'admin'])
     * @param array $config Platform signing configuration:
     *   - private_key_path: string - Path to RSA private key (PEM format)
     *   - private_key_passphrase: string|null - Passphrase if key is encrypted
     *   - issuer: string - Platform issuer URL
     *   - audience: string|null - Optional audience claim
     *   - ttl: int - Token TTL in seconds (default: 900)
     * @return string Signed platform JWT
     * @throws TokenExchangeException If token generation fails
     */
    public function exchange(array $identityClaims, array $roles, array $config): string
    {
        return $this->mintToken(
            $identityClaims,
            $config,
            static function (array $base) use ($roles): array {
                $base['token_type'] = 'platform';
                $base['roles']      = $roles;
                return $base;
            }
        );
    }

    /**
     * Convenience method: validate passport and mint a card in one call.
     *
     * @param string $passportToken JWT passport from identity provider
     * @param string $activeRoleId  The single active role to stamp on the card
     * @param array  $authConfig Auth validation config:
     *   - jwks_url: string - JWKS endpoint
     *   - issuer: string - Expected issuer
     *   - audience: string|null - Expected audience
     * @param array $platformConfig Platform signing config (see exchangeCard())
     * @return array{token: string, claims: array} Card token and decoded passport claims
     * @throws TokenExchangeException If validation or exchange fails
     */
    public function validateAndExchangeCard(
        string $passportToken,
        string $activeRoleId,
        array $authConfig,
        array $platformConfig
    ): array {
        $passportClaims = $this->validateIdentityToken(
            $passportToken,
            $authConfig['jwks_url'],
            $authConfig['issuer'],
            $authConfig['audience'] ?? null
        );

        $cardToken = $this->exchangeCard($passportClaims, $activeRoleId, $platformConfig);

        return [
            'token'  => $cardToken,
            'claims' => $passportClaims,
        ];
    }

    /**
     * Convenience method: validate and exchange in one call (legacy).
     *
     * @deprecated Use validateAndExchangeCard() for the card model.
     *
     * @param string $identityToken JWT from identity provider
     * @param array $roles Roles from platform's tenant DB
     * @param array $authConfig Auth validation config:
     *   - jwks_url: string - JWKS endpoint
     *   - issuer: string - Expected issuer
     *   - audience: string|null - Expected audience
     * @param array $platformConfig Platform signing config (see exchange())
     * @return array{token: string, claims: array} Platform token and decoded claims
     * @throws TokenExchangeException If validation or exchange fails
     */
    public function validateAndExchange(
        string $identityToken,
        array $roles,
        array $authConfig,
        array $platformConfig
    ): array {
        $identityClaims = $this->validateIdentityToken(
            $identityToken,
            $authConfig['jwks_url'],
            $authConfig['issuer'],
            $authConfig['audience'] ?? null
        );

        $platformToken = $this->exchange($identityClaims, $roles, $platformConfig);

        return [
            'token'  => $platformToken,
            'claims' => $identityClaims,
        ];
    }

    /**
     * Set JWKS cache TTL.
     *
     * @param int $seconds Cache TTL in seconds
     * @return self
     */
    public function setCacheTTL(int $seconds): self
    {
        $this->cacheTTL = $seconds;
        return $this;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Shared token-minting logic used by both exchangeCard() and exchange().
     *
     * @param array    $identityClaims  Source identity/passport claims
     * @param array    $config          Signing configuration
     * @param callable $claimsDecorator fn(array $base): array — adds token-type-specific claims
     * @return string Signed JWT
     * @throws TokenExchangeException
     */
    private function mintToken(array $identityClaims, array $config, callable $claimsDecorator): string
    {
        // Validate required config
        if (empty($config['private_key_path'])) {
            throw new TokenExchangeException(
                'private_key_path is required in config',
                'CONFIG_ERROR'
            );
        }
        if (empty($config['issuer'])) {
            throw new TokenExchangeException(
                'issuer is required in config',
                'CONFIG_ERROR'
            );
        }

        // Load private key
        $privateKeyPath = $config['private_key_path'];
        if (!str_starts_with($privateKeyPath, '/') && defined('ROOT_PATH')) {
            $privateKeyPath = ROOT_PATH . $privateKeyPath;
        }

        $privateKeyContent = @file_get_contents($privateKeyPath);
        if ($privateKeyContent === false) {
            throw new TokenExchangeException(
                "Cannot read private key: $privateKeyPath",
                'KEY_ERROR'
            );
        }

        $passphrase = $config['private_key_passphrase'] ?? null;
        $privateKey = $passphrase
            ? openssl_pkey_get_private($privateKeyContent, $passphrase)
            : openssl_pkey_get_private($privateKeyContent);

        if (!$privateKey) {
            throw new TokenExchangeException(
                'Unable to load private key for JWT signing',
                'KEY_ERROR'
            );
        }

        // Build base claims (common to all token types)
        $now = time();
        $ttl = $config['ttl'] ?? 3600;

        $baseClaims = [
            // Standard JWT claims
            'iss' => $config['issuer'],
            'iat' => $now,
            'exp' => $now + $ttl,

            // Identity claims (preserved from passport / identity token)
            'sub'          => $identityClaims['sub'] ?? null,
            'identity_id'  => $identityClaims['sub'] ?? $identityClaims['identity_id'] ?? null,
            'tenant_id'    => $identityClaims['tenant_id'] ?? null,
            'tenant_slug'  => $identityClaims['tenant_slug'] ?? null,
            'email'        => $identityClaims['email'] ?? null,
            'name'         => $identityClaims['name'] ?? null,
            'display_name' => $identityClaims['display_name'] ?? $identityClaims['name'] ?? null,
        ];

        // Add audience if specified
        if (!empty($config['audience'])) {
            $baseClaims['aud'] = $config['audience'];
        }

        // Allow the decorator to add token-type-specific claims (role_id for cards,
        // roles[] for legacy platform tokens).
        $tokenClaims = $claimsDecorator($baseClaims);

        // Merge custom claims (lowest priority — do not overwrite identity claims).
        if (!empty($config['custom_claims']) && is_array($config['custom_claims'])) {
            foreach ($config['custom_claims'] as $key => $value) {
                if (!isset($tokenClaims[$key])) {
                    $tokenClaims[$key] = $value;
                }
            }
        }

        // Remove null values for a cleaner token.
        $tokenClaims = array_filter($tokenClaims, fn($v) => $v !== null);

        try {
            return JWT::encode($tokenClaims, $privateKey, self::ALGORITHM);
        } catch (\Exception $e) {
            throw new TokenExchangeException(
                'Failed to sign token: ' . $e->getMessage(),
                'SIGNING_ERROR'
            );
        }
    }

    /**
     * Fetch JWKS from endpoint with caching.
     *
     * @param string $jwksUrl JWKS endpoint URL
     * @param string|null $requiredKid If set, force refetch if kid not in cache
     * @return array Parsed JWKS keyed by kid
     * @throws TokenExchangeException If JWKS cannot be fetched
     */
    private function fetchJWKS(string $jwksUrl, ?string $requiredKid = null): array
    {
        $now = time();
        $cacheKey = md5($jwksUrl);

        // Check in-memory cache
        if (isset($this->jwksCache[$cacheKey])) {
            $cached = $this->jwksCache[$cacheKey];
            $age = $now - $cached['time'];

            if ($age < $this->cacheTTL) {
                $keys = $cached['keys'];
                // If we need a specific kid and it's in cache, return it
                if ($requiredKid === null || isset($keys[$requiredKid])) {
                    return $keys;
                }
                // kid not found, fall through to refetch
            }
        }

        // Fetch fresh JWKS
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        $response = @file_get_contents($jwksUrl, false, $context);
        if ($response === false) {
            // Try to use stale cache if fetch fails
            if (isset($this->jwksCache[$cacheKey])) {
                error_log("JWKS fetch failed, using stale cache for: $jwksUrl");
                return $this->jwksCache[$cacheKey]['keys'];
            }
            throw new TokenExchangeException(
                "Failed to fetch JWKS from: $jwksUrl",
                'JWKS_FETCH_ERROR'
            );
        }

        $jwksData = json_decode($response, true);
        if (!$jwksData || !isset($jwksData['keys'])) {
            throw new TokenExchangeException(
                "Invalid JWKS response from: $jwksUrl",
                'JWKS_INVALID'
            );
        }

        try {
            $keys = JWK::parseKeySet($jwksData);
            $this->jwksCache[$cacheKey] = ['keys' => $keys, 'time' => $now];
            return $keys;
        } catch (\Exception $e) {
            throw new TokenExchangeException(
                "Failed to parse JWKS: " . $e->getMessage(),
                'JWKS_PARSE_ERROR'
            );
        }
    }
}
