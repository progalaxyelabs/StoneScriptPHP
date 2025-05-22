<?php

namespace App\Database\Functions;

use Framework\Database;

class GoogleOauthModel
{
    public string $user_id;
    public string $display_name;
    public string $photo_url;
    public string $profile_url;
    public string $created_at;
    public string $updated_at;
}

class DbFnGoogleOauth
{
    /**
     * @return GoogleOauthModel[]
     */
    public static function run(string $p_google_id, string $p_email, string $p_display_name, string $p_photo_url): array
    {
        $function_name = 'google_oauth';
        $rows = Database::fn($function_name, [$p_google_id, $p_email, $p_display_name, $p_photo_url]);
        return Database::result_as_table($function_name, $rows, GoogleOauthModel::class);
    }
}

// function db_fn_create_project(string $p_google_id, string $p_email, string $p_display_name, string $p_photo_url): array
// {
//     $function_name = 'google_oauth';
//     $rows = Database::fn($function_name, [$p_google_id, $p_email, $p_display_name, $p_photo_url]);
//     return Database::result_as_table($function_name, $rows, GoogleOauthModel::class);
// }