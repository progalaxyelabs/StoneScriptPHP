#!/bin/bash
cd /tmp/test-project

# Test: php stone setup (should exist)
php stone --help | grep "setup" || exit 1

# Test: php stone generate route
php stone generate route test-route
[ -f "src/App/Routes/TestRouteRoute.php" ] || exit 1
echo "✓ Route generation works"

# Test: php stone generate env
php stone generate env
[ -f ".env" ] || exit 1
echo "✓ Env generation works"
