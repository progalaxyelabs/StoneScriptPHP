<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth;

use StoneScriptPHP\Env;

/**
 * HybridApiTokenJwtHandler — validates BOTH platform-minted API tokens and auth-service auth tokens.
 *
 * ## The problem it solves
 *
 * In the auth-token/API-token model (framework-spec.md §6) the platform mints two
 * completely different kinds of JWTs:
 *
 *   - **Auth token** — identity JWT issued by the central auth service.
 *     Signed with the auth service's private key, `iss` = AUTH_ISSUER.
 *     Validated via JWKS fetched from the auth service.
 *
 *   - **API token** — platform token issued by THIS platform's API.
 *     Signed with THIS platform's RSA key (JWT_PRIVATE_KEY_PATH), `iss` = JWT_ISSUER.
 *     Validated with the platform's own public key.
 *
 * The old `MultiAuthJwtAdapter` (JWKS-only) rejects platform-minted API tokens because it
 * only knows the auth service's public key — NOT the platform's. Using `RsaJwtHandler`
 * alone rejects auth tokens because their issuer and signature don't match the platform key.
 *
 * `HybridApiTokenJwtHandler` solves this by chaining both:
 *   1. Try platform RSA first (fast, no network) → succeeds for API tokens.
 *   2. Fall back to JWKS validation → succeeds for auth tokens.
 *
 * ## Validation order
 *
 * Platform RSA is tried first because:
 *   - It is fast (local key file, no network).
 *   - In steady state (all clients hold an API token), RSA succeeds immediately.
 *   - RSA gracefully returns `false` on key mismatch — NEVER throws beyond Exception.
 *
 * JWKS is the fallback for:
 *   - Auth tokens on protected routes (rare — usually exchange is the only auth-token-bearing route).
 *   - Transition period when old clients still hold auth tokens from a prior session.
 *
 * ## Usage in Application::run()
 *
 * Platforms do NOT need to instantiate this directly. It is the default JWT handler
 * for AUTH_MODE=external|hybrid when a platform RSA key exists. Override via the
 * `jwt.handler` injection key if needed:
 *
 *   Application::run([
 *       'jwt' => ['handler' => new HybridApiTokenJwtHandler(...)],
 *       'auth' => ['mode' => 'external', ...],
 *   ]);
 *
 * @package StoneScriptPHP\Auth
 * @since   5.4.0
 */
class HybridApiTokenJwtHandler implements JwtHandlerInterface
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
     * Verify a JWT — platform API token (RSA) or auth-service auth token (JWKS).
     *
     * Validation order: platform RSA → JWKS fallback.
     * Returns decoded claims (minus standard JWT fields) on success, false on failure.
     *
     * @param string $jwt
     * @return array|false
     */
    public function verifyToken(string $jwt): array|false
    {
        // Attempt platform RSA validation first (API tokens).
        // RsaJwtHandler::verifyToken() returns false on any failure — never throws.
        $claims = $this->platformHandler->verifyToken($jwt);
        if ($claims !== false) {
            return $claims;
        }

        // Task #10005: an EXPIRED (or otherwise RSA-rejected) platform API token
        // — the overwhelmingly common case, happening every access-token TTL for
        // every active user — has `iss` = this platform's own JWT_ISSUER. Falling
        // through to the JWKS handler for it can never succeed (the JWKS handler
        // only knows the auth service's issuer) and only produces a misleading
        // "Unknown issuer '<platform>'" log line that masks the real, already-
        // known reason (RsaJwtHandler already logged expired/signature-invalid).
        // Skip the useless network-capable fallback for tokens self-identifying
        // as this platform's own issuer; only genuinely foreign-issuer tokens
        // (auth-service auth tokens) reach the JWKS path below.
        $platformIssuer = Env::get_instance()->JWT_ISSUER ?? null;
        if (!empty($platformIssuer) && $this->peekIssuer($jwt) === $platformIssuer) {
            return false;
        }

        // Fall back to JWKS (auth tokens from the auth service).
        return $this->authServiceHandler->verifyToken($jwt);
    }

    /**
     * Read the `iss` claim WITHOUT verifying — used only to short-circuit the
     * JWKS fallback for tokens that already self-identify as this platform's
     * own issuer (see verifyToken() above). The claim is verified for real by
     * whichever handler actually processes the token; peeking it here never
     * grants trust on its own.
     */
    private function peekIssuer(string $jwt): ?string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        try {
            $payload = \Firebase\JWT\JWT::jsonDecode(\Firebase\JWT\JWT::urlsafeB64Decode($parts[1]));
        } catch (\Throwable $e) {
            return null;
        }
        $iss = $payload->iss ?? null;
        return (is_string($iss) && $iss !== '') ? $iss : null;
    }

    /**
     * Generate a platform JWT (API token) using the platform's RSA private key.
     *
     * Delegates to RsaJwtHandler — all API-token signing uses the platform key.
     *
     * @param array       $payload      Claims to stamp on the API token.
     * @param int|null    $expirySeconds Expiry in seconds (defaults to JWT_ACCESS_TOKEN_EXPIRY or 900).
     * @param string      $tokenType    'access' or 'refresh' — stamped as the `type` claim.
     * @param string      $purpose      'authentication'|'authorization' — stamped as the `purpose` claim.
     * @return string Signed JWT.
     * @throws \RuntimeException if JWT_ISSUER is unset/empty or private key is missing.
     */
    public function generateToken(
        array $payload,
        ?int $expirySeconds = null,
        string $tokenType = TokenClaims::TYPE_ACCESS,
        string $purpose = TokenClaims::PURPOSE_AUTHENTICATION
    ): string {
        return $this->platformHandler->generateToken($payload, $expirySeconds, $tokenType, $purpose);
    }
}
