<?php

namespace App\Routes;

use App\Lib\JWTAuth;
use Framework\ApiResponse;
use Framework\IRouteHandler;

class RenewAccessTokenRoute implements IRouteHandler
{
    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        $jwtAuth = JWTAuth::getInstance();
        if ($user_id = $jwtAuth->userIdFromRefreshToken()) {
            if ($ok = $jwtAuth->createTokens($user_id)) {

                $jwtAuth->setRefreshTokenCookie();

                return new ApiResponse('ok', '', [
                    'access_token' => $jwtAuth->getAccessToken()
                ]);
            } else {
                return new ApiResponse('not ok', 'token creation failed', []);
            }
        }
        return res_not_authorized();
    }
}
