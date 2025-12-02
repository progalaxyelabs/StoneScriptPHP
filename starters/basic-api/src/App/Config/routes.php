<?php

/**
 * Route Configuration
 *
 * Map HTTP methods and URL patterns to route handlers
 */

use App\Routes\HelloRoute;
use App\Routes\UsersRoute;

return [
    'GET' => [
        '/hello' => HelloRoute::class,
        // Add your GET routes here
        // '/products' => ProductsRoute::class,
    ],

    'POST' => [
        '/users' => UsersRoute::class,
        // Add your POST routes here
        // '/products' => CreateProductRoute::class,
    ],

    'PUT' => [
        // Add your PUT routes here
        // '/products/{id}' => UpdateProductRoute::class,
    ],

    'DELETE' => [
        // Add your DELETE routes here
        // '/products/{id}' => DeleteProductRoute::class,
    ],

    'PATCH' => [
        // Add your PATCH routes here
    ],
];
