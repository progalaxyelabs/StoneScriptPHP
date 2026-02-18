<?php

return [
    // Legacy single-issuer config (for backward compatibility)
    'gateway_url' => env('GATEWAY_URL', 'http://localhost:9000'),
    'jwks_endpoint' => '/auth/jwks',
    'jwks_cache_ttl' => 3600,
    'platform_code' => env('PLATFORM_CODE', 'myapp'),

    // Multi-auth server configuration
    // If auth_servers is defined, it takes precedence over legacy config
    'auth_servers' => env('AUTH_SERVERS') ? json_decode(env('AUTH_SERVERS'), true) : null,

    // Example multi-auth server config (uncomment and customize):
    /*
    'auth_servers' => [
        'customer' => [
            'issuer' => 'https://auth.example.com',
            'jwks_url' => 'https://auth.example.com/auth/jwks',
            'audience' => 'my-api',
            'cache_ttl' => 3600,
        ],
        'employee' => [
            'issuer' => 'https://admin-auth.example.com',
            'jwks_url' => 'https://admin-auth.example.com/auth/jwks',
            'audience' => 'my-admin-api',
            'cache_ttl' => 3600,
        ],
    ],
    */
];
