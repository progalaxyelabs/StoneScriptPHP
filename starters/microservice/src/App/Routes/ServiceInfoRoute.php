<?php

namespace App\Routes;

use Framework\Routing\BaseRoute;
use Framework\Http\ApiResponse;

/**
 * Service Information Route
 *
 * Returns metadata about this microservice
 *
 * Usage:
 *   GET /info
 */
class ServiceInfoRoute extends BaseRoute
{
    public function process(): ApiResponse
    {
        return new ApiResponse('ok', 'Service information', [
            'service' => env('SERVICE_NAME', 'microservice'),
            'version' => env('SERVICE_VERSION', '1.0.0'),
            'environment' => env('APP_ENV', 'development'),
            'uptime' => $this->getUptime(),
            'endpoints' => $this->getEndpoints(),
            'dependencies' => $this->getDependencies(),
        ]);
    }

    private function getUptime(): string
    {
        $uptime = time() - $_SERVER['REQUEST_TIME_FLOAT'];
        return gmdate('H:i:s', (int)$uptime);
    }

    private function getEndpoints(): array
    {
        return [
            'GET /health' => 'Health check endpoint',
            'GET /info' => 'Service information',
            // Add your custom endpoints here
        ];
    }

    private function getDependencies(): array
    {
        return [
            'database' => 'PostgreSQL',
            'php' => PHP_VERSION,
            'framework' => 'StoneScriptPHP',
        ];
    }
}
