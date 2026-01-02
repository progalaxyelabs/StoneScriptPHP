#!/bin/bash
# Release Script for StoneScriptPHP v2.3.5
# Critical bugfix release for Router namespace issue

set -e  # Exit on any error

echo "=========================================="
echo "StoneScriptPHP v2.3.5 Release Script"
echo "=========================================="
echo ""

# Check we're in the right directory
if [ ! -f "composer.json" ] || [ ! -f "VERSION" ]; then
    echo "❌ Error: Must run from StoneScriptPHP root directory"
    exit 1
fi

# Check current branch
BRANCH=$(git branch --show-current)
if [ "$BRANCH" != "main" ]; then
    echo "⚠️  Warning: Not on main branch (currently on: $BRANCH)"
    read -p "Continue anyway? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

# Check for uncommitted changes (besides Router.php which we want to commit)
CHANGED=$(git status --porcelain | grep -v "src/Routing/Router.php" | wc -l)
if [ $CHANGED -gt 0 ]; then
    echo "⚠️  Warning: You have uncommitted changes besides Router.php:"
    git status --porcelain | grep -v "src/Routing/Router.php"
    read -p "Continue anyway? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

echo "Step 1: Verifying the fix..."
if grep -q "\\Framework\\IRouteHandler" src/Routing/Router.php; then
    echo "❌ Error: Router.php still contains Framework\\IRouteHandler"
    echo "   The fix hasn't been applied yet!"
    exit 1
fi

if grep -q "\\StoneScriptPHP\\IRouteHandler" src/Routing/Router.php; then
    echo "✅ Fix verified: Router.php uses correct namespace"
else
    echo "❌ Error: Router.php doesn't contain StoneScriptPHP\\IRouteHandler"
    exit 1
fi

echo ""
echo "Step 2: Committing the fix..."
git add src/Routing/Router.php
git commit -m "Fix: Correct namespace check in Router.php from \Framework\IRouteHandler to \StoneScriptPHP\IRouteHandler

This bug caused all routes to fail with 'Handler not implemented correctly'
error when using the new Router with middleware.

Fixes: Incorrect instanceof check on line 261
Severity: Critical
Affects: v2.3.0, v2.3.1, v2.3.3, v2.3.4"

echo "✅ Fix committed"

echo ""
echo "Step 3: Bumping version to 2.3.5..."
echo "2.3.5" > VERSION
git add VERSION
git commit -m "Bump version to 2.3.5"
echo "✅ Version bumped"

echo ""
echo "Step 4: Creating git tag v2.3.5..."
git tag -a v2.3.5 -m "Release v2.3.5

Bug Fixes:
- Fix incorrect namespace check in Router.php (Critical)
  - Changed \Framework\IRouteHandler to \StoneScriptPHP\IRouteHandler
  - Fixes 'Handler not implemented correctly' errors
  - Affects all routes when using new Router with middleware

This is a critical bugfix release that resolves routing failures
for users of the new middleware-based routing system."

echo "✅ Tag created"

echo ""
echo "Step 5: Pushing to origin..."
echo "  Pushing commits..."
git push origin main

echo "  Pushing tag..."
git push origin v2.3.5

echo "✅ Pushed to origin"

echo ""
echo "=========================================="
echo "✅ Release v2.3.5 Complete!"
echo "=========================================="
echo ""
echo "Next steps:"
echo "1. Wait ~5 minutes for Packagist to auto-update"
echo "2. Verify at: https://packagist.org/packages/progalaxyelabs/stonescriptphp"
echo "3. Test with: composer require progalaxyelabs/stonescriptphp:^2.3.5"
echo ""
echo "Packagist should auto-detect the new tag and publish automatically."
echo "If it doesn't update after 5 minutes, click 'Update' on the Packagist page."
echo ""
