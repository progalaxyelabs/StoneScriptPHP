<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth;

/**
 * HybridCardJwtHandler — validates BOTH platform-minted cards and auth-service passports.
 *
 * ## The problem it solves
 *
 * In the passport/card model (framework-spec.md §6) the platform mints two
 * completely different kinds of JWTs:
 *
 *   - **Passport** — identity JWT issued by the central auth service.
 *     Signed with the auth service's private key, `iss` = AUTH_ISSUER.
 *     Validated via JWKS fetched from the auth service.
 *
 *   - **Card** — platform token issued by THIS platform's API.
 *     Signed with THIS platform's RSA key (JWT_PRIVATE_KEY_PATH), `iss` = JWT_ISSUER.
 *     Validated with the platform's own public key.
 *
 * The old `MultiAuthJwtAdapter` (JWKS-only) rejects platform-minted cards because it
 * only knows the auth service's public key — NOT the platform's. Using `RsaJwtHandler`
 * alone rejects passports because their issuer and signature don't match the platform key.
 *
 * `HybridCardJwtHandler` solves this by chaining both:
 *   1. Try platform RSA first (fast, no network) → succeeds for cards.
 *   2. Fall back to JWKS validation → succeeds for passports.
 *
 * ## Validation order
 *
 * Platform RSA is tried first because:
 *   - It is fast (local key file, no network).
 *   - In steady state (all clients hold a card), RSA succeeds immediately.
 *   - RSA gracefully returns `false` on key mismatch — NEVER throws beyond Exception.
 *
 * JWKS is the fallback for:
 *   - Passports on protected routes (rare — usually exchange is the only passport-bearing route).
 *   - Transition period when old clients still hold passports from a prior session.
 *
 * ## Usage in Application::run()
 *
 * Platforms do NOT need to instantiate this directly. It is the default JWT handler
 * for AUTH_MODE=external|hybrid when a platform RSA key exists. Override via the
 * `jwt.handler` injection key if needed:
 *
 *   Application::run([
 *       'jwt' => ['handler' => new HybridCardJwtHandler(...)],
 *       'auth' => ['mode' => 'external', ...],
 *   ]);
 *
 * @package StoneScriptPHP\Auth
 * @since   5.4.0
 */
class HybridCardJwtHandler implements JwtHandlerInterface
{
    private RsaJwtHandler $platformHandler;
    private JwtHandlerInterface $authServiceHandler;

    /**
     * @param string $authServiceUrl Auth service base URL (used for JWKS fetch — container URL in Docker)
     * @param string $authIssuer     Auth service JWT 'iss' claim (public URL — NOT the container URL)
     * @param string $jwksPath       JWKS endpoint path on the auth service (default: /api/auth/jwks)
     */
    public function __construct(
        string $authServiceUrl,
        string $authIssuer,
        string $jwksPath = '/api/auth/jwks'
    ) {
        $this->platformHandler = new RsaJwtHandler();

        $validator = new MultiAuthJwtValidator([
            'primary' => [
                'issuer'    => $authIssuer,
                'jwks_url'  => rtrim($authServiceUrl, '/') . $jwksPath,
                'audience'  => null,
                'cache_ttl' => 3600,
            ],
        ]);
        $this->authServiceHandler = new MultiAuthJwtAdapter($validator);
    }

    /**
     * Verify a JWT — platform card (RSA) or auth-service passport (JWKS).
     *
     * Validation order: platform RSA → JWKS fallback.
     * Returns decoded claims (minus standard JWT fields) on success, false on failure.
     *
     * @param string $jwt
     * @return array|false
     */
    public function verifyToken(string $jwt): array|false
    {
        // Attempt platform RSA validation first (cards).
        // RsaJwtHandler::verifyToken() returns false on any failure — never throws.
        $claims = $this->platformHandler->verifyToken($jwt);
        if ($claims !== false) {
            return $claims;
        }

        // Fall back to JWKS (passports from the auth service).
        return $this->authServiceHandler->verifyToken($jwt);
    }

    /**
     * Generate a platform JWT (card) using the platform's RSA private key.
     *
     * Delegates to RsaJwtHandler — all card signing uses the platform key.
     *
     * @param array       $payload      Claims to stamp on the card.
     * @param int|null    $expirySeconds Expiry in seconds (defaults to JWT_ACCESS_TOKEN_EXPIRY or 900).
     * @param string      $tokenType    'access' or 'refresh'.
     * @return string Signed JWT.
     * @throws \RuntimeException if JWT_ISSUER is unset/empty or private key is missing.
     */
    public function generateToken(array $payload, ?int $expirySeconds = null, string $tokenType = 'access'): string
    {
        return $this->platformHandler->generateToken($payload, $expirySeconds, $tokenType);
    }
}
