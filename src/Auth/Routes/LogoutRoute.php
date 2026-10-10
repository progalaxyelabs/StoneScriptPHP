<?php

namespace StoneScriptPHP\Auth\Routes;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\CookieHelper;
use StoneScriptPHP\Auth\CsrfHelper;
use StoneScriptPHP\Auth\AuthRoutes;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\RefreshTokens\RefreshTokenIssuer;

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

        // 2b. Persisted sessions: end the whole session family; optionally every session of this user in this tenant.
        $issuer = RefreshTokenIssuer::configured();
        if ($issuer !== null) {
            try {
                if (!empty($refreshToken)) {
                    $issuer->revokeSession($refreshToken);
                }
                $authUser = AuthContext::check() ? AuthContext::getUser() : null;
                if ($authUser !== null && filter_var($_GET['revoke_all'] ?? $_POST['revoke_all'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    // Same subject builder as issuing: a real global identity_id when the token carries one, else {tenant}#{user}.
                    $issuer->revokeAllForClaims($authUser->subjectClaims(), null, true);
                }
            } catch (\Throwable $e) {
                log_error('LogoutRoute: failed to revoke persisted session - ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
                // never fail logout: cookies are still cleared below
            }
        }

        // 3. Get token storage (if configured)
        $tokenStorage = AuthRoutes::getTokenStorage();

        // 4. Revoke refresh token (if storage provided and token exists)
        if ($tokenStorage !== null && !empty($refreshToken)) {
            $tokenHash = hash('sha256', $refreshToken);

            try {
                $tokenStorage->revokeRefreshToken($tokenHash);
                log_debug('LogoutRoute: Refresh token revoked successfully');
            } catch (\Exception $e) {
                log_error('LogoutRoute: Failed to revoke refresh token - ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
                // Don't fail logout if revocation fails - still clear cookies
            }
        }

        // 5. Optionally revoke all user tokens (if user is authenticated)
        // This is useful for "logout from all devices" functionality
        $user = AuthContext::check() ? AuthContext::getUser() : null;
        if ($user !== null && $tokenStorage !== null) {
            $revokeAll = $_GET['revoke_all'] ?? $_POST['revoke_all'] ?? false;

            if (filter_var($revokeAll, FILTER_VALIDATE_BOOLEAN)) {
                try {
                    $tokenStorage->revokeAllUserTokens($user->user_id);
                    log_debug("LogoutRoute: All tokens revoked for user {$user->user_id}");
                } catch (\Exception $e) {
                    log_error('LogoutRoute: Failed to revoke all user tokens - ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
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
