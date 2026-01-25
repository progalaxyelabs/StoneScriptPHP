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
composer serve
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
* **Auth Service Integration** - HTTP clients for ProGalaxy Auth Service (memberships, invitations)
* **Token Validation** - Middleware for validating JWT tokens
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

# Start development server (via composer script)
composer serve
# Your API is running at http://localhost:9100
```

**👉 [View Full Documentation](https://stonescriptphp.org/docs)**

## Upgrading

### Keep Your Project Up-to-Date

**Update framework and CLI tools:**
```bash
# Update framework (vendor packages)
composer update progalaxyelabs/stonescriptphp

# Update CLI tools (project files)
php stone upgrade
```

**Check for updates without installing:**
```bash
php stone upgrade --check
```

See [online upgrade guide](https://stonescriptphp.org/docs/upgrade) for version-specific migration guides.

## Running Development Server

### Using Composer Scripts (Recommended)

The application skeleton ([stonescriptphp-server](https://github.com/progalaxyelabs/StoneScriptPHP-Server)) includes composer scripts for running a development server:

```bash
# Start development server
composer serve
# Server runs on http://localhost:9100
```

Press `Ctrl+C` to stop the server.

### Manual Server Start

You can also run PHP's built-in development server directly:

```bash
# Basic usage
php -S localhost:9100 -t public

# Custom host and port
php -S 0.0.0.0:8080 -t public

# With router script (if needed for URL rewriting)
php -S localhost:9100 -t public public/index.php
```

**Note:** PHP's built-in server is for development only. Use Nginx, Apache, or Caddy in production.

### Production Deployment

For production environments, configure your web server (Nginx/Apache/Caddy) to:
- Set document root to `public/`
- Route all requests to `public/index.php`
- Enable FastCGI with PHP-FPM

See [deployment documentation](https://stonescriptphp.org/docs/deployment) for production setup guides.

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

## CLI Tools

**Location:** `cli/` directory in this package
**Usage:** Via `php stone` command from [stonescriptphp-server](https://github.com/progalaxyelabs/StoneScriptPHP-Server)

The framework bundles all CLI code generators. When you run `composer update`, the CLI tools automatically update along with the framework.

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

## Auth Service Integration

### ProGalaxy Auth Service Clients

StoneScriptPHP includes HTTP clients for backend-to-backend operations with the ProGalaxy Auth Service.

**Use these for:**
- System automation (e.g., auto-create membership after payment)
- Webhook handlers
- Backend CLI tools
- Bulk operations

#### Membership Client

```php
use StoneScriptPHP\Auth\Client\MembershipClient;
use StoneScriptPHP\Auth\Client\AuthServiceException;

$client = new MembershipClient('http://auth-service:3139');

try {
    // Create membership after payment webhook
    $membership = $client->createMembership([
        'identity_id' => $userId,
        'tenant_id' => $tenantId,
        'role' => 'premium_member'
    ], $systemAdminToken);

    // Update role
    $client->updateMembership($membershipId, [
        'role' => 'admin'
    ], $adminToken);

    // Get user's memberships
    $memberships = $client->getUserMemberships($userId, 'myapp', $token);

} catch (AuthServiceException $e) {
    log_error("Auth service error: " . $e->getMessage());
}
```

#### Invitation Client

```php
use StoneScriptPHP\Auth\Client\InvitationClient;

$invitations = new InvitationClient('http://auth-service:3139');

// Invite user
$invitation = $invitations->inviteUser(
    email: 'user@example.com',
    tenantId: $tenantId,
    role: 'member',
    authToken: $adminToken
);

// Bulk invite (system automation)
$invitations->bulkInvite([
    ['email' => 'user1@example.com', 'tenant_id' => $tid, 'role' => 'member'],
    ['email' => 'user2@example.com', 'tenant_id' => $tid, 'role' => 'admin'],
], $adminToken);

// Cancel invitation
$invitations->cancelInvitation($invitationId, $adminToken);
```

**Note:** For frontend operations (user login, token validation), use the auth service directly from Angular or use JWT validation middleware.

## Documentation

### 📖 Main Documentation

* **[📚 Complete Documentation](https://stonescriptphp.org/docs)** - Full documentation site
* **[🏗️ High Level Design (HLD)](HLD.md)** - System architecture and design
* **[📦 Server Package](https://github.com/progalaxyelabs/StoneScriptPHP-Server)** - Application skeleton

### 🚀 Getting Started

* [Getting Started Guide](https://stonescriptphp.org/docs/getting-started)
* [CLI Usage Guide](https://stonescriptphp.org/docs/cli-usage)
* [Environment Configuration](https://stonescriptphp.org/docs/environment)

### 🔧 Core Features

* [API Reference](https://stonescriptphp.org/docs/api-reference)
* [Logging & Exceptions](https://stonescriptphp.org/docs/logging)
* [Request Validation](https://stonescriptphp.org/docs/validation)
* [Middleware Guide](https://stonescriptphp.org/docs/middleware)
* [Caching System](https://stonescriptphp.org/docs/caching)

### 🔐 Security

* [Authentication](https://stonescriptphp.org/docs/authentication)
* [JWT Configuration](https://stonescriptphp.org/docs/jwt)
* [RBAC (Access Control)](https://stonescriptphp.org/docs/rbac)
* [Security Best Practices](https://stonescriptphp.org/docs/security)

### ⚡ Performance

* [Redis Caching Guide](https://stonescriptphp.org/docs/caching)
* [Performance Guidelines](https://stonescriptphp.org/docs/performance)

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

**Current stable:** v2.4.2 - Production-ready with ongoing improvements

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
