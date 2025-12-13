# CSRF Protection

This framework includes comprehensive CSRF (Cross-Site Request Forgery) protection for public routes to prevent bot spam, automated registrations, and API abuse.

## Features

- ✅ **Time-based expiration** - Tokens expire after 1 hour
- ✅ **Client fingerprint binding** - Tokens tied to IP + User-Agent
- ✅ **HMAC signature** - Cryptographic integrity verification
- ✅ **Single-use tokens** - Each token can only be used once
- ✅ **Rate limiting** - Maximum 10 active tokens per client
- ✅ **Action-specific tokens** - Tokens scoped to specific actions (register, login, etc.)

## How It Works

1. Frontend requests a CSRF token from `/api/csrf/token`
2. Backend generates a token containing:
   - Timestamp
   - Random nonce (for single-use)
   - Client fingerprint (hashed IP + User-Agent)
   - Action context (e.g., "register")
   - HMAC signature
3. Frontend includes token in form submission
4. Backend validates token before processing request

## Backend Setup

### 1. Add CSRF Middleware

```php
use Framework\Routing\Middleware\CsrfMiddleware;
use App\Routes\Security\CsrfTokenRoute;

// CSRF token endpoint (must be BEFORE CSRF middleware)
$router->get('/api/csrf/token', CsrfTokenRoute::class);

// Add CSRF protection for public POST routes
$router->use(new CsrfMiddleware([
    '/api/auth/register',
    '/api/auth/login',
    '/api/contact/submit'
]));

// Your routes...
$router->post('/api/auth/register', RegisterRoute::class);
$router->post('/api/auth/login', LoginRoute::class);
```

### 2. Configure Secret Key

Add to `.env`:

```env
CSRF_SECRET_KEY=your-random-64-character-hex-string-here
```

Generate a secure key:
```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

## Frontend Integration

### JavaScript/Fetch Example

```javascript
class CsrfTokenManager {
    constructor() {
        this.token = null;
        this.action = null;
    }

    /**
     * Request a new CSRF token
     */
    async requestToken(action = 'general') {
        try {
            const response = await fetch(`/api/csrf/token?action=${action}`, {
                method: 'GET',
                credentials: 'same-origin'
            });

            const data = await response.json();

            if (data.status === 'success') {
                this.token = data.data.csrf_token;
                this.action = data.data.action;
                console.log('CSRF token acquired');
                return this.token;
            } else {
                throw new Error(data.message || 'Failed to get CSRF token');
            }
        } catch (error) {
            console.error('CSRF token request failed:', error);
            throw error;
        }
    }

    /**
     * Get current token (request new one if expired)
     */
    async getToken(action = 'general') {
        if (!this.token || this.action !== action) {
            return await this.requestToken(action);
        }
        return this.token;
    }

    /**
     * Clear token (after use, as tokens are single-use)
     */
    clearToken() {
        this.token = null;
        this.action = null;
    }
}

// Global instance
const csrfManager = new CsrfTokenManager();

// Example: Registration form
async function register(formData) {
    try {
        // Get CSRF token for registration
        const csrfToken = await csrfManager.getToken('register');

        const response = await fetch('/api/auth/register', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken  // Include token in header
            },
            body: JSON.stringify({
                email: formData.email,
                password: formData.password,
                display_name: formData.displayName
            })
        });

        const data = await response.json();

        // Clear token after use (tokens are single-use)
        csrfManager.clearToken();

        if (data.status === 'success') {
            console.log('Registration successful');
            return data;
        } else {
            // Handle errors
            if (data.error_code === 'CSRF_TOKEN_INVALID') {
                // Token expired or invalid, retry
                console.log('CSRF token invalid, retrying...');
                csrfManager.clearToken();
                return await register(formData);
            }
            throw new Error(data.message);
        }
    } catch (error) {
        console.error('Registration failed:', error);
        throw error;
    }
}

// Example: Login form
async function login(email, password) {
    const csrfToken = await csrfManager.getToken('login');

    const response = await fetch('/api/auth/login', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({ email, password })
    });

    csrfManager.clearToken();
    return await response.json();
}
```

### React Example

```jsx
import { useState, useEffect } from 'react';

// Custom hook for CSRF tokens
function useCsrfToken(action = 'general') {
    const [token, setToken] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    const requestToken = async () => {
        setLoading(true);
        setError(null);

        try {
            const response = await fetch(`/api/csrf/token?action=${action}`);
            const data = await response.json();

            if (data.status === 'success') {
                setToken(data.data.csrf_token);
            } else {
                throw new Error(data.message);
            }
        } catch (err) {
            setError(err.message);
        } finally {
            setLoading(false);
        }
    };

    const clearToken = () => setToken(null);

    useEffect(() => {
        requestToken();
    }, [action]);

    return { token, loading, error, requestToken, clearToken };
}

// Registration component
function RegisterForm() {
    const { token, loading, error, requestToken, clearToken } = useCsrfToken('register');
    const [formData, setFormData] = useState({
        email: '',
        password: '',
        displayName: ''
    });

    const handleSubmit = async (e) => {
        e.preventDefault();

        if (!token) {
            await requestToken();
            return;
        }

        try {
            const response = await fetch('/api/auth/register', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': token
                },
                body: JSON.stringify(formData)
            });

            const data = await response.json();

            // Clear token after use
            clearToken();

            if (data.status === 'success') {
                console.log('Registration successful');
            } else if (data.error_code === 'CSRF_TOKEN_INVALID') {
                // Retry with new token
                await requestToken();
                handleSubmit(e);
            } else {
                alert(data.message);
            }
        } catch (err) {
            console.error(err);
        }
    };

    return (
        <form onSubmit={handleSubmit}>
            <input
                type="email"
                value={formData.email}
                onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                placeholder="Email"
            />
            <input
                type="password"
                value={formData.password}
                onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                placeholder="Password"
            />
            <input
                type="text"
                value={formData.displayName}
                onChange={(e) => setFormData({ ...formData, displayName: e.target.value })}
                placeholder="Display Name"
            />
            <button type="submit" disabled={loading || !token}>
                {loading ? 'Loading...' : 'Register'}
            </button>
            {error && <div className="error">{error}</div>}
        </form>
    );
}
```

### Vue Example

```vue
<template>
  <form @submit.prevent="handleSubmit">
    <input v-model="email" type="email" placeholder="Email" />
    <input v-model="password" type="password" placeholder="Password" />
    <button type="submit" :disabled="!csrfToken || loading">
      {{ loading ? 'Loading...' : 'Login' }}
    </button>
    <div v-if="error" class="error">{{ error }}</div>
  </form>
</template>

<script>
export default {
  data() {
    return {
      email: '',
      password: '',
      csrfToken: null,
      loading: false,
      error: null
    };
  },
  mounted() {
    this.requestCsrfToken();
  },
  methods: {
    async requestCsrfToken() {
      try {
        const response = await fetch('/api/csrf/token?action=login');
        const data = await response.json();

        if (data.status === 'success') {
          this.csrfToken = data.data.csrf_token;
        }
      } catch (err) {
        this.error = err.message;
      }
    },
    async handleSubmit() {
      if (!this.csrfToken) {
        await this.requestCsrfToken();
        return;
      }

      this.loading = true;
      this.error = null;

      try {
        const response = await fetch('/api/auth/login', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': this.csrfToken
          },
          body: JSON.stringify({
            email: this.email,
            password: this.password
          })
        });

        const data = await response.json();
        this.csrfToken = null; // Clear after use

        if (data.status === 'success') {
          // Handle success
          console.log('Login successful', data);
        } else if (data.error_code === 'CSRF_TOKEN_INVALID') {
          // Retry with new token
          await this.requestCsrfToken();
          this.handleSubmit();
        } else {
          this.error = data.message;
        }
      } catch (err) {
        this.error = err.message;
      } finally {
        this.loading = false;
      }
    }
  }
};
</script>
```

## Token Inclusion Methods

The middleware accepts CSRF tokens in multiple ways (in priority order):

1. **HTTP Header (Recommended)**: `X-CSRF-Token: {token}`
2. **HTTP Header (Angular)**: `X-XSRF-TOKEN: {token}`
3. **Request Body**: `{ "csrf_token": "{token}", ... }`
4. **Query Parameter**: `?csrf_token={token}` (least secure)

## Error Handling

### CSRF Token Missing
```json
{
  "status": "error",
  "message": "CSRF token required",
  "data": {
    "error_code": "CSRF_TOKEN_MISSING",
    "message": "Please refresh the page and try again"
  },
  "http_code": 403
}
```

### CSRF Token Invalid/Expired
```json
{
  "status": "error",
  "message": "Invalid or expired CSRF token",
  "data": {
    "error_code": "CSRF_TOKEN_INVALID",
    "message": "Please refresh the page and try again"
  },
  "http_code": 403
}
```

### Rate Limit Exceeded
```json
{
  "status": "error",
  "message": "Too many token requests. Please try again later.",
  "data": {
    "error_code": "RATE_LIMIT_EXCEEDED"
  },
  "http_code": 429
}
```

## Best Practices

### ✅ DO

- Request new token for each form submission
- Clear token after use (tokens are single-use)
- Store tokens in memory (component state)
- Include tokens in HTTP headers (`X-CSRF-Token`)
- Handle token expiration gracefully with retry logic
- Use action-specific tokens when available

### ❌ DON'T

- Store tokens in `localStorage` or `sessionStorage` (XSS vulnerability)
- Reuse tokens across requests (single-use only)
- Include tokens in URLs (can be logged)
- Cache tokens indefinitely
- Share tokens between different forms/actions

## Security Considerations

1. **Token Expiration**: Tokens expire after 1 hour
2. **Single-Use**: Each token can only be used once
3. **Client Binding**: Tokens tied to IP prefix + User-Agent
4. **Rate Limiting**: Max 10 active tokens per client
5. **Secure Secret**: Use strong random secret key in production
6. **HTTPS Only**: Always use HTTPS in production

## Troubleshooting

### Token validation fails after deployment

**Cause**: `CSRF_SECRET_KEY` not set or changes between deployments

**Solution**: Set a persistent `CSRF_SECRET_KEY` in `.env`

### Token validation fails for mobile users

**Cause**: Dynamic IP addresses or changing User-Agent

**Solution**: The fingerprint uses only first 3 octets of IP to handle this

### High rate limit errors

**Cause**: Too many token requests from same client

**Solution**: Implement client-side token caching (per action)

### Token expired errors

**Cause**: Form left open for over 1 hour

**Solution**: Implement automatic token refresh on form focus

## Advanced Configuration

### Custom Token Expiry

```php
// Extend CsrfTokenHandler
class CustomCsrfHandler extends CsrfTokenHandler {
    private const TOKEN_EXPIRY = 7200; // 2 hours
}

$router->use(new CsrfMiddleware(
    protectedRoutes: ['/api/auth/*'],
    handler: new CustomCsrfHandler()
));
```

### Disable CSRF for Specific Routes

```php
$router->use(new CsrfMiddleware(
    protectedRoutes: ['/api/*'],
    excludedRoutes: ['/api/webhooks/*', '/api/public/*']
));
```

### Integration with Mobile Apps

For mobile apps, consider using JWT authentication instead of CSRF tokens, as the same-origin policy doesn't apply.

## Testing

```php
// Test CSRF token generation
$handler = new CsrfTokenHandler();
$token = $handler->generateToken(['action' => 'test']);
assert($handler->validateToken($token, ['action' => 'test']));

// Test expiration
sleep(3601);
assert(!$handler->validateToken($token, ['action' => 'test']));
```

## Additional Resources

- [OWASP CSRF Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html)
- [MDN: CSRF](https://developer.mozilla.org/en-US/docs/Glossary/CSRF)
