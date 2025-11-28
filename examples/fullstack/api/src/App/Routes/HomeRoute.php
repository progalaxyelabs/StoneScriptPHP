<?php

namespace App\Routes;

use Framework\ApiResponse;
use Framework\Database;
use Framework\IRouteHandler;

class HomeRoute implements IRouteHandler
{
    function validation_rules(): array
    {
        return [];
    }

    function process(): ApiResponse
    {        
        return res_ok([], 'visit home page' );
    }
}
