<?php

namespace App\Routes;

use Framework\ApiResponse;
use Framework\IRouteHandler;

class OauthGoogleRefreshRoute implements IRouteHandler
{
    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        // return new ApiResponse('ok', '', []);
        throw new \Exception('Not Implemented');
    }

}
