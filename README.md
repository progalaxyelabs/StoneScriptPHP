<p align="center">
  <img src="images/logo.svg" width="200" alt="StoneScriptPHP Logo">
</p>

# StoneScriptPHP

[![PHP Tests](https://github.com/progalaxyelabs/StoneScriptPHP/actions/workflows/php-test.yml/badge.svg)](https://github.com/progalaxyelabs/StoneScriptPHP/actions/workflows/php-test.yml)
[![Packagist Version](https://img.shields.io/packagist/v/progalaxyelabs/stonescriptphp)](https://packagist.org/packages/progalaxyelabs/stonescriptphp)
[![License](https://img.shields.io/github/license/progalaxyelabs/StoneScriptPHP)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/progalaxyelabs/stonescriptphp)](composer.json)
[![VS Code Extension](https://img.shields.io/visual-studio-marketplace/v/progalaxyelabs.stonescriptphp-snippets?label=VS%20Code%20Snippets)](https://marketplace.visualstudio.com/items?itemName=progalaxyelabs.stonescriptphp-snippets)
[![Extension Installs](https://img.shields.io/visual-studio-marketplace/i/progalaxyelabs.stonescriptphp-snippets)](https://marketplace.visualstudio.com/items?itemName=progalaxyelabs.stonescriptphp-snippets)

A modern PHP backend framework for building APIs with PostgreSQL, inspired by Angular's developer experience.

**Note:** While this package is published under `progalaxyelabs/stonescriptphp`, the official website and documentation are at https://stonescriptphp.org. A future migration to the `@stonescriptphp` namespace is planned.

---------------------------------------------------------------

## Installation

StoneScriptPHP supports two installation modes:

### Option 1: New Project (Recommended)

Create a standalone project with everything included:

```bash
composer create-project progalaxyelabs/stonescriptphp my-api
cd my-api
```

The setup wizard will:
1. Ask you to choose a starter template (Basic API, Microservice, SaaS Boilerplate)
2. Scaffold your project structure from the selected template
3. Configure database connection
4. Generate JWT keys
5. Run initial migrations

Start development server:

```bash
php stone serve
# Your API is running at http://localhost:9100
```

### Option 2: Add to Existing Project

Add StoneScriptPHP as a dependency to an existing project:

```bash
composer require progalaxyelabs/stonescriptphp
vendor/bin/stone init
```

The init wizard will:
1. Let you choose a starter template or create minimal structure
2. Create project directories (src/App/Routes, Models, Database, etc.)
3. Generate .env configuration
4. Generate JWT keypair
5. Create a `./stone` wrapper for convenience

Then start the server:

```bash
vendor/bin/stone serve
# Or use the wrapper: ./stone serve
```

**New to StoneScriptPHP?** Check out the [Getting Started Guide](docs/getting-started.md) for a complete walkthrough.

## Upgrade Path

Update the framework without affecting your code:

```bash
composer update progalaxyelabs/stonescriptphp
# Your code in src/ stays intact
# Only framework files in vendor/ updatedo
```

Both installation modes keep the framework in `vendor/`, allowing seamless composer-based upgrades. The template-based scaffolding ensures you get a clean git history from day one, with no template pollution.

## Features

### Core Framework
- 🎯 **Angular-like CLI** - `php stone` commands for code generation
- 📝 **PostgreSQL-first** - Built for PostgreSQL with migration system
- 🚀 **Fast Development** - Code generators for routes, models, migrations
- 📦 **Zero Config** - Works out of the box after setup

### Security & Auth
- 🔐 **JWT Authentication** - RSA & HMAC support, built-in OAuth (Google)
- 🛡️ **RBAC** - Role-Based Access Control with permissions
- 🔒 **Security Middleware** - CORS, rate limiting, security headers

### Performance & Monitoring
- ⚡ **Redis Caching** - Cache tags, TTL, automatic invalidation
- 📊 **Production Logging** - PSR-3 compatible, console + file output, colorized
- 🚨 **Exception Handling** - Global exception handler with structured errors
- ✅ **Validation Layer** - Powerful request validation with 12+ built-in rules

### Developer Experience
- 🎨 **Color-Coded Logs** - Beautiful ANSI-colored console output
- 📝 **Comprehensive Docs** - 20+ documentation files (600+ pages)
- ✅ **Testing** - PHPUnit test suite included
- 🔧 **VS Code Extension** - Snippets and IntelliSense

## CLI Commands

### Create-Project Mode
```bash
php stone setup              # Interactive project setup
php stone serve              # Start dev server (port 9100)
php stone generate route <name>   # Generate route handler
php stone generate model <file>   # Generate model from SQL function
php stone migrate verify     # Check database drift
php stone test               # Run PHPUnit tests
php stone env                # Generate .env file
```

### Require Mode
```bash
vendor/bin/stone init        # Initialize project (first time)
vendor/bin/stone serve       # Start dev server
vendor/bin/stone generate route <name>   # Generate route handler
# Or use the wrapper after init:
./stone serve
./stone generate route <name>
```

For detailed CLI usage, see [CLI-USAGE.md](CLI-USAGE.md)

## Workflow

### 1. Define Database Schema

Create all the database tables in individual `.pssql` files in the `src/App/Database/postgres/tables/` folder

### 2. Create SQL Functions

Create all the database queries as SQL functions in individual `.pssql` files in the `src/App/Database/postgres/functions/` folder

If there is seed data, create those as SQL scripts (insert statements) as `.pssql` files in the `src/App/Database/postgres/seeds/` folder

In pgadmin4, develop PostgreSQL functions. Test them and once working, save them as individual files under `src/App/Database/postgres/functions/` folder.

Example: `src/App/Database/postgresql/functions/function_name.pssql`

### 3. Generate PHP Model Class

Use the CLI to generate a PHP class for each SQL function that will help in identifying the function arguments and return values:

```bash
php stone generate model function_name.pssql
```

This will create a `FnFunctionName.php` in `src/App/Database/Functions` folder.

This can be used to call the SQL function from PHP with proper arguments with reasonable typing that PHP allows.

### 4. Create Service Class (Recommended)

For better code organization and testability, create a Service class to contain your business logic:

```php
// src/App/Services/TrophyService.php
namespace App\Services;

use Database\Functions\FnUpdatetrophyDetails;

class TrophyService
{
    /**
     * Update trophy details
     * Pure business logic - no HTTP, no auth
     */
    public function updateTrophyDetails(int $user_trophy_id, int $count): object
    {
        return FnUpdatetrophyDetails::run($user_trophy_id, $count);
    }
}
```

**Why Service Layer?**
- **Testability**: Business logic can be tested without HTTP/auth concerns
- **Reusability**: Same logic can be used from multiple routes
- **Separation of Concerns**: Routes handle HTTP (validation, auth, cookies), Services handle business logic

### 5. Create API Route

```bash
php stone generate route update-trophies
```

This will create a `UpdateTrophiesRoute.php` file in `Routes` folder.

### 6. Create URL to Class Route Mapping

In `src/App/Config/routes.php`, add a URL-to-class route mapping.

Example: For adding a POST route, add the line in the `POST` section:

```php
return [
    ...
    'POST' => [
         ...
        '/update-trophies' => UpdateTrophiesRoute::class
        ...
    ]
    ...
];
```

### 7. Implement the Route Class's Process Function

In `UpdateTrophiesRoute.php`, in the `process` function, delegate to the service layer:

**With Service Layer (Recommended):**

```php
class UpdateTrophiesRoute implements IRouteHandler
{
    private TrophyService $trophyService;

    public function __construct()
    {
        $this->trophyService = new TrophyService();
    }

    public function validation_rules(): array
    {
        return [
            'user_trophy_id' => 'required|integer',
            'count' => 'required|integer',
        ];
    }

    public function process(): ApiResponse
    {
        // Extract and validate input
        $input = request_body();

        // Authenticate user (if needed)
        $user_id = auth_user_id(); // Returns null if not authenticated

        // Call service (pure business logic)
        $data = $this->trophyService->updateTrophyDetails(
            $input['user_trophy_id'],
            $input['count']
        );

        // Handle HTTP response (could add cookies, headers, etc. here)
        return res_ok(['course' => $data]);
    }
}
```

**Without Service Layer (Simple cases):**

For very simple routes with no business logic, you can call database functions directly:

```php
public function process(): ApiResponse
{
    $input = request_body();

    $data = FnUpdatetrophyDetails::run(
        $input['user_trophy_id'],
        $input['count']
    );

    return res_ok(['course' => $data]);
}
```

### 8. Run Migrations

```bash
php stone migrate verify
```

This checks for database drift and ensures your database schema matches your source code.

## OAuth Support

StoneScriptPHP includes built-in Google OAuth support:

- `Framework/Oauth/Google.php` - Google OAuth handler
- Automatic JWT token generation
- Secure keypair management

## Testing

Run the test suite:

```bash
php stone test

# Or use composer
composer test
```

## Requirements

- PHP >= 8.2
- PostgreSQL >= 13
- Redis (optional, for caching)
- Composer
- Extensions: `pdo`, `pgsql`, `redis`, `openssl`

## Documentation

### 📖 Main Documentation
- **[📑 Documentation Index](docs/INDEX.md)** - Complete documentation with website-style navigation
- **[🏗️ High Level Design (HLD)](HLD.md)** - System architecture and design patterns
- **[📋 Release Notes](RELEASE.md)** - Version history and changelog

### 🚀 Getting Started
- [Getting Started Guide](docs/getting-started.md) - Complete tutorial from installation to deployment
- [CLI Usage Guide](CLI-USAGE.md) - Command reference for `php stone`
- [Environment Configuration](docs/environment-configuration.md) - Type-safe environment setup

### 🔧 Core Features
- [API Reference](docs/api-reference.md) - Complete API documentation with examples
- [Logging & Exceptions](docs/logging-and-exceptions.md) - **NEW** Production-ready logging system
- [Request Validation](docs/validation.md) - Validation rules and usage guide
- [Middleware Guide](docs/MIDDLEWARE.md) - Middleware system and custom middleware

### 🔐 Security
- [Authentication](docs/authentication.md) - JWT and OAuth implementation
- [RBAC (Access Control)](docs/RBAC.md) - Role-Based Access Control
- [Security Best Practices](docs/security-best-practices.md) - Comprehensive security guide

### ⚡ Performance
- [Redis Caching Guide](docs/CACHING.md) - Cache tags and automatic invalidation
- [Performance Guidelines](docs/performance-guidelines.md) - Optimization strategies

### 📚 Additional Resources
- [API Design Guidelines](docs/api-design-guidelines.md) - REST API design patterns
- [Coding Standards](docs/coding-standards.md) - PHP coding conventions
- [Online Documentation](https://stonescriptphp.org/docs)
- [Examples](examples/)

## Development

The framework uses PostgreSQL as its primary database and follows a function-first approach:

1. Write SQL functions that encapsulate business logic
2. Generate PHP models from these functions
3. Create routes that call the models
4. Map URLs to route handlers

This approach keeps your business logic close to the data and leverages PostgreSQL's powerful procedural capabilities.

## Composer Scripts

```bash
composer serve    # Start development server
composer test     # Run PHPUnit tests
composer migrate  # Run database migrations
```

## License

MIT

## Support

- Website: https://stonescriptphp.org
- Documentation: https://stonescriptphp.org/docs
- Issues: https://github.com/progalaxyelabs/StoneScriptPHP/issues
- Source: https://github.com/progalaxyelabs/StoneScriptPHP
