# Server Package Migration Guide

**Target:** stonescriptphp-server maintainers
**Framework Version:** 2.1.0+
**Date:** December 13, 2025

## Overview

This guide helps migrate the `stonescriptphp-server` skeleton package to work with the refactored framework that uses `StoneScriptPHP\` namespace instead of `Framework\`.

## Changes Required in Server Package

### 1. Update `stone` Script

**File:** `stone` (root of server package)

**Replace the path detection section:**

```php
// ❌ OLD (DO NOT USE)
// Detect installation mode
$isVendorMode = strpos(__DIR__, 'vendor/progalaxyelabs/stonescriptphp') !== false;

if ($isVendorMode) {
    $vendorDir = dirname(dirname(__DIR__));
    define('ROOT_PATH', dirname($vendorDir) . DIRECTORY_SEPARATOR);
    define('FRAMEWORK_PATH', __DIR__ . '/Framework');  // ❌ This directory doesn't exist!
    define('IS_VENDOR_MODE', true);
} else {
    define('ROOT_PATH', __DIR__ . DIRECTORY_SEPARATOR);
    define('FRAMEWORK_PATH', ROOT_PATH . 'Framework'); // ❌ This directory doesn't exist!
    define('IS_VENDOR_MODE', false);
}
```

```php
// ✅ NEW (USE THIS)
// Detect installation mode and set up paths
$isVendorMode = strpos(__DIR__, 'vendor/progalaxyelabs/stonescriptphp') !== false;

if ($isVendorMode) {
    // Running from vendor/progalaxyelabs/stonescriptphp/stone
    // This happens when framework is installed via Composer in a project
    $vendorDir = dirname(dirname(__DIR__)); // vendor/
    $projectRoot = dirname($vendorDir);      // project root
    define('ROOT_PATH', $projectRoot . DIRECTORY_SEPARATOR);
    define('IS_VENDOR_MODE', true);
} else {
    // Running from framework development repository
    // This happens when working on the framework itself
    define('ROOT_PATH', __DIR__ . DIRECTORY_SEPARATOR);
    define('IS_VENDOR_MODE', false);
}

// Define project paths
// SRC_PATH points to the project's src/ directory (where App\ namespace lives)
define('SRC_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);
define('CONFIG_PATH', SRC_PATH . 'App' . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR);
```

**Key changes:**
- ❌ Removed `FRAMEWORK_PATH` constant (it's obsolete)
- ✅ Added clear comments explaining vendor vs development mode
- ✅ Framework code is automatically available via Composer autoloader

### 2. Update Route Handlers (if any examples exist)

**Example route handlers in the skeleton should use:**

```php
<?php

namespace App\Routes;

use StoneScriptPHP\IRouteHandler;  // ✅ NEW namespace
use StoneScriptPHP\ApiResponse;    // ✅ NEW namespace

class ExampleRoute implements IRouteHandler
{
    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        return res_ok(['message' => 'Hello World']);
    }
}
```

**NOT:**

```php
use Framework\IRouteHandler;  // ❌ OLD - will not work
use Framework\ApiResponse;    // ❌ OLD - will not work
```

### 3. Update composer.json

**Ensure the server package's composer.json requires the updated framework:**

```json
{
    "require": {
        "php": ">=8.2",
        "progalaxyelabs/stonescriptphp": "^2.1"
    },
    "autoload": {
        "psr-4": {
            "App\\": "src/App/"
        }
    }
}
```

**Do NOT include:**

```json
{
    "autoload": {
        "psr-4": {
            "Framework\\": "Framework/"  // ❌ Remove this!
        },
        "files": [
            "Framework/functions.php"    // ❌ Remove this!
        ]
    }
}
```

The framework is now loaded from `vendor/progalaxyelabs/stonescriptphp/` automatically.

### 4. Remove Framework Directory (if it exists)

If the server package has a `Framework/` directory, **delete it entirely**:

```bash
rm -rf Framework/
```

The framework code is now in:
```
vendor/progalaxyelabs/stonescriptphp/
├── src/              # StoneScriptPHP\ namespace
├── cli/              # StoneScriptPHP\CLI\ namespace
└── ...
```

### 5. Update Documentation

**README.md and other docs should reference:**

- ✅ `StoneScriptPHP\` namespace
- ✅ Framework installed via Composer
- ✅ `vendor/progalaxyelabs/stonescriptphp/` for framework code

**NOT:**

- ❌ `Framework\` namespace
- ❌ `Framework/` directory
- ❌ Copying framework files

## Testing the Migration

### 1. Create a Test Project

```bash
# In the server package repository
rm -rf test-project
mkdir test-project
cd test-project

# Copy skeleton files
cp -r ../src .
cp -r ../public .
cp ../stone .
cp ../composer.json .
cp ../.env.example .env

# Install dependencies
composer install

# Test CLI
php stone help
```

### 2. Verify Code Generation

```bash
# Generate a test route
php stone generate route post /test

# Check the generated file
cat src/App/Routes/PostTestRoute.php

# Should contain:
# use StoneScriptPHP\IRouteHandler;
# use StoneScriptPHP\ApiResponse;
```

### 3. Verify Server Runs

```bash
php stone serve

# Visit http://localhost:9100
# Should see the API running
```

## Migration Checklist

Use this checklist to ensure all changes are made:

- [ ] Updated `stone` script with new path detection
- [ ] Removed `FRAMEWORK_PATH` constant
- [ ] Updated example route handlers to use `StoneScriptPHP\` namespace
- [ ] Updated composer.json to remove `Framework\` PSR-4 mapping
- [ ] Deleted `Framework/` directory (if it existed)
- [ ] Updated README.md to reference `StoneScriptPHP\` namespace
- [ ] Updated getting started docs
- [ ] Tested `php stone help` works
- [ ] Tested `php stone generate route` produces correct code
- [ ] Tested `php stone serve` starts server
- [ ] Verified generated routes use `StoneScriptPHP\` namespace

## Framework Updates Required

**Server package should require framework version:**

```json
"progalaxyelabs/stonescriptphp": "^2.1"
```

This ensures users get the refactored framework with correct namespace.

## Breaking Changes

### For Users

Users creating new projects with the migrated server skeleton will automatically get the correct setup. No migration needed for new projects.

### For Existing Projects

Existing projects that update the server skeleton files will need to:

1. Update their route handlers to use `StoneScriptPHP\` instead of `Framework\`
2. Run `composer update` to get the new framework version
3. Update their custom code imports

**Migration command for existing projects:**

```bash
# Update all route handlers
find src/App -name "*.php" -exec sed -i 's/use Framework\\/use StoneScriptPHP\\/g' {} \;

# Update composer
composer update progalaxyelabs/stonescriptphp

# Verify
php stone help
```

## Common Issues

### Issue 1: "Class Framework\IRouteHandler not found"

**Cause:** Old route handlers still using `Framework\` namespace

**Fix:**
```bash
# Update imports in route files
find src/App/Routes -name "*.php" -exec sed -i 's/use Framework\\/use StoneScriptPHP\\/g' {} \;
```

### Issue 2: "Framework directory not found"

**Cause:** `stone` script still references old `FRAMEWORK_PATH`

**Fix:** Update `stone` script to match the new version (see section 1 above)

### Issue 3: Composer can't find framework

**Cause:** Framework version is too old

**Fix:**
```bash
composer update progalaxyelabs/stonescriptphp
```

Should pull version 2.1.0 or higher.

## Support

If you encounter issues during migration:

1. Check the [Getting Started Guide](../guides/getting-started.md)
2. Review [API Reference](../reference/api-reference.md)
3. See [Test Results](namespace-refactoring-test-results.md)
4. Open an issue on GitHub

## Example Files

### Example stone Script (Complete)

See: `/stone` in the framework repository

### Example Route Handler

```php
<?php

namespace App\Routes;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;

class WelcomeRoute implements IRouteHandler
{
    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        return res_ok([
            'message' => 'Welcome to StoneScriptPHP!',
            'version' => '2.1.0',
            'framework' => 'StoneScriptPHP'
        ]);
    }
}
```

### Example composer.json (Server Package)

```json
{
    "name": "progalaxyelabs/stonescriptphp-server",
    "description": "StoneScriptPHP Application Skeleton",
    "type": "project",
    "license": "MIT",
    "require": {
        "php": ">=8.2",
        "progalaxyelabs/stonescriptphp": "^2.1",
        "ext-pdo": "*",
        "ext-pdo_pgsql": "*",
        "ext-json": "*",
        "ext-openssl": "*"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "App\\": "src/App/"
        }
    },
    "scripts": {
        "serve": "php stone serve",
        "test": "phpunit",
        "setup": "php stone setup"
    },
    "config": {
        "optimize-autoloader": true,
        "preferred-install": "dist",
        "sort-packages": true
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

## Timeline

**Recommended migration timeline:**

1. ✅ Framework v2.1.0 released (current)
2. ⏭️ Update stonescriptphp-server package (next step)
3. ⏭️ Tag new server package version
4. ⏭️ Update documentation
5. ⏭️ Announce migration guide to users

## Version Compatibility

| Server Package | Framework Version | Namespace | Status |
|----------------|-------------------|-----------|--------|
| v1.x | v2.0.x | `Framework\` | ⚠️ Deprecated |
| v2.0+ | v2.1.0+ | `StoneScriptPHP\` | ✅ Current |

Users should upgrade to server package v2.0+ to get the correct namespace.
