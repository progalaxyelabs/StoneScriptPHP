<p align="center">
  <img src="images/logo.svg" width="200" alt="StoneScriptPHP Logo">
</p>

# ⚠️ Are You Looking to Create a New Project?

## You Probably Want [stonescriptphp-server](https://github.com/progalaxyelabs/StoneScriptPHP-Server) Instead!

This repository contains the **core framework library**. Most developers should use the application skeleton to get started:

```bash
# ✅ RECOMMENDED: Create a new project with the application skeleton
composer create-project progalaxyelabs/stonescriptphp-server my-api
cd my-api
php stone serve
```

**Why use stonescriptphp-server?**
- Includes complete project structure
- Pre-configured routes and examples
- `stone` CLI entry point ready to use
- Environment setup automated
- This framework (stonescriptphp) is automatically installed as a dependency
- CLI tools auto-update with `composer update`

---

## When to Use This Package Directly

Only install this framework package directly if you're:

- ✅ Contributing to the framework core
- ✅ Building custom framework extensions or plugins
- ✅ Integrating StoneScriptPHP into an existing project
- ✅ Creating your own custom project template

```bash
# Direct installation (advanced usage only)
composer require progalaxyelabs/stonescriptphp
```

---

# StoneScriptPHP Framework

[![PHP Tests](https://github.com/progalaxyelabs/StoneScriptPHP/actions/workflows/php-test.yml/badge.svg)](https://github.com/progalaxyelabs/StoneScriptPHP/actions/workflows/php-test.yml)
[![Packagist Version](https://img.shields.io/packagist/v/progalaxyelabs/stonescriptphp)](https://packagist.org/packages/progalaxyelabs/stonescriptphp)
[![License](https://img.shields.io/github/license/progalaxyelabs/StoneScriptPHP)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/progalaxyelabs/stonescriptphp)](composer.json)
[![VS Code Extension](https://img.shields.io/visual-studio-marketplace/v/progalaxyelabs.stonescriptphp-snippets?label=VS%20Code%20Snippets)](https://marketplace.visualstudio.com/items?itemName=progalaxyelabs.stonescriptphp-snippets)
[![Extension Installs](https://img.shields.io/visual-studio-marketplace/i/progalaxyelabs.stonescriptphp-snippets)](https://marketplace.visualstudio.com/items?itemName=progalaxyelabs.stonescriptphp-snippets)

A modern PHP backend framework for building APIs with PostgreSQL, inspired by Angular routing, MCP-style CLI commands, and Laravel's elegance for a streamlined developer experience.

Built for developers who value clean architecture and rapid API development, StoneScriptPHP combines PostgreSQL's power with an intuitive CLI workflow.

## 📦 Package Ecosystem

| Package | Purpose | When to Use |
|---------|---------|-------------|
| **[stonescriptphp-server](https://github.com/progalaxyelabs/StoneScriptPHP-Server)** | Application skeleton | ✅ **Start here** - Creating new projects |
| **[stonescriptphp](https://github.com/progalaxyelabs/StoneScriptPHP)** | Core framework | Advanced - Framework development, custom integrations |


## Features

### Core Framework

* **CLI Tools** - Code generators in `cli/` directory (used via `php stone` from server package)
* **PostgreSQL-first** - Built for PostgreSQL with migration system
* **Fast Development** - Code generators for routes, models, migrations
* **Auto-updates** - CLI tools update automatically with `composer update`

### Security & Auth

* **JWT Authentication** - RSA & HMAC support, built-in OAuth (Google)
* **RBAC** - Role-Based Access Control with permissions
* **Security Middleware** - CORS, rate limiting, security headers

### Performance & Monitoring

* **Redis Caching** - Optional Redis integration with cache tags, TTL, automatic invalidation
* **Production Logging** - PSR-3 compatible, console + file output, colorized
* **Exception Handling** - Global exception handler with structured errors
* **Validation Layer** - Powerful request validation with 12+ built-in rules

### Developer Experience

* **Color-Coded Logs** - Beautiful ANSI-colored console output
* **Comprehensive Docs** - 20+ documentation files (600+ pages)
* **Testing** - PHPUnit test suite included
* **VS Code Extension** - Snippets and IntelliSense

## Quick Start (Using Application Skeleton)

```bash
# Create a new project
composer create-project progalaxyelabs/stonescriptphp-server my-api
cd my-api

# Start development server
php stone serve
# Your API is running at http://localhost:9100
```

**👉 [View Full Getting Started Guide](https://stonescriptphp.org/docs/getting-started)**

## Development Workflow

### 1. Define Database Schema

Create tables in `src/App/Database/postgres/tables/table_name.pssql`

### 2. Create SQL Functions

Create functions in `src/App/Database/postgres/functions/function_name.pssql`

```sql
-- Example: get_users.pssql
CREATE OR REPLACE FUNCTION get_users()
RETURNS TABLE (
    id INTEGER,
    name VARCHAR(100),
    email VARCHAR(255)
) AS $$
BEGIN
    RETURN QUERY
    SELECT u.id, u.name, u.email
    FROM users u
    ORDER BY u.id DESC;
END;
$$ LANGUAGE plpgsql;
```

### 3. Generate PHP Model

```bash
php stone generate model get_users.pssql
```

Creates `FnGetUsers.php` in `src/App/Database/Functions/`

### 4. Create Route

```bash
php stone generate route get-users
```

### 5. Map URL to Route

In `src/App/Config/routes.php`:

```php
return [
    'GET' => [
        '/api/users' => GetUsersRoute::class,
    ],
];
```

### 6. Implement Route Logic

```php
class GetUsersRoute implements IRouteHandler
{
    public function validation_rules(): array
    {
        return []; // No validation needed for GET
    }

    public function process(): ApiResponse
    {
        $users = FnGetUsers::run();
        return res_ok(['users' => $users]);
    }
}
```

### 7. Run Migrations

```bash
php stone migrate verify
```

## CLI Tools (v2.0.13+)

**Location:** `cli/` directory in this package
**Usage:** Via `php stone` command from [stonescriptphp-server](https://github.com/progalaxyelabs/StoneScriptPHP-Server)

The framework now bundles all CLI code generators. When you run `composer update`, the CLI tools automatically update along with the framework.

```bash
# Run from stonescriptphp-server project:
php stone generate route <name>         # Generate route handler
php stone generate model <file.pgsql>   # Generate model from SQL function
php stone generate auth:google          # Generate OAuth authentication
php stone migrate verify                # Check database drift
```

See [stonescriptphp-server](https://github.com/progalaxyelabs/StoneScriptPHP-Server) for complete CLI documentation.

## Architecture Philosophy

StoneScriptPHP follows a **PostgreSQL-first architecture**:

1. **Business Logic in Database** - SQL functions encapsulate complex queries and business rules
2. **Type-Safe PHP Models** - Generated classes wrap SQL functions with PHP typing
3. **Thin Route Layer** - Routes handle HTTP concerns (validation, auth, responses)
4. **Clean Separation** - Database → Models → Services → Routes → Frontend

This approach:
- Leverages PostgreSQL's procedural capabilities
- Keeps logic close to the data
- Enables database performance optimization
- Facilitates testing and maintenance

## Requirements

### Required

* PHP >= 8.2
* PostgreSQL >= 13
* Composer
* PHP Extensions: `pdo`, `pdo_pgsql`, `json`, `openssl`

### Optional

* Redis server (for caching support)
* PHP Extension: `redis` (for Redis caching)

## Documentation

### 📖 Main Documentation

* **[📑 Documentation Index](https://github.com/progalaxyelabs/StoneScriptPHP/blob/main/docs/INDEX.md)** - Complete documentation with navigation
* **[🏗️ High Level Design (HLD)](https://github.com/progalaxyelabs/StoneScriptPHP/blob/main/HLD.md)** - System architecture
* **[📋 Release Notes](https://github.com/progalaxyelabs/StoneScriptPHP/blob/main/RELEASE.md)** - Version history

### 🚀 Getting Started

* [Getting Started Guide](https://github.com/progalaxyelabs/StoneScriptPHP/blob/main/docs/getting-started.md)
* [CLI Usage Guide](https://github.com/progalaxyelabs/StoneScriptPHP/blob/main/docs/CLI-USAGE.md)
* [Environment Configuration](https://github.com/progalaxyelabs/StoneScriptPHP/blob/main/docs/environment-configuration.md)

### 🔧 Core Features

* [API Reference](https://github.com/progalaxyelabs/StoneScriptPHP/blob/main/docs/api-reference.md)
* [Logging & Exceptions](https://github.com/progalaxyelabs/StoneScriptPHP/blob/main/docs/logging-and-exceptions.md)
* [Request Validation](https://github.com/progalaxyelabs/StoneScriptPHP/blob/main/docs/validation.md)
* [Middleware Guide](https://github.com/progalaxyelabs/StoneScriptPHP/blob/main/docs/MIDDLEWARE.md)

### 🔐 Security

* [Authentication](https://github.com/progalaxyelabs/StoneScriptPHP/blob/main/docs/authentication.md)
* [RBAC (Access Control)](https://github.com/progalaxyelabs/StoneScriptPHP/blob/main/docs/RBAC.md)
* [Security Best Practices](https://github.com/progalaxyelabs/StoneScriptPHP/blob/main/docs/security-best-practices.md)

### ⚡ Performance

* [Redis Caching Guide](https://github.com/progalaxyelabs/StoneScriptPHP/blob/main/docs/CACHING.md)
* [Performance Guidelines](https://github.com/progalaxyelabs/StoneScriptPHP/blob/main/docs/performance-guidelines.md)

## Contributing to the Framework

We welcome contributions! This repository is for framework core development.

### Development Setup

```bash
# Clone the framework repository
git clone https://github.com/progalaxyelabs/StoneScriptPHP.git
cd StoneScriptPHP

# Install dependencies
composer install

# Run tests
composer test
```

### Testing Local Changes

To test framework changes in a project without publishing:

```json
// In your test project's composer.json
{
    "repositories": [
        {
            "type": "path",
            "url": "../StoneScriptPHP",
            "options": {"symlink": false}
        }
    ],
    "require": {
        "progalaxyelabs/stonescriptphp": "@dev"
    }
}
```

Then run `composer update progalaxyelabs/stonescriptphp`

## Versioning Strategy

StoneScriptPHP follows [Semantic Versioning](https://semver.org/):

* **Patch versions (2.0.x)**: Bug fixes, security patches. Safe to update anytime.
* **Minor versions (2.x.0)**: New features, backward-compatible. Update when needed.
* **Major versions (x.0.0)**: Breaking changes. Review migration guide first.

**Current stable:** v2.0.x - Production-ready with ongoing bug fixes

## Related Packages

* **[stonescriptphp-server](https://github.com/progalaxyelabs/StoneScriptPHP-Server)** - Application skeleton (recommended for new projects)

## Support & Community

* **Website**: [stonescriptphp.org](https://stonescriptphp.org)
* **Documentation**: [stonescriptphp.org/docs](https://stonescriptphp.org/docs)
* **Framework Issues**: [GitHub Issues](https://github.com/progalaxyelabs/StoneScriptPHP/issues)
* **Discussions**: [GitHub Discussions](https://github.com/progalaxyelabs/StoneScriptPHP/discussions)

## License

MIT License - see [LICENSE](LICENSE) file for details

---

## Remember: For New Projects

Most developers should start with the application skeleton:

```bash
composer create-project progalaxyelabs/stonescriptphp-server my-api
```

This framework package is automatically included as a dependency. Visit [stonescriptphp-server](https://github.com/progalaxyelabs/StoneScriptPHP-Server) to get started!
