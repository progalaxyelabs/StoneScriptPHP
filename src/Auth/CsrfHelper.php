<?php

namespace StoneScriptPHP\Auth;

/**
 * CSRF Helper
 *
 * Generates and validates CSRF tokens for cookie-based authentication.
 * Prevents Cross-Site Request Forgery attacks on auth endpoints.
 *
 * Security features:
 * - Cryptographically secure random tokens
 * - Session-based or stateless validation
 * - Configurable token length
 *
 * Example usage:
 *   // Generate and store
 *   $token = CsrfHelper::generate();
 *   CookieHelper::setCsrfToken($token);
 *
 *   // Validate from request header
 *   $requestToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
 *   if (!CsrfHelper::validate($requestToken)) {
 *       // Invalid CSRF token
 *   }
 */
class CsrfHelper
{
    private const TOKEN_LENGTH = 32;
    private const SESSION_KEY = 'csrf_token';

    /**
     * Generate a new CSRF token
     *
     * @return string The generated CSRF token
     * @throws \RuntimeException if random_bytes fails
     */
    public static function generate(): string
    {
        try {
            $token = bin2hex(random_bytes(self::TOKEN_LENGTH));

            // Store in session if available
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION[self::SESSION_KEY] = $token;
            }

            return $token;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to generate CSRF token: ' . $e->getMessage());
        }
    }

    /**
     * Validate a CSRF token
     *
     * Supports two validation modes:
     * 1. Session-based: Compare against token stored in session
     * 2. Cookie-based: Compare against token stored in cookie
     *
     * @param string $token The token to validate
     * @param bool $useSession Use session-based validation (default: false, uses cookie)
     * @return bool True if valid, false otherwise
     */
    public static function validate(string $token, bool $useSession = false): bool
    {
        if (empty($token)) {
            return false;
        }

        if ($useSession) {
            return self::validateWithSession($token);
        }

        return self::validateWithCookie($token);
    }

    /**
     * Validate CSRF token against session
     *
     * @param string $token The token to validate
     * @return bool True if valid, false otherwise
     */
    private static function validateWithSession(string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            log_error('CSRF: Session not active for validation');
            return false;
        }

        $storedToken = $_SESSION[self::SESSION_KEY] ?? null;

        if (empty($storedToken)) {
            log_error('CSRF: No token found in session');
            return false;
        }

        // Timing-safe comparison
        return hash_equals($storedToken, $token);
    }

    /**
     * Validate CSRF token against cookie
     *
     * @param string $token The token to validate
     * @return bool True if valid, false otherwise
     */
    private static function validateWithCookie(string $token): bool
    {
        $storedToken = CookieHelper::getCsrfToken();

        if (empty($storedToken)) {
            log_error('CSRF: No token found in cookie');
            return false;
        }

        // Timing-safe comparison
        return hash_equals($storedToken, $token);
    }

    /**
     * Get CSRF token from request headers
     *
     * Checks common CSRF header names:
     * - X-CSRF-Token
     * - X-XSRF-Token
     *
     * @return string|null The CSRF token from headers, or null if not found
     */
    public static function getTokenFromRequest(): ?string
    {
        // Check X-CSRF-Token header
        if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            return $_SERVER['HTTP_X_CSRF_TOKEN'];
        }

        // Check X-XSRF-Token header (alternative common name)
        if (isset($_SERVER['HTTP_X_XSRF_TOKEN'])) {
            return $_SERVER['HTTP_X_XSRF_TOKEN'];
        }

        // Try apache_request_headers if available
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();

            if (isset($headers['X-CSRF-Token'])) {
                return $headers['X-CSRF-Token'];
            }

            if (isset($headers['X-XSRF-Token'])) {
                return $headers['X-XSRF-Token'];
            }
        }

        return null;
    }

    /**
     * Validate CSRF token from request
     *
     * Convenience method that extracts token from request headers
     * and validates it.
     *
     * @param bool $useSession Use session-based validation (default: false)
     * @return bool True if valid, false otherwise
     */
    public static function validateRequest(bool $useSession = false): bool
    {
        $token = self::getTokenFromRequest();

        if ($token === null) {
            log_error('CSRF: No token found in request headers');
            return false;
        }

        return self::validate($token, $useSession);
    }

    /**
     * Clear CSRF token from session
     */
    public static function clear(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::SESSION_KEY]);
        }
    }
}
