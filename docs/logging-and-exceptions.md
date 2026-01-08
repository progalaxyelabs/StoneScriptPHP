# Logging and Exception Handling

StoneScriptPHP includes a robust, production-ready logging and exception handling system with multiple output channels and PSR-3 compatible log levels.

## Table of Contents

1. [Logging System](#logging-system)
2. [Log Levels](#log-levels)
3. [Logging to Console and File](#logging-to-console-and-file)
4. [Structured Logging](#structured-logging)
5. [Exception Handling](#exception-handling)
6. [Custom Exceptions](#custom-exceptions)
7. [Best Practices](#best-practices)

---

## Logging System

The `Framework\Logger` class provides a singleton logger that outputs to both console (STDOUT/STDERR) and log files simultaneously.

### Important: Web vs CLI Context

**The logger automatically detects web contexts (PHP-FPM, Apache, Nginx) and disables console output to prevent interfering with HTTP headers.**

In web contexts, console output writes to the HTTP response body stream **before** headers are sent, which breaks CORS and other critical headers. The logger now:

- ✅ **CLI context** (PHP_SAPI = 'cli'): Console logging enabled by default
- ✅ **Web context** (PHP-FPM, Apache, etc.): Console logging auto-disabled
- ✅ **Explicit configuration**: You can still override with `configure(console: false)`

**For web entry points (public/index.php), you don't need to configure anything - it just works.**

### Basic Usage

```php
// Simple message logging
log_debug('User login attempt');
log_info('User logged in successfully');
log_warning('High memory usage detected');
log_error('Database connection failed');
log_critical('Payment gateway down');
```

### Logging with Context

```php
// Add contextual information
log_info('User logged in', [
    'user_id' => 123,
    'ip' => '192.168.1.1',
    'user_agent' => 'Mozilla/5.0'
]);

log_error('Payment failed', [
    'order_id' => 456,
    'amount' => 99.99,
    'currency' => 'USD',
    'error_code' => 'INSUFFICIENT_FUNDS'
]);
```

---

## Log Levels

StoneScriptPHP supports PSR-3 compatible log levels:

| Level | Function | Use Case | Console Output |
|-------|----------|----------|----------------|
| **DEBUG** | `log_debug()` | Detailed debugging information | DEBUG_MODE only |
| **INFO** | `log_info()` | Informational messages | DEBUG_MODE only |
| **NOTICE** | `log_notice()` | Normal but significant events | DEBUG_MODE only |
| **WARNING** | `log_warning()` | Warning conditions | Always |
| **ERROR** | `log_error()` | Error conditions | Always |
| **CRITICAL** | `log_critical()` | Critical conditions | Always |
| **ALERT** | `log_alert()` | Action must be taken immediately | Always |
| **EMERGENCY** | `log_emergency()` | System is unusable | Always |

### Level Selection Guidelines

```php
// DEBUG: Detailed information for debugging
log_debug('SQL query executed', ['query' => $sql, 'params' => $params]);

// INFO: Routine application events
log_info('Email sent to user', ['email' => $user_email]);

// NOTICE: Unusual but not error conditions
log_notice('User uploaded large file', ['size_mb' => 50]);

// WARNING: Use of deprecated APIs, poor performance
log_warning('API rate limit approaching', ['usage' => '80%']);

// ERROR: Runtime errors that don't require immediate action
log_error('Failed to send email', ['recipient' => $email, 'error' => $e->getMessage()]);

// CRITICAL: Critical conditions (component unavailable)
log_critical('Database connection lost', ['host' => $db_host]);

// ALERT: Action must be taken immediately
log_alert('Disk space critically low', ['available' => '5%']);

// EMERGENCY: System is unusable
log_emergency('Application crashed', ['error' => $fatal_error]);
```

---

## Logging to Console and File

### Console Output

**Development (DEBUG_MODE = true):**
- All log levels output to console with colors
- DEBUG, INFO, NOTICE → STDOUT (standard output)
- WARNING, ERROR, CRITICAL, ALERT, EMERGENCY → STDERR (error output)

**Production (DEBUG_MODE = false):**
- Only WARNING and above output to console
- Reduces console noise in production

### File Output

All logs are written to `logs/YYYY-MM-DD.log`:

```
[2025-12-05 17:45:23.123456] DEBUG     User login attempt
[2025-12-05 17:45:23.456789] INFO      User logged in successfully {"user_id":123}
[2025-12-05 17:45:24.789012] ERROR     Database connection failed {"host":"localhost","error":"Connection refused"}
```

### Color Coding (Console)

When running in console, logs are color-coded for easy reading:

- 🔴 **EMERGENCY**: Red background
- 🟣 **ALERT**: Bright magenta
- 🔴 **CRITICAL**: Bright red
- 🔴 **ERROR**: Red
- 🟡 **WARNING**: Yellow
- 🔵 **NOTICE**: Cyan
- 🟢 **INFO**: Green
- ⚪ **DEBUG**: White

---

## Structured Logging

### JSON Log Format (Optional)

Enable JSON logging for structured log aggregation (ELK, Splunk, Datadog):

```php
// Configure logger for JSON output
Logger::get_instance()->configure(
    console: true,
    file: true,
    json: true  // Enable JSON logs
);
```

This creates `logs/YYYY-MM-DD.json.log` with structured entries:

```json
{
  "timestamp": "2025-12-05 17:45:23.123456",
  "level": "ERROR",
  "message": "Database connection failed",
  "context": {
    "host": "localhost",
    "error": "Connection refused"
  },
  "memory": 12582912,
  "pid": 12345
}
```

### Custom Log Directory

You can customize the log directory for different execution contexts:

```php
// Method 1: Via configure() method (recommended)
Logger::get_instance()->configure(
    console: false,
    file: true,
    json: false,
    log_directory: '/var/log/stonescriptphp-cli'
);

// Method 2: Via environment variable
// Set in Dockerfile or docker-compose.yml:
ENV STONESCRIPTPHP_LOG_DIR=/var/log/stonescriptphp
```

**Priority order:**
1. Custom directory from `configure()` method
2. `STONESCRIPTPHP_LOG_DIR` environment variable
3. `/var/log/stonescriptphp` in Docker environments
4. `ROOT_PATH/logs` (default)

### HTTP Request Logging

Log HTTP requests automatically:

```php
// In your route handler or middleware
$start_time = microtime(true);

// ... process request ...

$duration = (microtime(true) - $start_time) * 1000;
log_request('POST', '/api/users', 201, $duration);
```

Output:
```
[2025-12-05 18:00:00.000000] INFO      POST /api/users {
  "status_code": 201,
  "duration_ms": 45.23,
  "ip": "192.168.1.1",
  "user_agent": "Mozilla/5.0..."
}
```

---

## Exception Handling

StoneScriptPHP includes a global exception handler that catches all uncaught exceptions and errors.

### Automatic Exception Logging

All uncaught exceptions are automatically logged:

```php
// This exception will be caught and logged
throw new \Exception('Something went wrong');

// Output in logs:
// [2025-12-05 18:00:00] CRITICAL  Something went wrong {
//   "exception_class": "Exception",
//   "code": 0,
//   "file": "/path/to/file.php",
//   "line": 42,
//   "trace": "..."
// }
```

### Exception Response Format

Exceptions are converted to JSON API responses:

**Development (DEBUG_MODE = true):**
```json
{
  "status": "error",
  "message": "Database connection failed",
  "data": null,
  "debug": {
    "exception": "Framework\\DatabaseException",
    "message": "Database connection failed",
    "code": 500,
    "file": "/path/to/Database.php",
    "line": 123,
    "trace": [
      {
        "file": "/path/to/Router.php",
        "line": 45,
        "function": "Framework\\Database::connect"
      }
    ],
    "context": {
      "host": "localhost",
      "port": 5432
    }
  }
}
```

**Production (DEBUG_MODE = false):**
```json
{
  "status": "error",
  "message": "An error occurred",
  "data": null
}
```

---

## Custom Exceptions

StoneScriptPHP provides a hierarchy of custom exceptions:

### Framework Exceptions

```php
use Framework\{
    BadRequestException,
    UnauthorizedException,
    ForbiddenException,
    InvalidRouteException,
    ValidationException,
    RateLimitException,
    InternalServerErrorException,
    ServiceUnavailableException,
    DatabaseException,
    ConfigurationException,
    CacheException,
    StorageException
};
```

### Using Custom Exceptions

```php
// 400 Bad Request
if (empty($data)) {
    throw new BadRequestException('Request data is required');
}

// 401 Unauthorized
if (!$auth_token) {
    throw new UnauthorizedException('Authentication required');
}

// 403 Forbidden
if (!$user->can('delete_posts')) {
    throw new ForbiddenException('You do not have permission');
}

// 404 Not Found
if (!$user) {
    throw new InvalidRouteException('User not found');
}

// 422 Validation Error
if (!$validator->validate()) {
    throw new ValidationException($validator->errors());
}

// 429 Rate Limit
if ($rate_limiter->exceeded()) {
    throw new RateLimitException('Too many requests');
}

// 500 Internal Server Error
if (!$database->connect()) {
    throw new DatabaseException('Database connection failed', [
        'host' => $host,
        'port' => $port
    ]);
}
```

### Exceptions with Context

Add contextual information to exceptions:

```php
throw new DatabaseException('Query failed', [
    'query' => $sql,
    'params' => $params,
    'error' => $e->getMessage()
]);

// This context is included in logs and debug output
```

### HTTP Status Codes

All custom exceptions automatically set the correct HTTP status code:

| Exception | HTTP Status |
|-----------|-------------|
| BadRequestException | 400 |
| UnauthorizedException | 401 |
| ForbiddenException | 403 |
| InvalidRouteException | 404 |
| ValidationException | 422 |
| RateLimitException | 429 |
| InternalServerErrorException | 500 |
| ServiceUnavailableException | 503 |

---

## Best Practices

### 1. Use Appropriate Log Levels

```php
// ❌ DON'T: Use ERROR for everything
log_error('User clicked button');

// ✅ DO: Use appropriate levels
log_debug('User clicked button');
log_info('Order placed', ['order_id' => $id]);
log_warning('Slow query detected', ['duration' => 5.2]);
log_error('Payment gateway timeout');
```

### 2. Always Add Context

```php
// ❌ DON'T: Log without context
log_error('Failed to process payment');

// ✅ DO: Include relevant details
log_error('Failed to process payment', [
    'order_id' => $order_id,
    'amount' => $amount,
    'gateway' => 'stripe',
    'error' => $e->getMessage()
]);
```

### 3. Log at Boundaries

```php
// Log when entering/exiting important operations
log_info('Starting backup process');
try {
    performBackup();
    log_info('Backup completed successfully');
} catch (Exception $e) {
    log_error('Backup failed', ['error' => $e->getMessage()]);
    throw $e;
}
```

### 4. Don't Log Sensitive Data

```php
// ❌ DON'T: Log passwords, tokens, credit cards
log_debug('User login', [
    'password' => $password  // NEVER DO THIS
]);

// ✅ DO: Redact sensitive information
log_debug('User login', [
    'email' => $email,
    'password' => '[REDACTED]'
]);
```

### 5. Use Exceptions for Exceptional Cases

```php
// ❌ DON'T: Use exceptions for control flow
try {
    $user = User::find($id);
    if (!$user) {
        throw new Exception('User not found');
    }
} catch (Exception $e) {
    return null;
}

// ✅ DO: Return null for expected cases
$user = User::find($id);
if (!$user) {
    return null;
}

// ✅ DO: Throw exceptions for truly exceptional conditions
if (!$database->connect()) {
    throw new DatabaseException('Cannot connect to database');
}
```

### 6. Catch and Re-throw with Context

```php
try {
    $result = ExternalAPI::call($params);
} catch (Exception $e) {
    // Add context and re-throw
    throw new InternalServerErrorException(
        'External API call failed',
        ['api' => 'payments', 'error' => $e->getMessage()]
    );
}
```

### 7. Log Configuration Changes

```php
log_notice('Configuration updated', [
    'setting' => 'rate_limit',
    'old_value' => 100,
    'new_value' => 200,
    'updated_by' => $admin_id
]);
```

### 8. Performance Monitoring

```php
$start = microtime(true);
$result = expensive_operation();
$duration = (microtime(true) - $start) * 1000;

if ($duration > 1000) {
    log_warning('Slow operation detected', [
        'operation' => 'expensive_operation',
        'duration_ms' => $duration
    ]);
}
```

---

## Docker Deployment Best Practices

### The Problem: Multi-User Contexts

In Docker environments, you often have:
- **Entrypoint scripts** running as `root` (migrations, initialization)
- **Web server** running as `www-data` (PHP-FPM, Apache)
- **Background jobs** running as various users

This creates permission conflicts when different users try to write to the same log files.

### Solution 1: Separate Log Directories (Recommended)

Use different log directories for different execution contexts:

**Dockerfile:**
```dockerfile
# Create separate log directories with proper permissions
RUN mkdir -p /var/log/stonescriptphp && \
    chown www-data:www-data /var/log/stonescriptphp && \
    chmod 775 /var/log/stonescriptphp

RUN mkdir -p /var/log/stonescriptphp-cli && \
    chmod 777 /var/log/stonescriptphp-cli
```

**CLI scripts (migrations, etc.):**
```php
// In cli/migrate.php or entrypoint scripts
Logger::get_instance()->configure(
    console: true,                              // OK in CLI
    file: true,
    log_directory: '/var/log/stonescriptphp-cli'  // Separate directory
);
```

**Web entry point (public/index.php):**
```php
// No configuration needed - auto-detects web context
// Uses /var/log/stonescriptphp by default in Docker
```

### Solution 2: Environment Variables

Use environment variables for flexible configuration:

**docker-compose.yml:**
```yaml
services:
  api:
    environment:
      STONESCRIPTPHP_LOG_DIR: /var/log/stonescriptphp
      DEBUG_MODE: "false"
```

**Dockerfile entrypoint:**
```bash
#!/bin/bash

# For CLI/migration context, use separate log directory
export STONESCRIPTPHP_LOG_DIR=/var/log/stonescriptphp-cli
php stone migrate

# For web context, unset to use default
unset STONESCRIPTPHP_LOG_DIR
exec php-fpm
```

### Solution 3: Fix Permissions in Entrypoint

**docker-entrypoint.sh:**
```bash
#!/bin/bash

# Ensure log directories exist with proper permissions
mkdir -p /var/log/stonescriptphp
chown -R www-data:www-data /var/log/stonescriptphp
chmod -R 775 /var/log/stonescriptphp

# Run migrations as root
php stone migrate

# Fix ownership of any log files created during startup
chown -R www-data:www-data /var/log/stonescriptphp/*.log 2>/dev/null || true

# Start web server as www-data
exec gosu www-data php-fpm
```

### Graceful Failure Handling

The logger now handles permission errors gracefully:
- **Web context**: Fails silently - no warnings that would break HTTP headers
- **CLI context**: Logs error to `error_log()` for debugging
- **No application crashes** due to logging failures

This means even if permissions are wrong, your application continues to work.

---

## Production Configuration

### Environment Variables

```ini
# .env
DEBUG_MODE=false          # Disable debug output in production
TIMEZONE=America/New_York # Set your timezone
STONESCRIPTPHP_LOG_DIR=/custom/log/path  # Optional: custom log directory
```

### Log Rotation

Implement log rotation to prevent disk space issues:

```bash
# Add to crontab for daily log rotation
0 0 * * * find /path/to/logs -name "*.log" -mtime +30 -delete
```

Or use logrotate:

```
# /etc/logrotate.d/stonescriptphp
/path/to/logs/*.log {
    daily
    rotate 30
    compress
    delaycompress
    notifempty
    missingok
    create 0640 www-data www-data
}
```

---

## Integration with Error Reporting Services

The ExceptionHandler includes a `reportException()` method for future integration:

```php
// Framework/ExceptionHandler.php
private function reportException(Throwable $exception): void
{
    // TODO: Integrate with:
    // - Sentry: Sentry\captureException($exception);
    // - Rollbar: Rollbar\report_exception($exception);
    // - Bugsnag: Bugsnag\report($exception);
    // - New Relic: newrelic_notice_error($exception);
}
```

---

## Examples

### Complete Route with Logging

```php
class CreateUserRoute implements IRouteHandler
{
    public function process(): ApiResponse
    {
        log_info('Create user request received');

        try {
            $input = request_body();

            log_debug('Validating user input', ['email' => $input['email']]);

            $validator = new Validator($input, [
                'email' => 'required|email',
                'name' => 'required|string'
            ]);

            if (!$validator->validate()) {
                log_warning('User validation failed', [
                    'errors' => $validator->errors()
                ]);
                throw new ValidationException($validator->errors());
            }

            $user = User::create($input);

            log_info('User created successfully', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);

            return res_ok(['user' => $user], 'User created');

        } catch (ValidationException $e) {
            throw $e; // Re-throw to be handled by global handler

        } catch (Exception $e) {
            log_error('User creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new InternalServerErrorException('Failed to create user');
        }
    }
}
```

---

## Troubleshooting

### Logs Not Appearing in Console

- Check `DEBUG_MODE` in `.env`
- Verify log level (only WARNING+ appear in production)

### Logs Not Writing to File

- Check `logs/` directory permissions: `chmod 755 logs`
- Verify disk space: `df -h`

### JSON Logs Not Created

- Ensure JSON logging is enabled:
  ```php
  Logger::get_instance()->configure(console: true, file: true, json: true);
  ```

---

## Summary

✅ **PSR-3 compatible** log levels
✅ **Dual output** to console and file
✅ **Colorized** console output
✅ **Structured** logging with context
✅ **Global exception** handling
✅ **Custom exceptions** with HTTP status codes
✅ **Production-ready** with debug/production modes
✅ **Thread-safe** file writes with LOCK_EX

StoneScriptPHP's logging and exception handling system gives you the visibility and control needed for production applications.
