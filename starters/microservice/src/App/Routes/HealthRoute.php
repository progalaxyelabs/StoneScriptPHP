<?php

namespace App\Routes;

use Framework\Routing\BaseRoute;
use Framework\Http\ApiResponse;

/**
 * Health Check Route
 *
 * Used by load balancers, orchestrators, and monitoring tools
 * to check if the service is healthy and responsive.
 *
 * Usage:
 *   GET /health
 */
class HealthRoute extends BaseRoute
{
    public function process(): ApiResponse
    {
        // Check database connectivity
        $dbStatus = $this->checkDatabase();

        $health = [
            'status' => $dbStatus ? 'ok' : 'degraded',
            'service' => env('SERVICE_NAME', 'microservice'),
            'version' => env('SERVICE_VERSION', '1.0.0'),
            'timestamp' => date('c'),
            'checks' => [
                'database' => $dbStatus ? 'connected' : 'disconnected',
            ]
        ];

        $statusCode = $dbStatus ? 200 : 503;

        return new ApiResponse(
            $health['status'],
            'Service health check',
            $health,
            $statusCode
        );
    }

    /**
     * Check database connectivity
     */
    private function checkDatabase(): bool
    {
        try {
            global $db;
            $stmt = $db->query('SELECT 1');
            return $stmt !== false;
        } catch (\Exception $e) {
            return false;
        }
    }
}
