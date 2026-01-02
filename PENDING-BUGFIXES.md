# 🐛 Pending Bug Fixes Not Yet Published to Packagist

**Last Published Version:** v2.3.4 (released 2025-12-22)
**Current Local State:** Uncommitted fix in working directory

---

## Critical Bug #1: Incorrect Namespace Check in Router.php

### Status: 🔴 **NOT PUBLISHED** - Fix exists locally but not committed/released

### Details

**File:** `src/Routing/Router.php`
**Line:** 261
**Severity:** 🔴 **CRITICAL** - Breaks all routes with middleware

### The Bug

```php
// ❌ WRONG - Currently in v2.3.4 on Packagist
if (!($handler instanceof \Framework\IRouteHandler)) {
    log_debug("Handler does not implement IRouteHandler: $handlerClass");
    return $this->error404('Handler not implemented correctly');
}
```

**Problem:**
- Checks for `\Framework\IRouteHandler` namespace
- This namespace **doesn't exist** in StoneScriptPHP
- The correct namespace is `\StoneScriptPHP\IRouteHandler`

### Impact

**Affected Users:** Anyone using the new Router with middleware (v2.3.0+)

**Symptoms:**
- All routes return HTTP 404 with error: `"Handler not implemented correctly"`
- Routes work fine with old Router (`StoneScriptPHP\Router`)
- Particularly affects users migrating to the new middleware system

**Workaround:**
```php
// Temporarily users must apply this patch manually
// Or use the old Router without middleware
```

### The Fix

```php
// ✅ CORRECT - Local uncommitted fix
if (!($handler instanceof \StoneScriptPHP\IRouteHandler)) {
    log_debug("Handler does not implement IRouteHandler: $handlerClass");
    return $this->error404('Handler not implemented correctly');
}
```

### Git Diff

```diff
diff --git a/src/Routing/Router.php b/src/Routing/Router.php
index ca6b121..09b17cf 100644
--- a/src/Routing/Router.php
+++ b/src/Routing/Router.php
@@ -258,7 +258,7 @@ class Router
             $handler = new $handlerClass();

             // Check if handler implements IRouteHandler interface
-            if (!($handler instanceof \Framework\IRouteHandler)) {
+            if (!($handler instanceof \StoneScriptPHP\IRouteHandler)) {
                 log_debug("Handler does not implement IRouteHandler: $handlerClass");
                 return $this->error404('Handler not implemented correctly');
             }
```

### Current Status in Repository

```bash
# Check with:
git status

# Output:
# On branch main
# Your branch is up-to-date with 'origin/main'.
#
# Changes not staged for commit:
#   modified:   src/Routing/Router.php
```

**This fix is:**
- ✅ Applied locally
- ❌ Not committed
- ❌ Not tagged
- ❌ Not published to Packagist

---

## Related Issues

### Issue History

The bug was introduced when the new Router with middleware support was added. The developer likely:

1. Started with a `Framework` namespace in early development
2. Renamed to `StoneScriptPHP` for consistency
3. Missed updating this one instanceof check

### Files Checked

All other files are clean - no other incorrect namespace references found:

```bash
# Checked with:
find src/ -name "*.php" -type f -exec grep -l "Framework\\\\" {} \;

# Results:
# src/Tenancy/TenantConnectionManager.php (only in a @deprecated comment, not code)
```

---

## Recommended Actions

### 1. Commit the Fix

```bash
cd /ssd2/projects/progalaxy-elabs/opensource/stonescriptphp/StoneScriptPHP

git add src/Routing/Router.php
git commit -m "Fix: Correct namespace check in Router.php from \Framework\IRouteHandler to \StoneScriptPHP\IRouteHandler

This bug caused all routes to fail with 'Handler not implemented correctly'
error when using the new Router with middleware.

Fixes: Incorrect instanceof check on line 261
Severity: Critical
Affects: v2.3.0, v2.3.1, v2.3.3, v2.3.4"
```

### 2. Update Version

```bash
# Update VERSION file
echo "2.3.5" > VERSION

git add VERSION
git commit -m "Bump version to 2.3.5"
```

### 3. Create Git Tag

```bash
git tag -a v2.3.5 -m "Release v2.3.5

Bug Fixes:
- Fix incorrect namespace check in Router.php (Critical)
  - Changed \Framework\IRouteHandler to \StoneScriptPHP\IRouteHandler
  - Fixes 'Handler not implemented correctly' errors
  - Affects all routes when using new Router with middleware

This is a critical bugfix release that resolves routing failures
for users of the new middleware-based routing system."

git push origin main
git push origin v2.3.5
```

### 4. Publish to Packagist

Packagist should auto-update when you push the tag, but verify:

1. Go to https://packagist.org/packages/progalaxyelabs/stonescriptphp
2. Click "Update" button if needed
3. Verify v2.3.5 appears in the version list

### 5. Notify Users

Consider posting in:
- GitHub Releases page
- Documentation changelog
- Any community channels

**Release Notes Template:**

```markdown
# v2.3.5 - Critical Bugfix Release

## 🐛 Bug Fixes

### Critical: Fixed Router Namespace Check

**Issue:** Routes using the new middleware-based Router (v2.3.0+) failed with
"Handler not implemented correctly" error.

**Cause:** Incorrect namespace check - Router was checking for
`\Framework\IRouteHandler` instead of `\StoneScriptPHP\IRouteHandler`.

**Impact:** All users of the new Router with middleware system were affected.

**Fix:** Updated instanceof check to use correct namespace.

**Affected Versions:** v2.3.0, v2.3.1, v2.3.3, v2.3.4

## Upgrade Instructions

```bash
composer update progalaxyelabs/stonescriptphp
```

No breaking changes - this is a drop-in bugfix.

## For Users on v2.3.0-v2.3.4

If you experienced routing issues with the new Router, this release fixes it.
Update immediately.

## Verification

After upgrading, verify routes work correctly:

```bash
# All routes should now work properly
curl http://localhost:8000/
curl http://localhost:8000/api/health
```
```

---

## Testing Checklist

Before publishing, verify:

- [ ] Fix is committed
- [ ] Version bumped to 2.3.5
- [ ] Git tag created
- [ ] Changes pushed to origin
- [ ] Tag pushed to origin
- [ ] Packagist updated (wait ~5 min after push)
- [ ] New version appears on Packagist
- [ ] Tested with `composer require progalaxyelabs/stonescriptphp:^2.3.5`
- [ ] Routes work correctly in test project
- [ ] No regression in existing functionality

---

## Prevention

To prevent similar issues:

1. **Add automated tests** for namespace checks
2. **Search for hardcoded namespaces** before each release:
   ```bash
   grep -r "\\\\Framework\\\\" src/ --include="*.php"
   ```
3. **Code review** checklist should include namespace verification
4. **CI/CD** should catch incorrect namespace references

---

## Summary

| Item | Status |
|------|--------|
| Bug Identified | ✅ Yes |
| Fix Applied Locally | ✅ Yes |
| Fix Committed | ❌ No |
| Version Bumped | ❌ No |
| Tagged | ❌ No |
| Published to Packagist | ❌ No |

**Next Steps:** Commit, tag, and publish v2.3.5 immediately.

**Priority:** 🔴 **CRITICAL** - Blocks middleware functionality

**Estimated Time to Publish:** < 5 minutes
