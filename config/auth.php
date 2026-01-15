<?php

return [
    'gateway_url' => env('GATEWAY_URL', 'http://192.168.122.173:9000'),
    'jwks_endpoint' => '/auth/jwks',
    'jwks_cache_ttl' => 3600,
    'platform_code' => env('PLATFORM_CODE', 'progalaxy'),
];
