<?php

namespace Tests\Fixtures;

use Framework\IRouteHandler;
use Framework\ApiResponse;

/**
 * Test route handler that accepts an {id} parameter from URL
 */
class UserByIdRoute implements IRouteHandler
{
    public $id;  // Will be populated from /user/{id}

    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        return res_ok(['userId' => $this->id], "User $this->id retrieved");
    }
}
