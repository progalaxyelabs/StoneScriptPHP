<?php

namespace App\Routes;

use App\Database\Functions\DbFnGoogleOauth;
use App\Env;
use App\Lib\JWTAuth;
use Framework\ApiResponse;
use Framework\IRouteHandler;

class OauthGoogleVerifyRoute implements IRouteHandler
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
            if (!$payload) {
                // Invalid Token (verification failed)
                http_response_code(401); // Unauthorized
                log_error("Google Sign-In Error: Invalid ID token received.");
                return new ApiResponse('not ok', 'Invalid ID token', []);
            }

            // Token is valid!
            $google_id = $payload['sub']; // Google's unique user ID - BEST for identifying users
            $email = isset($payload['email']) ? $payload['email'] : null;
            $email_verified = isset($payload['email_verified']) ? $payload['email_verified'] : false;
            $name = isset($payload['name']) ? $payload['name'] : null;
            $picture = isset($payload['picture']) ? $payload['picture'] : null;


            /** @var GoogleOauthModel $user */
            $user = DbFnGoogleOauth::run($google_id, $email, $name, $picture)[0];
            //      $_SESSION['logged_in_time'] = time();

            list($access_token, $refresh_token) =  JWTAuth::create_tokens($user->user_id);
            
            $cookie_options = [
                'expires' => time() + 60*60*24*30, 
                'path' => '/oauth/google/refresh/', 
                'domain' => Env::$OAUTH_APP_DOMAIN, // leading dot for compatibility or use subdomain
                'secure' => true,     // or false
                'httponly' => true,    // or false
            ];
            setcookie('refresh-token', $refresh_token, $cookie_options);
            return new ApiResponse('ok', '', [
                'user' => [ 
                    'id' => $user->user_id,
                    'name' => $name,
                    'picture' => $picture,
                ],
                'access_token' => $access_token
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            log_error("Google Sign-In Exception: " . $e->getMessage());
            return new ApiResponse('not ok', 'Token verification failed', []);
        }
    }
}
