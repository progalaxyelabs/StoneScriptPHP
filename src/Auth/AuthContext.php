<?php

namespace StoneScriptPHP\Auth;

/**
 * Authentication Context
 *
 * Stores the currently authenticated user for the current request.
 * This is a singleton that holds user information after JWT validation.
 *
 * Usage:
 *   AuthContext::setUser($user);
 *   $user = AuthContext::getUser();
 *   $userId = AuthContext::id();
 */
class AuthContext
{
    private static ?AuthenticatedUser $user = null;

    /**
     * Set the authenticated user
     *
     * @param AuthenticatedUser $user
     */
    public static function setUser(AuthenticatedUser $user): void
    {
        self::$user = $user;
    }

    /**
     * Get the authenticated user
     *
     * @return AuthenticatedUser|null
     */
    public static function getUser(): ?AuthenticatedUser
    {
        return self::$user;
    }

    /**
     * Get the authenticated user ID
     *
     * @return int|null
     */
    public static function id(): ?int
    {
        return self::$user?->user_id;
    }

    /**
     * Check if user is authenticated
     *
     * @return bool
     */
    public static function check(): bool
    {
        return self::$user !== null;
    }

    /**
     * Check if user is a guest (not authenticated)
     *
     * @return bool
     */
    public static function guest(): bool
    {
        return self::$user === null;
    }

    /**
     * Clear the authenticated user (for testing)
     */
    public static function clear(): void
    {
        self::$user = null;
    }
}
