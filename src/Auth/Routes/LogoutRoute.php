<?php

namespace StoneScriptPHP\Auth\Routes;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\CookieHelper;
use StoneScriptPHP\Auth\CsrfHelper;
use StoneScriptPHP\Auth\AuthRoutes;
use StoneScriptPHP\Auth\AuthContext;

/**
 * Logout Route
 *
 * Built-in route for logging out users and clearing auth cookies.
 *
 * Security features:
 * - Validates CSRF token
 * - Revokes refresh token (if token storage provided)
 * - Clears httpOnly refresh token cookie
 * - Clears CSRF token cookie
 * - Optionally invalidates access token
 *
 * Request:
 *   POST /auth/logout
 *   Headers:
 *     Authorization: Bearer eyJ... (optional, for token blacklisting)
 *     X-CSRF-Token: ...
 *     Cookie: refresh_token=...
 *
 * Response:
 *   {
 *     "status": "ok",
 *     "message": "Logged out successfully"
 *   }
 */
class LogoutRoute implements IRouteHandler
{
    public function __construct()
    {
        // No dependencies needed - uses AuthRoutes::getTokenStorage()
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
            log_error('LogoutRoute: CSRF validation failed');
            http_response_code(403);
            return new ApiResponse('error', 'CSRF token validation failed');
        }

        // 2. Get refresh token from cookie
        $refreshToken = CookieHelper::getRefreshToken();

        // 3. Get token storage (if configured)
        $tokenStorage = AuthRoutes::getTokenStorage();

        // 4. Revoke refresh token (if storage provided and token exists)
        if ($tokenStorage !== null && !empty($refreshToken)) {
            $tokenHash = hash('sha256', $refreshToken);

            try {
                $tokenStorage->revokeRefreshToken($tokenHash);
                log_debug('LogoutRoute: Refresh token revoked successfully');
            } catch (\Exception $e) {
                log_error("LogoutRoute: Failed to revoke refresh token - {$e->getMessage()}");
                // Don't fail logout if revocation fails - still clear cookies
            }
        }

        // 5. Optionally revoke all user tokens (if user is authenticated)
        // This is useful for "logout from all devices" functionality
        $user = AuthContext::user();
        if ($user !== null && $tokenStorage !== null) {
            $revokeAll = $_GET['revoke_all'] ?? $_POST['revoke_all'] ?? false;

            if (filter_var($revokeAll, FILTER_VALIDATE_BOOLEAN)) {
                try {
                    $tokenStorage->revokeAllUserTokens($user->user_id);
                    log_debug("LogoutRoute: All tokens revoked for user {$user->user_id}");
                } catch (\Exception $e) {
                    log_error("LogoutRoute: Failed to revoke all user tokens - {$e->getMessage()}");
                }
            }
        }

        // 6. Clear refresh token cookie
        CookieHelper::clearRefreshToken();

        // 7. Clear CSRF token cookie
        CookieHelper::clearCsrfToken();

        // 8. Clear CSRF from session (if using session-based CSRF)
        CsrfHelper::clear();

        // 9. Clear auth context
        AuthContext::clear();

        log_debug('LogoutRoute: User logged out successfully');

        return new ApiResponse('ok', 'Logged out successfully');
    }
}
