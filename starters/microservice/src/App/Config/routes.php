<?php

/**
 * Route Configuration
 *
 * Map HTTP methods and URL patterns to route handlers
 */

use App\Routes\HealthRoute;
use App\Routes\ServiceInfoRoute;

return [
    'GET' => [
        '/health' => HealthRoute::class,
        '/info' => ServiceInfoRoute::class,
        // Add your GET routes here
    ],

    'POST' => [
        // Add your POST routes here
    ],

    'PUT' => [
        // Add your PUT routes here
    ],

    'DELETE' => [
        // Add your DELETE routes here
    ],
];
