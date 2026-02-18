# Multi-Auth Server Support

StoneScriptPHP supports validating JWT tokens from multiple authentication servers, enabling shared platform APIs to accept tokens from different issuers with distinct authorization rules.

## Use Case

Shared platform APIs that need to accept tokens from:
- **Customer auth server** - Standard customer access
- **Employee auth server** - Elevated admin/support access

Different authorization rules can be applied based on the token issuer.

## Configuration

### Option 1: config/auth.php (Recommended)

Edit `config/auth.php`:

```php
<?php

return [
    // Multi-auth server configuration
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
];
```

### Option 2: Environment Variable

Set `AUTH_SERVERS` in `.env`:

```bash
AUTH_SERVERS='{"customer":{"issuer":"https://auth.example.com","jwks_url":"https://auth.example.com/auth/jwks","audience":"api","cache_ttl":3600},"employee":{"issuer":"https://admin-auth.example.com","jwks_url":"https://admin-auth.example.com/auth/jwks","audience":"admin-api","cache_ttl":3600}}'
```

## How It Works

1. **Token arrives** with `Authorization: Bearer <jwt>`
2. **Issuer detection** - JWT's `iss` claim is decoded to identify the issuer
3. **JWKS fetching** - Appropriate JWKS endpoint is selected based on issuer
4. **Validation** - Token is validated against the correct public keys
5. **Issuer type injection** - `issuer_type` field is added to JWT claims for authorization

## Usage

### Basic Multi-Auth Validation

```php
use StoneScriptPHP\Auth\AuthService;

$authService = new AuthService(null, [
    'auth_servers' => [
        'customer' => [
            'issuer' => 'https://auth.example.com',
            'jwks_url' => 'https://auth.example.com/auth/jwks',
            'audience' => 'api',
        ],
        'employee' => [
            'issuer' => 'https://admin-auth.example.com',
            'jwks_url' => 'https://admin-auth.example.com/auth/jwks',
            'audience' => 'admin-api',
        ],
    ],
]);

$user = $authService->getCurrentUser();
// Returns:
// [
//     'id' => '123',
//     'email' => 'user@example.com',
//     'issuer_type' => 'customer' // or 'employee'
// ]
```

### Issuer-Based Authorization with Middleware

Use `RequireIssuerMiddleware` to restrict endpoints by issuer:

```php
use StoneScriptPHP\Auth\Middleware\ValidateJwtMiddleware;
use StoneScriptPHP\Auth\Middleware\RequireIssuerMiddleware;

// Employee-only endpoint (admin panel)
$router->get('/admin/users', AdminController::class, 'listUsers')
    ->middleware(new ValidateJwtMiddleware($jwtHandler))
    ->middleware(new RequireIssuerMiddleware(['employee']));

// Customer-only endpoint
$router->get('/api/orders', OrderController::class, 'myOrders')
    ->middleware(new ValidateJwtMiddleware($jwtHandler))
    ->middleware(new RequireIssuerMiddleware(['customer']));

// Mixed endpoint (both customer and employee can access)
$router->get('/api/products', ProductController::class, 'list')
    ->middleware(new ValidateJwtMiddleware($jwtHandler))
    ->middleware(new RequireIssuerMiddleware(['customer', 'employee']));
```

### Manual Issuer Checking in Controllers

```php
class OrderController
{
    public function getOrders($request)
    {
        $claims = $request['jwt_claims'];
        $issuerType = $claims['issuer_type'] ?? null;

        if ($issuerType === 'employee') {
            // Employee sees all orders
            return $this->getAllOrders();
        } elseif ($issuerType === 'customer') {
            // Customer sees only their orders
            $userId = $claims['sub'];
            return $this->getUserOrders($userId);
        }

        return ['error' => 'Unknown issuer type'];
    }
}
```

## Backward Compatibility

Multi-auth is **opt-in**. If `auth_servers` is not configured, StoneScriptPHP falls back to single-issuer mode using legacy config:

```php
return [
    'gateway_url' => env('GATEWAY_URL'),
    'jwks_endpoint' => '/auth/jwks',
    'jwks_cache_ttl' => 3600,
];
```

Existing apps continue to work without changes.

## Security Considerations

1. **Issuer validation** - Each token's `iss` claim must match a configured issuer
2. **Audience validation** - If configured, token's `aud` claim is verified
3. **JWKS caching** - Public keys are cached per issuer to reduce latency
4. **Signature verification** - All tokens are cryptographically verified before acceptance

## Benefits for Open Source

This feature provides **"Multi-issuer JWT validation"** - enterprise-grade auth flexibility that allows:
- Microservices to accept tokens from multiple auth providers
- Shared APIs to serve different user types (customers, employees, partners)
- SSO integration with multiple identity providers
- Multi-tenant platforms with tenant-specific auth servers

## API Reference

### MultiAuthJwtValidator

```php
class MultiAuthJwtValidator
{
    public function __construct(array $authServers);
    public function validateJWT(string $jwt): ?array;
    public function getAuthServers(): array;
    public function hasIssuerType(string $issuerType): bool;
}
```

### RequireIssuerMiddleware

```php
class RequireIssuerMiddleware implements MiddlewareInterface
{
    public function __construct(array $allowedIssuers);
    public function handle(array $request, callable $next): ?ApiResponse;
}
```

### CentralizedAuth (Enhanced)

```php
class CentralizedAuth
{
    public function isMultiAuthMode(): bool;
    public function validateJWT(string $jwt): ?array; // Returns claims with issuer_type
    public function getCurrentUser(): ?array; // Returns user with issuer_type
}
```

## Example: Complete Setup

```php
// config/auth.php
return [
    'auth_servers' => [
        'customer' => [
            'issuer' => 'https://auth.example.com',
            'jwks_url' => 'https://auth.example.com/auth/jwks',
            'audience' => 'my-api',
        ],
        'employee' => [
            'issuer' => 'https://admin-auth.example.com',
            'jwks_url' => 'https://admin-auth.example.com/auth/jwks',
            'audience' => 'my-admin-api',
        ],
    ],
];

// routes.php
use StoneScriptPHP\Auth\Middleware\ValidateJwtMiddleware;
use StoneScriptPHP\Auth\Middleware\RequireIssuerMiddleware;

$router->group(['middleware' => [new ValidateJwtMiddleware($jwtHandler)]], function($router) {

    // Public API - both customer and employee
    $router->get('/api/products', ProductController::class, 'list')
        ->middleware(new RequireIssuerMiddleware(['customer', 'employee']));

    // Customer-only API
    $router->post('/api/orders', OrderController::class, 'create')
        ->middleware(new RequireIssuerMiddleware(['customer']));

    // Employee-only admin panel
    $router->get('/admin/stats', AdminController::class, 'stats')
        ->middleware(new RequireIssuerMiddleware(['employee']));
});
```
