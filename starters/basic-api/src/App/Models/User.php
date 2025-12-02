<?php

namespace App\Models;

/**
 * User Model
 *
 * Example model class generated from create_user.pgsql function
 * In a real project, use: php stone generate model create_user.pgsql
 */
class User
{
    /**
     * Call the create_user database function
     *
     * @param string $email User email
     * @param string $name User name
     * @param int $age User age
     * @return array User data
     */
    public static function create(string $email, string $name, int $age): array
    {
        global $db;

        $stmt = $db->prepare('SELECT * FROM create_user($1, $2, $3)');
        $stmt->execute([$email, $name, $age]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Call the get_user database function
     *
     * @param int $userId User ID
     * @return array|null User data or null if not found
     */
    public static function get(int $userId): ?array
    {
        global $db;

        $stmt = $db->prepare('SELECT * FROM get_user($1)');
        $stmt->execute([$userId]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }
}
