# Getting Started with StoneScriptPHP

Welcome to StoneScriptPHP! This guide will walk you through everything you need to build your first API, from installation to deployment.

## Table of Contents

1. [Installation](#installation)
2. [Project Structure Overview](#project-structure-overview)
3. [First API Endpoint](#first-api-endpoint)
4. [Database Setup](#database-setup)
5. [SQL Functions → PHP Models](#sql-functions--php-models)
6. [Routes and URL Mapping](#routes-and-url-mapping)
7. [Authentication](#authentication)
8. [Testing](#testing)
9. [Deployment](#deployment)
10. [Next Steps](#next-steps)

## Installation

### Requirements

Before you begin, ensure you have:

- **PHP >= 8.2** with extensions: `pdo`, `pgsql`, `openssl`
- **PostgreSQL >= 13**
- **Composer** (latest version recommended)
- **Git** (optional, but recommended)

### Create a New Project

StoneScriptPHP uses Composer to scaffold new projects, similar to Angular's `ng new`:

```bash
# Create new project
composer create-project progalaxyelabs/stonescriptphp my-api

# Navigate to your project
cd my-api
```

### Interactive Setup

After project creation, the setup wizard runs automatically. If you need to run it again:

```bash
php stone setup
```

The setup wizard will:

1. **Configure PostgreSQL connection**
   - Database host (default: localhost)
   - Port (default: 5432)
   - Database name
   - Username and password

2. **Generate JWT keypair**
   - Creates RSA keypair for JWT token signing
   - Saves keys securely in project root

3. **Create .env file**
   - Generates environment configuration
   - Sets secure defaults

4. **Initialize database**
   - Creates tables from schema
   - Applies initial migrations (optional)

### Verify Installation

Start the development server:

```bash
php stone serve
```

Visit `http://localhost:9100` in your browser. You should see a welcome message confirming your API is running.

## Project Structure Overview

StoneScriptPHP follows a clean, organized structure:

```
my-api/
├── Framework/              # Framework core (don't modify)
│   ├── cli/               # CLI command implementations
│   ├── Http/              # HTTP request/response handling
│   ├── Routing/           # Router implementation
│   ├── DI/                # Dependency injection
│   └── Oauth/             # OAuth providers (Google, etc.)
├── src/
│   ├── App/               # Your application code
│   │   ├── Routes/        # Route handler classes
│   │   ├── Models/        # Business logic models
│   │   └── Lib/           # Utility libraries
│   ├── config/            # Application configuration
│   │   ├── routes.php     # URL → Route class mapping
│   │   └── allowed_origins.php  # CORS settings
│   ├── Framework/         # Framework overrides (if needed)
│   └── postgresql/        # Database definitions
│       ├── tables/        # Table schemas (.pssql files)
│       ├── functions/     # SQL functions (.pssql files)
│       └── seeds/         # Seed data scripts (.pssql files)
├── tests/                 # PHPUnit tests
├── public/                # Public web root
│   └── index.php          # Application entry point
├── docs/                  # Documentation
├── .env                   # Environment variables (never commit)
├── stone                  # CLI tool
└── composer.json          # Dependencies
```

### Key Directories

- **src/App/Routes/**: Your API endpoint handlers (controllers)
- **src/postgresql/**: All database-related code (schema, functions, seeds)
- **src/config/**: Application configuration (routes, CORS, etc.)
- **Framework/**: Core framework code (read-only)

## First API Endpoint

Let's create a simple "Hello World" API endpoint to understand the workflow.

### Step 1: Generate a Route Handler

```bash
php stone generate route hello-world
```

This creates `src/App/Routes/HelloWorldRoute.php`:

```php
<?php

namespace App\Routes;

use Framework\Http\Request;
use Framework\Http\ApiResponse;

class HelloWorldRoute
{
    public function process(Request $request)
    {
        // Your logic here
        return new ApiResponse('ok', 'Success', [
            'message' => 'Hello, World!'
        ]);
    }
}
```

### Step 2: Register the Route

Open `src/config/routes.php` and add your route:

```php
<?php

use App\Routes\HelloWorldRoute;

return [
    'GET' => [
        '/hello' => HelloWorldRoute::class,
    ],
    'POST' => [
        // POST routes here
    ],
    'PUT' => [
        // PUT routes here
    ],
    'DELETE' => [
        // DELETE routes here
    ],
];
```

### Step 3: Test Your Endpoint

Start the server:

```bash
php stone serve
```

Test with curl:

```bash
curl http://localhost:9100/hello
```

Response:

```json
{
  "status": "ok",
  "message": "Success",
  "data": {
    "message": "Hello, World!"
  }
}
```

### Understanding the Response Format

StoneScriptPHP uses a consistent API response structure:

```php
new ApiResponse(
    'ok',                    // Status: 'ok' or 'error'
    'Success message',       // Human-readable message
    ['key' => 'value']       // Data payload
);
```

## Database Setup

StoneScriptPHP is PostgreSQL-first. All database code lives in `.pssql` files.

### Step 1: Configure Database Connection

Your `.env` file contains database settings:

```ini
DATABASE_HOST=localhost
DATABASE_PORT=5432
DATABASE_USER=myuser
DATABASE_PASSWORD=mypassword
DATABASE_DBNAME=my_api_db
DATABASE_SSLMODE=prefer
DATABASE_TIMEOUT=30
DATABASE_APPNAME=StoneScriptPHP
```

Update these values to match your PostgreSQL setup.

### Step 2: Create Database Tables

Create a table schema file in `src/postgresql/tables/users.pssql`:

```sql
-- Table: users
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255),
    google_id VARCHAR(255) UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Index for fast email lookups
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);

-- Index for Google OAuth
CREATE INDEX IF NOT EXISTS idx_users_google_id ON users(google_id);
```

### Step 3: Apply Database Schema

Run the migration verification:

```bash
php stone migrate verify
```

This checks if your database matches your `.pssql` files and reports any drift.

To apply tables manually during development, you can use:

```bash
# Using psql
psql -h localhost -U myuser -d my_api_db -f src/postgresql/tables/users.pssql
```

### Step 4: Add Seed Data (Optional)

Create `src/postgresql/seeds/demo_users.pssql`:

```sql
-- Seed data: demo users
INSERT INTO users (email, name, password_hash) VALUES
    ('demo@example.com', 'Demo User', '$2y$10$dummyhash'),
    ('admin@example.com', 'Admin User', '$2y$10$dummyhash')
ON CONFLICT (email) DO NOTHING;
```

Apply seeds:

```bash
psql -h localhost -U myuser -d my_api_db -f src/postgresql/seeds/demo_users.pssql
```

## SQL Functions → PHP Models

StoneScriptPHP's unique approach: write business logic as PostgreSQL functions, then generate PHP wrapper classes.

### Why SQL Functions?

- **Performance**: Logic executes close to data
- **Consistency**: Same logic for all clients
- **Testability**: Test functions directly in PostgreSQL
- **Type Safety**: PostgreSQL's strong typing

### Step 1: Create a SQL Function

Create `src/postgresql/functions/get_user_by_email.pssql`:

```sql
-- Function: get_user_by_email
-- Description: Fetch user details by email address
-- Returns: Single user record or NULL

CREATE OR REPLACE FUNCTION get_user_by_email(
    p_email VARCHAR
)
RETURNS TABLE (
    id INTEGER,
    email VARCHAR,
    name VARCHAR,
    google_id VARCHAR,
    created_at TIMESTAMP
) AS $$
BEGIN
    RETURN QUERY
    SELECT
        u.id,
        u.email,
        u.name,
        u.google_id,
        u.created_at
    FROM users u
    WHERE u.email = p_email;
END;
$$ LANGUAGE plpgsql;
```

Apply it to your database:

```bash
psql -h localhost -U myuser -d my_api_db -f src/postgresql/functions/get_user_by_email.pssql
```

### Step 2: Generate PHP Model

```bash
php stone generate model get_user_by_email.pssql
```

This creates `src/App/Models/FnGetUserByEmail.php`:

```php
<?php

namespace App\Models;

use Framework\Database\PostgreSQL;

class FnGetUserByEmail
{
    /**
     * Execute get_user_by_email function
     *
     * @param string $p_email
     * @return array|null User record or null if not found
     */
    public static function run(string $p_email): ?array
    {
        $db = PostgreSQL::getInstance();
        $result = $db->query(
            'SELECT * FROM get_user_by_email($1)',
            [$p_email]
        );

        return $result[0] ?? null;
    }
}
```

### Step 3: Use the Model in a Route

Update a route to use your model:

```php
<?php

namespace App\Routes;

use App\Models\FnGetUserByEmail;
use Framework\Http\Request;
use Framework\Http\ApiResponse;

class GetUserRoute
{
    public function process(Request $request)
    {
        $email = $request->query['email'] ?? null;

        if (!$email) {
            return new ApiResponse('error', 'Email parameter required', null);
        }

        $user = FnGetUserByEmail::run($email);

        if (!$user) {
            return new ApiResponse('error', 'User not found', null);
        }

        return new ApiResponse('ok', 'User found', $user);
    }
}
```

### Best Practices for SQL Functions

1. **One function per file**: `function_name.pssql`
2. **Add comments**: Describe parameters and return values
3. **Use RETURNS TABLE**: Easier to parse and generate models
4. **Test in pgAdmin first**: Verify logic before generating models
5. **Use parameters**: Prefix with `p_` (e.g., `p_email`)

## Routes and URL Mapping

Routes connect HTTP requests to your handler classes.

### Route Configuration File

All routes are defined in `src/config/routes.php`:

```php
<?php

use App\Routes\HelloWorldRoute;
use App\Routes\GetUserRoute;
use App\Routes\CreateUserRoute;
use App\Routes\UpdateUserRoute;
use App\Routes\DeleteUserRoute;

return [
    'GET' => [
        '/hello' => HelloWorldRoute::class,
        '/user' => GetUserRoute::class,
    ],
    'POST' => [
        '/user' => CreateUserRoute::class,
    ],
    'PUT' => [
        '/user' => UpdateUserRoute::class,
    ],
    'DELETE' => [
        '/user' => DeleteUserRoute::class,
    ],
];
```

### URL Patterns

Routes support:

- **Static paths**: `/users`, `/products`
- **Path segments**: `/user/{id}`, `/posts/{slug}`
- **Query parameters**: `/search?q=term` (accessed via `$request->query`)

### Accessing Request Data

In your route handler:

```php
public function process(Request $request)
{
    // Path parameters (from URL segments)
    $id = $request->params['id'] ?? null;

    // Query parameters (?key=value)
    $search = $request->query['search'] ?? null;

    // POST/PUT body (JSON)
    $data = $request->body;

    // Headers
    $authHeader = $request->headers['Authorization'] ?? null;

    // HTTP method
    $method = $request->method; // GET, POST, PUT, DELETE
}
```

### Example: RESTful User API

```php
// src/config/routes.php
return [
    'GET' => [
        '/users' => ListUsersRoute::class,           // List all users
        '/users/{id}' => GetUserRoute::class,        // Get single user
    ],
    'POST' => [
        '/users' => CreateUserRoute::class,          // Create user
    ],
    'PUT' => [
        '/users/{id}' => UpdateUserRoute::class,     // Update user
    ],
    'DELETE' => [
        '/users/{id}' => DeleteUserRoute::class,     // Delete user
    ],
];
```

### CORS Configuration

Configure allowed origins in `src/config/allowed_origins.php`:

```php
<?php

return [
    'http://localhost:4200',        // Angular dev server
    'http://localhost:3000',        // React dev server
    'https://myapp.com',            // Production frontend
];
```

## Authentication

StoneScriptPHP includes built-in JWT authentication and Google OAuth support.

### JWT Authentication

#### Step 1: Generate JWT Keypair

During setup, keys are auto-generated. To regenerate manually:

```bash
bash scripts/generate-openssl-keypair.sh
```

This creates:
- `stone-script-php-jwt.pem` (private key)
- `stone-script-php-jwt.pub` (public key)

#### Step 2: Issue JWT Tokens

Create a login route:

```php
<?php

namespace App\Routes;

use App\Models\FnGetUserByEmail;
use Framework\Http\Request;
use Framework\Http\ApiResponse;
use Framework\Security\JWT;

class LoginRoute
{
    public function process(Request $request)
    {
        $email = $request->body['email'] ?? null;
        $password = $request->body['password'] ?? null;

        // Validate credentials
        $user = FnGetUserByEmail::run($email);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return new ApiResponse('error', 'Invalid credentials', null);
        }

        // Generate JWT token
        $payload = [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'exp' => time() + (60 * 60 * 24 * 7), // 7 days
        ];

        $token = JWT::encode($payload);

        return new ApiResponse('ok', 'Login successful', [
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name'],
            ],
        ]);
    }
}
```

#### Step 3: Protect Routes with JWT

Create a middleware or validate tokens in routes:

```php
<?php

namespace App\Routes;

use Framework\Http\Request;
use Framework\Http\ApiResponse;
use Framework\Security\JWT;

class ProtectedRoute
{
    public function process(Request $request)
    {
        // Extract token from Authorization header
        $authHeader = $request->headers['Authorization'] ?? '';

        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return new ApiResponse('error', 'No token provided', null);
        }

        $token = $matches[1];

        // Verify and decode token
        try {
            $payload = JWT::decode($token);
            $userId = $payload['user_id'];

            // Your protected logic here
            return new ApiResponse('ok', 'Access granted', [
                'user_id' => $userId,
                'data' => 'Protected data',
            ]);

        } catch (\Exception $e) {
            return new ApiResponse('error', 'Invalid token', null);
        }
    }
}
```

### Google OAuth

#### Step 1: Configure Google OAuth

Add to your `.env`:

```ini
GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI=http://localhost:9100/auth/google/callback
```

#### Step 2: Create OAuth Routes

```php
<?php

namespace App\Routes;

use Framework\Http\Request;
use Framework\Http\ApiResponse;
use Framework\Oauth\Google;

class GoogleLoginRoute
{
    public function process(Request $request)
    {
        // Redirect to Google
        $google = new Google();
        $authUrl = $google->getAuthUrl();

        header('Location: ' . $authUrl);
        exit;
    }
}
```

```php
<?php

namespace App\Routes;

use Framework\Http\Request;
use Framework\Http\ApiResponse;
use Framework\Oauth\Google;
use App\Models\FnGetUserByEmail;
use App\Models\FnCreateUserFromGoogle;

class GoogleCallbackRoute
{
    public function process(Request $request)
    {
        $code = $request->query['code'] ?? null;

        if (!$code) {
            return new ApiResponse('error', 'No authorization code', null);
        }

        $google = new Google();
        $userInfo = $google->getUserInfo($code);

        // Find or create user
        $user = FnGetUserByEmail::run($userInfo['email']);

        if (!$user) {
            $user = FnCreateUserFromGoogle::run(
                $userInfo['email'],
                $userInfo['name'],
                $userInfo['id']
            );
        }

        // Generate JWT
        $token = JWT::encode([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'exp' => time() + (60 * 60 * 24 * 7),
        ]);

        return new ApiResponse('ok', 'Login successful', [
            'token' => $token,
            'user' => $user,
        ]);
    }
}
```

## Testing

StoneScriptPHP uses PHPUnit for testing.

### Running Tests

```bash
# Run all tests
php stone test

# Or use composer
composer test

# Run specific test file
php stone test tests/Unit/UserTest.php

# Run with coverage
php stone test --coverage
```

### Writing Tests

Create a test in `tests/Unit/UserTest.php`:

```php
<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Models\FnGetUserByEmail;

class UserTest extends TestCase
{
    public function testGetUserByEmail()
    {
        $user = FnGetUserByEmail::run('demo@example.com');

        $this->assertNotNull($user);
        $this->assertEquals('demo@example.com', $user['email']);
        $this->assertArrayHasKey('name', $user);
    }

    public function testGetNonexistentUser()
    {
        $user = FnGetUserByEmail::run('nonexistent@example.com');

        $this->assertNull($user);
    }
}
```

### Testing Routes

Create `tests/Feature/ApiTest.php`:

```php
<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use App\Routes\HelloWorldRoute;
use Framework\Http\Request;

class ApiTest extends TestCase
{
    public function testHelloWorldRoute()
    {
        $request = new Request('GET', '/hello', [], [], '');
        $route = new HelloWorldRoute();

        $response = $route->process($request);

        $this->assertEquals('ok', $response->status);
        $this->assertArrayHasKey('message', $response->data);
    }
}
```

### Test Database Setup

Use a separate test database:

```ini
# .env.testing
DATABASE_DBNAME=my_api_test
```

In your tests:

```php
protected function setUp(): void
{
    parent::setUp();

    // Load test environment
    putenv('DATABASE_DBNAME=my_api_test');

    // Run migrations
    // Seed test data
}
```

## Deployment

### Production Checklist

1. **Environment Configuration**
   ```bash
   # Generate production .env
   php stone env
   ```

   Set production values:
   ```ini
   DATABASE_HOST=production-db.example.com
   DATABASE_DBNAME=production_db
   DATABASE_USER=prod_user
   DATABASE_PASSWORD=secure-password
   ```

2. **Database Migration**
   ```bash
   # Verify schema matches
   php stone migrate verify

   # Apply all .pssql files
   find src/postgresql/tables -name "*.pssql" -exec psql -h $DB_HOST -U $DB_USER -d $DB_NAME -f {} \;
   find src/postgresql/functions -name "*.pssql" -exec psql -h $DB_HOST -U $DB_USER -d $DB_NAME -f {} \;
   ```

3. **Install Dependencies**
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

4. **Set Permissions**
   ```bash
   chmod 600 .env
   chmod 600 stone-script-php-jwt.pem
   chmod 644 stone-script-php-jwt.pub
   ```

### Deployment Options

#### Option 1: Traditional PHP Server

**Nginx + PHP-FPM:**

```nginx
server {
    listen 80;
    server_name api.example.com;
    root /var/www/my-api/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

#### Option 2: Docker

Create `Dockerfile`:

```dockerfile
FROM php:8.2-apache

# Install PostgreSQL extension
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy application
COPY . /var/www/html
WORKDIR /var/www/html

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
```

`docker-compose.yml`:

```yaml
version: '3.8'

services:
  api:
    build: .
    ports:
      - "9100:80"
    environment:
      DATABASE_HOST: db
      DATABASE_PORT: 5432
      DATABASE_USER: api_user
      DATABASE_PASSWORD: secure_password
      DATABASE_DBNAME: api_db
    depends_on:
      - db

  db:
    image: postgres:15
    environment:
      POSTGRES_USER: api_user
      POSTGRES_PASSWORD: secure_password
      POSTGRES_DB: api_db
    volumes:
      - postgres_data:/var/lib/postgresql/data

volumes:
  postgres_data:
```

Deploy:

```bash
docker-compose up -d
```

#### Option 3: Cloud Platforms

**Heroku:**

1. Create `Procfile`:
   ```
   web: vendor/bin/heroku-php-apache2 public/
   ```

2. Deploy:
   ```bash
   heroku create my-api
   heroku addons:create heroku-postgresql:mini
   git push heroku main
   ```

**AWS Elastic Beanstalk / Google Cloud Run / Azure App Service:**

Follow platform-specific PHP deployment guides. Ensure PostgreSQL connection details are set via environment variables.

### Monitoring and Logs

**Application Logs:**

StoneScriptPHP logs to `logs/` directory:

```php
// In your route
error_log('User login: ' . $email, 3, ROOT_PATH . '/logs/app.log');
```

**Database Query Logging:**

Enable PostgreSQL logging in production for performance monitoring.

**Health Check Endpoint:**

Create a `/health` endpoint:

```php
// src/App/Routes/HealthRoute.php
public function process(Request $request)
{
    // Check database connection
    try {
        $db = PostgreSQL::getInstance();
        $result = $db->query('SELECT 1');
        $dbStatus = 'ok';
    } catch (\Exception $e) {
        $dbStatus = 'error';
    }

    return new ApiResponse('ok', 'Health check', [
        'status' => 'online',
        'database' => $dbStatus,
        'timestamp' => time(),
    ]);
}
```

## Next Steps

Congratulations! You've learned the fundamentals of StoneScriptPHP. Here's what to explore next:

### Learn More

1. **[Environment Configuration](environment-configuration.md)** - Deep dive into type-safe environment management
2. **[CLI Usage Guide](../CLI-USAGE.md)** - Master all CLI commands
3. **[Examples](../examples/)** - Study real-world applications
4. **[Official Documentation](https://stonescriptphp.org/docs)** - Comprehensive reference

### Build Your First Real API

Try building:

1. **Todo List API** - CRUD operations, user authentication
2. **Blog API** - Posts, comments, tags, pagination
3. **E-commerce API** - Products, cart, orders, payments

### Advanced Topics

- **Database Migrations** - Version control your database schema
- **Rate Limiting** - Protect your API from abuse
- **Caching** - Redis integration for performance
- **File Uploads** - Handle media uploads
- **Background Jobs** - Async task processing
- **WebSockets** - Real-time features

### Community and Support

- **Website**: [https://stonescriptphp.org](https://stonescriptphp.org)
- **Documentation**: [https://stonescriptphp.org/docs](https://stonescriptphp.org/docs)
- **GitHub Issues**: [https://github.com/progalaxyelabs/StoneScriptPHP/issues](https://github.com/progalaxyelabs/StoneScriptPHP/issues)
- **GitHub Discussions**: Share your projects and get help

### Contributing

StoneScriptPHP is open source! Contributions are welcome:

- Report bugs and request features via GitHub Issues
- Submit pull requests for improvements
- Share your projects built with StoneScriptPHP
- Improve documentation

Happy coding with StoneScriptPHP! 🚀
