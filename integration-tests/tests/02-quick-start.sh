#!/bin/bash
cd /tmp/test-project

# Test: php stone serve (just verify command exists)
php stone --help | grep "serve" || exit 1
echo "✓ CLI accessible"

# Test: Project structure matches README
[ -d "src/App/Routes" ] || exit 1
[ -d "Framework" ] || exit 1
echo "✓ Project structure valid"
