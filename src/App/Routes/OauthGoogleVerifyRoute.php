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
                return res_not_authorized('Invalid ID token');
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

            $jwtAuth = JWTAuth::getInstance();

            if ($ok = $jwtAuth->createTokens($user->user_id)) {

                $jwtAuth->setRefreshTokenCookie();

                return new ApiResponse('ok', '', [
                    'user' => [
                        'id' => $user->user_id,
                        'name' => $name,
                        'picture' => $picture,
                    ],
                    'access_token' => $jwtAuth->getAccessToken()
                ]);
            } else {
                return new ApiResponse('not ok', 'token creation failed', []);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            log_error("Google Sign-In Exception: " . $e->getMessage());
            return new ApiResponse('not ok', 'Token verification failed', []);
        }
    }
}
