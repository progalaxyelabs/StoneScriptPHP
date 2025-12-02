<?php

namespace App\Routes;

use Framework\Routing\BaseRoute;
use Framework\Http\ApiResponse;

/**
 * Sample GET route - Hello World
 *
 * Usage:
 *   GET http://localhost:9100/hello
 */
class HelloRoute extends BaseRoute
{
    /**
     * Process the request
     */
    public function process(): ApiResponse
    {
        return new ApiResponse('ok', 'Hello from StoneScriptPHP!', [
            'timestamp' => date('c'),
            'version' => '1.0.0',
            'framework' => 'StoneScriptPHP'
        ]);
    }
}
