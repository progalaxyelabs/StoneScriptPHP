<?php

namespace App\Routes;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\RsaJwtHandler;
use StoneScriptPHP\Auth\CookieHelper;
use StoneScriptPHP\Auth\CsrfHelper;
use StoneScriptPHP\Auth\AuthRoutes;

/**
 * Login Route Example
 *
 * This is an example implementation of a login route that works with
 * StoneScriptPHP's built-in auth routes (refresh, logout).
 *
 * Features:
 * - Email + password authentication
 * - Access token in response body (store in memory, NOT localStorage)
 * - Refresh token in httpOnly cookie
 * - CSRF token in cookie (for refresh/logout requests)
 * - Optional token storage for blacklisting
 *
 * Usage:
 * 1. Copy this file to src/App/Routes/LoginRoute.php
 * 2. Customize the user validation logic for your database
 * 3. Register in your routes config:
 *    $router->post('/auth/login', LoginRoute::class);
 *
 * Request:
 *   POST /auth/login
 *   {
 *     "email": "user@example.com",
 *     "password": "secret123"
 *   }
 *
 * Response:
 *   {
 *     "status": "ok",
 *     "data": {
 *       "access_token": "eyJ...",
 *       "expires_in": 900,
 *       "token_type": "Bearer",
 *       "user": {
 *         "id": 123,
 *         "email": "user@example.com",
 *         "name": "John Doe"
 *       }
 *     }
 *   }
 *
 * Cookies Set:
 *   - refresh_token (httpOnly, secure, sameSite=Strict)
 *   - csrf_token (readable by JS for request headers)
 */
class LoginRoute implements IRouteHandler
{
    // Request body parameters
    public string $email;
    public string $password;

    private RsaJwtHandler $jwtHandler;

    public function __construct()
    {
        $this->jwtHandler = new RsaJwtHandler();
    }

    public function validation_rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'min:8', 'max:255']
        ];
    }

    public function process(): ApiResponse
    {
        // 1. Validate user credentials (customize this for your database)
        $user = $this->validateCredentials($this->email, $this->password);

        if (!$user) {
            log_error("LoginRoute: Invalid credentials for email: {$this->email}");
            http_response_code(401);
            return new ApiResponse('error', 'Invalid email or password');
        }

        // 2. Get expiry times from environment
        $env = \StoneScriptPHP\Env::get_instance();
        $accessTokenExpiry = $env->JWT_ACCESS_TOKEN_EXPIRY ?? 900; // 15 minutes
        $refreshTokenExpiry = $env->JWT_REFRESH_TOKEN_EXPIRY ?? 15552000; // 180 days

        // 3. Generate access token
        $accessTokenPayload = [
            'user_id' => $user['id'],
            'sub' => $user['id'], // Standard JWT claim for subject
            'email' => $user['email'],
            'role' => $user['role'] ?? 'user',
            // Add any other claims your app needs
        ];

        $accessToken = $this->jwtHandler->generateToken($accessTokenPayload, $accessTokenExpiry, 'access');

        // 4. Generate refresh token
        $refreshTokenPayload = [
            'user_id' => $user['id'],
            'sub' => $user['id'],
            'type' => 'refresh' // Important: mark as refresh token
        ];

        $refreshToken = $this->jwtHandler->generateToken($refreshTokenPayload, $refreshTokenExpiry, 'refresh');

        // 5. Store refresh token (if token storage is configured)
        $tokenStorage = AuthRoutes::getTokenStorage();
        if ($tokenStorage !== null) {
            $tokenHash = hash('sha256', $refreshToken);

            $metadata = [
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ];

            try {
                $tokenStorage->storeRefreshToken(
                    $tokenHash,
                    $user['id'],
                    time() + $refreshTokenExpiry,
                    $metadata
                );
                log_debug("LoginRoute: Stored refresh token for user {$user['id']}");
            } catch (\Exception $e) {
                log_error("LoginRoute: Failed to store refresh token - {$e->getMessage()}");
                // Don't fail login if storage fails - tokens still work stateless
            }
        }

        // 6. Set refresh token in httpOnly cookie
        CookieHelper::setRefreshToken($refreshToken, $refreshTokenExpiry);

        // 7. Generate and set CSRF token
        $csrfToken = CsrfHelper::generate();
        CookieHelper::setCsrfToken($csrfToken, $refreshTokenExpiry);

        log_info("LoginRoute: User {$user['id']} logged in successfully");

        // 8. Return access token and user data
        return new ApiResponse('ok', [
            'access_token' => $accessToken,
            'expires_in' => $accessTokenExpiry,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name'] ?? null,
                'role' => $user['role'] ?? 'user'
            ]
        ]);
    }

    /**
     * Validate user credentials
     *
     * CUSTOMIZE THIS METHOD for your database and user model.
     *
     * Examples:
     *
     * Example 1: Direct database query
     * ```php
     * private function validateCredentials(string $email, string $password): ?array
     * {
     *     $db = db(); // Your database connection
     *
     *     $stmt = $db->prepare("SELECT id, email, password_hash, name, role FROM users WHERE email = ?");
     *     $stmt->execute([$email]);
     *     $user = $stmt->fetch(\PDO::FETCH_ASSOC);
     *
     *     if (!$user || !password_verify($password, $user['password_hash'])) {
     *         return null;
     *     }
     *
     *     return $user;
     * }
     * ```
     *
     * Example 2: Using a database function
     * ```php
     * private function validateCredentials(string $email, string $password): ?array
     * {
     *     $user = FnGetUserByEmail::run($email);
     *
     *     if (!$user || !password_verify($password, $user['password_hash'])) {
     *         return null;
     *     }
     *
     *     return $user;
     * }
     * ```
     *
     * Example 3: With additional checks (email verification, account status)
     * ```php
     * private function validateCredentials(string $email, string $password): ?array
     * {
     *     $user = FnGetUserByEmail::run($email);
     *
     *     if (!$user) {
     *         return null;
     *     }
     *
     *     // Check password
     *     if (!password_verify($password, $user['password_hash'])) {
     *         return null;
     *     }
     *
     *     // Check email verified
     *     if (!$user['email_verified']) {
     *         http_response_code(403);
     *         throw new \Exception('Please verify your email before logging in');
     *     }
     *
     *     // Check account active
     *     if ($user['status'] !== 'active') {
     *         http_response_code(403);
     *         throw new \Exception('Your account has been suspended');
     *     }
     *
     *     return $user;
     * }
     * ```
     *
     * @param string $email User's email
     * @param string $password User's plain text password
     * @return array|null User data array on success, null on failure
     */
    private function validateCredentials(string $email, string $password): ?array
    {
        // TODO: Replace this with your actual database query
        //
        // This is a placeholder example that checks against a hardcoded user.
        // In production, you should query your database.

        // Example hardcoded user (for demonstration only)
        $exampleUser = [
            'id' => 1,
            'email' => 'demo@example.com',
            'password_hash' => password_hash('password123', PASSWORD_DEFAULT),
            'name' => 'Demo User',
            'role' => 'admin'
        ];

        if ($email !== $exampleUser['email']) {
            return null;
        }

        if (!password_verify($password, $exampleUser['password_hash'])) {
            return null;
        }

        return $exampleUser;
    }
}
