# Built-in Auth Routes (v2.2.0+)

## Overview

StoneScriptPHP v2.2.0 introduces **built-in authentication routes** that handle token refresh and logout out of the box, similar to Laravel Sanctum and Django REST Framework.

**What's included:**
- ✅ `/auth/refresh` - Secure token refresh using httpOnly cookies
- ✅ `/auth/logout` - Logout with cookie clearing
- ✅ CSRF protection
- ✅ Automatic token rotation
- ✅ Optional token blacklisting/revocation
- ✅ Login route example template

**What you still implement:**
- ❌ Login validation (your database, your rules)
- ❌ User schema
- ❌ Token storage (optional, for blacklisting)

---

## Quick Start

### 1. Register Auth Routes

In your router setup (e.g., `public/index.php` or a bootstrap file):

```php
<?php

use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\Auth\AuthRoutes;

$router = new Router();

// Register built-in auth routes (refresh, logout)
AuthRoutes::register($router);

// Now you have:
// POST /auth/refresh
// POST /auth/logout
```

That's it! Your refresh and logout endpoints are ready.

###2. Implement Login

Copy the example login route from `docs/examples/auth/LoginRoute.php` to your `src/App/Routes/` directory and customize the user validation:

```php
<?php

namespace App\Routes;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\RsaJwtHandler;
use StoneScriptPHP\Auth\CookieHelper;
use StoneScriptPHP\Auth\CsrfHelper;

class LoginRoute implements IRouteHandler
{
    public string $email;
    public string $password;

    private RsaJwtHandler $jwtHandler;

    public function __construct()
    {
        $this->jwtHandler = new RsaJwtHandler();
    }

    public function validation_rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'min:8']
        ];
    }

    public function process(): ApiResponse
    {
        // 1. Validate credentials (YOUR implementation)
        $user = $this->validateCredentials($this->email, $this->password);

        if (!$user) {
            http_response_code(401);
            return new ApiResponse('error', 'Invalid credentials');
        }

        // 2. Generate tokens
        $accessToken = $this->jwtHandler->generateToken([
            'user_id' => $user['id'],
            'email' => $user['email']
        ], 900, 'access'); // 15 minutes

        $refreshToken = $this->jwtHandler->generateToken([
            'user_id' => $user['id'],
            'type' => 'refresh'
        ], 15552000, 'refresh'); // 180 days

        // 3. Set httpOnly cookies
        CookieHelper::setRefreshToken($refreshToken);
        $csrfToken = CsrfHelper::generate();
        CookieHelper::setCsrfToken($csrfToken);

        // 4. Return access token
        return new ApiResponse('ok', [
            'access_token' => $accessToken,
            'expires_in' => 900,
            'user' => ['id' => $user['id'], 'email' => $user['email']]
        ]);
    }

    private function validateCredentials(string $email, string $password): ?array
    {
        // TODO: Query your database
        // Example:
        // $user = FnGetUserByEmail::run($email);
        // if ($user && password_verify($password, $user['password_hash'])) {
        //     return $user;
        // }
        // return null;
    }
}
```

Register your login route:

```php
$router->post('/auth/login', LoginRoute::class);
```

---

## How It Works

### Authentication Flow

```
┌──────────┐                    ┌──────────┐
│  Client  │                    │  Server  │
└─────┬────┘                    └─────┬────┘
      │                               │
      │  POST /auth/login             │
      │  { email, password }          │
      │─────────────────────────────> │
      │                               │ 1. Validate credentials
      │                               │ 2. Generate access + refresh tokens
      │                               │ 3. Set httpOnly cookie (refresh_token)
      │                               │ 4. Set csrf_token cookie
      │                               │
      │  200 OK                       │
      │  { access_token, user }       │
      │  Set-Cookie: refresh_token... │
      │  Set-Cookie: csrf_token...    │
      │ <───────────────────────────── │
      │                               │
      │  Use access_token for         │
      │  authenticated requests       │
      │                               │
      │  GET /api/profile             │
      │  Authorization: Bearer eyJ... │
      │─────────────────────────────> │
      │                               │
      │  200 OK                       │
      │  { profile data }             │
      │ <───────────────────────────── │
      │                               │
      │  Access token expires (15min) │
      │                               │
      │  POST /auth/refresh           │
      │  Cookie: refresh_token=...    │
      │  X-CSRF-Token: ...            │
      │─────────────────────────────> │
      │                               │ 1. Validate CSRF token
      │                               │ 2. Validate refresh token from cookie
      │                               │ 3. Generate new access token
      │                               │ 4. Rotate refresh token
      │                               │ 5. Set new refresh token cookie
      │                               │
      │  200 OK                       │
      │  { access_token }             │
      │  Set-Cookie: refresh_token... │
      │ <───────────────────────────── │
      │                               │
      │  POST /auth/logout            │
      │  X-CSRF-Token: ...            │
      │─────────────────────────────> │
      │                               │ 1. Revoke refresh token
      │                               │ 2. Clear cookies
      │                               │
      │  200 OK                       │
      │  Set-Cookie: (clear cookies)  │
      │ <───────────────────────────── │
```

### Security Features

1. **httpOnly Cookies** - Refresh tokens stored in cookies JavaScript can't access (prevents XSS)
2. **CSRF Protection** - CSRF token required for refresh/logout (prevents CSRF attacks)
3. **Token Rotation** - Old refresh token invalidated when new one issued
4. **Short-lived Access Tokens** - Access tokens expire in 15 minutes (configurable)
5. **Long-lived Refresh Tokens** - Refresh tokens expire in 180 days (configurable)
6. **Secure Cookies** - Secure flag (HTTPS), SameSite=Strict

---

## Frontend Integration

### React Example

```typescript
// lib/auth.ts
const API_URL = 'http://localhost:9100';

export const login = async (email: string, password: string) => {
  const response = await fetch(`${API_URL}/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password }),
    credentials: 'include' // Important: include cookies
  });

  const data = await response.json();

  if (response.ok) {
    // Store access token in memory (NOT localStorage!)
    sessionStorage.setItem('access_token', data.data.access_token);

    // Store CSRF token for refresh/logout
    const csrfToken = getCookie('csrf_token');
    sessionStorage.setItem('csrf_token', csrfToken);

    return data.data;
  }

  throw new Error(data.message || 'Login failed');
};

export const refreshToken = async () => {
  const csrfToken = sessionStorage.getItem('csrf_token');

  const response = await fetch(`${API_URL}/auth/refresh`, {
    method: 'POST',
    headers: {
      'X-CSRF-Token': csrfToken || ''
    },
    credentials: 'include'
  });

  const data = await response.json();

  if (response.ok) {
    sessionStorage.setItem('access_token', data.data.access_token);
    return data.data.access_token;
  }

  throw new Error('Refresh failed');
};

export const logout = async () => {
  const csrfToken = sessionStorage.getItem('csrf_token');

  await fetch(`${API_URL}/auth/logout`, {
    method: 'POST',
    headers: {
      'X-CSRF-Token': csrfToken || ''
    },
    credentials: 'include'
  });

  sessionStorage.removeItem('access_token');
  sessionStorage.removeItem('csrf_token');
};

// Helper to get cookie value
function getCookie(name: string): string {
  const value = `; ${document.cookie}`;
  const parts = value.split(`; ${name}=`);
  if (parts.length === 2) return parts.pop()?.split(';').shift() || '';
  return '';
}

// Axios interceptor for auto-refresh
axios.interceptors.response.use(
  response => response,
  async error => {
    if (error.response?.status === 401) {
      try {
        await refreshToken();
        // Retry original request
        return axios(error.config);
      } catch {
        // Refresh failed, redirect to login
        window.location.href = '/login';
      }
    }
    return Promise.reject(error);
  }
);
```

---

## Configuration

### Environment Variables

Add to your `.env`:

```ini
# JWT Configuration
JWT_PRIVATE_KEY_PATH=./keys/jwt-private.pem
JWT_PUBLIC_KEY_PATH=./keys/jwt-public.pem
JWT_ACCESS_TOKEN_EXPIRY=900           # 15 minutes
JWT_REFRESH_TOKEN_EXPIRY=15552000     # 180 days
JWT_ISSUER=example.com

# Auth Cookie Configuration
AUTH_COOKIE_DOMAIN=                   # Leave empty for current domain
AUTH_COOKIE_SECURE=                   # Leave empty to auto-detect HTTPS
```

### Custom Prefix

```php
AuthRoutes::register($router, [
    'prefix' => '/api/auth' // Default: /auth
]);

// Routes become:
// POST /api/auth/refresh
// POST /api/auth/logout
```

### Disable Specific Routes

```php
AuthRoutes::register($router, [
    'refresh' => true,  // Enable
    'logout' => false   // Disable
]);
```

---

## Token Storage (Optional)

For token blacklisting and revocation, implement `TokenStorageInterface`:

```php
<?php

namespace App\Auth;

use StoneScriptPHP\Auth\TokenStorageInterface;
use PDO;

class PostgresTokenStorage implements TokenStorageInterface
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function storeRefreshToken(
        string $tokenHash,
        int $userId,
        int $expiresAt,
        array $metadata = []
    ): void {
        $stmt = $this->db->prepare(
            "INSERT INTO refresh_tokens (token_hash, user_id, expires_at, ip_address, user_agent)
             VALUES (?, ?, to_timestamp(?), ?, ?)"
        );
        $stmt->execute([
            $tokenHash,
            $userId,
            $expiresAt,
            $metadata['ip_address'] ?? null,
            $metadata['user_agent'] ?? null
        ]);
    }

    public function validateRefreshToken(string $tokenHash): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM refresh_tokens
             WHERE token_hash = ? AND expires_at > NOW() AND revoked_at IS NULL"
        );
        $stmt->execute([$tokenHash]);
        return $stmt->fetchColumn() > 0;
    }

    public function revokeRefreshToken(string $tokenHash): void
    {
        $stmt = $this->db->prepare(
            "UPDATE refresh_tokens SET revoked_at = NOW() WHERE token_hash = ?"
        );
        $stmt->execute([$tokenHash]);
    }

    public function revokeAllUserTokens(int $userId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE refresh_tokens SET revoked_at = NOW()
             WHERE user_id = ? AND revoked_at IS NULL"
        );
        $stmt->execute([$userId]);
    }

    // Optional methods
    public function updateLastUsed(string $tokenHash): bool { return false; }
    public function getUserTokens(int $userId): array { return []; }
    public function cleanupExpiredTokens(?int $olderThanDays = 30): int { return 0; }
}
```

### Database Schema

```sql
CREATE TABLE refresh_tokens (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    last_used_at TIMESTAMPTZ,
    revoked_at TIMESTAMPTZ,
    ip_address VARCHAR(45),
    user_agent TEXT,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_token_hash (token_hash),
    INDEX idx_expires_at (expires_at)
);
```

### Register with Token Storage

```php
$tokenStorage = new App\Auth\PostgresTokenStorage($db);

AuthRoutes::register($router, [
    'token_storage' => $tokenStorage
]);
```

---

## Comparison with Other Frameworks

### Laravel Sanctum

**Laravel:**
```php
// Requires manual controller
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
```

**StoneScriptPHP:**
```php
// Built-in, one line
AuthRoutes::register($router);
```

### Django REST Framework

**Django:**
```python
from rest_framework_simplejwt.views import TokenRefreshView

urlpatterns = [
    path('api/token/refresh/', TokenRefreshView.as_view()),
]
```

**StoneScriptPHP:**
```php
AuthRoutes::register($router);
```

**Advantages:**
- ✅ Zero configuration for basic setup
- ✅ Built-in CSRF protection
- ✅ Automatic token rotation
- ✅ httpOnly cookies by default

---

## Best Practices

### 1. Store Access Tokens in Memory

**Good** ✅:
```javascript
// Store in memory (React state, Vuex, etc.)
sessionStorage.setItem('access_token', token);
```

**Bad** ❌:
```javascript
// Never store in localStorage (vulnerable to XSS)
localStorage.setItem('access_token', token);
```

### 2. Always Use HTTPS in Production

```ini
# .env (production)
AUTH_COOKIE_SECURE=true
```

### 3. Implement Token Rotation

Token rotation is automatic with built-in routes. Each refresh invalidates the old refresh token.

### 4. Monitor Token Usage

If using token storage, track IP address and user agent:

```php
$tokenStorage->getUserTokens($userId); // See all active devices
$tokenStorage->revokeRefreshToken($tokenHash); // Revoke specific device
$tokenStorage->revokeAllUserTokens($userId); // Logout from all devices
```

---

## Troubleshooting

### "CSRF token validation failed"

Make sure frontend sends CSRF token in `X-CSRF-Token` header:

```javascript
headers: {
  'X-CSRF-Token': csrfToken
}
```

### "No refresh token provided"

Ensure `credentials: 'include'` is set in fetch:

```javascript
fetch('/auth/refresh', {
  credentials: 'include' // Important!
})
```

### "Refresh token has been revoked"

Token was manually revoked or user logged out. User needs to login again.

###  "CORS errors"

Add frontend origin to `ALLOWED_ORIGINS` in `.env`:

```ini
ALLOWED_ORIGINS=http://localhost:3000,https://app.example.com
```

---

## Migration from Manual Implementation

**Before (manual refresh endpoint):**
```php
// 50+ lines of custom refresh logic
class PostUserRefreshAccessRoute implements IRouteHandler {
    public function process(): ApiResponse {
        // Manual cookie reading
        // Manual CSRF validation
        // Manual token generation
        // Manual cookie setting
        // etc.
    }
}
```

**After (built-in auth routes):**
```php
// One line
AuthRoutes::register($router);
```

---

## See Also

- [JWT Configuration Guide](jwt-configuration.md)
- [Authentication Overview](authentication.md)
- [Example Login Route](../examples/auth/LoginRoute.php)
