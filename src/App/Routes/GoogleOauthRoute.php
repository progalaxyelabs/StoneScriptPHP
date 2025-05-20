<?php

namespace App\Routes;

use Framework\ApiResponse;
use Framework\Database;
use Framework\IRouteHandler;

require CONFIG_PATH . 'google-oauth.php';

class GoogleOauthRoute implements IRouteHandler
{

    public string $credential = '';

    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        $google_client = new \Google\Client(['client_id' => GOOGLE_CLIENT_ID]);
        try {
            $payload = $google_client->verifyIdToken($this->credential);
            if ($payload) {
                // Token is valid!
                $google_id = $payload['sub']; // Google's unique user ID - BEST for identifying users
                $email = isset($payload['email']) ? $payload['email'] : null;
                $email_verified = isset($payload['email_verified']) ? $payload['email_verified'] : false;
                $name = isset($payload['name']) ? $payload['name'] : null;
                $picture = isset($payload['picture']) ? $payload['picture'] : null;

                // $db = Database::get_insta

                // --- IMPORTANT: Backend Authentication/Registration Logic ---
                // 1. Check if user exists in your database using $google_id (preferred) or $email
                //    $user = findUserByGoogleId($google_id); // Your DB function
                //
                // 2. If user exists:
                //    - Update name/picture if necessary.
                //    - Create a secure backend session for this user.
                //      if (session_status() == PHP_SESSION_NONE) { session_start(); } // Start session *here*
                //      $_SESSION['user_id'] = $user['id']; // Your internal user ID
                //      $_SESSION['user_email'] = $email;
                //      $_SESSION['user_name'] = $name;
                //      $_SESSION['logged_in_time'] = time();
                //
                // 3. If user doesn't exist:
                //    - Optionally check $email_verified if you require verified emails.
                //    - Register the new user in your database.
                //      $newUserId = createUser($google_id, $email, $name, $picture); // Your DB function
                //    - Create a secure backend session for the new user.
                //      if (session_status() == PHP_SESSION_NONE) { session_start(); } // Start session *here*
                //      $_SESSION['user_id'] = $newUserId;
                //      $_SESSION['user_email'] = $email;
                //      $_SESSION['user_name'] = $name;
                //      $_SESSION['logged_in_time'] = time();
                // --- End of Backend Authentication/Registration Logic ---

                // Send success response back to Angular
                http_response_code(200); // OK
                
                // You might include some user data, but avoid sending sensitive info
                // The session cookie implicitly tells the browser the user is logged in
                return new ApiResponse('ok', '', [
                    'user' => [ // Optional: send non-sensitive info back
                        'name' => $name,
                        'email' => $email, // Be mindful if email is sensitive in your context
                        'picture' => $picture
                    ]
                ]);
            } else {                
                // Invalid Token (verification failed)
                http_response_code(401); // Unauthorized
                log_error("Google Sign-In Error: Invalid ID token received.");
                return new ApiResponse('not ok', 'Invalid ID token', []);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            log_error("Google Sign-In Exception: " . $e->getMessage());
            return new ApiResponse('not ok', 'Token verification failed', []);
        }        
    }
}
