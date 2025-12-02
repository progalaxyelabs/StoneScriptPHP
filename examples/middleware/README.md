# Middleware System Examples

This directory contains examples of using the StoneScriptPHP middleware system.

## Running the Example

### Using PHP Built-in Server

```bash
cd examples/middleware
php -S localhost:8080 index.php
```

### Testing the Endpoints

#### 1. Public Endpoint (No Auth Required)

```bash
curl http://localhost:8080/public
```

Expected response:
```json
{
    "status": "ok",
    "message": "Public endpoint - no auth required",
    "data": {
        "timestamp": 1234567890,
        "message": "This is a public endpoint"
    }
}
```

#### 2. Protected Endpoint (Auth Required)

Without token (should fail):
```bash
curl http://localhost:8080/protected
```

Expected response:
```json
{
    "status": "error",
    "message": "Unauthorized: Missing authentication token"
}
```

With valid token:
```bash
curl -H "Authorization: Bearer demo-token-12345" http://localhost:8080/protected
```

Expected response:
```json
{
    "status": "ok",
    "message": "Protected endpoint - auth required",
    "data": {
        "timestamp": 1234567890,
        "message": "You are authenticated!",
        "user": "authenticated_user"
    }
}
```

#### 3. Route with Parameters

```bash
curl -H "Authorization: Bearer demo-token-12345" http://localhost:8080/users/123
```

Expected response:
```json
{
    "status": "ok",
    "message": "User data retrieved",
    "data": {
        "userId": "123",
        "name": "John Doe",
        "email": "john@example.com"
    }
}
```

#### 4. POST with JSON Body

```bash
curl -X POST \
  -H "Authorization: Bearer demo-token-12345" \
  -H "Content-Type: application/json" \
  -d '{"name":"Jane Doe","email":"jane@example.com"}' \
  http://localhost:8080/data
```

Expected response:
```json
{
    "status": "ok",
    "message": "Data created successfully",
    "data": {
        "id": "unique-id",
        "name": "Jane Doe",
        "email": "jane@example.com",
        "created_at": "2024-01-01 12:00:00"
    }
}
```

#### 5. Testing Rate Limiting

Make more than 100 requests in 60 seconds to see rate limiting in action:

```bash
for i in {1..110}; do
  curl http://localhost:8080/protected
done
```

After 100 requests, you should see:
```json
{
    "status": "error",
    "message": "Rate limit exceeded. Please try again later."
}
```

#### 6. Testing CORS

From a browser console on http://localhost:3000:

```javascript
fetch('http://localhost:8080/public')
  .then(r => r.json())
  .then(console.log);
```

The CORS middleware will add appropriate headers allowing the request.

## Middleware in the Example

The example demonstrates all built-in middleware:

1. **SecurityHeadersMiddleware** - Adds security headers to all responses
2. **LoggingMiddleware** - Logs all requests, responses, and timing
3. **CorsMiddleware** - Handles CORS for cross-origin requests
4. **RateLimitMiddleware** - Limits requests to 100 per minute
5. **AuthMiddleware** - Validates bearer tokens on protected routes
6. **JsonBodyParserMiddleware** - Parses JSON request bodies

## Customizing the Example

### Change the Valid Token

Edit the `validateToken()` function in `index.php`:

```php
function validateToken($token): bool
{
    return $token === 'your-custom-token';
}
```

### Add CORS Origins

Edit the CorsMiddleware configuration:

```php
->use(new CorsMiddleware(
    allowedOrigins: ['http://localhost:3000', 'https://yourdomain.com']
))
```

### Adjust Rate Limits

Edit the RateLimitMiddleware configuration:

```php
->use(new RateLimitMiddleware(
    maxRequests: 200,    // Allow 200 requests
    windowSeconds: 120,  // In 2 minutes
    excludedPaths: ['/health', '/public']
))
```

### Add Custom Middleware

Create a custom middleware class and add it to the router:

```php
use Framework\Http\MiddlewareInterface;
use Framework\ApiResponse;

class CustomMiddleware implements MiddlewareInterface
{
    public function handle(array $request, callable $next): ?ApiResponse
    {
        // Your custom logic here
        return $next($request);
    }
}

// Add to router
$router->use(new CustomMiddleware());
```

## Response Headers

The middleware adds various headers to responses:

### Security Headers (SecurityHeadersMiddleware)
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `X-XSS-Protection: 1; mode=block`
- `Referrer-Policy: strict-origin-when-cross-origin`

### CORS Headers (CorsMiddleware)
- `Access-Control-Allow-Origin`
- `Access-Control-Allow-Methods`
- `Access-Control-Allow-Headers`
- `Access-Control-Allow-Credentials`
- `Access-Control-Max-Age`

### Rate Limit Headers (RateLimitMiddleware)
- `X-RateLimit-Limit: 100`
- `X-RateLimit-Remaining: 95`
- `X-RateLimit-Reset: 1234567890`
- `Retry-After: 60` (when rate limit exceeded)

## Troubleshooting

### 404 Not Found

Make sure you're using the correct URL path and HTTP method.

### 401 Unauthorized

Ensure you're including the `Authorization: Bearer demo-token-12345` header on protected routes.

### 429 Too Many Requests

You've hit the rate limit. Wait for the time window to reset or adjust the rate limit settings.

### CORS Errors

Make sure your origin is in the `allowedOrigins` array in the CorsMiddleware configuration.

## Next Steps

- Read the full [Middleware Documentation](../../docs/MIDDLEWARE.md)
- Create custom middleware for your specific needs
- Integrate with your existing StoneScriptPHP application
- Explore the source code of built-in middleware for implementation details
