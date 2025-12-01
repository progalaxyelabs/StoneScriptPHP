# StoneScriptPHP

[![PHP Tests](https://github.com/progalaxyelabs/StoneScriptPHP/actions/workflows/php-test.yml/badge.svg)](https://github.com/progalaxyelabs/StoneScriptPHP/actions/workflows/php-test.yml)
[![Packagist Version](https://img.shields.io/packagist/v/progalaxyelabs/stonescriptphp)](https://packagist.org/packages/progalaxyelabs/stonescriptphp)
[![License](https://img.shields.io/github/license/progalaxyelabs/StoneScriptPHP)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/progalaxyelabs/stonescriptphp)](composer.json)

A modern PHP backend framework for building APIs with PostgreSQL, inspired by Angular's developer experience.

**Note:** While this package is published under `progalaxyelabs/stonescriptphp`, the official website and documentation are at https://stonescriptphp.org. A future migration to the `@stonescriptphp` namespace is planned.

---------------------------------------------------------------

## Quick Start

```bash
# Create new project (like 'ng new')
composer create-project progalaxyelabs/stonescriptphp my-api

# Navigate to project
cd my-api

# Interactive setup (automatically runs after create-project)
# Will prompt for database config, generate JWT keys, etc.

# Start development server
php stone serve

# Your API is running at http://localhost:9100
```

## Features

- 🎯 **Angular-like CLI** - `php stone` commands for code generation
- 📝 **PostgreSQL-first** - Built for PostgreSQL with migration system
- 🔐 **JWT Authentication** - Built-in OAuth support (Google)
- ✅ **Testing** - PHPUnit test suite included
- 🚀 **Fast Development** - Code generators for routes, models, migrations
- 📦 **Zero Config** - Works out of the box after setup

## CLI Commands

```bash
php stone setup              # Interactive project setup
php stone serve              # Start dev server (port 9100)
php stone generate route <name>   # Generate route handler
php stone generate model <file>   # Generate model from SQL function
php stone migrate verify     # Check database drift
php stone test               # Run PHPUnit tests
php stone env                # Generate .env file
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

### 4. Create API Route

```bash
php stone generate route update-trophies
```

This will create a `UpdateTrophiesRoute.php` file in `Routes` folder.

### 5. Create URL to Class Route Mapping

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

### 6. Implement the Route Class's Process Function

In `UpdateTrophiesRoute.php`, in the `process` function, call the database function and return data.

Example:

```php
$data = FnUpdatetrophyDetails::run(
    $user_trophy_id,
    $count
);

return new ApiResponse('ok', '', [
    'course' => $data
]);
```

### 7. Run Migrations

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
- Composer
- Extensions: `pdo`, `pgsql`, `openssl`

## Documentation

- [CLI Usage Guide](CLI-USAGE.md)
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
