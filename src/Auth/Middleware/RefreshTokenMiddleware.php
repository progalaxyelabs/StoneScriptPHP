<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\Routing\RouteAccess;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\RefreshTokenStore;
use StoneScriptPHP\Auth\TokenClaims;
use StoneScriptPHP\Auth\TrustedIssuerVerifier;

/**
 * RefreshTokenMiddleware — STATEFUL verification of a body REFRESH token.
 *
 * Separate-by-type (owner preference): this class handles ONLY `token_type=refresh`
 * routes; {@see AccessTokenMiddleware} handles access routes. `purpose` is a
 * PARAMETER on this class (authentication for identity-refresh/logout, authorization
 * for card-refresh) — NOT a third middleware class. Split by TYPE only.
 *
 * For a route whose `token_type` is `refresh`, it:
 *   1. reads `refresh_token` from the request body (not the Authorization header),
 *   2. verifies signature + `iss`(→key-select) + `exp` via {@see TrustedIssuerVerifier},
 *   3. asserts `type == refresh` (strict equality),
 *   4. asserts `purpose == route.access` (strict equality),
 *   5. asserts the refresh-token ROW EXISTS in the {@see RefreshTokenStore}
 *      (absent row ⇒ revoked / never issued ⇒ reject), i.e. the stateful gate that
 *      the stateless access path deliberately lacks.
 *
 * On success it exposes the validated refresh claims on `$request['refresh_claims']`
 * (and `$request['jwt_claims']`) so the route handler can mint the new token pair.
 * The middleware OWNS the gate; the handler performs the actual re-mint using the
 * platform's minter.
 *
 * ## Body-parsing ordering
 *
 * The refresh token is read from the request BODY. Pipe {@see \StoneScriptPHP\Routing\Middleware\JsonBodyParserMiddleware}
 * BEFORE this middleware so `$request['body']` is populated. As a safety net (so a
 * missing parser cannot silently 401 every refresh endpoint), when the `body` key is
 * ABSENT this middleware parses `php://input` itself. When the `body` key is PRESENT
 * (even as an empty array) it is trusted as-is — an empty body then legitimately
 * yields "missing refresh token". Prefer the explicit parser; the fallback exists
 * only to remove the silent-miswire footgun.
 *
 * @package StoneScriptPHP\Auth\Middleware
 * @since   6.2.0
 */
class RefreshTokenMiddleware implements MiddlewareInterface
{
    /**
     * @param TrustedIssuerVerifier $verifier Issuer→key verifier (mandatory iss).
     * @param RefreshTokenStore $store Persistence gate — row present ⇒ not revoked.
     * @param string|null $purpose Required `purpose` (RouteAccess::AUTHENTICATION|AUTHORIZATION).
     *   When null, read from `route.access` at request time.
     * @param string $bodyField Body field carrying the refresh token.
     */
    public function __construct(
        private readonly TrustedIssuerVerifier $verifier,
        private readonly RefreshTokenStore $store,
        private readonly ?string $purpose = null,
        private readonly string $bodyField = 'refresh_token',
    ) {
        if ($purpose !== null && !TokenClaims::isValidPurpose($purpose)) {
            throw new \InvalidArgumentException(
                "RefreshTokenMiddleware purpose must be a valid TokenClaims purpose, got '$purpose'."
            );
        }
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        $route = $request['route'] ?? null;
        $access = $route['access'] ?? null;

        // Only a refresh-token route is ours. Anything else passes through untouched
        // (public routes, and access-token routes handled by AccessTokenMiddleware).
        $routeTokenType = $route['token_type'] ?? null;
        if ($routeTokenType !== RouteAccess::TOKEN_REFRESH) {
            return $next($request);
        }

        $requiredPurpose = $this->purpose ?? $access;
        if (!TokenClaims::isValidPurpose($requiredPurpose)) {
            return $this->deny(500, 'Route access model misconfigured');
        }

        $body = $this->resolveBody($request);
        $refreshToken = $body[$this->bodyField] ?? null;
        if (!is_string($refreshToken) || $refreshToken === '') {
            return $this->deny(401, 'Unauthorized: Missing refresh token');
        }

        $claims = $this->verifier->verify($refreshToken);
        if ($claims === null) {
            return $this->deny(401, 'Unauthorized: Invalid or expired refresh token');
        }

        // Strict type: must be a refresh token.
        if (($claims[TokenClaims::CLAIM_TYPE] ?? null) !== TokenClaims::TYPE_REFRESH) {
            return $this->deny(401, 'Unauthorized: wrong token type (refresh token required)');
        }

        // Strict purpose: must match the route's access requirement.
        if (($claims[TokenClaims::CLAIM_PURPOSE] ?? null) !== $requiredPurpose) {
            return $this->deny(403, 'Forbidden: token purpose does not match route');
        }

        // Stateful gate: the row must exist. Absent ⇒ revoked (hard-deleted) or never
        // issued ⇒ reject, even though the JWT signature + exp are still valid.
        if (!$this->store->exists($this->hash($refreshToken))) {
            return $this->deny(401, 'Unauthorized: refresh token has been revoked');
        }

        $request['refresh_claims'] = $claims;
        $request['jwt_claims'] = $claims;

        return $next($request);
    }

    /**
     * Resolve the request body, tolerating a missing JSON body-parser.
     *
     * If the `body` key is present (the parser ran), it is trusted as-is. If it is
     * ABSENT, we parse `php://input` ourselves so a mis-ordered pipeline cannot make
     * every refresh endpoint silently 401. Non-JSON / unreadable input yields an
     * empty array (⇒ a clean "missing refresh token" rather than a crash).
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function resolveBody(array $request): array
    {
        if (array_key_exists('body', $request)) {
            return is_array($request['body']) ? $request['body'] : [];
        }

        $raw = @file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $parsed = json_decode($raw, true);
        return is_array($parsed) ? $parsed : [];
    }

    /**
     * SHA-256 hash of the raw token — the storage/lookup key. Must match the hash
     * the mint site passed to RefreshTokenStore::store().
     */
    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function deny(int $code, string $message): ApiResponse
    {
        http_response_code($code);
        return new ApiResponse('error', $message, ['error' => 'unauthorized'], $code);
    }
}
