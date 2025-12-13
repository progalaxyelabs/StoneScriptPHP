<?php

namespace Framework\Routing;

use Framework\ApiResponse;

interface MiddlewareInterface
{
    /**
     * Process an incoming request and return a response or pass to next middleware
     *
     * @param array $request The request data (input, headers, route params, etc.)
     * @param callable $next The next middleware in the pipeline
     * @return ApiResponse|null Return ApiResponse to short-circuit, null to continue
     */
    public function handle(array $request, callable $next): ?ApiResponse;
}
