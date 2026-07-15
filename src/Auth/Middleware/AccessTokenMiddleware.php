<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\Routing\RouteAccess;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\AuthenticatedUser;
use StoneScriptPHP\Auth\TokenClaims;
use StoneScriptPHP\Auth\TrustedIssuerVerifier;

/**
 * AccessTokenMiddleware — STATELESS verification of a Bearer ACCESS token.
 *
 * Separate-by-type (owner preference): this class handles ONLY `token_type=access`
 * routes; {@see RefreshTokenMiddleware} handles refresh routes. There is no
 * branching "one middleware for both" class.
 *
 * For a route whose `access` is `authentication` or `authorization`, it:
 *   1. reads the `Authorization: Bearer <jwt>` header,
 *   2. verifies signature + `iss`(→key-select) + `exp` via {@see TrustedIssuerVerifier},
 *   3. asserts `type == access` (strict equality),
 *   4. asserts `purpose == route.access` (strict equality),
 *   5. populates `AuthContext` + `$request['jwt_claims']` for downstream guards.
 *
 * No database access. Routes whose `access` is `public` pass straight through.
 *
 * @package StoneScriptPHP\Auth\Middleware
 * @since   6.2.0
 */
class AccessTokenMiddleware implements MiddlewareInterface
{
    /**
     * @param TrustedIssuerVerifier $verifier Issuer→key verifier (mandatory iss).
     * @param string|null $purpose Required `purpose` (RouteAccess::AUTHENTICATION|AUTHORIZATION).
     *   When null, the required purpose is read from `route.access` at request time —
     *   the recommended wiring, so a single instance serves every access-token route.
     * @param string $headerName Header carrying the bearer token.
     */
    public function __construct(
        private readonly TrustedIssuerVerifier $verifier,
        private readonly ?string $purpose = null,
        private readonly string $headerName = 'Authorization',
    ) {
        if ($purpose !== null && !TokenClaims::isValidPurpose($purpose)) {
            throw new \InvalidArgumentException(
                "AccessTokenMiddleware purpose must be a valid TokenClaims purpose, got '$purpose'."
            );
        }
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        $route = $request['route'] ?? null;
        $access = $route['access'] ?? null;

        // Public routes require no token. (A route with no access model and no
        // is_public flag falls back to the legacy JwtAuthMiddleware path — this
        // middleware only acts when an access model is declared.)
        if ($access === RouteAccess::PUBLIC || ($access === null && ($route['is_public'] ?? false))) {
            return $next($request);
        }

        // This middleware only enforces access-token routes. A refresh-token route
        // must be handled by RefreshTokenMiddleware — never accept a refresh token
        // where an access token is expected.
        $routeTokenType = $route['token_type'] ?? RouteAccess::TOKEN_ACCESS;
        if ($access !== null && $routeTokenType !== RouteAccess::TOKEN_ACCESS) {
            return $next($request);
        }

        // Determine the required purpose: explicit constructor value wins; else route.access.
        $requiredPurpose = $this->purpose ?? $access;
        if (!TokenClaims::isValidPurpose($requiredPurpose)) {
            // No access model on this route and no explicit purpose → not ours to enforce.
            if ($access === null) {
                return $next($request);
            }
            return $this->deny(500, 'Route access model misconfigured');
        }

        $authHeader = $this->getAuthHeader();
        if ($authHeader === '') {
            return $this->deny(401, 'Unauthorized: Missing authentication token');
        }

        $token = $this->extractToken($authHeader);
        $claims = $this->verifier->verify($token);
        if ($claims === null) {
            return $this->deny(401, 'Unauthorized: Invalid or expired token');
        }

        // Strict type: must be an access token.
        if (($claims[TokenClaims::CLAIM_TYPE] ?? null) !== TokenClaims::TYPE_ACCESS) {
            return $this->deny(401, 'Unauthorized: wrong token type (access token required)');
        }

        // Strict purpose: must match the route's access requirement.
        if (($claims[TokenClaims::CLAIM_PURPOSE] ?? null) !== $requiredPurpose) {
            return $this->deny(403, 'Forbidden: token purpose does not match route');
        }

        // Populate the shared auth context + raw claims for the Require* guard family.
        try {
            AuthContext::setUser(AuthenticatedUser::fromPayload($claims));
        } catch (\InvalidArgumentException $e) {
            // Claims verified but not shaped as a user (unusual) — still expose claims.
        }
        $request['jwt_claims'] = $claims;

        return $next($request);
    }

    private function deny(int $code, string $message): ApiResponse
    {
        http_response_code($code);
        return new ApiResponse('error', $message, ['error' => 'unauthorized'], $code);
    }

    private function getAuthHeader(): string
    {
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return $_SERVER['HTTP_AUTHORIZATION'];
        }
        $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $this->headerName));
        if (isset($_SERVER[$headerKey])) {
            return $_SERVER[$headerKey];
        }
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers[$this->headerName])) {
                return $headers[$this->headerName];
            }
        }
        return '';
    }

    private function extractToken(string $authHeader): string
    {
        if (stripos($authHeader, 'Bearer ') === 0) {
            return substr($authHeader, 7);
        }
        return $authHeader;
    }
}
