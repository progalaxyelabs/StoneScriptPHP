# Environment Configuration

StoneScriptPHP uses a type-safe environment configuration system that ensures all required environment variables are present and have the correct types.

## Overview

The environment configuration system consists of three components:

1. **Framework/Env.php** - Schema definition (manually edited by developers)
2. **.env** - Runtime values (auto-generated, never committed)
3. **Framework/cli/generate-env.php** - Generator script

## Quick Start

### 1. Generate .env file

```bash
php Framework/cli/generate-env.php
```

This creates a `.env` file with all defined variables from the schema.

### 2. Configure your values

Edit `.env` and set your environment-specific values:

```ini
DATABASE_HOST=localhost
DATABASE_PORT=5432
DATABASE_USER=myuser
DATABASE_PASSWORD=mypassword
DATABASE_DBNAME=mydb
```

### 3. Use in code

```php
use Framework\Env;

$env = Env::get_instance();

// Access environment variables
$db_host = $env->DATABASE_HOST;
$db_port = $env->DATABASE_PORT;
$db_user = $env->DATABASE_USER;
```

## Usage Patterns

### Basic access
```php
$env = Env::get_instance();
$host = $env->DATABASE_HOST;
$port = $env->DATABASE_PORT;
```

### With fallback (for optional variables)
```php
$env = Env::get_instance();
$timeout = $env->DATABASE_TIMEOUT ?? 30;
$appname = $env->DATABASE_APPNAME ?? 'DefaultApp';
```

### Type-safe values
The Env class automatically validates and casts values to their correct types:

```php
$env = Env::get_instance();

// DATABASE_PORT is defined as 'int' in schema
$port = $env->DATABASE_PORT; // Always an integer

// DATABASE_TIMEOUT has default value
$timeout = $env->DATABASE_TIMEOUT; // Integer with default: 30
```

## Adding New Environment Variables

### 1. Define in Framework/Env.php

Add your variable to the schema in the `getSchema()` method:

```php
public static function getSchema(): array
{
    return [
        // ... existing variables ...

        'MY_NEW_VAR' => [
            'type' => 'string',           // string, int, bool, float
            'required' => true,            // true = must be in .env
            'default' => 'default_value',  // null or a default value
            'description' => 'Description of this variable'
        ],
    ];
}
```

### 2. Add property to class

```php
class Env
{
    // ... existing properties ...

    public $MY_NEW_VAR;
}
```

### 3. Regenerate .env

```bash
php Framework/cli/generate-env.php
```

This updates `.env` with the new variable while preserving existing values.

## Generator Script Options

### Generate .env
```bash
php Framework/cli/generate-env.php
```
Creates or updates `.env` file, preserving existing values.

### Force regeneration
```bash
php Framework/cli/generate-env.php --force
```
Overwrites all values with defaults (use with caution).

### Generate example file
```bash
php Framework/cli/generate-env.php --example
```
Creates `.env.example` with placeholder values for documentation.

## Schema Definition Reference

Each environment variable in the schema has four properties:

| Property | Type | Description |
|----------|------|-------------|
| `type` | string | Data type: `string`, `int`, `bool`, or `float` |
| `required` | bool | Whether the variable must be set in `.env` |
| `default` | mixed | Default value if not set (can be `null`) |
| `description` | string | Human-readable description |

### Type Validation

- **string**: Any text value
- **int**: Numeric values, validated and cast to integer
- **bool**: Accepts `true/1/yes/on` or `false/0/no/off`
- **float**: Decimal numbers, validated and cast to float

### Required vs Optional

- **Required** (`required: true`): Must be present in `.env` or have a default value
- **Optional** (`required: false`): Can be omitted, will be `null` if not set

## Examples

### Database Configuration
```php
$env = Env::get_instance();

$dsn = "host={$env->DATABASE_HOST} port={$env->DATABASE_PORT} " .
       "dbname={$env->DATABASE_DBNAME} user={$env->DATABASE_USER} " .
       "password={$env->DATABASE_PASSWORD}";

$conn = pg_connect($dsn);
```

### Email Configuration
```php
$env = Env::get_instance();

if ($env->ZEPTOMAIL_SEND_MAIL_TOKEN) {
    // ZeptoMail is configured
    $sender = $env->ZEPTOMAIL_SENDER_EMAIL;
    $token = $env->ZEPTOMAIL_SEND_MAIL_TOKEN;
}
```

### Optional Settings
```php
$env = Env::get_instance();

// Use default if not configured
$timeout = $env->DATABASE_TIMEOUT ?? 30;
$appname = $env->DATABASE_APPNAME ?? 'StoneScriptPHP';
```

## Best Practices

1. **Never commit .env** - Add `.env` to `.gitignore`
2. **Commit .env.example** - Provide template for other developers
3. **Use defaults wisely** - Set sensible defaults for non-sensitive values
4. **Validate early** - The Env class validates on instantiation, catching errors early
5. **Document variables** - Use clear descriptions in the schema
6. **Group related variables** - Use prefixes like `DATABASE_`, `MAIL_`, etc.

## Error Handling

### Missing required variables
```
Exception: 3 required settings missing in .env file: DATABASE_USER, DATABASE_PASSWORD, DATABASE_DBNAME
```
**Solution**: Add the missing variables to your `.env` file.

### Type validation errors
```
Exception: Type validation errors in .env: DATABASE_PORT (expected int, got: abc)
```
**Solution**: Ensure the value matches the expected type.

### Missing .env file
```
Exception: missing .env file. Run: php Framework/cli/generate-env.php
```
**Solution**: Run the generator script to create `.env`.

## Migration from Legacy .env

If you have an existing `.env` file, the generator will preserve all your values:

```bash
# This preserves your existing configuration
php Framework/cli/generate-env.php
```

Your existing values will be kept, and only new variables from the schema will be added.
