<?php

namespace App\Routes;

use Framework\ApiResponse;
use Framework\Database;
use Framework\IRouteHandler;

class HomeRouter implements IRouteHandler
{
    function validation_rules(): array
    {
        return [];
    }

    function process(): ApiResponse
    {        
        return res_ok([], 'visit https://www.online-exams.in');
    }
}
