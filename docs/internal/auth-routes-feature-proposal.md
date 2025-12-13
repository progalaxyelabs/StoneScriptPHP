# Feature Proposal: Built-in Auth Routes

**Status:** Proposal
**Target Version:** v2.2.0
**Date:** December 13, 2025
**Author:** Development Team Feedback

## Problem Statement

Every developer using JWT authentication must manually implement:
- ✗ Token refresh endpoint
- ✗ httpOnly cookie handling
- ✗ Secure token rotation
- ✗ Login/logout endpoints
- ✗ CSRF protection for cookies

**Current Pain Points:**
1. Boilerplate code repeated across all projects
2. Security vulnerabilities from incorrect implementations
3. Inconsistent patterns between projects
4. Steep learning curve for new developers
5. No standardized way to handle refresh tokens

## Proposed Solution

Add built-in auth routes similar to Laravel Sanctum and Django REST Framework.

### API Design

```php
<?php

use StoneScriptPHP\Auth\AuthRoutes;

// In src/config/routes.php or bootstrap

// Option 1: Enable with defaults
AuthRoutes::enable();

// Option 2: Customize endpoints
AuthRoutes::enable([
    'login' => '/auth/login',
    'refresh' => '/auth/refresh',
    'logout' => '/auth/logout',
    'register' => '/auth/register', // optional
    'verify-email' => '/auth/verify-email', // optional
]);

// Option 3: Customize handlers
AuthRoutes::enable([
    'login' => [
        'path' => '/auth/login',
        'handler' => CustomLoginHandler::class
    ],
    'refresh' => [
        'path' => '/auth/refresh',
        'handler' => CustomRefreshHandler::class
    ]
]);

// Option 4: Disable specific routes
AuthRoutes::enable([
    'login' => '/auth/login',
    'refresh' => '/auth/refresh',
    'register' => false, // disabled
    'logout' => '/auth/logout',
]);
```

### Built-in Routes

#### 1. POST /auth/login
**Request:**
```json
{
    "email": "user@example.com",
    "password": "secret123"
}
```

**Response:**
```json
{
    "status": "ok",
    "data": {
        "access_token": "eyJhbG...",
        "expires_in": 900,
        "token_type": "Bearer",
        "user": {
            "id": 123,
            "email": "user@example.com",
            "name": "John Doe"
        }
    }
}
```

**Cookies Set:**
- `refresh_token` (httpOnly, secure, sameSite=strict)
- `csrf_token` (readable by JS for request headers)

#### 2. POST /auth/refresh
**Request:**
```json
{} // Empty body, uses httpOnly cookie
```

**Headers Required:**
```
Cookie: refresh_token=...
X-CSRF-Token: ...
```

**Response:**
```json
{
    "status": "ok",
    "data": {
        "access_token": "eyJhbG...",
        "expires_in": 900,
        "token_type": "Bearer"
    }
}
```

**Behavior:**
- Validates refresh token from httpOnly cookie
- Validates CSRF token
- Issues new access token
- Rotates refresh token (invalidates old one)
- Sets new refresh token cookie

#### 3. POST /auth/logout
**Request:**
```json
{} // Empty body
```

**Headers Required:**
```
Authorization: Bearer eyJhbG...
X-CSRF-Token: ...
```

**Response:**
```json
{
    "status": "ok",
    "message": "Logged out successfully"
}
```

**Behavior:**
- Invalidates current access token
- Invalidates refresh token
- Clears httpOnly cookie
- Clears CSRF cookie

#### 4. POST /auth/register (Optional)
**Request:**
```json
{
    "email": "user@example.com",
    "password": "secret123",
    "name": "John Doe"
}
```

**Response:**
```json
{
    "status": "ok",
    "data": {
        "user": {
            "id": 124,
            "email": "user@example.com",
            "name": "John Doe"
        },
        "message": "Registration successful. Please verify your email."
    }
}
```

## Security Features

### 1. Refresh Token Rotation
```php
// Automatic token rotation on refresh
// Old refresh token is immediately invalidated
// New refresh token issued with each refresh
```

### 2. httpOnly Cookies
```php
// Refresh tokens stored in httpOnly cookies
// JavaScript cannot access refresh tokens
// Prevents XSS attacks from stealing refresh tokens
setcookie('refresh_token', $token, [
    'httponly' => true,
    'secure' => true,
    'samesite' => 'Strict',
    'path' => '/auth',
    'expires' => time() + (60 * 60 * 24 * 30) // 30 days
]);
```

### 3. CSRF Protection
```php
// CSRF token required for cookie-based requests
// Prevents CSRF attacks on refresh endpoint
// Token validated on every refresh/logout
```

### 4. Token Storage
```php
// Refresh tokens stored in database
// Can be revoked at any time
// Track refresh token usage (IP, user agent, last used)
```

### 5. Rate Limiting
```php
// Built-in rate limiting for auth endpoints
// Prevents brute force attacks
// Configurable limits per endpoint
```

## Implementation Plan

### Phase 1: Core Auth Routes (v2.2.0)
- ✅ Create `StoneScriptPHP\Auth\AuthRoutes` class
- ✅ Implement login endpoint handler
- ✅ Implement refresh endpoint handler
- ✅ Implement logout endpoint handler
- ✅ Add refresh token database table
- ✅ Add httpOnly cookie support
- ✅ Add CSRF token generation and validation
- ✅ Add token rotation logic
- ✅ Add rate limiting middleware
- ✅ Write comprehensive tests
- ✅ Update documentation

### Phase 2: Optional Routes (v2.3.0)
- ⏭️ Registration endpoint
- ⏭️ Email verification
- ⏭️ Password reset flow
- ⏭️ Two-factor authentication

### Phase 3: Advanced Features (v2.4.0)
- ⏭️ OAuth integration helpers
- ⏭️ SSO support
- ⏭️ Session management dashboard
- ⏭️ Device tracking

## Database Schema

### Refresh Tokens Table
```sql
CREATE TABLE refresh_tokens (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    csrf_token VARCHAR(255) NOT NULL,
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

## Configuration

### Environment Variables
```ini
# Auth Routes Configuration
AUTH_ROUTES_ENABLED=true
AUTH_REFRESH_TOKEN_EXPIRY=2592000  # 30 days in seconds
AUTH_ACCESS_TOKEN_EXPIRY=900       # 15 minutes
AUTH_COOKIE_DOMAIN=example.com
AUTH_COOKIE_SECURE=true
AUTH_CSRF_ENABLED=true

# Rate Limiting
AUTH_RATE_LIMIT_LOGIN=5            # 5 attempts per minute
AUTH_RATE_LIMIT_REFRESH=10         # 10 refreshes per minute
AUTH_RATE_LIMIT_LOGOUT=10          # 10 logouts per minute
```

### Programmatic Configuration
```php
<?php

use StoneScriptPHP\Auth\AuthConfig;

AuthConfig::configure([
    'refresh_token_expiry' => 60 * 60 * 24 * 30, // 30 days
    'access_token_expiry' => 60 * 15, // 15 minutes
    'cookie_domain' => 'example.com',
    'cookie_secure' => true,
    'csrf_enabled' => true,
    'rate_limit' => [
        'login' => 5,
        'refresh' => 10,
        'logout' => 10
    ],
    'token_rotation' => true,
]);
```

## Usage Examples

### Basic Setup
```php
<?php
// In bootstrap.php or routes.php

use StoneScriptPHP\Auth\AuthRoutes;

// Enable with defaults
AuthRoutes::enable();

// Now /auth/login, /auth/refresh, /auth/logout are available
```

### Custom Validation
```php
<?php

use StoneScriptPHP\Auth\AuthRoutes;
use App\Auth\CustomLoginHandler;

AuthRoutes::enable([
    'login' => [
        'path' => '/api/auth/login',
        'handler' => CustomLoginHandler::class,
        'middleware' => [
            new RateLimitMiddleware(5, 60), // 5 per minute
            new HCaptchaMiddleware()
        ]
    ]
]);
```

### Frontend Integration (React Example)
```typescript
// Login
const login = async (email: string, password: string) => {
    const response = await fetch('/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password }),
        credentials: 'include' // Important: include cookies
    });

    const data = await response.json();

    // Store access token in memory (not localStorage!)
    setAccessToken(data.data.access_token);

    // Store CSRF token from cookie
    const csrfToken = getCookie('csrf_token');
    setCSRFToken(csrfToken);
};

// Refresh token
const refreshToken = async () => {
    const response = await fetch('/auth/refresh', {
        method: 'POST',
        headers: {
            'X-CSRF-Token': csrfToken
        },
        credentials: 'include'
    });

    const data = await response.json();
    setAccessToken(data.data.access_token);
};

// Logout
const logout = async () => {
    await fetch('/auth/logout', {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${accessToken}`,
            'X-CSRF-Token': csrfToken
        },
        credentials: 'include'
    });

    setAccessToken(null);
    setCSRFToken(null);
};
```

## Security Considerations

### ✅ Protections Included
- **XSS Protection**: Refresh tokens in httpOnly cookies (JS can't access)
- **CSRF Protection**: CSRF token required for cookie-based requests
- **Token Rotation**: Old refresh tokens invalidated on use
- **Rate Limiting**: Prevent brute force attacks
- **Secure Cookies**: Secure, sameSite=Strict flags
- **Token Revocation**: Database storage allows token invalidation
- **IP/User Agent Tracking**: Detect suspicious activity

### ⚠️ Best Practices
1. **Use HTTPS in production** - Secure flag requires HTTPS
2. **Store access tokens in memory** - Not localStorage or sessionStorage
3. **Validate CSRF tokens** - Required for all cookie-based requests
4. **Monitor token usage** - Track IP, user agent, last used
5. **Implement token expiry** - Short-lived access tokens (15 min)
6. **Rotate refresh tokens** - On every refresh request

## Comparison with Other Frameworks

### Laravel Sanctum
```php
// Laravel
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

// StoneScriptPHP (proposed)
AuthRoutes::enable();
```

**Advantages over Laravel:**
- ✅ Zero configuration for basic setup
- ✅ Built-in CSRF protection
- ✅ Automatic token rotation
- ✅ httpOnly cookies by default

### Django REST Framework
```python
# Django
from rest_framework_simplejwt.views import TokenObtainPairView, TokenRefreshView

urlpatterns = [
    path('api/token/', TokenObtainPairView.as_view()),
    path('api/token/refresh/', TokenRefreshView.as_view()),
]

# StoneScriptPHP (proposed)
AuthRoutes::enable();
```

**Advantages over Django:**
- ✅ Simpler API
- ✅ Integrated with existing Router system
- ✅ Built-in middleware support

## Migration Guide

### For Existing Projects

**Before (manual implementation):**
```php
// src/App/Routes/LoginRoute.php
class LoginRoute implements IRouteHandler {
    public function process(): ApiResponse {
        // 50+ lines of manual auth logic
        // Cookie handling
        // Token generation
        // etc.
    }
}
```

**After (built-in routes):**
```php
// In bootstrap.php or routes.php
AuthRoutes::enable();

// Optionally customize user validation
AuthRoutes::setUserValidator(function($email, $password) {
    return App\Models\User::authenticate($email, $password);
});
```

## Testing

### Unit Tests
- ✅ Token generation
- ✅ Token validation
- ✅ Token rotation
- ✅ CSRF token validation
- ✅ Cookie handling
- ✅ Rate limiting

### Integration Tests
- ✅ Login flow
- ✅ Refresh flow
- ✅ Logout flow
- ✅ Invalid credentials
- ✅ Expired tokens
- ✅ Revoked tokens
- ✅ CSRF validation
- ✅ Rate limit enforcement

## Documentation

### User Documentation
- Getting Started guide
- API reference
- Security best practices
- Frontend integration examples
- Customization guide

### Developer Documentation
- Architecture overview
- Implementation details
- Extension points
- Testing guide

## Breaking Changes

**None** - This is an optional feature that can be enabled via `AuthRoutes::enable()`.

Existing projects continue to work without changes.

## Timeline

- **v2.2.0-alpha** - Core auth routes (1-2 weeks)
- **v2.2.0-beta** - Testing and refinement (1 week)
- **v2.2.0** - Stable release (after testing)
- **v2.3.0** - Optional routes (registration, etc.)
- **v2.4.0** - Advanced features (OAuth, SSO, etc.)

## Questions for Team

1. Should we support both JWT and session-based auth?
2. Should refresh tokens be single-use (rotate on every refresh)?
3. What should be the default token expiry times?
4. Should we include 2FA in the initial release or defer to v2.3?
5. Should we support multiple concurrent refresh tokens per user?

## Feedback Requested

Please provide feedback on:
- API design
- Security approach
- Configuration options
- Documentation needs
- Use cases we should support

---

**Next Steps:**
1. Team review and approval
2. Create implementation plan
3. Write tests
4. Implement core features
5. Documentation
6. Beta release for community feedback
