<?php

namespace StoneScriptPHP\Auth;

use StoneScriptPHP\Env;

/**
 * Cookie Helper
 *
 * Manages secure httpOnly cookies for refresh tokens.
 * Provides consistent cookie handling across auth routes.
 *
 * Security features:
 * - httpOnly (prevents JavaScript access)
 * - Secure flag (HTTPS only in production)
 * - SameSite=Strict (CSRF protection)
 * - Configurable domain and path
 *
 * Example usage:
 *   CookieHelper::setRefreshToken($token);
 *   $token = CookieHelper::getRefreshToken();
 *   CookieHelper::clearRefreshToken();
 */
class CookieHelper
{
    private const REFRESH_TOKEN_COOKIE = 'refresh_token';
    private const CSRF_TOKEN_COOKIE = 'csrf_token';

    /**
     * Set refresh token in httpOnly cookie
     *
     * @param string $token The refresh token to store
     * @param int|null $expirySeconds Cookie expiry in seconds (default: 180 days)
     * @return bool True on success, false on failure
     */
    public static function setRefreshToken(string $token, ?int $expirySeconds = null): bool
    {
        $env = Env::get_instance();

        if ($expirySeconds === null) {
            $expirySeconds = $env->JWT_REFRESH_TOKEN_EXPIRY ?? 15552000; // 180 days
        }

        $options = [
            'expires' => time() + $expirySeconds,
            'path' => '/auth',
            'domain' => $env->AUTH_COOKIE_DOMAIN ?? '',
            'secure' => self::isSecure(),
            'httponly' => true,
            'samesite' => 'Strict'
        ];

        return setcookie(self::REFRESH_TOKEN_COOKIE, $token, $options);
    }

    /**
     * Get refresh token from httpOnly cookie
     *
     * @return string|null The refresh token, or null if not found
     */
    public static function getRefreshToken(): ?string
    {
        return $_COOKIE[self::REFRESH_TOKEN_COOKIE] ?? null;
    }

    /**
     * Clear refresh token cookie
     *
     * @return bool True on success, false on failure
     */
    public static function clearRefreshToken(): bool
    {
        $env = Env::get_instance();

        $options = [
            'expires' => time() - 3600, // Expire 1 hour ago
            'path' => '/auth',
            'domain' => $env->AUTH_COOKIE_DOMAIN ?? '',
            'secure' => self::isSecure(),
            'httponly' => true,
            'samesite' => 'Strict'
        ];

        // Also unset from $_COOKIE
        unset($_COOKIE[self::REFRESH_TOKEN_COOKIE]);

        return setcookie(self::REFRESH_TOKEN_COOKIE, '', $options);
    }

    /**
     * Set CSRF token in cookie (readable by JavaScript)
     *
     * This cookie is NOT httpOnly so JavaScript can read it
     * and include it in request headers.
     *
     * @param string $token The CSRF token to store
     * @param int|null $expirySeconds Cookie expiry in seconds (default: 180 days)
     * @return bool True on success, false on failure
     */
    public static function setCsrfToken(string $token, ?int $expirySeconds = null): bool
    {
        $env = Env::get_instance();

        if ($expirySeconds === null) {
            $expirySeconds = $env->JWT_REFRESH_TOKEN_EXPIRY ?? 15552000; // 180 days
        }

        $options = [
            'expires' => time() + $expirySeconds,
            'path' => '/',
            'domain' => $env->AUTH_COOKIE_DOMAIN ?? '',
            'secure' => self::isSecure(),
            'httponly' => false, // JavaScript needs to read this
            'samesite' => 'Strict'
        ];

        return setcookie(self::CSRF_TOKEN_COOKIE, $token, $options);
    }

    /**
     * Get CSRF token from cookie
     *
     * @return string|null The CSRF token, or null if not found
     */
    public static function getCsrfToken(): ?string
    {
        return $_COOKIE[self::CSRF_TOKEN_COOKIE] ?? null;
    }

    /**
     * Clear CSRF token cookie
     *
     * @return bool True on success, false on failure
     */
    public static function clearCsrfToken(): bool
    {
        $env = Env::get_instance();

        $options = [
            'expires' => time() - 3600, // Expire 1 hour ago
            'path' => '/',
            'domain' => $env->AUTH_COOKIE_DOMAIN ?? '',
            'secure' => self::isSecure(),
            'httponly' => false,
            'samesite' => 'Strict'
        ];

        // Also unset from $_COOKIE
        unset($_COOKIE[self::CSRF_TOKEN_COOKIE]);

        return setcookie(self::CSRF_TOKEN_COOKIE, '', $options);
    }

    /**
     * Determine if secure flag should be set
     *
     * @return bool True if HTTPS or secure flag is enabled
     */
    private static function isSecure(): bool
    {
        $env = Env::get_instance();

        // Check environment variable
        $configSecure = $env->AUTH_COOKIE_SECURE ?? null;
        if ($configSecure !== null) {
            return filter_var($configSecure, FILTER_VALIDATE_BOOLEAN);
        }

        // Auto-detect HTTPS
        return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }
}
