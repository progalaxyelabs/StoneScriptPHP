<?php

namespace Tests\Fixtures;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;
use Exception;

class ExceptionThrowingRoute implements IRouteHandler
{
    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        throw new Exception("Test exception from route handler");
    }
}
