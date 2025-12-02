# Security Best Practices

This document outlines security best practices for building secure applications with StoneScriptPHP. Security must be a primary concern in all phases of development.

## Table of Contents

- [OWASP Top 10 Protection](#owasp-top-10-protection)
- [Input Validation and Sanitization](#input-validation-and-sanitization)
- [Authentication Security](#authentication-security)
- [Authorization and Access Control](#authorization-and-access-control)
- [SQL Injection Prevention](#sql-injection-prevention)
- [XSS Prevention](#xss-prevention)
- [CSRF Protection](#csrf-protection)
- [Secure Configuration](#secure-configuration)
- [Data Protection](#data-protection)
- [Error Handling and Logging](#error-handling-and-logging)
- [API Security](#api-security)
- [Dependency Management](#dependency-management)
- [Security Testing](#security-testing)

---

## OWASP Top 10 Protection

StoneScriptPHP applications should protect against all OWASP Top 10 vulnerabilities:

1. **Broken Access Control** - Implement proper authorization
2. **Cryptographic Failures** - Use strong encryption
3. **Injection** - Validate and sanitize all inputs
4. **Insecure Design** - Design security from the start
5. **Security Misconfiguration** - Secure all configurations
6. **Vulnerable Components** - Keep dependencies updated
7. **Authentication Failures** - Implement strong authentication
8. **Data Integrity Failures** - Validate data integrity
9. **Logging Failures** - Log security events properly
10. **SSRF** - Validate external requests

---

## Input Validation and Sanitization

### Always Validate All Inputs

Never trust user input. Validate everything:

```php
class CreateUserRoute implements IRouteHandler
{
    public string $username;
    public string $email;
    public string $password;

    function validation_rules(): array
    {
        return [
            // Require all fields
            'username' => 'required|string|min:3|max:50',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8|max:128',
        ];
    }

    function process(): ApiResponse
    {
        // Additional sanitization
        $username = trim($this->username);
        $email = strtolower(trim($this->email));

        // Validate username format (alphanumeric, underscore, hyphen only)
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
            return e400('Username can only contain letters, numbers, underscores and hyphens');
        }

        // Hash password before storage
        $password_hash = password_hash($this->password, PASSWORD_ARGON2ID);

        $user_id = FnCreateUser::run($username, $email, $password_hash);

        return res_ok(['user_id' => $user_id]);
    }
}
```

### Whitelist, Don't Blacklist

```php
// Good - Whitelist allowed values
function validation_rules(): array
{
    return [
        'role' => 'required|in:user,admin,moderator',
        'status' => 'required|in:active,inactive,pending'
    ];
}

// Bad - Trying to blacklist dangerous values
// This approach always misses edge cases
```

### Validate Data Types

```php
function validation_rules(): array
{
    return [
        // Enforce data types
        'age' => 'required|integer|min:0|max:150',
        'price' => 'required|numeric|min:0',
        'is_active' => 'required|boolean',
        'tags' => 'array',
        'email' => 'required|email',
        'url' => 'url',

        // Complex patterns
        'phone' => 'regex:/^\+?[1-9]\d{1,14}$/',
        'zip_code' => 'regex:/^\d{5}(-\d{4})?$/',
    ];
}
```

### Sanitize Output

```php
function process(): ApiResponse
{
    $user_bio = FnGetUserBio::run($this->user_id);

    // If returning data that will be rendered in HTML, sanitize it
    // However, since StoneScriptPHP returns JSON, the frontend should
    // handle HTML escaping. Never render user content as HTML without escaping.

    return res_ok([
        'bio' => $user_bio  // Frontend must escape before rendering
    ]);
}
```

---

## Authentication Security

### Password Security

```php
class LoginRoute implements IRouteHandler
{
    public string $email;
    public string $password;

    function validation_rules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|string'
        ];
    }

    function process(): ApiResponse
    {
        $email = strtolower(trim($this->email));

        // Get user from database
        $user = FnGetUserByEmail::run($email);

        if (empty($user)) {
            // Don't reveal whether email exists
            return e401('Invalid credentials');
        }

        $user_data = $user[0];

        // Verify password using password_verify
        if (!password_verify($this->password, $user_data['password_hash'])) {
            // Log failed attempt
            log_debug("Failed login attempt for email: $email");

            // Increment failed login counter
            FnIncrementFailedLogins::run($user_data['user_id']);

            return e401('Invalid credentials');
        }

        // Check if account is locked
        if ($user_data['failed_logins'] >= 5) {
            return e401('Account locked due to multiple failed login attempts');
        }

        // Reset failed login counter on success
        FnResetFailedLogins::run($user_data['user_id']);

        // Generate JWT token
        $token = JWT::encode([
            'user_id' => $user_data['user_id'],
            'email' => $user_data['email'],
            'exp' => time() + 3600  // 1 hour expiration
        ], JWT_PRIVATE_KEY);

        return res_ok([
            'token' => $token,
            'expires_at' => time() + 3600
        ]);
    }
}
```

### Password Hashing

Always use strong password hashing:

```php
// Good - Use Argon2id (most secure)
$hash = password_hash($password, PASSWORD_ARGON2ID);

// Acceptable - Use bcrypt if Argon2id not available
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

// Bad - Never use MD5, SHA1, or plain SHA256
// $hash = md5($password);  // NEVER DO THIS
// $hash = sha1($password); // NEVER DO THIS
```

### Password Requirements

Enforce strong password policies:

```php
namespace App\Validators;

class PasswordValidator
{
    /**
     * Validate password strength
     * - At least 8 characters
     * - At least 1 uppercase letter
     * - At least 1 lowercase letter
     * - At least 1 number
     * - At least 1 special character
     */
    public static function strong_password(string $password): bool
    {
        if (strlen($password) < 8) {
            return false;
        }

        $has_uppercase = preg_match('/[A-Z]/', $password);
        $has_lowercase = preg_match('/[a-z]/', $password);
        $has_number = preg_match('/\d/', $password);
        $has_special = preg_match('/[@$!%*?&#]/', $password);

        return $has_uppercase && $has_lowercase && $has_number && $has_special;
    }

    /**
     * Check if password is in common password list
     */
    public static function not_common_password(string $password): bool
    {
        $common_passwords = [
            'password', 'password123', '12345678', 'qwerty',
            'abc123', 'monkey', '1234567', 'letmein'
        ];

        return !in_array(strtolower($password), $common_passwords);
    }
}
```

### JWT Token Security

```php
namespace App\Utils;

class JWT
{
    /**
     * Generate JWT token with secure claims
     */
    public static function generate(int $user_id, string $email): string
    {
        $payload = [
            'user_id' => $user_id,
            'email' => $email,
            'iat' => time(),                    // Issued at
            'exp' => time() + 3600,             // Expires in 1 hour
            'nbf' => time(),                    // Not valid before
            'jti' => bin2hex(random_bytes(16))  // Unique token ID
        ];

        return self::encode($payload, JWT_PRIVATE_KEY);
    }

    /**
     * Verify and decode JWT token
     */
    public static function verify(string $token): ?array
    {
        try {
            $decoded = self::decode($token, JWT_PUBLIC_KEY);

            // Verify expiration
            if ($decoded['exp'] < time()) {
                log_debug('JWT token expired');
                return null;
            }

            // Verify not-before
            if ($decoded['nbf'] > time()) {
                log_debug('JWT token not yet valid');
                return null;
            }

            return $decoded;

        } catch (\Exception $e) {
            log_debug('JWT verification failed: ' . $e->getMessage());
            return null;
        }
    }
}
```

### Session Management

If using sessions (though JWT is preferred):

```php
// Secure session configuration
ini_set('session.cookie_httponly', 1);  // Prevent JavaScript access
ini_set('session.cookie_secure', 1);    // HTTPS only
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_lifetime', 0);  // Session cookie

// Regenerate session ID on login
session_regenerate_id(true);
```

---

## Authorization and Access Control

### Role-Based Access Control (RBAC)

```php
class AdminOnlyRoute implements IRouteHandler
{
    function process(): ApiResponse
    {
        // Authenticate user
        $token_data = AuthMiddleware::verify();
        if ($token_data === null) {
            return e401('Authentication required');
        }

        $user_id = $token_data['user_id'];

        // Check user role
        $user_role = FnGetUserRole::run($user_id);

        if ($user_role !== 'admin') {
            log_debug("Unauthorized access attempt by user $user_id to admin endpoint");
            return e403('Admin access required');
        }

        // Proceed with admin action
        return res_ok(['message' => 'Admin action completed']);
    }
}
```

### Resource-Based Access Control

```php
class UpdateUserProfileRoute implements IRouteHandler
{
    public int $user_id;
    public array $profile_data;

    function validation_rules(): array
    {
        return [
            'user_id' => 'required|integer',
            'profile_data' => 'required|array'
        ];
    }

    function process(): ApiResponse
    {
        // Authenticate
        $token_data = AuthMiddleware::verify();
        if ($token_data === null) {
            return e401('Authentication required');
        }

        $authenticated_user_id = $token_data['user_id'];

        // Check if user can update this profile
        // Users can only update their own profile unless they're admin
        if ($authenticated_user_id !== $this->user_id) {
            $user_role = FnGetUserRole::run($authenticated_user_id);

            if ($user_role !== 'admin') {
                log_debug("User $authenticated_user_id attempted to update profile of user {$this->user_id}");
                return e403('You can only update your own profile');
            }
        }

        // Update profile
        FnUpdateUserProfile::run($this->user_id, $this->profile_data);

        return res_ok(['message' => 'Profile updated successfully']);
    }
}
```

### Prevent Insecure Direct Object References (IDOR)

```php
class GetOrderRoute implements IRouteHandler
{
    public int $order_id;

    function validation_rules(): array
    {
        return [
            'order_id' => 'required|integer'
        ];
    }

    function process(): ApiResponse
    {
        // Authenticate user
        $token_data = AuthMiddleware::verify();
        if ($token_data === null) {
            return e401('Authentication required');
        }

        $user_id = $token_data['user_id'];

        // Get order with ownership check
        $order = FnGetOrderByIdAndUserId::run($this->order_id, $user_id);

        if (empty($order)) {
            // Don't reveal whether order exists
            return e404('Order not found');
        }

        return res_ok(['order' => $order[0]]);
    }
}
```

---

## SQL Injection Prevention

### Use Database Functions (Parameterized Queries)

StoneScriptPHP's function-first approach prevents SQL injection:

```sql
-- fn_get_user_by_email.pssql
-- Good - Parameterized function
CREATE OR REPLACE FUNCTION fn_get_user_by_email(
    p_email VARCHAR(255)
)
RETURNS TABLE (
    user_id INT,
    username VARCHAR(50),
    email VARCHAR(255)
)
LANGUAGE plpgsql
STABLE
AS $$
BEGIN
    RETURN QUERY
    SELECT u.user_id, u.username, u.email
    FROM users u
    WHERE u.email = p_email;  -- Parameter binding prevents injection
END;
$$;
```

```php
// Good - Using database function
$user = FnGetUserByEmail::run($email);

// Bad - Never construct raw SQL in PHP
// $query = "SELECT * FROM users WHERE email = '$email'";  // VULNERABLE!
// $result = $db->query($query);
```

### Additional SQL Security

```sql
-- Validate input in database functions
CREATE OR REPLACE FUNCTION fn_create_user(
    p_username VARCHAR(50),
    p_email VARCHAR(255),
    p_password_hash VARCHAR(255)
)
RETURNS INT
LANGUAGE plpgsql
AS $$
DECLARE
    v_user_id INT;
BEGIN
    -- Validate inputs
    IF p_username IS NULL OR LENGTH(TRIM(p_username)) = 0 THEN
        RAISE EXCEPTION 'Username cannot be empty';
    END IF;

    IF p_email !~ '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$' THEN
        RAISE EXCEPTION 'Invalid email format';
    END IF;

    -- Use parameterized insert
    INSERT INTO users (username, email, password_hash)
    VALUES (p_username, p_email, p_password_hash)
    RETURNING user_id INTO v_user_id;

    RETURN v_user_id;
END;
$$;
```

---

## XSS Prevention

### Output Encoding

Since StoneScriptPHP returns JSON, XSS prevention happens on the frontend:

```php
function process(): ApiResponse
{
    $user_comment = FnGetUserComment::run($this->comment_id);

    // Return raw data as JSON
    // Frontend MUST escape before rendering in HTML
    return res_ok([
        'comment' => $user_comment  // Contains user input
    ]);
}
```

Frontend must escape:
```javascript
// Good - Escape before inserting into DOM
element.textContent = data.comment;  // Safe

// Bad - Direct HTML insertion
// element.innerHTML = data.comment;  // VULNERABLE to XSS!
```

### Content Security Policy

Add CSP headers for additional protection:

```php
// In bootstrap.php or route processing
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
```

---

## CSRF Protection

### CSRF Tokens

Implement CSRF protection for state-changing operations:

```php
class GenerateCSRFTokenRoute implements IRouteHandler
{
    function process(): ApiResponse
    {
        // Generate CSRF token
        $token = bin2hex(random_bytes(32));

        // Store in session or return to client
        // Client must include this in subsequent requests

        return res_ok(['csrf_token' => $token]);
    }
}

class StateChangingRoute implements IRouteHandler
{
    public string $csrf_token;
    public array $data;

    function validation_rules(): array
    {
        return [
            'csrf_token' => 'required|string',
            'data' => 'required|array'
        ];
    }

    function process(): ApiResponse
    {
        // Verify CSRF token
        $expected_token = $_SESSION['csrf_token'] ?? '';

        if (!hash_equals($expected_token, $this->csrf_token)) {
            log_debug('CSRF token mismatch');
            return e403('Invalid CSRF token');
        }

        // Process request...
        return res_ok(['success' => true]);
    }
}
```

### SameSite Cookies

```php
// Set SameSite cookie attribute
setcookie(
    'session_id',
    $session_id,
    [
        'expires' => time() + 3600,
        'path' => '/',
        'domain' => 'yourdomain.com',
        'secure' => true,      // HTTPS only
        'httponly' => true,    // Not accessible via JavaScript
        'samesite' => 'Strict' // CSRF protection
    ]
);
```

---

## Secure Configuration

### Environment Variables

Never hardcode secrets:

```php
// Good - Use environment variables
$db_password = Env::get('DB_PASSWORD');
$jwt_secret = Env::get('JWT_PRIVATE_KEY');
$api_key = Env::get('THIRD_PARTY_API_KEY');

// Bad - Hardcoded secrets
// $db_password = 'mypassword123';  // NEVER DO THIS
```

### Secure .env File

```bash
# .env file should never be committed to version control
# Add to .gitignore

# Database credentials
DB_HOST=localhost
DB_PORT=5432
DB_NAME=myapp_db
DB_USER=myapp_user
DB_PASSWORD=strong_random_password_here

# JWT keys (use generate-openssl-keypair.sh)
JWT_PRIVATE_KEY=/path/to/private.pem
JWT_PUBLIC_KEY=/path/to/public.pem

# API keys
GOOGLE_CLIENT_ID=your_client_id
GOOGLE_CLIENT_SECRET=your_client_secret

# Security settings
DEBUG_MODE=false
ALLOWED_ORIGINS=https://yourdomain.com,https://app.yourdomain.com
```

### File Permissions

```bash
# Protect sensitive files
chmod 600 .env                          # Only owner can read/write
chmod 600 stone-script-php-jwt.pem      # Private key
chmod 644 stone-script-php-jwt.pub      # Public key (readable)
chmod 755 public/                       # Web root
chmod 600 logs/*.log                    # Log files
```

### Debug Mode

```php
// .env
DEBUG_MODE=false  // Always false in production

// In code
if (DEBUG_MODE) {
    return e500('Detailed error: ' . $error_message);
} else {
    return e500('An error occurred');
}
```

---

## Data Protection

### Encryption at Rest

```php
/**
 * Encrypt sensitive data before storage
 */
function encryptData(string $data, string $key): string
{
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt(
        $data,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    // Return IV + tag + encrypted data
    return base64_encode($iv . $tag . $encrypted);
}

/**
 * Decrypt sensitive data
 */
function decryptData(string $encrypted, string $key): ?string
{
    $decoded = base64_decode($encrypted);

    $iv = substr($decoded, 0, 16);
    $tag = substr($decoded, 16, 16);
    $ciphertext = substr($decoded, 32);

    $decrypted = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    return $decrypted !== false ? $decrypted : null;
}
```

### Encryption in Transit

Always use HTTPS in production:

```php
// Redirect HTTP to HTTPS
if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
    if (DEBUG_MODE === false) {
        $redirect_url = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        header('Location: ' . $redirect_url, true, 301);
        exit;
    }
}
```

### Secure Data Transmission

```php
// Add security headers
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
```

### Personally Identifiable Information (PII)

```php
class GetUserDataRoute implements IRouteHandler
{
    function process(): ApiResponse
    {
        $token_data = AuthMiddleware::verify();
        if ($token_data === null) {
            return e401('Authentication required');
        }

        $user_id = $token_data['user_id'];
        $user = FnGetUserData::run($user_id);

        // Mask sensitive data in logs
        log_debug('User data retrieved for user: ' . $user_id);
        // Don't log: log_debug('User email: ' . $user['email']);

        // Return only necessary data
        return res_ok([
            'user_id' => $user['user_id'],
            'username' => $user['username'],
            'email' => $user['email'],
            // Don't return: password_hash, security questions, etc.
        ]);
    }
}
```

---

## Error Handling and Logging

### Secure Error Messages

```php
function process(): ApiResponse
{
    try {
        $result = FnComplexOperation::run($this->param);
        return res_ok(['result' => $result]);

    } catch (\PDOException $e) {
        // Log full error
        log_debug('Database error: ' . $e->getMessage());
        log_debug('Stack trace: ' . $e->getTraceAsString());

        // Return safe error to client
        if (DEBUG_MODE) {
            return e500('Database error: ' . $e->getMessage());
        } else {
            return e500('A database error occurred');
        }
    }
}
```

### Security Event Logging

```php
// Log security events
function logSecurityEvent(string $event, array $context): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

    $message = sprintf(
        '[SECURITY] %s | IP: %s | User-Agent: %s | Context: %s',
        $event,
        $ip,
        $user_agent,
        json_encode($context)
    );

    log_debug($message);

    // Also write to dedicated security log
    error_log($message, 3, LOGS_PATH . '/security.log');
}

// Usage
logSecurityEvent('Failed login attempt', [
    'email' => $email,
    'timestamp' => time()
]);

logSecurityEvent('Unauthorized access attempt', [
    'user_id' => $user_id,
    'endpoint' => $_SERVER['REQUEST_URI'],
    'timestamp' => time()
]);
```

### Log Sanitization

```php
function sanitizeForLog(string $message): string
{
    // Remove potentially sensitive data from logs
    $message = preg_replace('/password["\']?\s*[:=]\s*["\']?[^"\'&\s]+/', 'password=***', $message);
    $message = preg_replace('/token["\']?\s*[:=]\s*["\']?[^"\'&\s]+/', 'token=***', $message);
    $message = preg_replace('/api_key["\']?\s*[:=]\s*["\']?[^"\'&\s]+/', 'api_key=***', $message);

    return $message;
}
```

---

## API Security

### Rate Limiting

See [API Design Guidelines](api-design-guidelines.md#rate-limiting) for implementation.

### API Key Management

```php
class ApiKeyAuthMiddleware
{
    public static function verify(): ?ApiResponse
    {
        $api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';

        if (empty($api_key)) {
            return e401('API key required');
        }

        // Verify API key
        $key_data = FnVerifyApiKey::run($api_key);

        if (empty($key_data)) {
            logSecurityEvent('Invalid API key used', ['key' => substr($api_key, 0, 8) . '...']);
            return e401('Invalid API key');
        }

        // Check rate limit for this API key
        $rate_limit_result = RateLimitMiddleware::check('api_key:' . $api_key);
        if ($rate_limit_result !== null) {
            return $rate_limit_result;
        }

        // Store API key data for route handler
        $_SERVER['API_KEY_DATA'] = $key_data;

        return null;
    }
}
```

### CORS Security

```php
// In allowed-origins.php
return [
    'https://yourdomain.com',
    'https://app.yourdomain.com',
    // Don't use wildcard in production:
    // '*'  // INSECURE
];

// Framework handles CORS in Router.php
// Only allows origins from allowed-origins.php
```

---

## Dependency Management

### Keep Dependencies Updated

```bash
# Regularly update dependencies
composer update

# Check for security vulnerabilities
composer audit

# Review composer.lock for changes
git diff composer.lock
```

### Dependency Verification

```php
// Verify critical dependencies are present
if (!function_exists('password_hash')) {
    die('Required PHP password functions not available');
}

if (!extension_loaded('openssl')) {
    die('OpenSSL extension required for security features');
}

if (!extension_loaded('pdo_pgsql')) {
    die('PostgreSQL PDO extension required');
}
```

---

## Security Testing

### Test Checklist

- [ ] Test authentication with invalid credentials
- [ ] Test authorization bypass attempts
- [ ] Test SQL injection on all inputs
- [ ] Test XSS on all text inputs
- [ ] Test CSRF protection
- [ ] Test rate limiting
- [ ] Test with malformed JSON
- [ ] Test with extremely large payloads
- [ ] Test with special characters in inputs
- [ ] Test password reset flow
- [ ] Test session expiration
- [ ] Test concurrent requests

### Security Test Examples

```php
use PHPUnit\Framework\TestCase;

class SecurityTest extends TestCase
{
    public function testSQLInjectionPrevention(): void
    {
        $malicious_input = "'; DROP TABLE users; --";

        $result = FnGetUserByEmail::run($malicious_input);

        // Should return empty, not cause SQL error
        $this->assertEmpty($result);
    }

    public function testAuthenticationRequired(): void
    {
        // Attempt to access protected endpoint without auth
        $_SERVER['HTTP_AUTHORIZATION'] = '';

        $route = new ProtectedRoute();
        $response = $route->process();

        $this->assertEquals('error', $response->status);
        $this->assertEquals(401, http_response_code());
    }

    public function testAuthorizationEnforced(): void
    {
        // User trying to access admin-only endpoint
        $regular_user_token = $this->generateUserToken('user');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $regular_user_token;

        $route = new AdminOnlyRoute();
        $response = $route->process();

        $this->assertEquals('error', $response->status);
        $this->assertEquals(403, http_response_code());
    }

    public function testPasswordHashingIsSecure(): void
    {
        $password = 'TestPassword123!';
        $hash = password_hash($password, PASSWORD_ARGON2ID);

        // Verify it's not plain text
        $this->assertNotEquals($password, $hash);

        // Verify it can be verified
        $this->assertTrue(password_verify($password, $hash));
    }

    public function testRateLimiting(): void
    {
        $identifier = 'test_user';

        // Make requests up to limit
        for ($i = 0; $i < 100; $i++) {
            $result = RateLimitMiddleware::check($identifier);
            $this->assertNull($result);
        }

        // Next request should be rate limited
        $result = RateLimitMiddleware::check($identifier);
        $this->assertNotNull($result);
        $this->assertEquals('error', $result->status);
    }
}
```

---

## Security Incident Response

### Incident Detection

Monitor logs for suspicious activity:

```bash
# Check for failed login attempts
grep "Failed login attempt" logs/security.log | tail -20

# Check for unauthorized access attempts
grep "Unauthorized access" logs/security.log | tail -20

# Check for unusual patterns
grep "SECURITY" logs/*.log | grep -v "INFO" | tail -50
```

### Incident Response Plan

1. **Detect**: Monitor logs and alerts
2. **Contain**: Lock compromised accounts, block IPs if needed
3. **Investigate**: Determine scope and root cause
4. **Remediate**: Fix vulnerability, update code
5. **Recover**: Restore services, reset credentials if needed
6. **Review**: Post-incident analysis and prevention

---

## Security Best Practices Checklist

### Development

- [ ] Use latest PHP version (8.2+)
- [ ] Enable strict type checking
- [ ] Validate all inputs
- [ ] Sanitize all outputs
- [ ] Use parameterized database functions
- [ ] Hash passwords with Argon2id or bcrypt
- [ ] Implement proper authentication
- [ ] Implement proper authorization
- [ ] Use HTTPS in production
- [ ] Keep dependencies updated

### Configuration

- [ ] Set DEBUG_MODE=false in production
- [ ] Secure .env file (chmod 600)
- [ ] Protect JWT keys (chmod 600)
- [ ] Configure CORS properly
- [ ] Set secure session settings
- [ ] Add security headers
- [ ] Configure CSP
- [ ] Set file permissions correctly

### Operations

- [ ] Monitor security logs
- [ ] Implement rate limiting
- [ ] Use WAF (Web Application Firewall)
- [ ] Regular security audits
- [ ] Penetration testing
- [ ] Dependency scanning
- [ ] Code security reviews
- [ ] Incident response plan

### Data Protection

- [ ] Encrypt sensitive data at rest
- [ ] Use HTTPS for data in transit
- [ ] Minimize PII collection
- [ ] Implement data retention policies
- [ ] Secure backups
- [ ] GDPR compliance (if applicable)
- [ ] Regular data audits

---

## Related Documentation

- [Coding Standards](coding-standards.md)
- [API Design Guidelines](api-design-guidelines.md)
- [Performance Guidelines](performance-guidelines.md)
- [Validation Guide](validation.md)
- [Middleware Guide](MIDDLEWARE.md)

---

## Additional Resources

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Security Guide](https://www.php.net/manual/en/security.php)
- [PostgreSQL Security](https://www.postgresql.org/docs/current/security.html)
- [JWT Best Practices](https://tools.ietf.org/html/rfc8725)
