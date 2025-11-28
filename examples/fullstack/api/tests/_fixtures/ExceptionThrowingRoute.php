<?php

namespace Tests\Fixtures;

use Framework\IRouteHandler;
use Framework\ApiResponse;
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
