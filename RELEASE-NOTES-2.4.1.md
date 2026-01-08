# StoneScriptPHP v2.4.1 Release Notes

**Release Date:** January 8, 2026

## Critical Bug Fixes: Logger Permission and Web Context Issues

This release addresses critical production issues with the Logger that could break CORS headers and cause permission conflicts in Docker environments.

### Issues Fixed

1. **Console Logging in Web Contexts** (CRITICAL)
   - Logger now auto-detects PHP_SAPI and disables console output in web contexts (PHP-FPM, Apache, Nginx)
   - Prevents console output from interfering with HTTP headers (CORS failures)
   - Safe by default - no configuration needed in web entry points

2. **File Permission Conflicts** (HIGH)
   - Graceful failure handling when log files have permission issues
   - Suppresses warnings that would break HTTP responses
   - Only logs errors in CLI context to avoid header interference

3. **Configurable Log Directory** (NEW FEATURE)
   - Added `log_directory` parameter to `configure()` method
   - Support for `STONESCRIPTPHP_LOG_DIR` environment variable
   - Enables separate log directories for CLI vs web contexts in Docker

### What Changed

#### Logger.php

**New `configure()` signature:**
```php
public function configure(
    bool $console = true,
    bool $file = true,
    bool $json = false,
    ?string $log_directory = null  // NEW
): void
```

**Auto-Detection:**
- Automatically disables console logging when `PHP_SAPI !== 'cli'`
- Prevents accidental header interference in production

**Graceful Failure:**
- Uses `@` suppression for file operations to prevent warnings
- Wraps file operations in try/catch blocks
- Only reports errors in CLI context

**Priority Order for Log Directory:**
1. Custom directory from `configure()` method
2. `STONESCRIPTPHP_LOG_DIR` environment variable
3. `/var/log/stonescriptphp` in Docker
4. `ROOT_PATH/logs` (default)

### Migration Guide

**Before (Required manual workaround):**
```php
// public/index.php - HAD to manually disable console
Logger::get_instance()->configure(console: false, file: true);
```

**After (No configuration needed):**
```php
// public/index.php - Works automatically
// Console logging auto-disabled in web context
```

**For Docker Multi-User Contexts:**
```php
// CLI scripts (migrations, etc.)
Logger::get_instance()->configure(
    console: true,
    file: true,
    log_directory: '/var/log/stonescriptphp-cli'
);

// Web entry points
// No configuration needed - uses default
```

**Using Environment Variables:**
```dockerfile
# Dockerfile
ENV STONESCRIPTPHP_LOG_DIR=/var/log/stonescriptphp
```

### Documentation Updates

- Added comprehensive Docker deployment best practices
- Added permission handling examples
- Added multi-user context solutions
- Updated all examples to reflect new behavior

### Testing

New test script validates all fixes:
```bash
php test-logger-fixes.php
```

Tests verify:
- ✅ PHP_SAPI auto-detection
- ✅ Custom log directory configuration
- ✅ Environment variable support
- ✅ Graceful permission failure handling
- ✅ Configuration priority order

### Breaking Changes

**None.** This is a backward-compatible bug fix release.

- Existing code continues to work without changes
- New parameters are optional with safe defaults
- Auto-detection only improves safety

### Impact

This release fixes production-critical issues that affected:
- All Docker deployments with migrations
- All web applications using logging
- Multi-tenant platforms
- Applications with proper user separation

The fixes eliminate:
- ❌ CORS header failures
- ❌ Manual permission workarounds in Docker entrypoints
- ❌ "Headers already sent" warnings
- ❌ Application crashes due to logging failures

### Upgrade Instructions

1. Update via Composer:
   ```bash
   composer update progalaxyelabs/stonescriptphp
   ```

2. **(Optional)** Remove manual workarounds:
   - Remove `Logger::get_instance()->configure(console: false)` from web entry points
   - Simplify Docker entrypoint permission scripts
   - Remove manual permission fixes

3. **(Optional)** Configure separate log directories for CLI/web contexts

4. No code changes required - everything works automatically

### Credits

Thanks to the ProGalaxy Platform team for the detailed issue report and proposed solutions.

### Related Issues

- Fixes: Logger permission conflicts in Docker multi-user environments
- Fixes: Console output breaking HTTP headers in web contexts
- Implements: Configurable log directories
- Implements: Environment variable support for log paths

---

**Full Changelog:** v2.4.0...v2.4.1
