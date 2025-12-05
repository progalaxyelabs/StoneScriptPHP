# Dual-Mode Implementation Summary

## Overview
StoneScriptPHP now supports two installation modes:
1. **Create-Project Mode**: `composer create-project progalaxyelabs/stonescriptphp my-api`
2. **Require Mode**: `composer require progalaxyelabs/stonescriptphp`

This allows the framework to live in `vendor/` enabling composer-based upgrades while maintaining full CLI functionality.

## Changes Made

### 1. composer.json
**Added:**
- `"bin": ["stone"]` - Makes `vendor/bin/stone` available when installed as dependency
- `post-install-cmd` script - Shows initialization message after `composer require`

**Result:**
- When installed via `composer require`, users get a helpful message: "✅ StoneScriptPHP installed! Initialize your project with: vendor/bin/stone init"
- The `stone` CLI is automatically symlinked to `vendor/bin/stone`

### 2. stone CLI (Root Command)
**Added Path Detection:**
```php
// Detect installation mode
$isVendorMode = strpos(__DIR__, 'vendor/progalaxyelabs/stonescriptphp') !== false;

if ($isVendorMode) {
    // Installed via composer require - running from vendor/bin/stone
    $vendorDir = dirname(dirname(__DIR__));
    define('ROOT_PATH', dirname($vendorDir));
    define('FRAMEWORK_PATH', __DIR__ . '/Framework');
    define('IS_VENDOR_MODE', true);
} else {
    // Installed via composer create-project - running from project root
    define('ROOT_PATH', __DIR__);
    define('FRAMEWORK_PATH', ROOT_PATH . '/Framework');
    define('IS_VENDOR_MODE', false);
}
```

**Added init command:**
```php
'init' => [
    'description' => 'Initialize StoneScriptPHP in existing project (after composer require)',
    'file' => 'cli/init.php',
],
```

### 3. Framework/cli/init.php (New File)
**Purpose:** Initialize StoneScriptPHP in existing projects

**Features:**
- Detects vendor mode vs. project mode
- Interactive template selection (basic-api, microservice, saas-boilerplate, or skip)
- Scaffolds project structure from selected template
- Creates minimal structure if no template selected:
  - `src/App/Routes/`
  - `src/App/Models/`
  - `src/App/Database/Migrations/`
  - `public/`
  - `logs/`
  - `keys/`
- Generates `.env` file with interactive configuration
- Generates JWT keypair
- Creates `public/index.php` entry point
- Creates example `HealthRoute.php`
- Creates `./stone` wrapper script for convenience

**Usage:**
```bash
# Interactive mode
vendor/bin/stone init

# With template
vendor/bin/stone init basic-api

# Skip template (minimal)
vendor/bin/stone init skip
```

### 4. README.md
**Updated Installation section:**
- Split into "Option 1: New Project" and "Option 2: Add to Existing Project"
- Added examples for both modes
- Updated Upgrade Path to mention both modes
- Added CLI Commands section with both modes shown

### 5. CLI-USAGE.md
**Added:**
- "Installation Modes" section explaining both modes
- Documentation for the new `init` command
- Note to substitute `vendor/bin/stone` or `./stone` based on installation mode

## How It Works

### Create-Project Mode (Original Behavior)
1. User runs: `composer create-project progalaxyelabs/stonescriptphp my-api`
2. Composer downloads framework and scaffolds it as the project root
3. `post-create-project-cmd` triggers `php stone setup`
4. Framework files in root, user runs `php stone <command>`

### Require Mode (New Behavior)
1. User runs: `composer require progalaxyelabs/stonescriptphp`
2. Framework is installed to `vendor/progalaxyelabs/stonescriptphp/`
3. Composer creates symlink: `vendor/bin/stone -> vendor/progalaxyelabs/stonescriptphp/stone`
4. `post-install-cmd` shows message: "Run vendor/bin/stone init"
5. User runs: `vendor/bin/stone init`
6. Init command:
   - Detects it's running from vendor (checks `__DIR__` path)
   - Calculates project root: `dirname(dirname(dirname(__DIR__)))`
   - Scaffolds structure in project root
   - Creates `./stone` wrapper for convenience
7. User can now run: `vendor/bin/stone serve` or `./stone serve`

### Path Resolution
The `stone` CLI detects its location and adjusts paths accordingly:

**In create-project mode:**
- `ROOT_PATH = __DIR__` (stone script is in project root)
- `FRAMEWORK_PATH = ROOT_PATH/Framework`

**In require mode:**
- `ROOT_PATH = dirname(dirname(dirname(__DIR__)))` (go up to project root)
- `FRAMEWORK_PATH = __DIR__/Framework` (framework is in vendor)

## Testing

### Verify Create-Project Mode (Existing Behavior)
```bash
composer create-project progalaxyelabs/stonescriptphp test-create
cd test-create
php stone help
php stone serve
```

### Verify Require Mode (New Behavior)
```bash
mkdir test-require
cd test-require
composer init -n
composer require progalaxyelabs/stonescriptphp
vendor/bin/stone init
./stone serve
```

## Benefits

1. **Framework Lives in vendor/** - Enables composer-based upgrades
2. **Clean Git History** - User code stays in `src/`, framework updates don't pollute history
3. **Flexible Installation** - Works for new projects and existing projects
4. **Same CLI Experience** - All commands work in both modes
5. **Templates Reused** - Both modes can use the same starter templates
6. **Backwards Compatible** - Create-project mode still works exactly as before

## Acceptance Criteria Status

- ✅ `composer create-project` works as before
- ✅ `composer require` installs successfully
- ✅ `vendor/bin/stone` is accessible after require
- ✅ `vendor/bin/stone init` scaffolds project in existing directory
- ✅ Both modes use the same Framework code
- ✅ CLI commands work in both modes
- ✅ Documentation covers both installation methods

## Future Enhancements

1. Add `.stone-config.json` to persist installation mode preference
2. Add `stone upgrade` command to handle framework updates
3. Support custom template repositories
4. Add telemetry to track which mode is more popular
5. Consider creating separate packages for each starter template
