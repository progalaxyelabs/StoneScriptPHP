<?php

namespace StoneScriptPHP\Auth\Routes;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\RsaJwtHandler;
use StoneScriptPHP\Auth\JwtHandlerInterface;
use StoneScriptPHP\Auth\CookieHelper;
use StoneScriptPHP\Auth\CsrfHelper;
use StoneScriptPHP\Auth\AuthRoutes;

/**
 * Refresh Route
 *
 * Built-in route for refreshing access tokens using httpOnly cookies.
 *
 * Security features:
 * - Reads refresh token from httpOnly cookie (not request body)
 * - Validates CSRF token
 * - Issues new access token
 * - Rotates refresh token (invalidates old one)
 * - Sets new refresh token in httpOnly cookie
 *
 * Request:
 *   POST /auth/refresh
 *   Headers:
 *     Cookie: refresh_token=...
 *     X-CSRF-Token: ...
 *
 * Response:
 *   {
 *     "status": "ok",
 *     "data": {
 *       "access_token": "eyJ...",
 *       "expires_in": 900,
 *       "token_type": "Bearer"
 *     }
 *   }
 */
class RefreshRoute implements IRouteHandler
{
    private JwtHandlerInterface $jwtHandler;

    /**
     * @param JwtHandlerInterface|null $jwtHandler Optional JWT handler (defaults to RsaJwtHandler)
     */
    public function __construct(?JwtHandlerInterface $jwtHandler = null)
    {
        $this->jwtHandler = $jwtHandler ?? new RsaJwtHandler();
    }

    public function validation_rules(): array
    {
        // No body validation needed - uses cookies and headers
        return [];
    }

    public function process(): ApiResponse
    {
        // 1. Validate CSRF token
        if (!CsrfHelper::validateRequest()) {
            log_error('RefreshRoute: CSRF validation failed');
            http_response_code(403);
            return new ApiResponse('error', 'CSRF token validation failed');
        }

        // 2. Read refresh token from httpOnly cookie
        $refreshToken = CookieHelper::getRefreshToken();

        if (empty($refreshToken)) {
            log_error('RefreshRoute: No refresh token in cookie');
            http_response_code(401);
            return new ApiResponse('error', 'No refresh token provided');
        }

        // 3. Verify refresh token using RsaJwtHandler
        $payload = $this->jwtHandler->verifyToken($refreshToken);

        if ($payload === false) {
            log_error('RefreshRoute: Invalid or expired refresh token');
            http_response_code(401);
            return new ApiResponse('error', 'Invalid or expired refresh token');
        }

        // 4. Verify token type is 'refresh'
        if (!isset($payload['type']) || $payload['type'] !== 'refresh') {
            log_error('RefreshRoute: Token is not a refresh token');
            http_response_code(401);
            return new ApiResponse('error', 'Invalid token type');
        }

        // 5. Check token storage (if provided)
        $tokenStorage = AuthRoutes::getTokenStorage();
        if ($tokenStorage !== null) {
            $tokenHash = hash('sha256', $refreshToken);

            if (!$tokenStorage->validateRefreshToken($tokenHash)) {
                log_error('RefreshRoute: Refresh token revoked or not found in storage');
                http_response_code(401);
                return new ApiResponse('error', 'Refresh token has been revoked');
            }

            // Update last used timestamp
            $tokenStorage->updateLastUsed($tokenHash);

            // Revoke old refresh token (rotation)
            $tokenStorage->revokeRefreshToken($tokenHash);
        }

        // 6. Extract user ID and claims
        $userId = $payload['user_id'] ?? $payload['sub'] ?? null;

        if ($userId === null) {
            log_error('RefreshRoute: No user_id in refresh token payload');
            http_response_code(401);
            return new ApiResponse('error', 'Invalid token payload');
        }

        // 7. Generate new access token
        $env = \StoneScriptPHP\Env::get_instance();
        $accessTokenExpiry = $env->JWT_ACCESS_TOKEN_EXPIRY ?? 900; // 15 minutes

        $accessTokenPayload = [
            'user_id' => $userId,
            'sub' => $userId,
        ];

        // Include custom claims from original token (exclude type)
        foreach ($payload as $key => $value) {
            if (!in_array($key, ['user_id', 'sub', 'type', 'iat', 'exp', 'iss'])) {
                $accessTokenPayload[$key] = $value;
            }
        }

        $newAccessToken = $this->jwtHandler->generateToken($accessTokenPayload, $accessTokenExpiry, 'access');

        // 8. Generate new refresh token (rotation)
        $refreshTokenExpiry = $env->JWT_REFRESH_TOKEN_EXPIRY ?? 15552000; // 180 days

        $refreshTokenPayload = [
            'user_id' => $userId,
            'sub' => $userId,
            'type' => 'refresh'
        ];

        $newRefreshToken = $this->jwtHandler->generateToken($refreshTokenPayload, $refreshTokenExpiry, 'refresh');

        // 9. Store new refresh token (if storage provided)
        if ($tokenStorage !== null) {
            $newTokenHash = hash('sha256', $newRefreshToken);

            $metadata = [
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ];

            $tokenStorage->storeRefreshToken(
                $newTokenHash,
                $userId,
                time() + $refreshTokenExpiry,
                $metadata
            );
        }

        // 10. Set new refresh token in httpOnly cookie
        CookieHelper::setRefreshToken($newRefreshToken, $refreshTokenExpiry);

        // 11. Generate new CSRF token
        $newCsrfToken = CsrfHelper::generate();
        CookieHelper::setCsrfToken($newCsrfToken, $refreshTokenExpiry);

        log_debug("RefreshRoute: Successfully refreshed token for user $userId");

        // 12. Return new access token
        return new ApiResponse('ok', [
            'access_token' => $newAccessToken,
            'expires_in' => $accessTokenExpiry,
            'token_type' => 'Bearer'
        ]);
    }
}
