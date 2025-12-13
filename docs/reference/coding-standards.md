# StoneScriptPHP Coding Standards

This document defines the coding standards and best practices for StoneScriptPHP projects. Following these standards ensures consistency, maintainability, and quality across all codebases.

## Table of Contents

- [PHP Code Style](#php-code-style)
- [Naming Conventions](#naming-conventions)
- [Project Structure](#project-structure)
- [Database Development](#database-development)
- [Route Handlers](#route-handlers)
- [Error Handling](#error-handling)
- [Comments and Documentation](#comments-and-documentation)
- [Code Organization](#code-organization)

---

## PHP Code Style

### General Guidelines

- **PHP Version**: Use PHP 8.2 or higher
- **Opening Tags**: Always use `<?php` (never short tags)
- **Closing Tags**: Omit closing `?>` tags in pure PHP files
- **Encoding**: Use UTF-8 without BOM
- **Line Endings**: Use Unix line endings (LF)
- **Indentation**: Use 4 spaces (not tabs)
- **Line Length**: Keep lines under 120 characters when possible

### Type Declarations

Always use strict type declarations and type hints:

```php
<?php

namespace App\Routes;

use Framework\ApiResponse;
use Framework\IRouteHandler;

class UserRoute implements IRouteHandler
{
    public string $username;
    public int $age;
    public ?string $bio = null;  // Use nullable types when appropriate

    function validation_rules(): array
    {
        return [
            'username' => 'required|string|min:3',
            'age' => 'required|integer|min:18'
        ];
    }

    function process(): ApiResponse
    {
        // Implementation
        return res_ok(['user' => $this->username]);
    }
}
```

### Braces and Spacing

```php
// Control structures - opening brace on same line
if ($condition) {
    // code
} else {
    // code
}

foreach ($items as $item) {
    // code
}

// Functions - opening brace on same line
function myFunction(): void
{
    // code
}

// Classes - opening brace on new line
class MyClass
{
    // code
}
```

### Arrays

Use short array syntax:

```php
// Good
$routes = ['GET' => [], 'POST' => []];
$user = ['name' => 'John', 'email' => 'john@example.com'];

// Bad
$routes = array('GET' => array());
```

---

## Naming Conventions

### Files and Classes

- **Route Files**: `PascalCaseRoute.php` (e.g., `CreateUserRoute.php`)
- **Function Models**: `FnFunctionName.php` (e.g., `FnGetUserDetails.php`)
- **Middleware**: `PascalCaseMiddleware.php` (e.g., `AuthMiddleware.php`)
- **SQL Files**: `snake_case.pssql` (e.g., `get_user_details.pssql`)

### Classes and Methods

```php
// Class names: PascalCase
class UserAuthenticationRoute implements IRouteHandler
{
    // Public properties: snake_case
    public string $user_email;
    public int $user_id;

    // Methods: snake_case
    function validation_rules(): array
    {
        return [];
    }

    function process(): ApiResponse
    {
        return res_ok([]);
    }

    // Private methods: snake_case with underscore prefix
    private function _validate_credentials(): bool
    {
        return true;
    }
}
```

### Variables

```php
// Variables: snake_case
$user_id = 123;
$user_email = 'user@example.com';
$is_active = true;

// Constants: UPPER_SNAKE_CASE
define('MAX_LOGIN_ATTEMPTS', 5);
const API_VERSION = '1.0';
```

### Database Names

```php
// Tables: snake_case, plural
users
user_profiles
authentication_tokens

// Columns: snake_case
user_id
created_at
email_address

// Functions: snake_case, descriptive verb
fn_get_user_by_id
fn_create_user_profile
fn_update_authentication_token
```

---

## Project Structure

### Standard Directory Layout

```
src/
├── App/
│   ├── Config/
│   │   ├── routes.php
│   │   └── allowed-origins.php
│   ├── Database/
│   │   ├── Functions/
│   │   │   ├── FnGetUser.php
│   │   │   └── FnCreateUser.php
│   │   └── postgres/
│   │       ├── tables/
│   │       │   └── users.pssql
│   │       ├── functions/
│   │       │   ├── fn_get_user.pssql
│   │       │   └── fn_create_user.pssql
│   │       └── seeds/
│   │           └── initial_users.pssql
│   ├── Middleware/
│   │   ├── AuthMiddleware.php
│   │   └── LoggingMiddleware.php
│   ├── Routes/
│   │   ├── HomeRoute.php
│   │   ├── GetUserRoute.php
│   │   └── CreateUserRoute.php
│   └── DTO/
│       ├── UserRequest.php
│       └── UserResponse.php
└── public/
    └── index.php
```

### File Organization

- One class per file
- File name must match class name
- Namespace must match directory structure
- Keep related files together

---

## Database Development

### Function-First Approach

StoneScriptPHP follows a PostgreSQL function-first approach. Always encapsulate business logic in database functions.

#### 1. Define Tables

Create table definitions in `src/App/Database/postgres/tables/`:

```sql
-- users.pssql
CREATE TABLE IF NOT EXISTS users (
    user_id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_username ON users(username);
```

#### 2. Create Database Functions

Create functions in `src/App/Database/postgres/functions/`:

```sql
-- fn_get_user_by_email.pssql
CREATE OR REPLACE FUNCTION fn_get_user_by_email(
    p_email VARCHAR(255)
)
RETURNS TABLE (
    user_id INT,
    username VARCHAR(50),
    email VARCHAR(255),
    created_at TIMESTAMP
)
LANGUAGE plpgsql
STABLE
AS $$
BEGIN
    RETURN QUERY
    SELECT
        u.user_id,
        u.username,
        u.email,
        u.created_at
    FROM users u
    WHERE u.email = p_email;
END;
$$;
```

#### 3. Generate PHP Model

```bash
php stone generate model fn_get_user_by_email.pssql
```

This creates `FnGetUserByEmail.php` in `src/App/Database/Functions/`.

### Database Function Guidelines

- **Prefix functions**: Always prefix with `fn_`
- **Parameters**: Use `p_` prefix for parameters
- **Return types**: Always specify return type (TABLE, VARCHAR, INT, etc.)
- **Stability**: Use `STABLE` for read-only, `VOLATILE` for writes
- **Error handling**: Use `RAISE EXCEPTION` for errors
- **Transactions**: Handle transactions at function level when needed
- **Documentation**: Add comments explaining complex logic

```sql
-- Example with error handling
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
    -- Validate input
    IF p_username IS NULL OR LENGTH(TRIM(p_username)) = 0 THEN
        RAISE EXCEPTION 'Username cannot be empty';
    END IF;

    -- Insert user
    INSERT INTO users (username, email, password_hash)
    VALUES (p_username, p_email, p_password_hash)
    RETURNING user_id INTO v_user_id;

    RETURN v_user_id;
EXCEPTION
    WHEN unique_violation THEN
        RAISE EXCEPTION 'User already exists';
END;
$$;
```

---

## Route Handlers

### Route Handler Structure

All route handlers must implement `IRouteHandler`:

```php
<?php

namespace App\Routes;

use Framework\ApiResponse;
use Framework\IRouteHandler;
use App\Database\Functions\FnGetUserByEmail;

class GetUserRoute implements IRouteHandler
{
    // Public properties for request parameters
    public string $email;

    /**
     * Define validation rules for incoming request
     */
    function validation_rules(): array
    {
        return [
            'email' => 'required|email'
        ];
    }

    /**
     * Process the request and return response
     */
    function process(): ApiResponse
    {
        // Call database function
        $user = FnGetUserByEmail::run($this->email);

        // Check if user exists
        if (empty($user)) {
            return res_not_ok('User not found');
        }

        // Return successful response
        return res_ok(['user' => $user[0]]);
    }
}
```

### Route Handler Guidelines

1. **Keep routes thin**: Business logic belongs in database functions
2. **Use validation**: Always define validation rules for parameters
3. **Type your properties**: Use type hints for all public properties
4. **Error responses**: Use framework helpers (`res_ok`, `res_not_ok`, `e404`, `e400`)
5. **Single responsibility**: One route = one action
6. **No direct SQL**: Always use generated function models

### Response Helpers

```php
// Success response
return res_ok(['data' => $result], 'Operation successful');

// Error response
return res_not_ok('Error message');

// HTTP error responses
return e404('Resource not found');
return e400('Bad request');
return e401('Unauthorized');
return e500('Internal server error');

// Custom ApiResponse
return new ApiResponse('ok', 'Custom message', ['key' => 'value']);
```

---

## Error Handling

### Exception Handling

```php
function process(): ApiResponse
{
    try {
        $result = FnComplexOperation::run($this->param);
        return res_ok(['result' => $result]);
    } catch (\PDOException $e) {
        log_debug('Database error: ' . $e->getMessage());
        return e500('Database operation failed');
    } catch (\Exception $e) {
        log_debug('Unexpected error: ' . $e->getMessage());
        return e500('An unexpected error occurred');
    }
}
```

### Logging

Use the framework logging functions:

```php
// Debug logging (only in DEBUG_MODE)
log_debug('User ID: ' . $user_id);
log_debug('Processing request: ' . json_encode($data));

// Always check DEBUG_MODE for detailed error messages
if (DEBUG_MODE) {
    return e400('Validation failed: ' . $detailed_message);
} else {
    return e400('Validation failed');
}
```

### Error Response Standards

- **Development**: Return detailed error messages when `DEBUG_MODE` is true
- **Production**: Return generic error messages, log details
- **Never expose**: Database structure, internal paths, stack traces in production
- **Log everything**: All errors should be logged with context

---

## Comments and Documentation

### When to Comment

```php
// Good - Complex business logic explained
function process(): ApiResponse
{
    // Check if user has exceeded daily API limit
    // Limit resets at midnight UTC
    $requests_today = FnGetUserRequestCount::run(
        $this->user_id,
        date('Y-m-d')
    );

    if ($requests_today >= MAX_DAILY_REQUESTS) {
        return e429('Daily API limit exceeded');
    }

    // Process request...
}

// Bad - Obvious comment
function process(): ApiResponse
{
    // Get user by ID
    $user = FnGetUserById::run($this->user_id);
}
```

### PHPDoc

Use PHPDoc for public APIs and complex functions:

```php
/**
 * Authenticates user and generates JWT token
 *
 * @param string $email User email address
 * @param string $password Plain text password
 * @return array{token: string, expires_at: int}|null
 * @throws \Exception When authentication service is unavailable
 */
function authenticate(string $email, string $password): ?array
{
    // Implementation
}
```

### Documentation Standards

- Document public APIs thoroughly
- Explain the "why", not the "what"
- Keep comments up-to-date with code changes
- Use inline comments for complex algorithms
- Avoid redundant comments

---

## Code Organization

### Middleware

Create reusable middleware for cross-cutting concerns:

```php
<?php

namespace App\Middleware;

use Framework\ApiResponse;

class AuthMiddleware
{
    /**
     * Verify JWT token and extract user info
     */
    public static function handle(): ?ApiResponse
    {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (empty($auth_header)) {
            return e401('Missing authorization header');
        }

        // Verify token...

        return null; // Return null to continue processing
    }
}
```

### Data Transfer Objects (DTOs)

Use DTOs for complex request/response structures:

```php
<?php

namespace App\DTO;

class UserResponse
{
    public int $user_id;
    public string $username;
    public string $email;
    public string $created_at;

    public static function fromDbRow(array $row): self
    {
        $dto = new self();
        $dto->user_id = $row['user_id'];
        $dto->username = $row['username'];
        $dto->email = $row['email'];
        $dto->created_at = $row['created_at'];
        return $dto;
    }

    public function toArray(): array
    {
        return [
            'user_id' => $this->user_id,
            'username' => $this->username,
            'email' => $this->email,
            'created_at' => $this->created_at
        ];
    }
}
```

### Configuration

Keep configuration in dedicated files:

```php
// src/App/Config/routes.php
return [
    'GET' => [
        '/' => HomeRoute::class,
        '/users' => GetUsersRoute::class,
        '/users/profile' => GetUserProfileRoute::class,
    ],
    'POST' => [
        '/users' => CreateUserRoute::class,
        '/users/login' => LoginRoute::class,
    ]
];
```

---

## Testing

### Test Structure

```php
<?php

use PHPUnit\Framework\TestCase;
use App\Database\Functions\FnGetUserByEmail;

class UserFunctionTest extends TestCase
{
    public function testGetUserByEmailReturnsUser(): void
    {
        $email = 'test@example.com';
        $result = FnGetUserByEmail::run($email);

        $this->assertNotEmpty($result);
        $this->assertEquals($email, $result[0]['email']);
    }

    public function testGetUserByEmailReturnsEmptyForNonexistent(): void
    {
        $result = FnGetUserByEmail::run('nonexistent@example.com');
        $this->assertEmpty($result);
    }
}
```

### Testing Guidelines

- Write tests for all database functions
- Test both success and failure cases
- Use descriptive test names
- Keep tests isolated and independent
- Mock external dependencies
- Run tests before committing: `php stone test`

---

## Version Control

### Git Commit Messages

```
feat: Add user authentication route
fix: Correct email validation in CreateUserRoute
docs: Update API reference for user endpoints
refactor: Simplify database connection handling
test: Add tests for user creation flow
```

### What to Commit

- ✅ Source code files
- ✅ Configuration templates
- ✅ Database migration files
- ✅ Tests
- ✅ Documentation

### What NOT to Commit

- ❌ `.env` files
- ❌ JWT keypairs (`.pem`, `.pub` files)
- ❌ `vendor/` directory
- ❌ Database dumps with real data
- ❌ IDE-specific files
- ❌ Log files

---

## Performance Considerations

### Database Queries

```php
// Good - Use database functions with proper indexes
$users = FnGetActiveUsers::run();

// Bad - Never construct raw SQL in routes
// $users = $db->query("SELECT * FROM users WHERE active = true");
```

### Caching

```php
// Consider caching for expensive operations
function process(): ApiResponse
{
    $cache_key = "user_stats_{$this->user_id}";

    // Check cache first
    $cached = apcu_fetch($cache_key, $success);
    if ($success) {
        return res_ok(['stats' => $cached]);
    }

    // Compute and cache
    $stats = FnGetUserStats::run($this->user_id);
    apcu_store($cache_key, $stats, 300); // Cache for 5 minutes

    return res_ok(['stats' => $stats]);
}
```

### Resource Management

- Close database connections when done
- Limit result set sizes with pagination
- Use indexes on frequently queried columns
- Avoid N+1 queries (use JOINs in SQL functions)
- Profile slow endpoints and optimize

---

## Release Management

### Release Documentation Policy

StoneScriptPHP follows a **two-file release documentation system**:

#### 1. Root RELEASE.md (Current/Next Release)
**Location:** `/RELEASE.md`
**Purpose:** Roadmap for the next release
**Rules:**
- ✅ Maximum **20 lines** total
- ✅ Contains **only the upcoming release** (e.g., v1.3.0)
- ✅ Lists planned features with status (✅ done, ⏳ in progress)
- ✅ Known issues with workarounds
- ✅ Link to `docs/releases.md` for history

**Example:**
```markdown
# Release 1.3.0 Roadmap

**Target Date:** December 15, 2025
**Status:** Planning

## Planned Features
1. ✅ Fix 34 failing unit tests
2. ⏳ Redis rate limiting
3. ⏳ Health endpoint
...
```

#### 2. docs/releases.md (Complete History)
**Location:** `/docs/releases.md`
**Purpose:** Historical changelog of all releases
**Rules:**
- ✅ Maximum **10 lines per release** (excluding headers)
- ✅ One section per release, newest first
- ✅ Bullet points only, no verbose explanations
- ✅ Focus on user-facing changes

**Example:**
```markdown
## Release 1.2.0
**Release Date:** December 7, 2025

- ✅ Enhanced logging with PSR-3 compatibility
- ✅ Global exception handler
- ✅ Structured JSON logging
...
```

### Release Workflow

1. **During Development:**
   - Update `RELEASE.md` with planned features
   - Mark items as ✅ when completed

2. **Upon Release:**
   - Move release summary from `RELEASE.md` to `docs/releases.md`
   - Condense to max 10 lines for historical record
   - Update `RELEASE.md` with next version roadmap

3. **Version Numbering:**
   - Follow [Semantic Versioning](https://semver.org/): `MAJOR.MINOR.PATCH`
   - Major: Breaking changes
   - Minor: New features, backward compatible
   - Patch: Bug fixes, backward compatible

---

## Summary

Following these coding standards ensures:

- ✅ **Consistency** across the codebase
- ✅ **Maintainability** for future developers
- ✅ **Performance** through best practices
- ✅ **Security** by following secure patterns
- ✅ **Quality** through testing and validation

For more information, see:
- [API Design Guidelines](api-design-guidelines.md)
- [Security Best Practices](security-best-practices.md)
- [Performance Guidelines](performance-guidelines.md)
- [Release History](releases.md)
