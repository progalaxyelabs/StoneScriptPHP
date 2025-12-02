<?php

/**
 * Service Registry
 *
 * Configuration for other microservices that this service needs to communicate with
 */

return [
    'user-service' => [
        'url' => env('USER_SERVICE_URL', 'http://user-service:9100'),
        'timeout' => 5000,
        'description' => 'User management service',
    ],

    'product-service' => [
        'url' => env('PRODUCT_SERVICE_URL', 'http://product-service:9101'),
        'timeout' => 5000,
        'description' => 'Product catalog service',
    ],

    'order-service' => [
        'url' => env('ORDER_SERVICE_URL', 'http://order-service:9102'),
        'timeout' => 5000,
        'description' => 'Order processing service',
    ],

    // Add more services as needed
];
