# Authentication in StoneScriptPHP

## Overview

StoneScriptPHP provides built-in JWT authentication with two implementations:

1. **JwtHandler** (Default) - Simple HMAC-based JWT using a secret key
2. **RsaJwtHandler** (Advanced) - RSA public/private key pairs for distributed systems

Both implement `JwtHandlerInterface`, allowing you to create custom implementations.

## Quick Start (Default HMAC)

### 1. Add JWT Secret to .env

```bash
JWT_SECRET=your-super-secret-key-here-min-32-chars
```

Generate a secure secret:
```bash
php -r "echo bin2hex(random_bytes(32));"
```

### 2. Use in Your Routes

```php
<?php

namespace App\Routes;

use Framework\Auth\JwtHandler;
use Framework\IRouteHandler;
use Framework\ApiResponse;

class UserAccessRoute implements IRouteHandler
{
    private JwtHandler $jwt;

    public function __construct()
    {
        $this->jwt = new JwtHandler();
    }

    public function process(): ApiResponse
    {
        // Authenticate user (check password, etc.)
        // ...

        // Generate token
        $token = $this->jwt->generateToken([
            'user_id' => $userId,
            'email' => $email,
        ]);

        // Set as HTTP-only cookie
        setcookie('access_token', $token, [
            'expires' => time() + (30 * 24 * 60 * 60), // 30 days
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        return res_ok(['token' => $token]);
    }
}
```

### 3. Verify Token in Protected Routes

```php
<?php

namespace App\Routes;

use Framework\Auth\JwtHandler;
use Framework\IRouteHandler;
use Framework\ApiResponse;

class UserProfileRoute implements IRouteHandler
{
    private JwtHandler $jwt;

    public function __construct()
    {
        $this->jwt = new JwtHandler();
    }

    public function process(): ApiResponse
    {
        // Get token from cookie or Authorization header
        $token = $_COOKIE['access_token'] ?? null;

        if (!$token) {
            return res_error('Unauthorized', 401);
        }

        // Verify token
        $payload = $this->jwt->verifyToken($token);

        if (!$payload) {
            return res_error('Invalid or expired token', 401);
        }

        // Use payload data
        $userId = $payload['user_id'];
        $email = $payload['email'];

        // ... fetch user profile
        return res_ok(['user_id' => $userId, 'email' => $email]);
    }
}
```

## Advanced: RSA Keys

For distributed systems or microservices, use RSA keys:

### 1. Generate RSA Keypair

```bash
# Generate private key
ssh-keygen -t rsa -m pkcs8 -f keys/jwt-private.pem -N ""

# Extract public key
ssh-keygen -f keys/jwt-private.pem -e -m pkcs8 > keys/jwt-public.pem

# Set permissions
chmod 600 keys/jwt-private.pem
chmod 644 keys/jwt-public.pem
```

### 2. Configure .env

```bash
JWT_PRIVATE_KEY_PATH=./keys/jwt-private.pem
JWT_PUBLIC_KEY_PATH=./keys/jwt-public.pem
```

### 3. Use RsaJwtHandler

```php
<?php

use Framework\Auth\RsaJwtHandler;

class UserAccessRoute implements IRouteHandler
{
    private RsaJwtHandler $jwt;

    public function __construct()
    {
        $this->jwt = new RsaJwtHandler();
    }

    public function process(): ApiResponse
    {
        $token = $this->jwt->generateToken(['user_id' => $userId]);
        return res_ok(['token' => $token]);
    }
}
```

## Custom Implementation

Implement `JwtHandlerInterface` for custom auth providers:

### Example: Auth0 Integration

```php
<?php

namespace App\Lib;

use Framework\Auth\JwtHandlerInterface;

class Auth0Handler implements JwtHandlerInterface
{
    public function generateToken(array $payload, int $expiryDays = 30): string
    {
        // Use Auth0 SDK to generate token
        $auth0 = new \Auth0\SDK\Auth0([
            'domain' => env('AUTH0_DOMAIN'),
            'client_id' => env('AUTH0_CLIENT_ID'),
            'client_secret' => env('AUTH0_CLIENT_SECRET'),
        ]);

        return $auth0->generateToken($payload);
    }

    public function verifyToken(string $token): array|false
    {
        // Use Auth0 SDK to verify token
        // ...
    }
}
```

### Use Custom Handler

```php
<?php

use App\Lib\Auth0Handler;

class UserAccessRoute implements IRouteHandler
{
    private Auth0Handler $jwt;

    public function __construct()
    {
        $this->jwt = new Auth0Handler();
    }
}
```

## Token Expiry

### Default: 30 Days

```php
$token = $jwt->generateToken(['user_id' => 123]);
```

### Custom Expiry

```php
// 1 hour
$token = $jwt->generateToken(['user_id' => 123], 0.04167);

// 7 days
$token = $jwt->generateToken(['user_id' => 123], 7);

// 1 year
$token = $jwt->generateToken(['user_id' => 123], 365);
```

## Best Practices

### 1. Use HTTP-only Cookies

```php
setcookie('access_token', $token, [
    'httponly' => true,  // Prevents XSS attacks
    'secure' => true,    // HTTPS only
    'samesite' => 'Strict', // CSRF protection
]);
```

### 2. Short-lived Access Tokens + Refresh Tokens

```php
// Access token: 15 minutes
$accessToken = $jwt->generateToken(['user_id' => $userId], 0.0104);

// Refresh token: 30 days
$refreshToken = $jwt->generateToken(['user_id' => $userId, 'type' => 'refresh'], 30);
```

### 3. Store Minimal Data in Token

```php
// Good ✅
$token = $jwt->generateToken(['user_id' => 123]);

// Bad ❌ (too much data)
$token = $jwt->generateToken([
    'user_id' => 123,
    'email' => 'user@example.com',
    'name' => 'John Doe',
    'roles' => ['admin', 'editor'],
    'permissions' => [...], // Don't store large arrays
]);
```

### 4. Validate User Still Exists

```php
$payload = $jwt->verifyToken($token);

if (!$payload) {
    return res_error('Invalid token', 401);
}

// Check if user still exists in database
$user = User::find($payload['user_id']);

if (!$user) {
    return res_error('User not found', 404);
}
```

## Comparison

| Feature | JwtHandler (HMAC) | RsaJwtHandler | Custom |
|---------|-------------------|---------------|--------|
| Setup | ✅ Easy (JWT_SECRET) | ⚠️ Requires keypair | Varies |
| Performance | ✅ Fast | ⚠️ Slower | Varies |
| Security | ✅ Good | ✅ Excellent | Varies |
| Distributed Systems | ⚠️ Share secret | ✅ Public key verify | Varies |
| Token Size | ✅ Smaller | ⚠️ Larger | Varies |
| Use Case | Most projects | Microservices | Auth0, etc. |

## Troubleshooting

### "JWT_SECRET not set"
Add `JWT_SECRET` to your `.env` file.

### "Unable to load private key"
Check file paths and permissions on RSA key files.

### "Invalid token"
Token may be expired, corrupted, or signed with wrong key.

### "Signature invalid"
Token was modified or signed with different key.

## Migration from App\Lib\JWTAuth

If you have existing code using `App\Lib\JWTAuth`:

### Before:
```php
use App\Lib\JWTAuth;

list($accessToken, $refreshToken) = JWTAuth::create_tokens($userId);
```

### After:
```php
use Framework\Auth\JwtHandler;

$jwt = new JwtHandler();
$accessToken = $jwt->generateToken(['user_id' => $userId], 0.0104); // 15 min
$refreshToken = $jwt->generateToken(['user_id' => $userId], 30);    // 30 days
```

Or use `RsaJwtHandler` to keep RSA behavior:
```php
use Framework\Auth\RsaJwtHandler;

$jwt = new RsaJwtHandler();
$token = $jwt->generateToken(['user_id' => $userId]);
```
