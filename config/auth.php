<?php

return [
    // Legacy single-issuer config (for backward compatibility)
    'gateway_url' => env('GATEWAY_URL', 'http://192.168.122.173:9000'),
    'jwks_endpoint' => '/auth/jwks',
    'jwks_cache_ttl' => 3600,
    'platform_code' => env('PLATFORM_CODE', 'progalaxy'),

    // Multi-auth server configuration
    // If auth_servers is defined, it takes precedence over legacy config
    'auth_servers' => env('AUTH_SERVERS') ? json_decode(env('AUTH_SERVERS'), true) : null,

    // Example multi-auth server config (uncomment and customize as needed):
    /*
    'auth_servers' => [
        'customer' => [
            'issuer' => 'https://auth.progalaxyelabs.com',
            'jwks_url' => 'https://auth.progalaxyelabs.com/auth/jwks',
            'audience' => 'progalaxyelabs-api',
            'cache_ttl' => 3600,
        ],
        'employee' => [
            'issuer' => 'https://admin-auth.progalaxyelabs.com',
            'jwks_url' => 'https://admin-auth.progalaxyelabs.com/auth/jwks',
            'audience' => 'pel-admin-api',
            'cache_ttl' => 3600,
        ],
    ],
    */
];
