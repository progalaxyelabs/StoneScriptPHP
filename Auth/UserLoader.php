<?php

namespace Framework\Auth;

use PDO;

/**
 * User Loader
 *
 * Loads full user data from database based on JWT claims.
 * Allows developers to use their own database connection and query logic.
 *
 * Example usage:
 *   // Define your user loader function
 *   $getUserFromDb = function(AuthenticatedUser $user, PDO $db) {
 *       $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
 *       $stmt->execute([$user->user_id]);
 *       return $stmt->fetch(PDO::FETCH_ASSOC);
 *   };
 *
 *   // Load user data
 *   $dbUser = UserLoader::load(auth(), $db, $getUserFromDb);
 */
class UserLoader
{
    /**
     * Load user data from database
     *
     * @param AuthenticatedUser $user The authenticated user from JWT
     * @param PDO $db Database connection (can be from pool or single connection)
     * @param callable $loaderFn Function to load user from DB: fn(AuthenticatedUser, PDO): array|object|null
     * @return array|object|null User data from database
     */
    public static function load(
        AuthenticatedUser $user,
        PDO $db,
        callable $loaderFn
    ): array|object|null {
        try {
            return $loaderFn($user, $db);
        } catch (\Exception $e) {
            log_error("UserLoader: Failed to load user - {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Load user using a database function class
     *
     * Example:
     *   $dbUser = UserLoader::loadWithFunction(auth(), $db, FnGetUserById::class);
     *
     * @param AuthenticatedUser $user The authenticated user from JWT
     * @param PDO $db Database connection
     * @param string $functionClass Database function class name (e.g., FnGetUserById::class)
     * @param string $method Method name to call (default: 'run')
     * @return mixed User data from database function
     */
    public static function loadWithFunction(
        AuthenticatedUser $user,
        PDO $db,
        string $functionClass,
        string $method = 'run'
    ): mixed {
        try {
            if (!class_exists($functionClass)) {
                log_error("UserLoader: Function class $functionClass does not exist");
                return null;
            }

            if (!method_exists($functionClass, $method)) {
                log_error("UserLoader: Method $method does not exist on $functionClass");
                return null;
            }

            // Call the database function with user_id
            return $functionClass::$method($user->user_id);

        } catch (\Exception $e) {
            log_error("UserLoader: Failed to load user with function - {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Load user and merge with JWT claims
     *
     * Returns an array combining JWT claims with database data.
     * Database data takes precedence over JWT claims.
     *
     * @param AuthenticatedUser $user The authenticated user from JWT
     * @param PDO $db Database connection
     * @param callable $loaderFn Function to load user from DB
     * @return array Merged user data
     */
    public static function loadAndMerge(
        AuthenticatedUser $user,
        PDO $db,
        callable $loaderFn
    ): array {
        $jwtData = $user->toArray();
        $dbData = self::load($user, $db, $loaderFn);

        if ($dbData === null) {
            return $jwtData;
        }

        // Convert object to array if needed
        if (is_object($dbData)) {
            $dbData = (array) $dbData;
        }

        // Merge: database data overrides JWT data
        return array_merge($jwtData, $dbData);
    }
}
